<?php

namespace App\Console\Commands;

use App\Services\Team\TeamPhotoService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanOrphanedTeamPhotosCommand extends Command
{
    /**
     * Lock key preventing two cleanup runs (scheduled and/or manual) from overlapping.
     */
    public const LOCK_KEY = 'team:clean-orphaned-photos';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'team:clean-orphaned-photos
                            {--force : Execute destructive cleanup (default is dry-run mode)}
                            {--dry-run : Report candidates only; takes precedence over --force}
                            {--grace-hours=24 : Minimum object age in hours (from the R2 last-modified time) before an unreferenced object is eligible}
                            {--max-deletions=50 : Abort without deleting anything when more objects than this are eligible}
                            {--allow-empty-references : Permit deletion when no team member references any photo (normally treated as a wrong-database signal)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean orphaned team photos from the team/photos/ prefix in Cloudflare R2.';

    /**
     * Execute the console command.
     */
    public function handle(TeamPhotoService $photos): int
    {
        $isForce = (bool) $this->option('force') && ! $this->option('dry-run');
        $graceHours = max(1, (int) $this->option('grace-hours'));
        $maxDeletions = max(1, (int) $this->option('max-deletions'));
        $now = Carbon::now()->getTimestamp();
        $cutoffTimestamp = $now - ($graceHours * 3600);

        $lock = Cache::lock(self::LOCK_KEY, 3600);
        if (! $lock->get()) {
            $this->warn('Another orphaned team photo cleanup is already running. Skipping this run.');
            Log::warning('Orphaned team photo cleanup skipped: another run holds the lock.');

            return Command::FAILURE;
        }

        try {
            return $this->executeCleanup($photos, $isForce, $graceHours, $maxDeletions, $now, $cutoffTimestamp);
        } finally {
            $lock->release();
        }
    }

    private function executeCleanup(TeamPhotoService $photos, bool $isForce, int $graceHours, int $maxDeletions, int $now, int $cutoffTimestamp): int
    {
        $modeText = $isForce ? 'DESTRUCTIVE' : 'DRY-RUN';
        $this->info("Starting orphaned team photo cleanup [{$modeText}] (Grace period: {$graceHours}h, max deletions: {$maxDeletions}).");

        // 1. Fail closed: the reference set must be readable before anything is considered for deletion
        try {
            $referencedKeys = array_flip(
                DB::table('team_members')->whereNotNull('photo_path')->pluck('photo_path')->all()
            );
        } catch (Throwable $e) {
            $this->error('Database reference lookup failed. Aborting cleanup to fail closed.');
            Log::critical('Orphaned team photo cleanup aborted: database lookup failed.', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }

        // 2. Enumerate objects strictly under the dedicated team photo prefix
        try {
            $allFiles = $photos->allPhotoKeys();
        } catch (Throwable $e) {
            $this->error('Failed to list team photos from storage. Aborting cleanup.');
            Log::critical('Orphaned team photo cleanup aborted: storage listing failed.', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }

        $candidates = [];
        $skippedReferenced = 0;
        $skippedGrace = 0;
        $skippedInvalid = 0;
        $skippedTimestamp = 0;

        foreach ($allFiles as $key) {
            if (! $photos->isR2Key($key)) {
                $this->warn("Skipping key with unexpected format: {$key}");
                $skippedInvalid++;

                continue;
            }

            if (isset($referencedKeys[$key])) {
                $skippedReferenced++;

                continue;
            }

            // Age comes from the actual object's last-modified time; unknown/invalid timestamps keep the object
            try {
                $lastModified = $photos->lastModified($key);
            } catch (Throwable) {
                $this->warn("Could not determine last modified timestamp for key [{$key}]. Skipping for safety.");
                $skippedTimestamp++;

                continue;
            }

            if ($lastModified <= 0 || $lastModified > $now) {
                $this->warn("Invalid last modified timestamp for key [{$key}]. Skipping for safety.");
                $skippedTimestamp++;

                continue;
            }

            if ($lastModified > $cutoffTimestamp) {
                $skippedGrace++;

                continue;
            }

            $candidates[] = $key;
        }

        $this->info(sprintf(
            'Scan complete. Scanned: %d | Referenced: %d | Within Grace Period: %d | Invalid Format: %d | Invalid Timestamp: %d | Eligible: %d',
            count($allFiles),
            $skippedReferenced,
            $skippedGrace,
            $skippedInvalid,
            $skippedTimestamp,
            count($candidates)
        ));

        if ($candidates === []) {
            $this->info('No orphaned team photos found eligible for cleanup.');

            return Command::SUCCESS;
        }

        if (! $isForce) {
            $this->warn('DRY-RUN MODE ENABLED: No files were deleted.');
            $this->table(['Candidate Orphaned Key', 'Status'], array_map(
                fn ($key) => [$key, 'Eligible for deletion (dry-run)'],
                $candidates
            ));

            Log::info('Orphaned team photo cleanup dry-run completed.', [
                'eligible' => count($candidates),
                'grace_hours' => $graceHours,
            ]);

            return Command::SUCCESS;
        }

        // 3. Circuit breakers: a destructive run refuses to proceed when the result looks implausible
        if ($referencedKeys === [] && ! $this->option('allow-empty-references')) {
            $this->error('No team member references any photo but photos exist in storage. This may indicate the wrong database. Aborting; use --allow-empty-references after review.');
            Log::critical('Orphaned team photo cleanup aborted: no team member references any photo (possible wrong database).', [
                'eligible' => count($candidates),
            ]);

            return Command::FAILURE;
        }

        if (count($candidates) > $maxDeletions) {
            $this->error(sprintf(
                'Eligible count (%d) exceeds --max-deletions (%d). Nothing was deleted; review with --dry-run and re-run with a higher limit.',
                count($candidates),
                $maxDeletions
            ));
            Log::critical('Orphaned team photo cleanup aborted: eligible count exceeds the safety limit.', [
                'eligible' => count($candidates),
                'max_deletions' => $maxDeletions,
            ]);

            return Command::FAILURE;
        }

        // 4. Destructive execution with just-in-time revalidation of each candidate
        $deletedCount = 0;
        $failedCount = 0;
        $skippedRevalidation = 0;

        foreach ($candidates as $key) {
            try {
                if (DB::table('team_members')->where('photo_path', $key)->exists()) {
                    $this->warn("Candidate [{$key}] is now referenced in database. Skipping deletion.");
                    $skippedRevalidation++;

                    continue;
                }
            } catch (Throwable $e) {
                $this->error("Database revalidation failed for [{$key}]. Aborting cleanup for safety.");
                Log::critical('Database revalidation failed during destructive orphaned team photo cleanup.', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                return Command::FAILURE;
            }

            if ($photos->safeDeleteKey($key)) {
                $deletedCount++;
                Log::info("Orphaned team photo deleted: {$key}");
            } else {
                $failedCount++;
                Log::warning("Failed to delete orphaned team photo: {$key}");
            }
        }

        $this->info("Destructive cleanup complete. Deleted: {$deletedCount}, Failed: {$failedCount}, Skipped on revalidation: {$skippedRevalidation}.");

        return $failedCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
