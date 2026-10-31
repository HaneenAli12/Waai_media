<?php

namespace App\Console\Commands;

use App\Services\Portfolio\PortfolioImageService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanOrphanedPortfolioImagesCommand extends Command
{
    /**
     * Lock key preventing two cleanup runs (scheduled and/or manual) from overlapping.
     */
    public const LOCK_KEY = 'portfolio:clean-orphaned-images';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'portfolio:clean-orphaned-images
                            {--force : Execute destructive cleanup (default is dry-run mode)}
                            {--dry-run : Report candidates only; takes precedence over --force}
                            {--grace-hours=24 : Minimum object age in hours (from the R2 last-modified time) before an unreferenced object is eligible}
                            {--max-deletions=50 : Abort without deleting anything when more objects than this are eligible}
                            {--allow-empty-references : Permit deletion when no portfolio media references any image (normally treated as a wrong-database signal)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean orphaned portfolio images from the portfolio/images/ prefix in Cloudflare R2.';

    /**
     * Execute the console command.
     */
    public function handle(PortfolioImageService $images): int
    {
        $isForce = (bool) $this->option('force') && ! $this->option('dry-run');
        $graceHours = max(1, (int) $this->option('grace-hours'));
        $maxDeletions = max(1, (int) $this->option('max-deletions'));
        $now = Carbon::now()->getTimestamp();
        $cutoffTimestamp = $now - ($graceHours * 3600);

        $lock = Cache::lock(self::LOCK_KEY, 3600);
        if (! $lock->get()) {
            $this->warn('Another orphaned portfolio image cleanup is already running. Skipping this run.');
            Log::warning('Orphaned portfolio image cleanup skipped: another run holds the lock.');

            return Command::FAILURE;
        }

        try {
            return $this->executeCleanup($images, $isForce, $graceHours, $maxDeletions, $now, $cutoffTimestamp);
        } finally {
            $lock->release();
        }
    }

    private function executeCleanup(PortfolioImageService $images, bool $isForce, int $graceHours, int $maxDeletions, int $now, int $cutoffTimestamp): int
    {
        $modeText = $isForce ? 'DESTRUCTIVE' : 'DRY-RUN';
        $this->info("Starting orphaned portfolio image cleanup [{$modeText}] (Grace period: {$graceHours}h, max deletions: {$maxDeletions}).");

        // 1. Fail closed: the reference set must be readable before anything is considered for deletion
        try {
            // Work images and dedicated covers are both live references
            $referencedKeys = array_flip(array_merge(
                DB::table('portfolio_media')->whereNotNull('image_path')->pluck('image_path')->all(),
                DB::table('portfolio_items')->whereNotNull('cover_image_path')->pluck('cover_image_path')->all()
            ));
        } catch (Throwable $e) {
            $this->error('Database reference lookup failed. Aborting cleanup to fail closed.');
            Log::critical('Orphaned portfolio image cleanup aborted: database lookup failed.', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }

        // 2. Enumerate objects strictly under the dedicated portfolio image prefix
        try {
            $allFiles = $images->allImageKeys();
        } catch (Throwable $e) {
            $this->error('Failed to list portfolio images from storage. Aborting cleanup.');
            Log::critical('Orphaned portfolio image cleanup aborted: storage listing failed.', [
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
            if (! $images->isR2Key($key)) {
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
                $lastModified = $images->lastModified($key);
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
            $this->info('No orphaned portfolio images found eligible for cleanup.');

            return Command::SUCCESS;
        }

        if (! $isForce) {
            $this->warn('DRY-RUN MODE ENABLED: No files were deleted.');
            $this->table(['Candidate Orphaned Key', 'Status'], array_map(
                fn ($key) => [$key, 'Eligible for deletion (dry-run)'],
                $candidates
            ));

            Log::info('Orphaned portfolio image cleanup dry-run completed.', [
                'eligible' => count($candidates),
                'grace_hours' => $graceHours,
            ]);

            return Command::SUCCESS;
        }

        // 3. Circuit breakers: a destructive run refuses to proceed when the result looks implausible
        if ($referencedKeys === [] && ! $this->option('allow-empty-references')) {
            $this->error('No portfolio media references any image but images exist in storage. This may indicate the wrong database. Aborting; use --allow-empty-references after review.');
            Log::critical('Orphaned portfolio image cleanup aborted: no portfolio media references any image (possible wrong database).', [
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
            Log::critical('Orphaned portfolio image cleanup aborted: eligible count exceeds the safety limit.', [
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
                if (DB::table('portfolio_media')->where('image_path', $key)->exists()
                    || DB::table('portfolio_items')->where('cover_image_path', $key)->exists()) {
                    $this->warn("Candidate [{$key}] is now referenced in database. Skipping deletion.");
                    $skippedRevalidation++;

                    continue;
                }
            } catch (Throwable $e) {
                $this->error("Database revalidation failed for [{$key}]. Aborting cleanup for safety.");
                Log::critical('Database revalidation failed during destructive orphaned portfolio image cleanup.', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                return Command::FAILURE;
            }

            if ($images->safeDeleteKey($key)) {
                $deletedCount++;
                Log::info("Orphaned portfolio image deleted: {$key}");
            } else {
                $failedCount++;
                Log::warning("Failed to delete orphaned portfolio image: {$key}");
            }
        }

        $this->info("Destructive cleanup complete. Deleted: {$deletedCount}, Failed: {$failedCount}, Skipped on revalidation: {$skippedRevalidation}.");

        return $failedCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
