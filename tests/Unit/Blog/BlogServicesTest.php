<?php

namespace Tests\Unit\Blog;

use App\Models\Blog;
use App\Models\BlogImage;
use App\Models\Category;
use App\Models\News;
use App\Models\NewsImage;
use App\Models\User;
use App\Services\Blog\BlogHtmlSanitizerService;
use App\Services\Blog\BlogImageService;
use App\Services\Blog\BlogSlugService;
use App\Services\Storage\ImageStorageService;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BlogServicesTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_1X1_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private BlogSlugService $slugService;

    private BlogHtmlSanitizerService $sanitizer;

    private BlogImageService $imageService;

    private ImageStorageService $storageService;

    private function createFakeImageFile(string $name = 'test.jpg'): UploadedFile
    {
        $binary = base64_decode(self::FIXTURE_1X1_JPEG_BASE64);

        return UploadedFile::fake()->createWithContent($name, $binary);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);
        Config::set('filesystems.disks.r2.url', 'https://pub-r2.waai.test');

        $this->slugService = app(BlogSlugService::class);
        $this->sanitizer = app(BlogHtmlSanitizerService::class);
        $this->imageService = app(BlogImageService::class);
        $this->storageService = app(ImageStorageService::class);

        Storage::fake('r2');
    }

    // =========================================================================
    // 1. BlogSlugService
    // =========================================================================

    public function test_slug_service_normalizes_english_titles_and_appends_id(): void
    {
        $slug = $this->slugService->generateSlug('AI Technology Trends in 2026!', 42);

        $this->assertSame('ai-technology-trends-in-2026-42', $slug);
    }

    public function test_slug_service_returns_null_for_empty_or_non_latin_titles(): void
    {
        $this->assertNull($this->slugService->generateSlug(null, 10));
        $this->assertNull($this->slugService->generateSlug('   ', 10));
        $this->assertNull($this->slugService->generateSlug(' عنوان مقال باللغة العربية فقط ', 10));
        $this->assertNull($this->slugService->generateSlug('!@#$%^&*()', 10));
    }

    public function test_slug_service_handles_special_programming_and_framework_characters(): void
    {
        $csharpSlug = $this->slugService->generateSlug('Getting Started with C# and .NET 9 in 2026', 15);
        $this->assertSame('getting-started-with-c-and-net-9-in-2026-15', $csharpSlug);

        $aspSlug = $this->slugService->generateSlug('Building Modern APIs with ASP.NET Core', 22);
        $this->assertSame('building-modern-apis-with-aspnet-core-22', $aspSlug);
    }

    public function test_slug_service_regenerates_slug_for_draft_when_title_changes(): void
    {
        $category = Category::where('type', 'blogs')->firstOrFail();
        $blog = Blog::create([
            'category_id' => $category->id,
            'title_en' => 'Draft Blog Title',
            'slug' => 'draft-blog-title-50',
            'status' => Blog::STATUS_DRAFT,
            'published_at' => null,
        ]);

        $resolved = $this->slugService->resolveSlugForUpdate($blog, 'Updated Draft Title', true);

        $this->assertSame('updated-draft-title-'.$blog->id, $resolved);
    }

    public function test_partial_slug_update_preserves_slug_when_title_en_not_provided(): void
    {
        $category = Category::where('type', 'blogs')->firstOrFail();
        $blog = Blog::create([
            'category_id' => $category->id,
            'title_en' => 'Original Draft Blog Title',
            'slug' => 'original-draft-blog-title-77',
            'status' => Blog::STATUS_DRAFT,
            'published_at' => null,
        ]);

        // When title_en is NOT provided in partial update ($titleEnProvided = false)
        $resolved = $this->slugService->resolveSlugForUpdate($blog, null, false);
        $this->assertSame('original-draft-blog-title-77', $resolved);

        // When title_en IS provided on an un-published blog, slug is regenerated
        $regenerated = $this->slugService->resolveSlugForUpdate($blog, 'Brand New Title', true);
        $this->assertSame('brand-new-title-'.$blog->id, $regenerated);
    }

    public function test_slug_service_preserves_slug_once_published_even_if_unpublished(): void
    {
        $category = Category::where('type', 'blogs')->firstOrFail();
        $blog = Blog::create([
            'category_id' => $category->id,
            'title_en' => 'Original Blog Title',
            'slug' => 'original-blog-title-99',
            'status' => Blog::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        // 1. When published
        $resolved = $this->slugService->resolveSlugForUpdate($blog, 'Updated Blog Title', true);
        $this->assertSame('original-blog-title-99', $resolved);

        // 2. When unpublished back to draft, published_at remains historically not null
        $blog->status = Blog::STATUS_DRAFT;
        $blog->save();

        $resolvedDraft = $this->slugService->resolveSlugForUpdate($blog, 'Another New Title', true);
        $this->assertSame('original-blog-title-99', $resolvedDraft);
        $this->assertFalse($this->slugService->canRegenerateSlug($blog));
    }

    public function test_slug_service_enforces_maximum_length_of_255_and_preserves_id_suffix(): void
    {
        $title250 = str_repeat('a', 250);
        $slug250 = $this->slugService->generateSlug($title250, 1024);
        $this->assertNotNull($slug250);
        $this->assertLessThanOrEqual(255, strlen($slug250));
        $this->assertStringEndsWith('-1024', $slug250);
        $this->assertStringNotContainsString('--1024', $slug250);

        $title255 = str_repeat('b', 255);
        $slug255 = $this->slugService->generateSlug($title255, 999999);
        $this->assertNotNull($slug255);
        $this->assertLessThanOrEqual(255, strlen($slug255));
        $this->assertStringEndsWith('-999999', $slug255);
    }

    // =========================================================================
    // 2. ImageStorageService Allowlist & Prefixes
    // =========================================================================

    public function test_image_storage_service_allows_blogs_prefixes_without_breaking_existing_prefixes(): void
    {
        $file = $this->createFakeImageFile('cover.jpg');

        // Blogs prefixes
        $blogCover = $this->storageService->upload($file, 'blogs/covers/');
        $this->assertStringStartsWith('blogs/covers/', $blogCover->key);

        $blogEditor = $this->storageService->upload($file, 'blogs/editor/');
        $this->assertStringStartsWith('blogs/editor/', $blogEditor->key);

        // Existing prefixes must continue working
        $newsCover = $this->storageService->upload($file, 'news/covers/');
        $this->assertStringStartsWith('news/covers/', $newsCover->key);

        $newsEditor = $this->storageService->upload($file, 'news/editor/');
        $this->assertStringStartsWith('news/editor/', $newsEditor->key);

        $clientLogo = $this->storageService->upload($file, 'clients/logos/');
        $this->assertStringStartsWith('clients/logos/', $clientLogo->key);

        $teamPhoto = $this->storageService->upload($file, 'team/photos/');
        $this->assertStringStartsWith('team/photos/', $teamPhoto->key);

        $eventCover = $this->storageService->upload($file, 'events/covers/');
        $this->assertStringStartsWith('events/covers/', $eventCover->key);
    }

    // =========================================================================
    // 3. BlogHtmlSanitizerService
    // =========================================================================

    public function test_sanitizer_cleans_html_and_canonicalizes_valid_blog_images(): void
    {
        $user = User::factory()->editor()->create();
        $stored = $this->storageService->upload(
            $this->createFakeImageFile('test.jpg'),
            'blogs/editor/'
        );

        $blogImage = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $stored->key,
            'mime_type' => $stored->mimeType,
            'size_bytes' => $stored->size,
            'sha256' => $stored->sha256,
            'uploaded_by' => $user->id,
        ]);

        $html = sprintf(
            '<p>Hello world</p><script>alert("xss")</script><img src="https://pub-r2.waai.test/%s" data-image-id="%d" alt="Test">',
            $stored->key,
            $blogImage->id
        );

        $result = $this->sanitizer->sanitizeAndExtractImages($html, 'content_en', $user);

        $this->assertStringNotContainsString('<script>', $result['html']);
        $this->assertStringContainsString('Hello world', $result['html']);
        $this->assertStringContainsString('data-image-id="'.$blogImage->id.'"', $result['html']);
        $this->assertSame([$blogImage->id], $result['image_ids']);
    }

    public function test_sanitizer_rejects_base64_and_data_uri_images(): void
    {
        $user = User::factory()->editor()->create();
        $html = '<p>Test</p><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY44YAAAAASUVORK5CYII=">';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Base64 or data URI images are not allowed');

        $this->sanitizer->sanitizeAndExtractImages($html, 'content_en', $user);
    }

    public function test_sanitizer_rejects_external_and_unauthorized_image_urls(): void
    {
        $user = User::factory()->editor()->create();
        $html = '<p>Test</p><img src="https://evil.com/malicious.jpg">';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Embedded image URL is not a valid authorized R2 blog image URL');

        $this->sanitizer->sanitizeAndExtractImages($html, 'content_en', $user);
    }

    public function test_sanitizer_rejects_news_images_inside_blog_content(): void
    {
        $user = User::factory()->editor()->create();

        // Create a NewsImage (belonging to news module)
        $storedNews = $this->storageService->upload(
            $this->createFakeImageFile('news.jpg'),
            'news/editor/'
        );
        $newsImage = NewsImage::create([
            'disk' => 'r2',
            'storage_key' => $storedNews->key,
            'mime_type' => $storedNews->mimeType,
            'size_bytes' => $storedNews->size,
            'sha256' => $storedNews->sha256,
            'uploaded_by' => $user->id,
        ]);

        // Attempt to embed NewsImage in Blog HTML via its ID
        $htmlById = sprintf('<p>Test</p><img data-image-id="%d">', $newsImage->id);

        try {
            $this->sanitizer->sanitizeAndExtractImages($htmlById, 'content_en', $user);
            $this->fail('Expected ValidationException when using NewsImage ID in Blog');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('content_en', $e->errors());
        }

        // Attempt to embed NewsImage via its news R2 URL
        $htmlByUrl = sprintf('<p>Test</p><img src="https://pub-r2.waai.test/%s">', $storedNews->key);

        try {
            $this->sanitizer->sanitizeAndExtractImages($htmlByUrl, 'content_en', $user);
            $this->fail('Expected ValidationException when using News storage key in Blog');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('content_en', $e->errors());
        }
    }

    public function test_sanitizer_rejects_non_existent_image_id(): void
    {
        $user = User::factory()->editor()->create();
        $html = '<p>Test</p><img data-image-id="999999">';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Embedded image ID 999999 does not exist');

        $this->sanitizer->sanitizeAndExtractImages($html, 'content_en', $user);
    }

    public function test_sanitizer_rejects_mismatched_image_id_and_src_url(): void
    {
        $user = User::factory()->editor()->create();

        // 1. Create Image A
        $storedA = $this->storageService->upload(
            $this->createFakeImageFile('img_a.jpg'),
            'blogs/editor/'
        );
        $imageA = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $storedA->key,
            'mime_type' => $storedA->mimeType,
            'size_bytes' => $storedA->size,
            'sha256' => $storedA->sha256,
            'uploaded_by' => $user->id,
        ]);

        // 2. Create Image B
        $storedB = $this->storageService->upload(
            $this->createFakeImageFile('img_b.jpg'),
            'blogs/editor/'
        );
        $imageB = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $storedB->key,
            'mime_type' => $storedB->mimeType,
            'size_bytes' => $storedB->size,
            'sha256' => $storedB->sha256,
            'uploaded_by' => $user->id,
        ]);

        // 3. Mismatched: data-image-id is A, but src is B
        $mismatchedHtml = sprintf(
            '<p>Content</p><img data-image-id="%d" src="https://pub-r2.waai.test/%s">',
            $imageA->id,
            $storedB->key
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Embedded image ID and src URL do not match the same uploaded blog image');

        $this->sanitizer->sanitizeAndExtractImages($mismatchedHtml, 'content_en', $user);
    }

    public function test_sanitizer_accepts_matching_image_id_and_src_url(): void
    {
        $user = User::factory()->editor()->create();

        $stored = $this->storageService->upload(
            $this->createFakeImageFile('matched.jpg'),
            'blogs/editor/'
        );
        $image = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $stored->key,
            'mime_type' => $stored->mimeType,
            'size_bytes' => $stored->size,
            'sha256' => $stored->sha256,
            'uploaded_by' => $user->id,
        ]);

        // Correctly matching ID and URL
        $matchedHtml = sprintf(
            '<p>Matching Content</p><img data-image-id="%d" src="https://pub-r2.waai.test/%s" alt="Matched">',
            $image->id,
            $stored->key
        );

        $result = $this->sanitizer->sanitizeAndExtractImages($matchedHtml, 'content_en', $user);

        $this->assertSame([$image->id], $result['image_ids']);
        $this->assertStringContainsString('data-image-id="'.$image->id.'"', $result['html']);
        $this->assertStringContainsString('src="https://pub-r2.waai.test/'.$stored->key.'"', $result['html']);
    }

    // =========================================================================
    // 4. BlogImageService & Ownership Rules
    // =========================================================================

    public function test_editor_cannot_associate_unattached_image_uploaded_by_another_user(): void
    {
        $editor1 = User::factory()->editor()->create();
        $editor2 = User::factory()->editor()->create();

        $stored = $this->storageService->upload(
            $this->createFakeImageFile('e1.jpg'),
            'blogs/editor/'
        );

        $image = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $stored->key,
            'mime_type' => $stored->mimeType,
            'size_bytes' => $stored->size,
            'sha256' => $stored->sha256,
            'uploaded_by' => $editor1->id,
        ]);

        // Editor 2 cannot associate Editor 1's unattached image
        $this->assertFalse($this->imageService->canUserAssociateImage($image, $editor2));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You are not authorized to associate an unattached image uploaded by another user');

        $this->imageService->assertUserCanAssociateImage($image, $editor2);
    }

    public function test_admin_and_super_admin_can_associate_any_unattached_image(): void
    {
        $editor = User::factory()->editor()->create();
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $stored = $this->storageService->upload(
            $this->createFakeImageFile('e.jpg'),
            'blogs/editor/'
        );

        $image = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $stored->key,
            'mime_type' => $stored->mimeType,
            'size_bytes' => $stored->size,
            'sha256' => $stored->sha256,
            'uploaded_by' => $editor->id,
        ]);

        $this->assertTrue($this->imageService->canUserAssociateImage($image, $admin));
        $this->assertTrue($this->imageService->canUserAssociateImage($image, $superAdmin));
    }

    public function test_editor_can_associate_shared_image_already_referenced_in_blogs(): void
    {
        $editor1 = User::factory()->editor()->create();
        $editor2 = User::factory()->editor()->create();
        $category = Category::where('type', 'blogs')->firstOrFail();

        $stored = $this->storageService->upload(
            $this->createFakeImageFile('shared.jpg'),
            'blogs/editor/'
        );

        $image = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $stored->key,
            'mime_type' => $stored->mimeType,
            'size_bytes' => $stored->size,
            'sha256' => $stored->sha256,
            'uploaded_by' => $editor1->id,
        ]);

        // Blog 1 created by editor 1 references this image
        $blog1 = Blog::create([
            'category_id' => $category->id,
            'featured_image_id' => $image->id,
            'title_en' => 'Blog 1',
            'status' => Blog::STATUS_DRAFT,
        ]);

        // Editor 2 can now associate this image because it is already referenced in the blogs library
        $this->assertTrue($this->imageService->canUserAssociateImage($image, $editor2));
    }

    public function test_image_service_does_not_delete_referenced_blog_image(): void
    {
        $user = User::factory()->editor()->create();
        $category = Category::where('type', 'blogs')->firstOrFail();

        $stored = $this->storageService->upload(
            $this->createFakeImageFile('keep.jpg'),
            'blogs/editor/'
        );

        $image = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $stored->key,
            'mime_type' => $stored->mimeType,
            'size_bytes' => $stored->size,
            'sha256' => $stored->sha256,
            'uploaded_by' => $user->id,
        ]);

        Blog::create([
            'category_id' => $category->id,
            'featured_image_id' => $image->id,
            'title_en' => 'Referencing Blog',
            'status' => Blog::STATUS_DRAFT,
        ]);

        $deleted = $this->imageService->deleteImageIfUnreferenced($image->id);

        $this->assertFalse($deleted);
        $this->assertDatabaseHas('blog_images', ['id' => $image->id]);
        $this->assertTrue(Storage::disk('r2')->exists($image->storage_key));
    }

    public function test_image_service_deletes_unreferenced_blog_image(): void
    {
        $user = User::factory()->editor()->create();

        $stored = $this->storageService->upload(
            $this->createFakeImageFile('orphan.jpg'),
            'blogs/editor/'
        );

        $image = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $stored->key,
            'mime_type' => $stored->mimeType,
            'size_bytes' => $stored->size,
            'sha256' => $stored->sha256,
            'uploaded_by' => $user->id,
        ]);

        $this->assertTrue(Storage::disk('r2')->exists($image->storage_key));

        $deleted = $this->imageService->deleteImageIfUnreferenced($image->id);

        $this->assertTrue($deleted);
        $this->assertDatabaseMissing('blog_images', ['id' => $image->id]);
        $this->assertFalse(Storage::disk('r2')->exists($image->storage_key));
    }

    public function test_image_service_recovers_gracefully_when_r2_deletion_fails(): void
    {
        $user = User::factory()->editor()->create();

        // 1. Create target BlogImage to delete
        $storedTarget = $this->storageService->upload(
            $this->createFakeImageFile('fail_target.jpg'),
            'blogs/editor/'
        );
        $targetImage = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $storedTarget->key,
            'mime_type' => $storedTarget->mimeType,
            'size_bytes' => $storedTarget->size,
            'sha256' => $storedTarget->sha256,
            'uploaded_by' => $user->id,
        ]);

        // 2. Create an unrelated NewsImage to ensure it remains completely untouched
        $storedNews = $this->storageService->upload(
            $this->createFakeImageFile('news_untouched.jpg'),
            'news/covers/'
        );
        $newsImage = NewsImage::create([
            'disk' => 'r2',
            'storage_key' => $storedNews->key,
            'mime_type' => $storedNews->mimeType,
            'size_bytes' => $storedNews->size,
            'sha256' => $storedNews->sha256,
            'uploaded_by' => $user->id,
        ]);

        // 3. Mock ImageStorageService to simulate safeDelete returning false (failure)
        $mockStorage = $this->createMock(ImageStorageService::class);
        $mockStorage->method('validateStorageKey')->willReturn(true);
        $mockStorage->method('safeDelete')->willReturn(false);

        $failingImageService = new BlogImageService($mockStorage);

        // 4. Attempt deletion
        $result = $failingImageService->deleteImageIfUnreferenced($targetImage->id);

        // 5. Assertions
        $this->assertFalse($result, 'Service must report false when R2 deletion fails');

        // Target record remains in database
        $refreshed = BlogImage::find($targetImage->id);
        $this->assertNotNull($refreshed, 'BlogImage record must remain in database');

        // deleting_at must be reset to null for retry
        $this->assertNull($refreshed->deleting_at, 'deleting_at must be reset to null when R2 fails');

        // Unrelated NewsImage must remain untouched
        $refreshedNews = NewsImage::find($newsImage->id);
        $this->assertNotNull($refreshedNews);
        $this->assertNull($refreshedNews->deleting_at);
        $this->assertSame($storedNews->key, $refreshedNews->storage_key);
    }

    public function test_blog_image_operations_do_not_affect_news_images(): void
    {
        $user = User::factory()->editor()->create();

        // 1. Create a NewsImage
        $storedNews = $this->storageService->upload(
            $this->createFakeImageFile('news_keep.jpg'),
            'news/covers/'
        );
        $newsImage = NewsImage::create([
            'disk' => 'r2',
            'storage_key' => $storedNews->key,
            'mime_type' => $storedNews->mimeType,
            'size_bytes' => $storedNews->size,
            'sha256' => $storedNews->sha256,
            'uploaded_by' => $user->id,
        ]);

        // 2. Perform BlogImage deletion on an unreferenced blog image
        $storedBlog = $this->storageService->upload(
            $this->createFakeImageFile('blog_delete.jpg'),
            'blogs/covers/'
        );
        $blogImage = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => $storedBlog->key,
            'mime_type' => $storedBlog->mimeType,
            'size_bytes' => $storedBlog->size,
            'sha256' => $storedBlog->sha256,
            'uploaded_by' => $user->id,
        ]);

        $this->imageService->deleteImageIfUnreferenced($blogImage->id);

        // 3. Assert NewsImage and its R2 file remain 100% untouched
        $this->assertDatabaseHas('news_images', ['id' => $newsImage->id]);
        $this->assertTrue(Storage::disk('r2')->exists($newsImage->storage_key));
    }
}
