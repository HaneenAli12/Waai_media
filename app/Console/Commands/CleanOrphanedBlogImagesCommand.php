<?php

namespace App\Console\Commands;

use App\Models\BlogImage;
use App\Services\Blog\BlogImageService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanOrphanedBlogImagesCommand extends Command
{
    /**
     * Lock key preventing two cleanup runs (scheduled and/or manual) from overlapping.
     */
    public const LOCK_KEY = 'blogs:clean-orphaned-images';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blogs:clean-orphaned-images
                            {--force : Execute destructive cleanup (default is dry-run mode)}
                            {--dry-run : Report candidates only; takes precedence over --force}
                            {--grace-hours=24 : Minimum age in hours before an unattached image is eligible}
                            {--max-deletions=50 : Abort without deleting anything when more objects than this are eligible}
                            {--allow-empty-references : Permit deletion when no blog article references any image}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean orphaned blog cover and editor images from blog_images and Cloudflare R2.';

    /**
     * Execute the console command.
     */
    public function handle(BlogImageService $images): int
    {
        $isForce = (bool) $this->option('force') && ! $this->option('dry-run');
        $graceHours = max(1, (int) $this->option('grace-hours'));
        $maxDeletions = max(1, (int) $this->option('max-deletions'));
        $now = Carbon::now()->getTimestamp();
        $cutoffTimestamp = $now - ($graceHours * 3600);
        $cutoffCarbon = Carbon::createFromTimestamp($cutoffTimestamp);

        $lock = Cache::lock(self::LOCK_KEY, 3600);
        if (! $lock->get()) {
            $this->warn('Another orphaned blog image cleanup is already running. Skipping this run.');
            Log::warning('Orphaned blog image cleanup skipped: another run holds the lock.');

            return Command::FAILURE;
        }

        try {
            return $this->executeCleanup($images, $isForce, $graceHours, $maxDeletions, $now, $cutoffTimestamp, $cutoffCarbon);
        } finally {
            $lock->release();
        }
    }

    private function executeCleanup(
        BlogImageService $images,
        bool $isForce,
        int $graceHours,
        int $maxDeletions,
        int $now,
        int $cutoffTimestamp,
        Carbon $cutoffCarbon
    ): int {
        $this->info('Starting orphaned blog image scan (dry-run: '.($isForce ? 'NO' : 'YES').')...');

        // 1. Build authoritative sets of referenced blog image keys and IDs across all blog articles
        $referencedKeys = [];
        $referencedImageIds = [];
        $totalArticleRefs = 0;

        // Cover images referenced via blogs.featured_image_id
        $featuredImages = DB::table('blogs')
            ->join('blog_images', 'blogs.featured_image_id', '=', 'blog_images.id')
            ->select('blog_images.id', 'blog_images.storage_key')
            ->get();

        foreach ($featuredImages as $row) {
            $referencedKeys[strtolower($row->storage_key)] = true;
            $referencedImageIds[(int) $row->id] = true;
            $totalArticleRefs++;
        }

        // Cover images stored via blogs.featured_image_url
        $featuredUrls = DB::table('blogs')
            ->whereNotNull('featured_image_url')
            ->where('featured_image_url', '!=', '')
            ->pluck('featured_image_url');

        foreach ($featuredUrls as $urlOrKey) {
            $key = $images->extractStorageKeyFromUrl($urlOrKey);
            if ($key !== null) {
                $referencedKeys[strtolower($key)] = true;
                $totalArticleRefs++;
            }
        }

        // Editor images in blog_article_images pivot table
        $pivotImages = DB::table('blog_article_images')
            ->join('blog_images', 'blog_article_images.blog_image_id', '=', 'blog_images.id')
            ->select('blog_images.id', 'blog_images.storage_key')
            ->get();

        foreach ($pivotImages as $row) {
            $referencedKeys[strtolower($row->storage_key)] = true;
            $referencedImageIds[(int) $row->id] = true;
            $totalArticleRefs++;
        }

        // Inline images embedded in article body HTML (content_ar, content_en)
        $htmlContents = DB::table('blogs')
            ->select('content_ar', 'content_en')
            ->get();

        foreach ($htmlContents as $contentRow) {
            foreach ([$contentRow->content_ar, $contentRow->content_en] as $html) {
                if ($html === null || trim($html) === '') {
                    continue;
                }

                if (preg_match_all(BlogImageService::KEY_PATTERN, $html, $matches)) {
                    foreach ($matches[0] as $embeddedKey) {
                        $referencedKeys[strtolower($embeddedKey)] = true;
                        $totalArticleRefs++;
                    }
                }

                if (preg_match_all('/data-image-id=[\'"](\d+)[\'"]/i', $html, $matches)) {
                    foreach ($matches[1] as $imgId) {
                        $referencedImageIds[(int) $imgId] = true;
                        $totalArticleRefs++;
                    }
                }
            }
        }

        $this->info("Found {$totalArticleRefs} total image reference(s) across blog articles.");

        // 2. Scan Cloudflare R2 storage under blogs/covers/ and blogs/editor/ only
        $allFiles = $images->allBlogKeys();
        $candidateKeys = [];
        $skippedGrace = 0;
        $skippedReferenced = 0;
        $skippedInvalid = 0;
        $skippedTimestamp = 0;

        foreach ($allFiles as $key) {
            if (! $images->isR2Key($key)) {
                $skippedInvalid++;

                continue;
            }

            if (isset($referencedKeys[strtolower($key)])) {
                $skippedReferenced++;

                continue;
            }

            // Also check if a BlogImage row for this key is within the grace period
            $dbRecord = BlogImage::where('storage_key', $key)->first();
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

        // Also identify unattached BlogImage rows older than the grace period whose storage keys may or may not still be in $allFiles
        $orphanDbImages = BlogImage::query()
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
            $this->info('No orphaned blog images found eligible for cleanup.');

            return Command::SUCCESS;
        }

        if (! $isForce) {
            $this->warn('DRY-RUN MODE ENABLED: No files were deleted.');
            $this->table(['Candidate Orphaned Key', 'Status'], array_map(
                fn ($key) => [$key, 'Eligible for deletion (dry-run)'],
                $candidates
            ));

            Log::info('Orphaned blog image cleanup dry-run completed.', [
                'eligible' => count($candidates),
                'grace_hours' => $graceHours,
            ]);

            return Command::SUCCESS;
        }

        // 3. Circuit breakers
        if ($totalArticleRefs === 0 && ! $this->option('allow-empty-references')) {
            $this->error('No blog article references any image but images exist in storage. Aborting; use --allow-empty-references after review.');
            Log::critical('Orphaned blog image cleanup aborted: no blog article references any image.', [
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
            Log::critical('Orphaned blog image cleanup aborted: eligible count exceeds safety limit.', [
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

                $dbImage = BlogImage::where('storage_key', $key)->first();
            } catch (Throwable $e) {
                $this->error("Database revalidation failed for [{$key}]. Aborting cleanup for safety.");
                Log::critical('Database revalidation failed during destructive orphaned blog image cleanup.', [
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
                        Log::info("Orphaned blog image deleted: {$key}", ['blog_image_id' => $dbImage->id]);

                        continue;
                    }

                    if ($images->isImageReferenced($dbImage->id, $key)) {
                        $this->warn("Candidate [{$key}] is now referenced in database. Skipping deletion.");
                        $skippedRevalidation++;

                        continue;
                    }

                    if (! BlogImage::where('id', $dbImage->id)->exists()) {
                        $deletedCount++;
                        Log::info("Orphaned blog image already removed: {$key}", ['blog_image_id' => $dbImage->id]);

                        continue;
                    }
                } catch (Throwable $e) {
                    $this->error("Database revalidation failed for [{$key}]. Aborting cleanup for safety.");
                    Log::critical('Database revalidation failed during destructive orphaned blog image cleanup.', [
                        'key' => $key,
                        'blog_image_id' => $dbImage->id,
                        'error' => $e->getMessage(),
                    ]);

                    return Command::FAILURE;
                }

                $failedCount++;
                Log::warning("Failed to delete orphaned blog image: {$key}", ['blog_image_id' => $dbImage->id]);

                continue;
            }

            // Raw R2 object with no blog_images database record: delete directly from R2 without inventing a DB row
            if ($images->safeDeleteKey($key)) {
                $deletedCount++;
                Log::info("Orphaned raw blog R2 object deleted: {$key}");
            } else {
                $failedCount++;
                Log::warning("Failed to delete orphaned raw blog R2 object: {$key}");
            }
        }

        $this->info("Destructive cleanup complete. Deleted: {$deletedCount}, Failed: {$failedCount}, Skipped on revalidation: {$skippedRevalidation}.");

        return $failedCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
