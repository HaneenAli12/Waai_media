<?php

namespace Tests\Feature\News;

use App\Console\Commands\CleanOrphanedNewsImagesCommand;
use App\Http\Controllers\Api\Admin\NewsController as AdminNewsController;
use App\Http\Requests\Admin\News\UpdateNewsRequest;
use App\Jobs\DeleteUnreferencedNewsImagesJob;
use App\Models\Category;
use App\Models\News;
use App\Models\NewsImage;
use App\Models\User;
use App\Services\News\NewsImageService;
use App\Services\Storage\Exceptions\ImageUploadException;
use App\Services\Storage\ImageStorageService;
use Carbon\Carbon;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class NewsMediaAndCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_1X1_WEBP_BASE64 = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=';

    private User $admin;

    private Category $healthCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->healthCategory = Category::where('type', 'news')->where('slug', 'health')->firstOrFail();

        Storage::fake('r2');
        Config::set('filesystems.disks.r2.url', 'https://pub-r2.waai.test');
    }

    private function fakeWebp(string $name = 'photo.webp'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_WEBP_BASE64));
    }

    private function putR2File(int $ageHours, string $prefix = 'news/editor/', string $ext = 'webp'): string
    {
        $key = $prefix.Str::uuid().'.'.$ext;
        Storage::disk('r2')->put($key, base64_decode(self::FIXTURE_1X1_WEBP_BASE64));
        touch(Storage::disk('r2')->path($key), Carbon::now()->subHours($ageHours)->getTimestamp());

        return $key;
    }

    public function test_r2_upload_failure_does_not_persist_news_image_record(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $mockStorage = Mockery::mock(ImageStorageService::class);
        $mockStorage->shouldReceive('upload')
            ->once()
            ->andThrow(new ImageUploadException('Simulated R2 outage'));

        $this->app->instance(ImageStorageService::class, $mockStorage);

        $res = $this->postJson('/api/v1/admin/news/images', [
            'image' => $this->fakeWebp(),
        ]);

        $res->assertStatus(502);
        $this->assertSame(0, NewsImage::count());
    }

    public function test_failed_article_creation_with_direct_featured_image_upload_compensates_and_deletes_r2_object(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        // Force an exception during News::create inside the transaction
        News::creating(function () {
            throw new \RuntimeException('Simulated database failure during article creation');
        });

        try {
            $this->postJson('/api/v1/admin/news', [
                'category_id' => $this->healthCategory->id,
                'title_en' => 'Rollback Test Article',
                'featured_image' => $this->fakeWebp(),
            ])->assertStatus(500);
        } finally {
            News::flushEventListeners();
        }

        // Neither the News row nor the uploaded NewsImage / R2 object should remain
        $this->assertSame(0, News::count());
        $this->assertSame(0, NewsImage::count());
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_failed_article_update_preserves_existing_featured_image_and_rolls_back_replacement_upload(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $service = app(NewsImageService::class);
        $originalImage = $service->uploadAndCreate($this->fakeWebp('orig.webp'), NewsImageService::COVERS_DIRECTORY, $this->admin->id);

        $createRes = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Original Article',
            'featured_image_id' => $originalImage->id,
        ])->assertStatus(201);

        $newsId = $createRes->json('data.id');

        // Force DB failure during update
        News::updating(function () {
            throw new \RuntimeException('Simulated database failure during article update');
        });

        try {
            $this->post("/api/v1/admin/news/{$newsId}", [
                '_method' => 'PATCH',
                'title_en' => 'Failed Update Title',
                'featured_image' => $this->fakeWebp('replacement.webp'),
            ], ['Accept' => 'application/json'])->assertStatus(500);
        } finally {
            News::flushEventListeners();
        }

        // Original image must still exist in R2 and DB, while replacement upload was cleaned up
        Storage::disk('r2')->assertExists($originalImage->storage_key);
        $this->assertDatabaseHas('news_images', ['id' => $originalImage->id]);
        $this->assertSame(1, NewsImage::count());
        $this->assertSame($originalImage->id, News::findOrFail($newsId)->featured_image_id);
    }

    public function test_transient_r2_delete_failure_dispatches_retryable_cleanup_job(): void
    {
        Sanctum::actingAs($this->admin, ['access']);
        Queue::fake([DeleteUnreferencedNewsImagesJob::class]);

        $service = app(NewsImageService::class);
        $oldCover = $service->uploadAndCreate($this->fakeWebp('old.webp'), NewsImageService::COVERS_DIRECTORY, $this->admin->id);
        $newCover = $service->uploadAndCreate($this->fakeWebp('new.webp'), NewsImageService::COVERS_DIRECTORY, $this->admin->id);

        $newsId = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Retry Cleanup Test',
            'featured_image_id' => $oldCover->id,
        ])->assertStatus(201)->json('data.id');

        // Mock ImageStorageService::safeDelete to return false (simulating transient R2 delete failure)
        $realStorage = app(ImageStorageService::class);
        $partialMock = Mockery::mock(ImageStorageService::class, ['r2'])->makePartial();
        $partialMock->shouldReceive('safeDelete')
            ->with($oldCover->storage_key)
            ->once()
            ->andReturn(false);

        $this->app->instance(ImageStorageService::class, $partialMock);
        $this->app->forgetInstance(NewsImageService::class);

        $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'featured_image_id' => $newCover->id,
        ])->assertStatus(200);

        // Update succeeded, oldCover metadata retained for retry (with deleting_at reset to null), and retryable job was dispatched
        $this->assertDatabaseHas('news_images', [
            'id' => $oldCover->id,
            'deleting_at' => null,
        ]);
        Queue::assertPushed(DeleteUnreferencedNewsImagesJob::class, function (DeleteUnreferencedNewsImagesJob $job) use ($oldCover) {
            return in_array($oldCover->id, $job->imageIds, true);
        });

        // Restore real storage service and run the job to verify retry succeeds and cleans up R2 + DB
        $this->app->instance(ImageStorageService::class, $realStorage);
        $this->app->forgetInstance(NewsImageService::class);

        $job = new DeleteUnreferencedNewsImagesJob([$oldCover->id]);
        $job->handle(app(NewsImageService::class));

        Storage::disk('r2')->assertMissing($oldCover->storage_key);
        $this->assertDatabaseMissing('news_images', ['id' => $oldCover->id]);
    }

    public function test_cleanup_job_skips_deletion_when_image_is_reattached_before_job_executes(): void
    {
        Sanctum::actingAs($this->admin, ['access']);
        Queue::fake([DeleteUnreferencedNewsImagesJob::class]);

        $service = app(NewsImageService::class);
        $imageA = $service->uploadAndCreate($this->fakeWebp('a.webp'), NewsImageService::COVERS_DIRECTORY, $this->admin->id);
        $imageB = $service->uploadAndCreate($this->fakeWebp('b.webp'), NewsImageService::COVERS_DIRECTORY, $this->admin->id);

        $newsId = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Reattachment Protection Test',
            'featured_image_id' => $imageA->id,
        ])->assertStatus(201)->json('data.id');

        // Simulate transient R2 failure when replacing imageA with imageB so DeleteUnreferencedNewsImagesJob is queued
        $realStorage = app(ImageStorageService::class);
        $partialMock = Mockery::mock(ImageStorageService::class, ['r2'])->makePartial();
        $partialMock->shouldReceive('safeDelete')
            ->with($imageA->storage_key)
            ->once()
            ->andReturn(false);

        $this->app->instance(ImageStorageService::class, $partialMock);
        $this->app->forgetInstance(NewsImageService::class);

        $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'featured_image_id' => $imageB->id,
        ])->assertStatus(200);

        $this->app->instance(ImageStorageService::class, $realStorage);
        $this->app->forgetInstance(NewsImageService::class);

        // Before the queued cleanup job runs, reattach imageA to the article (or another article)
        $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'content_en' => sprintf('<p>Reattached in content</p><img data-image-id="%d" />', $imageA->id),
        ])->assertStatus(200);

        // Now execute the queued job (even if constructed with $ignoreNewsId = $newsId)
        $job = new DeleteUnreferencedNewsImagesJob([$imageA->id], $newsId);
        $job->handle(app(NewsImageService::class));

        // imageA MUST NOT be deleted because it was reattached
        Storage::disk('r2')->assertExists($imageA->storage_key);
        $this->assertDatabaseHas('news_images', ['id' => $imageA->id]);
    }

    public function test_same_image_referenced_as_both_featured_and_editor_image_is_protected_until_both_references_are_removed(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $service = app(NewsImageService::class);
        $dualImage = $service->uploadAndCreate($this->fakeWebp('dual.webp'), NewsImageService::COVERS_DIRECTORY, $this->admin->id);
        $otherCover = $service->uploadAndCreate($this->fakeWebp('other.webp'), NewsImageService::COVERS_DIRECTORY, $this->admin->id);
        $thirdCover = $service->uploadAndCreate($this->fakeWebp('third.webp'), NewsImageService::COVERS_DIRECTORY, $this->admin->id);

        $newsId = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Dual Role Image Article',
            'featured_image_id' => $dualImage->id,
            'content_en' => sprintf('<p>Embedded too</p><img data-image-id="%d" />', $dualImage->id),
        ])->assertStatus(201)->json('data.id');

        // 1. Replace featured_image_id with $otherCover while $dualImage remains in content_en
        $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'featured_image_id' => $otherCover->id,
        ])->assertStatus(200);

        Storage::disk('r2')->assertExists($dualImage->storage_key);
        $this->assertDatabaseHas('news_images', ['id' => $dualImage->id]);

        // 2. Put $dualImage back as featured_image_id and remove it from content_en ($otherCover is now detached and cleaned up)
        $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'featured_image_id' => $dualImage->id,
            'content_en' => '<p>Removed from editor content</p>',
        ])->assertStatus(200);

        Storage::disk('r2')->assertExists($dualImage->storage_key);
        $this->assertDatabaseHas('news_images', ['id' => $dualImage->id]);

        // 3. Replace $dualImage with $thirdCover -> $dualImage is now completely unreferenced and deleted
        $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'featured_image_id' => $thirdCover->id,
        ])->assertStatus(200);

        Storage::disk('r2')->assertMissing($dualImage->storage_key);
        $this->assertDatabaseMissing('news_images', ['id' => $dualImage->id]);
    }

    public function test_partial_update_with_stale_route_model_reads_latest_locked_content_and_preserves_concurrently_added_editor_images(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $service = app(NewsImageService::class);
        $editorImg = $service->uploadAndCreate($this->fakeWebp('concurrent.webp'), NewsImageService::EDITOR_DIRECTORY, $this->admin->id);

        // Create initial draft without embedded images
        $news = News::factory()->draft()->create([
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Initial Title',
            'content_en' => '<p>Initial content without images</p>',
            'content_ar' => '<p>محتوى أولي بدون صور</p>',
        ]);

        // Hold a stale route-bound Eloquent instance loaded BEFORE a concurrent request updates content_en in DB
        $staleRouteBoundModel = News::findOrFail($news->id);

        // Simulate Request A committing a content_en update that embeds $editorImg
        $this->patchJson("/api/v1/admin/news/{$news->id}", [
            'content_en' => sprintf('<p>Concurrent update with image</p><img data-image-id="%d" />', $editorImg->id),
        ])->assertStatus(200);

        $this->assertDatabaseHas('news_article_images', [
            'news_id' => $news->id,
            'news_image_id' => $editorImg->id,
            'role' => 'editor',
        ]);

        // Simulate Request B executing a partial update (updating only title_en) while bound to $staleRouteBoundModel
        $requestB = UpdateNewsRequest::create(
            "/api/v1/admin/news/{$news->id}",
            'PATCH',
            ['title_en' => 'Title Updated By Request B']
        );
        $requestB->setUserResolver(fn () => $this->admin);
        $requestB->setRouteResolver(function () use ($requestB, $staleRouteBoundModel) {
            $route = new Route('PATCH', '/api/v1/admin/news/{news}', []);
            $route->bind($requestB);
            $route->setParameter('news', $staleRouteBoundModel);

            return $route;
        });
        $requestB->setContainer($this->app)->validateResolved();

        $controller = $this->app->make(AdminNewsController::class);
        $response = $controller->update($requestB, $staleRouteBoundModel);

        $this->assertSame(200, $response->getStatusCode());

        // Because update() resolves omitted content_en from the locked DB record ($locked),
        // $editorImg MUST still be attached in news_article_images and MUST NOT be deleted from R2 or news_images!
        Storage::disk('r2')->assertExists($editorImg->storage_key);
        $this->assertDatabaseHas('news_images', ['id' => $editorImg->id]);
        $this->assertDatabaseHas('news_article_images', [
            'news_id' => $news->id,
            'news_image_id' => $editorImg->id,
            'role' => 'editor',
        ]);
    }

    public function test_staged_deletion_does_not_hold_db_transaction_during_r2_delete_and_blocks_concurrent_attachment(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $service = app(NewsImageService::class);
        $image = $service->uploadAndCreate($this->fakeWebp('staged.webp'), NewsImageService::COVERS_DIRECTORY, $this->admin->id);

        $baselineTransactionLevel = DB::transactionLevel();
        $observedTransactionLevelDuringR2Call = null;
        $observedDeletingAtDuringR2Call = null;
        $concurrentAttachResponseStatus = null;

        $realStorage = app(ImageStorageService::class);
        $partialMock = Mockery::mock(ImageStorageService::class, ['r2'])->makePartial();
        $partialMock->shouldReceive('safeDelete')
            ->with($image->storage_key)
            ->once()
            ->andReturnUsing(function (string $key) use (
                $image,
                $realStorage,
                &$observedTransactionLevelDuringR2Call,
                &$observedDeletingAtDuringR2Call,
                &$concurrentAttachResponseStatus
            ) {
                $observedTransactionLevelDuringR2Call = DB::transactionLevel();
                $freshImage = NewsImage::findOrFail($image->id);
                $observedDeletingAtDuringR2Call = $freshImage->deleting_at;

                // Attempt to attach $image to a new article while R2 deletion is in-flight
                $res = $this->postJson('/api/v1/admin/news', [
                    'category_id' => $this->healthCategory->id,
                    'title_en' => 'Concurrent Attachment During Deletion',
                    'featured_image_id' => $image->id,
                ]);
                $concurrentAttachResponseStatus = $res->getStatusCode();

                return $realStorage->safeDelete($key);
            });

        $this->app->instance(ImageStorageService::class, $partialMock);
        $this->app->forgetInstance(NewsImageService::class);

        $deleted = app(NewsImageService::class)->deleteImageIfUnreferenced($image->id);

        $this->assertTrue($deleted);
        $this->assertSame($baselineTransactionLevel, $observedTransactionLevelDuringR2Call, 'No additional DB transaction should be held open during external R2 delete.');
        $this->assertNotNull($observedDeletingAtDuringR2Call, 'deleting_at must be set before external R2 delete begins.');
        $this->assertSame(422, $concurrentAttachResponseStatus, 'Concurrent attachment while deleting_at is set must be rejected with 422.');
        $this->assertDatabaseMissing('news_images', ['id' => $image->id]);
        Storage::disk('r2')->assertMissing($image->storage_key);
    }

    public function test_orphan_cleanup_command_respects_grace_period_and_protects_referenced_images(): void
    {
        // 1. Anchor referenced image
        $anchorKey = $this->putR2File(72, 'news/covers/');
        $anchorImg = NewsImage::factory()->create([
            'storage_key' => $anchorKey,
            'created_at' => now()->subHours(72),
        ]);
        News::factory()->draft()->create([
            'category_id' => $this->healthCategory->id,
            'featured_image_id' => $anchorImg->id,
            'featured_image_url' => $anchorKey,
        ]);

        // 2. Old unattached orphan (> 24h) -> eligible for deletion
        $oldOrphanKey = $this->putR2File(48, 'news/editor/');
        $oldOrphanImg = NewsImage::factory()->editor()->create([
            'storage_key' => $oldOrphanKey,
            'created_at' => now()->subHours(48),
        ]);

        // 3. Recent unattached upload (2h old, within 24h grace period) -> protected
        $recentKey = $this->putR2File(2, 'news/editor/');
        $recentImg = NewsImage::factory()->editor()->create([
            'storage_key' => $recentKey,
            'created_at' => now()->subHours(2),
        ]);

        // Dry run first -> deletes nothing
        $this->artisan('news:clean-orphaned-images')
            ->expectsOutputToContain('DRY-RUN MODE ENABLED')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($oldOrphanKey);

        // Destructive run (--force) -> deletes only oldOrphanKey
        $this->artisan('news:clean-orphaned-images', ['--force' => true])
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 1, Failed: 0')
            ->assertExitCode(0);

        Storage::disk('r2')->assertMissing($oldOrphanKey);
        $this->assertDatabaseMissing('news_images', ['id' => $oldOrphanImg->id]);

        Storage::disk('r2')->assertExists($recentKey);
        $this->assertDatabaseHas('news_images', ['id' => $recentImg->id]);

        Storage::disk('r2')->assertExists($anchorKey);
        $this->assertDatabaseHas('news_images', ['id' => $anchorImg->id]);
    }

    public function test_orphan_cleanup_command_enforces_max_deletions_empty_references_and_lock_contention_guards(): void
    {
        $orphan1Key = $this->putR2File(48, 'news/editor/');
        $orphan1 = NewsImage::factory()->editor()->create([
            'storage_key' => $orphan1Key,
            'created_at' => now()->subHours(48),
        ]);

        $orphan2Key = $this->putR2File(48, 'news/editor/');
        $orphan2 = NewsImage::factory()->editor()->create([
            'storage_key' => $orphan2Key,
            'created_at' => now()->subHours(48),
        ]);

        // 1. Empty-reference circuit breaker: when 0 articles reference any image, --force aborts without --allow-empty-references
        $this->artisan('news:clean-orphaned-images', ['--force' => true])
            ->expectsOutputToContain('No news article references any image')
            ->assertExitCode(1);

        Storage::disk('r2')->assertExists($orphan1Key);
        Storage::disk('r2')->assertExists($orphan2Key);

        // 2. Max-deletions guard: 2 eligible orphans > --max-deletions=1 -> aborts and deletes nothing
        $this->artisan('news:clean-orphaned-images', [
            '--force' => true,
            '--allow-empty-references' => true,
            '--max-deletions' => 1,
        ])
            ->expectsOutputToContain('exceeds --max-deletions')
            ->assertExitCode(1);

        Storage::disk('r2')->assertExists($orphan1Key);
        Storage::disk('r2')->assertExists($orphan2Key);

        // 3. Lock contention guard: when lock is already held, command skips and returns failure
        $heldLock = Cache::lock(CleanOrphanedNewsImagesCommand::LOCK_KEY, 3600);
        $this->assertTrue($heldLock->get());

        try {
            $this->artisan('news:clean-orphaned-images', [
                '--force' => true,
                '--allow-empty-references' => true,
            ])
                ->expectsOutputToContain('Another orphaned news image cleanup is already running')
                ->assertExitCode(1);
        } finally {
            $heldLock->release();
        }

        Storage::disk('r2')->assertExists($orphan1Key);
        Storage::disk('r2')->assertExists($orphan2Key);

        // 4. With lock released and --allow-empty-references + sufficient --max-deletions, cleanup succeeds
        $this->artisan('news:clean-orphaned-images', [
            '--force' => true,
            '--allow-empty-references' => true,
            '--max-deletions' => 10,
        ])
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 2, Failed: 0')
            ->assertExitCode(0);

        Storage::disk('r2')->assertMissing($orphan1Key);
        Storage::disk('r2')->assertMissing($orphan2Key);
        $this->assertDatabaseMissing('news_images', ['id' => $orphan1->id]);
        $this->assertDatabaseMissing('news_images', ['id' => $orphan2->id]);
    }

    public function test_orphan_cleanup_deletes_db_backed_candidate_via_staged_service_and_blocks_concurrent_attachment(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        // Anchor referenced image so circuit breaker sees active references
        $anchorKey = $this->putR2File(72, 'news/covers/');
        $anchorImg = NewsImage::factory()->create([
            'storage_key' => $anchorKey,
            'created_at' => now()->subHours(72),
        ]);
        News::factory()->draft()->create([
            'category_id' => $this->healthCategory->id,
            'featured_image_id' => $anchorImg->id,
            'featured_image_url' => $anchorKey,
        ]);

        // Eligible database-backed orphan (> 24h old)
        $orphanKey = $this->putR2File(48, 'news/editor/');
        $orphanImg = NewsImage::factory()->editor()->create([
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
                $fresh = NewsImage::findOrFail($orphanImg->id);
                $observedDeletingAt = $fresh->deleting_at;

                // Attempt to attach the candidate as a featured image while orphan cleanup R2 delete is in-flight
                $featuredRes = $this->postJson('/api/v1/admin/news', [
                    'category_id' => $this->healthCategory->id,
                    'title_en' => 'Attach Featured During Orphan Cleanup',
                    'featured_image_id' => $orphanImg->id,
                ]);
                $concurrentFeaturedAttachStatus = $featuredRes->getStatusCode();

                // Attempt to attach the candidate as an editor image while orphan cleanup R2 delete is in-flight
                $editorRes = $this->postJson('/api/v1/admin/news', [
                    'category_id' => $this->healthCategory->id,
                    'title_en' => 'Attach Editor During Orphan Cleanup',
                    'content_en' => sprintf('<p>Body</p><img data-image-id="%d" />', $orphanImg->id),
                ]);
                $concurrentEditorAttachStatus = $editorRes->getStatusCode();

                return $realStorage->safeDelete($key);
            });

        $this->app->instance(ImageStorageService::class, $partialMock);
        $this->app->forgetInstance(NewsImageService::class);

        $this->artisan('news:clean-orphaned-images', ['--force' => true])
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 1, Failed: 0, Skipped on revalidation: 0.')
            ->assertExitCode(0);

        $this->assertSame($baselineTransactionLevel, $observedTransactionLevel, 'No DB transaction should be held open during R2 deletion in orphan cleanup.');
        $this->assertNotNull($observedDeletingAt, 'Database-backed orphan candidate must have deleting_at set during R2 deletion.');
        $this->assertSame(422, $concurrentFeaturedAttachStatus, 'Attaching as featured image while deleting_at is set must fail with 422.');
        $this->assertSame(422, $concurrentEditorAttachStatus, 'Attaching as editor image while deleting_at is set must fail with 422.');

        Storage::disk('r2')->assertMissing($orphanKey);
        $this->assertDatabaseMissing('news_images', ['id' => $orphanImg->id]);

        Storage::disk('r2')->assertExists($anchorKey);
        $this->assertDatabaseHas('news_images', ['id' => $anchorImg->id]);
    }

    public function test_orphan_cleanup_preserves_candidate_that_becomes_referenced_between_discovery_and_deletion(): void
    {
        $anchorKey = $this->putR2File(72, 'news/covers/');
        $anchorImg = NewsImage::factory()->create([
            'storage_key' => $anchorKey,
            'created_at' => now()->subHours(72),
        ]);
        $article = News::factory()->draft()->create([
            'category_id' => $this->healthCategory->id,
            'featured_image_id' => $anchorImg->id,
            'featured_image_url' => $anchorKey,
        ]);

        // Two unattached >24h candidates that will become referenced after discovery
        $raceKeyPreCheck = $this->putR2File(48, 'news/editor/');
        $raceImgPreCheck = NewsImage::factory()->editor()->create([
            'storage_key' => $raceKeyPreCheck,
            'created_at' => now()->subHours(48),
        ]);

        $raceKeyStaged = $this->putR2File(48, 'news/editor/');
        $raceImgStaged = NewsImage::factory()->editor()->create([
            'storage_key' => $raceKeyStaged,
            'created_at' => now()->subHours(48),
        ]);

        $realService = app(NewsImageService::class);
        $partialImageService = Mockery::mock(NewsImageService::class, [app(ImageStorageService::class)])->makePartial();

        // 1. When isKeyReferenced($raceKeyPreCheck) runs in Step 4, simulate $raceImgPreCheck having been attached after Step 2 discovery
        $partialImageService->shouldReceive('isKeyReferenced')
            ->with($raceKeyPreCheck)
            ->once()
            ->andReturnUsing(function (string $key) use ($article, $raceImgPreCheck, $realService) {
                DB::table('news_article_images')->insert([
                    'news_id' => $article->id,
                    'news_image_id' => $raceImgPreCheck->id,
                    'role' => 'editor',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $realService->isKeyReferenced($key);
            });

        // 2. For $raceKeyStaged, let pre-check pass, then simulate attachment right before deleteImageIfUnreferenced() Phase 1 locks the row
        $partialImageService->shouldReceive('isKeyReferenced')
            ->with($raceKeyStaged)
            ->once()
            ->andReturn(false);

        $partialImageService->shouldReceive('deleteImageIfUnreferenced')
            ->with($raceImgStaged->id)
            ->once()
            ->andReturnUsing(function (int $imageId) use ($article, $raceImgStaged, $realService) {
                DB::table('news_article_images')->insert([
                    'news_id' => $article->id,
                    'news_image_id' => $raceImgStaged->id,
                    'role' => 'editor',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $realService->deleteImageIfUnreferenced($imageId);
            });

        $this->app->instance(NewsImageService::class, $partialImageService);

        $this->artisan('news:clean-orphaned-images', ['--force' => true])
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 0, Failed: 0, Skipped on revalidation: 2.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($raceKeyPreCheck);
        Storage::disk('r2')->assertExists($raceKeyStaged);
        $this->assertDatabaseHas('news_images', ['id' => $raceImgPreCheck->id, 'deleting_at' => null]);
        $this->assertDatabaseHas('news_images', ['id' => $raceImgStaged->id, 'deleting_at' => null]);
    }

    public function test_orphan_cleanup_retains_metadata_and_resets_deleting_at_on_r2_failure_then_recovers_on_retry(): void
    {
        $anchorKey = $this->putR2File(72, 'news/covers/');
        $anchorImg = NewsImage::factory()->create([
            'storage_key' => $anchorKey,
            'created_at' => now()->subHours(72),
        ]);
        News::factory()->draft()->create([
            'category_id' => $this->healthCategory->id,
            'featured_image_id' => $anchorImg->id,
            'featured_image_url' => $anchorKey,
        ]);

        // Candidate 1: will experience transient R2 deletion failure on first run
        $transientFailKey = $this->putR2File(48, 'news/editor/');
        $transientFailImg = NewsImage::factory()->editor()->create([
            'storage_key' => $transientFailKey,
            'created_at' => now()->subHours(48),
        ]);

        // Candidate 2: previously interrupted image with stale deleting_at set (> 24h old)
        $stuckKey = $this->putR2File(48, 'news/editor/');
        $stuckImg = NewsImage::factory()->editor()->create([
            'storage_key' => $stuckKey,
            'created_at' => now()->subHours(48),
            'deleting_at' => now()->subHours(25),
        ]);

        $realStorage = app(ImageStorageService::class);
        $partialMock = Mockery::mock(ImageStorageService::class, ['r2'])->makePartial();
        $partialMock->shouldReceive('safeDelete')
            ->with($transientFailKey)
            ->once()
            ->andReturn(false);
        $partialMock->shouldReceive('safeDelete')
            ->with($stuckKey)
            ->once()
            ->andReturnUsing(fn (string $key) => $realStorage->safeDelete($key));

        $this->app->instance(ImageStorageService::class, $partialMock);
        $this->app->forgetInstance(NewsImageService::class);

        // First run: $stuckImg is recovered & deleted; $transientFailImg fails R2 delete, resets deleting_at = null, and returns failure exit code 1
        $this->artisan('news:clean-orphaned-images', ['--force' => true])
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 1, Failed: 1, Skipped on revalidation: 0.')
            ->assertExitCode(1);

        Storage::disk('r2')->assertMissing($stuckKey);
        $this->assertDatabaseMissing('news_images', ['id' => $stuckImg->id]);

        Storage::disk('r2')->assertExists($transientFailKey);
        $this->assertDatabaseHas('news_images', [
            'id' => $transientFailImg->id,
            'storage_key' => $transientFailKey,
            'deleting_at' => null,
        ]);

        // Second run: restore real storage service -> retry succeeds and cleans up $transientFailImg
        $this->app->instance(ImageStorageService::class, $realStorage);
        $this->app->forgetInstance(NewsImageService::class);

        $this->artisan('news:clean-orphaned-images', ['--force' => true])
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 1, Failed: 0, Skipped on revalidation: 0.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertMissing($transientFailKey);
        $this->assertDatabaseMissing('news_images', ['id' => $transientFailImg->id]);
    }

    public function test_orphan_cleanup_handles_raw_r2_objects_without_database_records_without_inventing_db_rows(): void
    {
        $anchorKey = $this->putR2File(72, 'news/covers/');
        $anchorImg = NewsImage::factory()->create([
            'storage_key' => $anchorKey,
            'created_at' => now()->subHours(72),
        ]);
        News::factory()->draft()->create([
            'category_id' => $this->healthCategory->id,
            'featured_image_id' => $anchorImg->id,
            'featured_image_url' => $anchorKey,
        ]);

        // Two raw R2 objects older than 24h with NO news_images database records, plus one recent raw R2 object (2h old)
        $rawCoverKey = $this->putR2File(48, 'news/covers/');
        $rawEditorKey = $this->putR2File(48, 'news/editor/');
        $rawRecentKey = $this->putR2File(2, 'news/editor/');

        $this->assertSame(1, NewsImage::count());

        // 1. Dry-run mode preserves raw R2 objects and does not create DB records
        $this->artisan('news:clean-orphaned-images')
            ->expectsOutputToContain('DRY-RUN MODE ENABLED')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($rawCoverKey);
        Storage::disk('r2')->assertExists($rawEditorKey);
        $this->assertSame(1, NewsImage::count());

        // 2. Max-deletions safeguard blocks when 2 raw R2 candidates > --max-deletions=1
        $this->artisan('news:clean-orphaned-images', [
            '--force' => true,
            '--max-deletions' => 1,
        ])
            ->expectsOutputToContain('exceeds --max-deletions')
            ->assertExitCode(1);

        Storage::disk('r2')->assertExists($rawCoverKey);
        Storage::disk('r2')->assertExists($rawEditorKey);
        $this->assertSame(1, NewsImage::count());

        // 3. Destructive run deletes only the 2 old raw R2 objects and never invents NewsImage rows
        $this->artisan('news:clean-orphaned-images', [
            '--force' => true,
            '--max-deletions' => 10,
        ])
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 2, Failed: 0, Skipped on revalidation: 0.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertMissing($rawCoverKey);
        Storage::disk('r2')->assertMissing($rawEditorKey);
        Storage::disk('r2')->assertExists($rawRecentKey);
        Storage::disk('r2')->assertExists($anchorKey);
        $this->assertSame(1, NewsImage::count());
        $this->assertDatabaseHas('news_images', ['id' => $anchorImg->id]);
    }
}
