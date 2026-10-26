<?php

namespace App\Console\Commands;

use App\Models\Music;
use App\Services\Storage\AudioStorageService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanOrphanedBackgroundMusicCommand extends Command
{
    /**
     * Lock key preventing two cleanup runs (scheduled and/or manual) from overlapping.
     */
    public const LOCK_KEY = 'music:clean-orphaned';

    /**
     * The name and signature of the console command.
     *
     * Background music is a single-record design (one active track), so the normal number of orphans is tiny:
     * the default --max-deletions is much lower than for the multi-record modules.
     *
     * @var string
     */
    protected $signature = 'music:clean-orphaned
                            {--force : Execute destructive cleanup (default is dry-run mode)}
                            {--dry-run : Report candidates only; takes precedence over --force}
                            {--grace-hours=24 : Minimum object age in hours (from the R2 last-modified time) before an object is eligible for cleanup}
                            {--max-deletions=5 : Abort without deleting anything when more objects than this are eligible}
                            {--allow-empty-references : Permit deletion when no active track is recorded (normally treated as a wrong-database signal)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean orphaned audio files from Cloudflare R2 background music storage.';

    /**
     * Execute the console command.
     */
    public function handle(AudioStorageService $audioStorage): int
    {
        $isForce = (bool) $this->option('force') && ! $this->option('dry-run');
        $graceHours = max(1, (int) $this->option('grace-hours'));
        $maxDeletions = max(1, (int) $this->option('max-deletions'));
        $now = Carbon::now()->getTimestamp();
        $cutoffTimestamp = $now - ($graceHours * 3600);

        $lock = Cache::lock(self::LOCK_KEY, 3600);
        if (! $lock->get()) {
            $this->warn('Another orphaned background music cleanup is already running. Skipping this run.');
            Log::warning('Orphaned background music cleanup skipped: another run holds the lock.');

            return Command::FAILURE;
        }

        try {
            return $this->executeCleanup($audioStorage, $isForce, $graceHours, $maxDeletions, $now, $cutoffTimestamp);
        } finally {
            $lock->release();
        }
    }

    private function executeCleanup(AudioStorageService $audioStorage, bool $isForce, int $graceHours, int $maxDeletions, int $now, int $cutoffTimestamp): int
    {
        $modeText = $isForce ? 'DESTRUCTIVE' : 'DRY-RUN';
        $this->info("Starting orphaned background music cleanup [{$modeText}] (Grace period: {$graceHours}h, max deletions: {$maxDeletions}).");

        // 1. Fail closed: Verify database connection and fetch referenced track keys
        $referencedKeys = [];
        try {
            DB::connection()->getPdo();

            // Fetch current active track key from music singleton
            $activeMusic = Music::find(1);
            if ($activeMusic && ! empty($activeMusic->file_path)) {
                $referencedKeys[] = $activeMusic->file_path;
            }

            // Also inspect any other rows if they ever exist
            $allDbMusicKeys = DB::table('music')
                ->whereNotNull('file_path')
                ->pluck('file_path')
                ->all();

            $referencedKeys = array_unique(array_merge($referencedKeys, $allDbMusicKeys));
        } catch (Throwable $e) {
            $this->error('Database health check or reference lookup failed. Aborting cleanup to fail closed.');
            Log::critical('Orphaned background music cleanup aborted: database lookup failed.', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }

        // 2. Enumerate objects strictly under the dedicated audio prefix
        try {
            $allFiles = $audioStorage->allAudioFiles();
        } catch (Throwable $e) {
            $this->error('Failed to list audio files from storage disk. Aborting cleanup.');
            Log::critical('Orphaned background music cleanup aborted: storage listing failed.', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }

        $candidates = [];
        $skippedActive = 0;
        $skippedGrace = 0;
        $skippedInvalid = 0;
        $skippedTimestamp = 0;

        foreach ($allFiles as $key) {
            // Check authorization of key prefix and pattern
            try {
                $audioStorage->validateStorageKey($key);
            } catch (Throwable) {
                $this->warn("Skipping key with unexpected format: {$key}");
                $skippedInvalid++;

                continue;
            }

            // Never delete active / referenced keys
            if (in_array($key, $referencedKeys, true)) {
                $skippedActive++;

                continue;
            }

            // Age comes from the actual object's last-modified time; unknown/invalid timestamps keep the object
            try {
                $lastModified = $audioStorage->lastModified($key);
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
            'Scan complete. Total files: %d | Referenced/Active: %d | Within Grace Period: %d | Invalid Format: %d | Invalid Timestamp: %d | Orphan Candidates: %d',
            count($allFiles),
            $skippedActive,
            $skippedGrace,
            $skippedInvalid,
            $skippedTimestamp,
            count($candidates)
        ));

        if (empty($candidates)) {
            $this->info('No orphaned audio files found eligible for cleanup.');

            return Command::SUCCESS;
        }

        // If not force mode, perform Dry Run only
        if (! $isForce) {
            $this->warn('DRY-RUN MODE ENABLED: No files were deleted.');
            $this->table(['Candidate Orphaned Key', 'Status'], array_map(function ($key) {
                return [$key, 'Eligible for deletion (dry-run)'];
            }, $candidates));

            Log::info('Orphaned background music cleanup dry-run completed.', [
                'eligible' => count($candidates),
                'grace_hours' => $graceHours,
            ]);

            return Command::SUCCESS;
        }

        // 3. Circuit breakers (adapted to the single-record design).
        //    With one active track, "no referenced key" is only plausible before the first upload or when the
        //    command points at the wrong/empty database; both deserve a human look before anything is deleted.
        //    A max-deletions cap fits too: a healthy singleton leaves at most a handful of orphans per week.
        if ($referencedKeys === [] && ! $this->option('allow-empty-references')) {
            $this->error('No active background music track is recorded but audio objects exist in storage. This may indicate the wrong database. Aborting; use --allow-empty-references after review.');
            Log::critical('Orphaned background music cleanup aborted: no track is recorded (possible wrong database).', [
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
            Log::critical('Orphaned background music cleanup aborted: eligible count exceeds the safety limit.', [
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
                $currentActiveTrack = DB::table('music')->where('id', 1)->value('file_path');
                if ($currentActiveTrack === $key) {
                    $this->warn("Candidate [{$key}] was activated concurrently. Skipping deletion.");
                    Log::warning('Candidate orphaned audio became active concurrently; deletion skipped.', [
                        'key' => $key,
                    ]);
                    $skippedRevalidation++;

                    continue;
                }

                if (DB::table('music')->where('file_path', $key)->exists()) {
                    $this->warn("Candidate [{$key}] is now referenced in database. Skipping deletion.");
                    $skippedRevalidation++;

                    continue;
                }
            } catch (Throwable $e) {
                $this->error("Database revalidation failed for [{$key}]. Aborting cleanup for safety.");
                Log::critical('Database revalidation failed during destructive orphaned audio cleanup.', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                return Command::FAILURE;
            }

            if ($audioStorage->safeDelete($key)) {
                $deletedCount++;
                Log::info("Orphaned background music object deleted: {$key}");
            } else {
                $failedCount++;
                Log::warning("Failed to delete orphaned background music object: {$key}");
            }
        }

        $this->info("Destructive cleanup complete. Deleted: {$deletedCount}, Failed: {$failedCount}, Skipped on revalidation: {$skippedRevalidation}.");

        return $failedCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
