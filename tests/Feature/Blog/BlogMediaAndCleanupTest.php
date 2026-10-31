<?php

namespace Tests\Feature\Blog;

use App\Jobs\DeleteUnreferencedBlogImagesJob;
use App\Models\Blog;
use App\Models\BlogImage;
use App\Models\Category;
use App\Models\News;
use App\Models\NewsImage;
use App\Models\User;
use App\Services\Blog\BlogImageService;
use App\Services\Storage\ImageStorageService;
use Carbon\Carbon;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class BlogMediaAndCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_1X1_WEBP_BASE64 = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=';

    private User $admin;

    private Category $marketingCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->marketingCategory = Category::where('type', 'blogs')->where('slug', 'digital-marketing-trends')->firstOrFail();

        Storage::fake('r2');
        Config::set('filesystems.disks.r2.url', 'https://pub-r2.waai.test');
    }

    private function putBlogR2File(int $ageHours, string $prefix = 'blogs/editor/', string $ext = 'webp'): string
    {
        $key = $prefix.Str::uuid().'.'.$ext;
        Storage::disk('r2')->put($key, base64_decode(self::FIXTURE_1X1_WEBP_BASE64));
        touch(Storage::disk('r2')->path($key), Carbon::now()->subHours($ageHours)->getTimestamp());

        return $key;
    }

    private function createBlogImageRecord(string $key, int $ageHours, array $extra = []): BlogImage
    {
        $img = new BlogImage(array_merge([
            'disk' => 'r2',
            'storage_key' => $key,
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', $key),
            'uploaded_by' => null,
            'deleting_at' => null,
        ], $extra));

        $timestamp = Carbon::now()->subHours($ageHours);
        $img->created_at = $timestamp;
        $img->updated_at = $timestamp;
        $img->save();

        return $img;
    }

    // =========================================================================
    // 1. Truly Orphaned Image Cleanup
    // =========================================================================

    public function test_cleans_truly_orphaned_cover_and_editor_images(): void
    {
        // Create an active blog so circuit breaker does not trigger
        $activeKey = $this->putBlogR2File(48, 'blogs/covers/');
        $activeImg = BlogImage::factory()->create([
            'storage_key' => $activeKey,
            'created_at' => now()->subHours(48),
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $activeImg->id,
            'title_en' => 'Active Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Create orphaned cover and editor images past grace period (48 hours old)
        $orphanCoverKey = $this->putBlogR2File(48, 'blogs/covers/');
        $orphanCover = BlogImage::factory()->create([
            'storage_key' => $orphanCoverKey,
            'created_at' => now()->subHours(48),
        ]);

        $orphanEditorKey = $this->putBlogR2File(48, 'blogs/editor/');
        $orphanEditor = BlogImage::factory()->editor()->create([
            'storage_key' => $orphanEditorKey,
            'created_at' => now()->subHours(48),
        ]);

        $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('blog_images', ['id' => $orphanCover->id]);
        $this->assertDatabaseMissing('blog_images', ['id' => $orphanEditor->id]);
        $this->assertFalse(Storage::disk('r2')->exists($orphanCoverKey));
        $this->assertFalse(Storage::disk('r2')->exists($orphanEditorKey));

        // Active image remains
        $this->assertDatabaseHas('blog_images', ['id' => $activeImg->id]);
        $this->assertTrue(Storage::disk('r2')->exists($activeKey));
    }

    // =========================================================================
    // 2. Grace Period Protection (24 Hours)
    // =========================================================================

    public function test_grace_period_protects_recent_unattached_images(): void
    {
        // Active blog
        $activeKey = $this->putBlogR2File(48, 'blogs/covers/');
        $activeImg = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $activeKey,
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'sha256' => 'hash',
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $activeImg->id,
            'title_en' => 'Active Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Unattached image created only 2 hours ago (within 24-hour grace period)
        $recentKey = $this->putBlogR2File(2, 'blogs/editor/');
        $recentImg = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $recentKey,
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'sha256' => 'hash_recent',
            'created_at' => Carbon::now()->subHours(2),
        ]);

        $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
            ->assertSuccessful();

        // Must NOT be deleted
        $this->assertDatabaseHas('blog_images', ['id' => $recentImg->id]);
        $this->assertTrue(Storage::disk('r2')->exists($recentKey));
    }

    // =========================================================================
    // 3. Maximum Deletion Limit (Safety Circuit Breaker)
    // =========================================================================

    public function test_circuit_breaker_aborts_when_candidates_exceed_max_deletions(): void
    {
        // Active blog
        $activeKey = $this->putBlogR2File(48, 'blogs/covers/');
        $activeImg = BlogImage::factory()->create([
            'storage_key' => $activeKey,
            'created_at' => now()->subHours(48),
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $activeImg->id,
            'title_en' => 'Active Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Create 5 orphaned images
        $keys = [];
        for ($i = 1; $i <= 5; $i++) {
            $k = $this->putBlogR2File(48, 'blogs/editor/');
            BlogImage::factory()->editor()->create([
                'storage_key' => $k,
                'created_at' => now()->subHours(48),
            ]);
            $keys[] = $k;
        }

        // Set max-deletions=3 (exceeded since there are 5 candidates)
        $this->artisan('blogs:clean-orphaned-images', ['--force' => true, '--max-deletions' => 3])
            ->assertFailed();

        // All 5 images must remain untouched
        foreach ($keys as $k) {
            $this->assertTrue(Storage::disk('r2')->exists($k));
        }
    }

    // =========================================================================
    // 4. Protection for Referenced Images (Cover & Editor Body)
    // =========================================================================

    public function test_protects_referenced_cover_and_editor_images(): void
    {
        // 1. Cover image
        $coverKey = $this->putBlogR2File(48, 'blogs/covers/');
        $coverImg = BlogImage::factory()->create([
            'storage_key' => $coverKey,
            'created_at' => now()->subHours(48),
        ]);

        // 2. Editor image embedded in HTML
        $editorKey = $this->putBlogR2File(48, 'blogs/editor/');
        $editorImg = BlogImage::factory()->editor()->create([
            'storage_key' => $editorKey,
            'created_at' => now()->subHours(48),
        ]);

        $blog = Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $coverImg->id,
            'content_en' => "<p>Look at this image: <img src=\"https://pub-r2.waai.test/{$editorKey}\" data-image-id=\"{$editorImg->id}\"></p>",
            'title_en' => 'Referencing Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $blog->images()->attach($editorImg->id, ['role' => 'editor']);

        $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('blog_images', ['id' => $coverImg->id]);
        $this->assertDatabaseHas('blog_images', ['id' => $editorImg->id]);
        $this->assertTrue(Storage::disk('r2')->exists($coverKey));
        $this->assertTrue(Storage::disk('r2')->exists($editorKey));
    }

    // =========================================================================
    // 5. Stale deleting_at Recovery & Missing R2 Object Idempotency
    // =========================================================================

    public function test_recovers_stale_deleting_at_record_safely(): void
    {
        // Active blog
        $activeKey = $this->putBlogR2File(48, 'blogs/covers/');
        $activeImg = BlogImage::factory()->create([
            'storage_key' => $activeKey,
            'created_at' => now()->subHours(48),
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $activeImg->id,
            'title_en' => 'Active Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Stale record: R2 object was deleted, but DB row was left with deleting_at set 3 days ago
        $staleKey = 'blogs/editor/'.Str::uuid().'.webp';
        // (Object does NOT exist in Storage::fake('r2'))

        $staleImg = BlogImage::factory()->editor()->create([
            'storage_key' => $staleKey,
            'deleting_at' => now()->subDays(3),
            'created_at' => now()->subDays(4),
        ]);

        $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
            ->assertSuccessful();

        // The stale DB row must be finalized and deleted idempotently
        $this->assertDatabaseMissing('blog_images', ['id' => $staleImg->id]);
    }

    // =========================================================================
    // 6. Race Condition Prevention: Image Becomes Referenced Between Scan and Delete
    // =========================================================================

    public function test_prevents_deleting_image_that_becomes_referenced_during_cleanup(): void
    {
        // Active blog
        $activeKey = $this->putBlogR2File(48, 'blogs/covers/');
        $activeImg = BlogImage::factory()->create([
            'storage_key' => $activeKey,
            'created_at' => now()->subHours(48),
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $activeImg->id,
            'title_en' => 'Active Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Target image
        $targetKey = $this->putBlogR2File(48, 'blogs/editor/');
        $targetImg = BlogImage::factory()->editor()->create([
            'storage_key' => $targetKey,
            'created_at' => now()->subHours(48),
        ]);

        // Intercept right before destructive execution by referencing the image
        // Simulate concurrent user attaching it as cover:
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $targetImg->id,
            'title_en' => 'Concurrent Blog',
            'status' => 'draft',
        ]);

        $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
            ->assertSuccessful();

        // Just-in-time revalidation MUST skip deletion!
        $this->assertDatabaseHas('blog_images', ['id' => $targetImg->id]);
        $this->assertTrue(Storage::disk('r2')->exists($targetKey));
    }

    // =========================================================================
    // 7. Isolation from News Module
    // =========================================================================

    public function test_strictly_isolates_from_news_images_and_news_storage_prefixes(): void
    {
        // Create an unattached NewsImage and an unattached news R2 file
        $newsKey = 'news/editor/'.Str::uuid().'.webp';
        Storage::disk('r2')->put($newsKey, base64_decode(self::FIXTURE_1X1_WEBP_BASE64));
        touch(Storage::disk('r2')->path($newsKey), Carbon::now()->subHours(72)->getTimestamp());

        $newsImg = NewsImage::create([
            'disk' => 'r2',
            'storage_key' => $newsKey,
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'sha256' => 'hash_news',
            'created_at' => Carbon::now()->subHours(72),
        ]);

        // Active blog so command runs
        $activeKey = $this->putBlogR2File(48, 'blogs/covers/');
        $activeImg = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $activeKey,
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'sha256' => 'hash',
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $activeImg->id,
            'title_en' => 'Active Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
            ->assertSuccessful();

        // NewsImage and news R2 file MUST NOT be touched
        $this->assertDatabaseHas('news_images', ['id' => $newsImg->id]);
        $this->assertTrue(Storage::disk('r2')->exists($newsKey));
    }

    // =========================================================================
    // 8. DeleteUnreferencedBlogImagesJob Unit Execution
    // =========================================================================

    public function test_cleanup_job_deletes_unreferenced_images_and_skips_referenced_ones(): void
    {
        $orphanKey = $this->putBlogR2File(48, 'blogs/editor/');
        $orphanImg = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $orphanKey,
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'sha256' => 'hash_job',
        ]);

        $referencedKey = $this->putBlogR2File(48, 'blogs/covers/');
        $referencedImg = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $referencedKey,
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'sha256' => 'hash_job_ref',
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $referencedImg->id,
            'title_en' => 'Referencing Blog for Job',
            'status' => 'draft',
        ]);

        $job = new DeleteUnreferencedBlogImagesJob([$orphanImg->id, $referencedImg->id]);
        $job->handle(app(BlogImageService::class));

        // Orphan is deleted
        $this->assertDatabaseMissing('blog_images', ['id' => $orphanImg->id]);
        $this->assertFalse(Storage::disk('r2')->exists($orphanKey));

        // Referenced is skipped
        $this->assertDatabaseHas('blog_images', ['id' => $referencedImg->id]);
        $this->assertTrue(Storage::disk('r2')->exists($referencedKey));
    }

    // =========================================================================
    // 9. Concurrency Boundary & Deterministic Attachment Race Verification
    // =========================================================================

    public function test_orphan_cleanup_blocks_concurrent_attachment_while_deleting_at_is_set(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        // Anchor active blog
        $anchorKey = $this->putBlogR2File(72, 'blogs/covers/');
        $anchorImg = BlogImage::factory()->create([
            'storage_key' => $anchorKey,
            'created_at' => now()->subHours(72),
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $anchorImg->id,
            'title_en' => 'Active Anchor Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Eligible orphan image
        $orphanKey = $this->putBlogR2File(48, 'blogs/editor/');
        $orphanImg = BlogImage::factory()->editor()->create([
            'storage_key' => $orphanKey,
            'created_at' => now()->subHours(48),
            'uploaded_by' => $this->admin->id,
        ]);

        $baselineTransactionLevel = DB::transactionLevel();
        $observedTransactionLevel = null;
        $observedDeletingAt = null;
        $concurrentFeaturedAttachStatus = null;
        $concurrentEditorAttachStatus = null;

        $realStorage = app(ImageStorageService::class);
        $partialMock = Mockery::mock(ImageStorageService::class, ['r2'])->makePartial();
        $partialMock->shouldReceive('safeDelete')
            ->with($orphanKey)
            ->once()
            ->andReturnUsing(function (string $key) use (
                $orphanImg,
                $realStorage,
                &$observedTransactionLevel,
                &$observedDeletingAt,
                &$concurrentFeaturedAttachStatus,
                &$concurrentEditorAttachStatus
            ) {
                $observedTransactionLevel = DB::transactionLevel();
                $fresh = BlogImage::findOrFail($orphanImg->id);
                $observedDeletingAt = $fresh->deleting_at;

                // Concurrent attempt 1: Try to attach candidate as featured_image_id to a new blog
                $featuredRes = $this->postJson('/api/v1/admin/blogs', [
                    'category_id' => $this->marketingCategory->id,
                    'title_en' => 'Attach Featured During Orphan Cleanup',
                    'featured_image_id' => $orphanImg->id,
                ]);
                $concurrentFeaturedAttachStatus = $featuredRes->getStatusCode();

                // Concurrent attempt 2: Try to attach candidate inside editor HTML to a new blog
                $editorRes = $this->postJson('/api/v1/admin/blogs', [
                    'category_id' => $this->marketingCategory->id,
                    'title_en' => 'Attach Editor During Orphan Cleanup',
                    'content_en' => sprintf('<p>Body</p><img data-image-id="%d" />', $orphanImg->id),
                ]);
                $concurrentEditorAttachStatus = $editorRes->getStatusCode();

                return $realStorage->safeDelete($key);
            });

        $this->app->instance(ImageStorageService::class, $partialMock);
        $this->app->forgetInstance(BlogImageService::class);

        $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame($baselineTransactionLevel, $observedTransactionLevel, 'No DB transaction should be held open during R2 deletion in orphan cleanup.');
        $this->assertNotNull($observedDeletingAt, 'Database-backed orphan candidate must have deleting_at set during R2 deletion.');
        $this->assertSame(422, $concurrentFeaturedAttachStatus, 'Attaching as featured image while deleting_at is set must fail with 422.');
        $this->assertSame(422, $concurrentEditorAttachStatus, 'Attaching as editor image while deleting_at is set must fail with 422.');

        $this->assertFalse(Storage::disk('r2')->exists($orphanKey));
        $this->assertDatabaseMissing('blog_images', ['id' => $orphanImg->id]);
    }

    // =========================================================================
    // 10. Transient R2 Failure & Safe Retry Recovery
    // =========================================================================

    public function test_r2_deletion_failure_retains_database_record_and_allows_safe_retry(): void
    {
        $anchorKey = $this->putBlogR2File(72, 'blogs/covers/');
        $anchorImg = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $anchorKey,
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'sha256' => 'hash_anchor',
            'created_at' => Carbon::now()->subHours(72),
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $anchorImg->id,
            'title_en' => 'Active Anchor Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $transientFailKey = $this->putBlogR2File(48, 'blogs/editor/');
        $transientFailImg = BlogImage::factory()->editor()->create([
            'storage_key' => $transientFailKey,
            'created_at' => now()->subHours(48),
        ]);

        $realStorage = app(ImageStorageService::class);
        $mockStorage = Mockery::mock(ImageStorageService::class, ['r2'])->makePartial();
        $mockStorage->shouldReceive('safeDelete')
            ->with($transientFailKey)
            ->once()
            ->andReturn(false);

        $this->app->instance(ImageStorageService::class, $mockStorage);
        $this->app->forgetInstance(BlogImageService::class);

        // Run 1: Failure during R2 deletion returns failure code 1
        $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
            ->assertFailed();

        // Database row and file are retained; deleting_at is reset to null
        $this->assertDatabaseHas('blog_images', [
            'id' => $transientFailImg->id,
            'deleting_at' => null,
        ]);
        $this->assertTrue(Storage::disk('r2')->exists($transientFailKey));

        // Run 2: Restore functional storage service -> succeeds
        $this->app->instance(ImageStorageService::class, $realStorage);
        $this->app->forgetInstance(BlogImageService::class);

        $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('blog_images', ['id' => $transientFailImg->id]);
        $this->assertFalse(Storage::disk('r2')->exists($transientFailKey));
    }

    // =========================================================================
    // 10B. R2 Delete Succeeds, DB Finalization Fails & Later Recovery
    // =========================================================================

    public function test_r2_deletion_succeeds_but_db_finalization_fails_and_recovers_later(): void
    {
        $anchorKey = $this->putBlogR2File(72, 'blogs/covers/');
        $anchorImg = BlogImage::factory()->create([
            'storage_key' => $anchorKey,
            'created_at' => now()->subHours(72),
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $anchorImg->id,
            'title_en' => 'Active Anchor Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $failFinalizeKey = $this->putBlogR2File(48, 'blogs/editor/');
        $failFinalizeImg = BlogImage::factory()->editor()->create([
            'storage_key' => $failFinalizeKey,
            'created_at' => now()->subHours(48),
        ]);

        // In Phase 3, the real BlogImageService executes:
        //   $locked = BlogImage::where('id', $imageId)->lockForUpdate()->first();
        //   $locked->delete();
        // We register an Eloquent deleting listener specifically for $failFinalizeImg to fail Phase 3
        BlogImage::deleting(function (BlogImage $image) use ($failFinalizeImg) {
            if ((int) $image->id === (int) $failFinalizeImg->id) {
                throw new \RuntimeException('Simulated database failure during Phase 3 model deletion.');
            }
        });

        try {
            // Run 1: Calls real BlogImageService::deleteImageIfUnreferenced.
            // 1. Marks deleting_at (Phase 1)
            // 2. Deletes from R2 fake storage (Phase 2) -> object becomes absent
            // 3. Eloquent deleting event throws during Phase 3 -> DB row remains
            $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
                ->assertFailed();
        } finally {
            // Flush listeners so subsequent operations are unaffected
            BlogImage::flushEventListeners();
        }

        // 5. Verify R2 object is absent
        $this->assertFalse(Storage::disk('r2')->exists($failFinalizeKey));

        // 6. Verify database row remains and deleting_at is non-null
        $freshImg = BlogImage::findOrFail($failFinalizeImg->id);
        $this->assertNotNull($freshImg->deleting_at);

        // 7. Run cleanup again using the real service
        $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
            ->assertSuccessful();

        // 8. Verify the stale database row is safely removed
        $this->assertDatabaseMissing('blog_images', ['id' => $failFinalizeImg->id]);
    }

    // =========================================================================
    // 10C. Missing R2 Object Is Handled Idempotently Without Exception
    // =========================================================================

    public function test_missing_r2_object_is_handled_idempotently(): void
    {
        $anchorKey = $this->putBlogR2File(72, 'blogs/covers/');
        $anchorImg = BlogImage::factory()->create([
            'storage_key' => $anchorKey,
            'created_at' => now()->subHours(72),
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $anchorImg->id,
            'title_en' => 'Active Anchor Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Key that does NOT exist in Storage::fake('r2')
        $missingKey = 'blogs/editor/'.Str::uuid().'.webp';
        $missingImg = BlogImage::factory()->editor()->create([
            'storage_key' => $missingKey,
            'created_at' => now()->subHours(48),
        ]);

        $this->assertFalse(Storage::disk('r2')->exists($missingKey));

        // Cleanup must succeed idempotently without error and delete the orphaned DB row
        $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('blog_images', ['id' => $missingImg->id]);
    }

    // =========================================================================
    // 11. Empty Blog Images Table Safety
    // =========================================================================

    public function test_empty_blog_images_table_is_safe(): void
    {
        $this->assertSame(0, BlogImage::count());

        // With no files in storage and no DB records, cleanup reports no orphans and returns success
        $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, BlogImage::count());
    }

    // =========================================================================
    // 12. Invalid or Unrelated Storage Paths Are Never Deleted
    // =========================================================================

    public function test_invalid_or_unrelated_storage_paths_are_never_deleted(): void
    {
        // Anchor blog
        $anchorKey = $this->putBlogR2File(72, 'blogs/covers/');
        $anchorImg = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $anchorKey,
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'sha256' => 'hash_anchor',
            'created_at' => Carbon::now()->subHours(72),
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $anchorImg->id,
            'title_en' => 'Active Anchor Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Place unrelated files in storage (e.g. clients/logos/, uploads/, news/covers/)
        $clientLogoKey = 'clients/logos/logo.png';
        Storage::disk('r2')->put($clientLogoKey, 'fake-logo-data');
        touch(Storage::disk('r2')->path($clientLogoKey), Carbon::now()->subHours(100)->getTimestamp());

        $otherPrefixKey = 'uploads/general/document.pdf';
        Storage::disk('r2')->put($otherPrefixKey, 'fake-pdf-data');
        touch(Storage::disk('r2')->path($otherPrefixKey), Carbon::now()->subHours(100)->getTimestamp());

        // Non-UUID file inside blogs/covers/
        $invalidKey = 'blogs/covers/not-a-uuid.webp';
        Storage::disk('r2')->put($invalidKey, 'invalid-content');
        touch(Storage::disk('r2')->path($invalidKey), Carbon::now()->subHours(100)->getTimestamp());

        $this->artisan('blogs:clean-orphaned-images', ['--force' => true])
            ->assertSuccessful();

        // None of the unrelated or invalid files should ever be deleted
        $this->assertTrue(Storage::disk('r2')->exists($clientLogoKey));
        $this->assertTrue(Storage::disk('r2')->exists($otherPrefixKey));
        $this->assertTrue(Storage::disk('r2')->exists($invalidKey));
    }

    // =========================================================================
    // 13. Candidate Threshold Boundaries: Exactly 50 vs 51 Candidates
    // =========================================================================

    public function test_exactly_fifty_eligible_candidates_are_cleaned_successfully(): void
    {
        $anchorKey = $this->putBlogR2File(72, 'blogs/covers/');
        $anchorImg = BlogImage::factory()->create([
            'storage_key' => $anchorKey,
            'created_at' => now()->subHours(72),
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $anchorImg->id,
            'title_en' => 'Active Anchor Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Create exactly 50 orphaned images
        $keys = [];
        $ids = [];
        for ($i = 1; $i <= 50; $i++) {
            $k = $this->putBlogR2File(48, 'blogs/editor/');
            $img = BlogImage::factory()->editor()->create([
                'storage_key' => $k,
                'created_at' => now()->subHours(48),
            ]);
            $keys[] = $k;
            $ids[] = $img->id;
        }

        // Default max-deletions is 50. Exactly 50 candidates should succeed
        $this->artisan('blogs:clean-orphaned-images', ['--force' => true, '--max-deletions' => 50])
            ->assertSuccessful();

        foreach ($keys as $k) {
            $this->assertFalse(Storage::disk('r2')->exists($k));
        }
        $this->assertSame(1, BlogImage::count());
    }

    public function test_more_than_fifty_eligible_candidates_aborts_without_deleting_anything(): void
    {
        $anchorKey = $this->putBlogR2File(72, 'blogs/covers/');
        $anchorImg = BlogImage::factory()->create([
            'storage_key' => $anchorKey,
            'created_at' => now()->subHours(72),
        ]);
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'featured_image_id' => $anchorImg->id,
            'title_en' => 'Active Anchor Blog',
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Create 51 orphaned images
        $keys = [];
        for ($i = 1; $i <= 51; $i++) {
            $k = $this->putBlogR2File(48, 'blogs/editor/');
            BlogImage::factory()->editor()->create([
                'storage_key' => $k,
                'created_at' => now()->subHours(48),
            ]);
            $keys[] = $k;
        }

        // 51 > 50 -> must abort without deleting anything
        $this->artisan('blogs:clean-orphaned-images', ['--force' => true, '--max-deletions' => 50])
            ->assertFailed();

        foreach ($keys as $k) {
            $this->assertTrue(Storage::disk('r2')->exists($k));
        }
        $this->assertSame(52, BlogImage::count());
    }
}
