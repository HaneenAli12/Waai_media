<?php

namespace App\Console\Commands;

use App\Models\NewsImage;
use App\Services\News\NewsImageService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanOrphanedNewsImagesCommand extends Command
{
    /**
     * Lock key preventing two cleanup runs (scheduled and/or manual) from overlapping.
     */
    public const LOCK_KEY = 'news:clean-orphaned-images';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'news:clean-orphaned-images
                            {--force : Execute destructive cleanup (default is dry-run mode)}
                            {--dry-run : Report candidates only; takes precedence over --force}
                            {--grace-hours=24 : Minimum age in hours before an unattached image is eligible}
                            {--max-deletions=50 : Abort without deleting anything when more objects than this are eligible}
                            {--allow-empty-references : Permit deletion when no news article references any image}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean orphaned news cover and editor images from news_images and Cloudflare R2.';

    /**
     * Execute the console command.
     */
    public function handle(NewsImageService $images): int
    {
        $isForce = (bool) $this->option('force') && ! $this->option('dry-run');
        $graceHours = max(1, (int) $this->option('grace-hours'));
        $maxDeletions = max(1, (int) $this->option('max-deletions'));
        $now = Carbon::now()->getTimestamp();
        $cutoffTimestamp = $now - ($graceHours * 3600);
        $cutoffCarbon = Carbon::createFromTimestamp($cutoffTimestamp);

        $lock = Cache::lock(self::LOCK_KEY, 3600);
        if (! $lock->get()) {
            $this->warn('Another orphaned news image cleanup is already running. Skipping this run.');
            Log::warning('Orphaned news image cleanup skipped: another run holds the lock.');

            return Command::FAILURE;
        }

        try {
            return $this->executeCleanup($images, $isForce, $graceHours, $maxDeletions, $now, $cutoffTimestamp, $cutoffCarbon);
        } finally {
            $lock->release();
        }
    }

    private function executeCleanup(
        NewsImageService $images,
        bool $isForce,
        int $graceHours,
        int $maxDeletions,
        int $now,
        int $cutoffTimestamp,
        Carbon $cutoffCarbon
    ): int {
        $modeText = $isForce ? 'DESTRUCTIVE' : 'DRY-RUN';
        $this->info("Starting orphaned news image cleanup [{$modeText}] (Grace period: {$graceHours}h, max deletions: {$maxDeletions}).");

        // 1. Fail closed: read all database references before considering any object for deletion.
        $referencedKeys = [];
        $referencedImageIds = [];
        $totalArticleRefs = 0;

        try {
            foreach (DB::table('news')->select(['featured_image_id', 'featured_image_url', 'content_ar', 'content_en'])->cursor() as $row) {
                if ($row->featured_image_id !== null) {
                    $referencedImageIds[(int) $row->featured_image_id] = true;
                    $totalArticleRefs++;
                }

                foreach ([$row->featured_image_url, $row->content_ar, $row->content_en] as $text) {
                    if ($text !== null && preg_match_all(NewsImageService::KEY_PATTERN, (string) $text, $matches)) {
                        foreach ($matches[0] as $key) {
                            $referencedKeys[strtolower($key)] = true;
                            $totalArticleRefs++;
                        }
                    }
                }
            }

            foreach (DB::table('news_article_images')->pluck('news_image_id') as $imgId) {
                $referencedImageIds[(int) $imgId] = true;
                $totalArticleRefs++;
            }

            if ($referencedImageIds !== []) {
                foreach (DB::table('news_images')->whereIn('id', array_keys($referencedImageIds))->pluck('storage_key') as $key) {
                    $referencedKeys[strtolower((string) $key)] = true;
                }
            }
        } catch (Throwable $e) {
            $this->error('Database reference lookup failed. Aborting cleanup to fail closed.');
            Log::critical('Orphaned news image cleanup aborted: database lookup failed.', ['error' => $e->getMessage()]);

            return Command::FAILURE;
        }

        // 2. Enumerate objects strictly under news/covers/ and news/editor/
        try {
            $allFiles = $images->allNewsKeys();
        } catch (Throwable $e) {
            $this->error('Failed to list news images from storage. Aborting cleanup.');
            Log::critical('Orphaned news image cleanup aborted: storage listing failed.', ['error' => $e->getMessage()]);

            return Command::FAILURE;
        }

        $candidateKeys = [];
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

            if (isset($referencedKeys[strtolower($key)])) {
                $skippedReferenced++;

                continue;
            }

            // Also check if a NewsImage row for this key is within the grace period
            $dbRecord = NewsImage::where('storage_key', $key)->first();
            if ($dbRecord && $dbRecord->created_at && $dbRecord->created_at->greaterThan($cutoffCarbon)) {
                $skippedGrace++;

                continue;
            }

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

            $candidateKeys[strtolower($key)] = $key;
        }

        // Also identify unattached NewsImage rows older than the grace period whose storage keys may or may not still be in $allFiles
        $orphanDbImages = NewsImage::query()
            ->where('created_at', '<=', $cutoffCarbon)
            ->when($referencedImageIds !== [], fn ($q) => $q->whereNotIn('id', array_keys($referencedImageIds)))
            ->get();

        foreach ($orphanDbImages as $dbImg) {
            $lowerKey = strtolower($dbImg->storage_key);
            if (! isset($referencedKeys[$lowerKey]) && $images->isR2Key($dbImg->storage_key)) {
                $candidateKeys[$lowerKey] = $dbImg->storage_key;
            }
        }

        $candidates = array_values($candidateKeys);

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
            $this->info('No orphaned news images found eligible for cleanup.');

            return Command::SUCCESS;
        }

        if (! $isForce) {
            $this->warn('DRY-RUN MODE ENABLED: No files were deleted.');
            $this->table(['Candidate Orphaned Key', 'Status'], array_map(
                fn ($key) => [$key, 'Eligible for deletion (dry-run)'],
                $candidates
            ));

            Log::info('Orphaned news image cleanup dry-run completed.', [
                'eligible' => count($candidates),
                'grace_hours' => $graceHours,
            ]);

            return Command::SUCCESS;
        }

        // 3. Circuit breakers
        if ($totalArticleRefs === 0 && ! $this->option('allow-empty-references')) {
            $this->error('No news article references any image but images exist in storage. Aborting; use --allow-empty-references after review.');
            Log::critical('Orphaned news image cleanup aborted: no news article references any image.', [
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
            Log::critical('Orphaned news image cleanup aborted: eligible count exceeds safety limit.', [
                'eligible' => count($candidates),
                'max_deletions' => $maxDeletions,
            ]);

            return Command::FAILURE;
        }

        // 4. Destructive execution with just-in-time revalidation and staged deletion
        $deletedCount = 0;
        $failedCount = 0;
        $skippedRevalidation = 0;

        foreach ($candidates as $key) {
            try {
                if ($images->isKeyReferenced($key)) {
                    $this->warn("Candidate [{$key}] is now referenced in database. Skipping deletion.");
                    $skippedRevalidation++;

                    continue;
                }

                $dbImage = NewsImage::where('storage_key', $key)->first();
            } catch (Throwable $e) {
                $this->error("Database revalidation failed for [{$key}]. Aborting cleanup for safety.");
                Log::critical('Database revalidation failed during destructive orphaned news image cleanup.', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                return Command::FAILURE;
            }

            if ($dbImage !== null) {
                if ($dbImage->created_at && $dbImage->created_at->greaterThan($cutoffCarbon)) {
                    $this->warn("Candidate [{$key}] is within the grace period. Skipping deletion.");
                    $skippedRevalidation++;

                    continue;
                }

                try {
                    $deleted = $images->deleteImageIfUnreferenced($dbImage->id);

                    if ($deleted) {
                        $deletedCount++;
                        Log::info("Orphaned news image deleted: {$key}", ['news_image_id' => $dbImage->id]);

                        continue;
                    }

                    if ($images->isImageReferenced($dbImage->id, $key)) {
                        $this->warn("Candidate [{$key}] is now referenced in database. Skipping deletion.");
                        $skippedRevalidation++;

                        continue;
                    }

                    if (! NewsImage::where('id', $dbImage->id)->exists()) {
                        $deletedCount++;
                        Log::info("Orphaned news image already removed: {$key}", ['news_image_id' => $dbImage->id]);

                        continue;
                    }
                } catch (Throwable $e) {
                    $this->error("Database revalidation failed for [{$key}]. Aborting cleanup for safety.");
                    Log::critical('Database revalidation failed during destructive orphaned news image cleanup.', [
                        'key' => $key,
                        'news_image_id' => $dbImage->id,
                        'error' => $e->getMessage(),
                    ]);

                    return Command::FAILURE;
                }

                $failedCount++;
                Log::warning("Failed to delete orphaned news image: {$key}", ['news_image_id' => $dbImage->id]);

                continue;
            }

            // Raw R2 object with no news_images database record: delete directly from R2 without inventing a DB row
            if ($images->safeDeleteKey($key)) {
                $deletedCount++;
                Log::info("Orphaned raw news R2 object deleted: {$key}");
            } else {
                $failedCount++;
                Log::warning("Failed to delete orphaned raw news R2 object: {$key}");
            }
        }

        $this->info("Destructive cleanup complete. Deleted: {$deletedCount}, Failed: {$failedCount}, Skipped on revalidation: {$skippedRevalidation}.");

        return $failedCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
