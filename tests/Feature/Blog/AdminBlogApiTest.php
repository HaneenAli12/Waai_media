<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use App\Models\BlogImage;
use App\Models\Category;
use App\Models\News;
use App\Models\User;
use App\Services\Blog\BlogImageService;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBlogApiTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_1X1_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private const FIXTURE_1X1_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const FIXTURE_1X1_WEBP_BASE64 = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=';

    private User $superAdmin;

    private User $admin;

    private User $editor;

    private Category $aiCategory;

    private Category $toolsCategory;

    private Category $newsCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);

        $this->superAdmin = User::factory()->superAdmin()->create([
            'email' => User::DESIGNATED_SUPER_ADMIN_EMAIL,
        ]);
        $this->admin = User::factory()->admin()->create();
        $this->editor = User::factory()->editor()->create();

        $this->aiCategory = Category::where('type', 'blogs')->where('slug', 'digital-marketing-trends')->firstOrFail();
        $this->toolsCategory = Category::where('type', 'blogs')->where('slug', 'tips-guides')->firstOrFail();
        $this->newsCategory = Category::where('type', 'news')->firstOrFail();

        Storage::fake('r2');
        Config::set('filesystems.disks.r2.url', 'https://pub-r2.waai.test');
    }

    private function fakeWebp(string $name = 'image.webp'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_WEBP_BASE64));
    }

    private function fakeJpeg(string $name = 'image.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_JPEG_BASE64));
    }

    private function uploadBlogR2Image(string $type = 'featured', ?User $uploader = null): BlogImage
    {
        $service = app(BlogImageService::class);
        $directory = in_array($type, ['featured', 'cover'], true)
            ? BlogImageService::COVERS_DIRECTORY
            : BlogImageService::EDITOR_DIRECTORY;

        return $service->uploadAndCreate(
            $this->fakeJpeg("test-{$type}.jpg"),
            $directory,
            $uploader?->id
        );
    }

    // =========================================================================
    // 1. Roles and Authorization Tests
    // =========================================================================

    public function test_unauthenticated_requests_are_denied(): void
    {
        $this->getJson('/api/v1/admin/blogs')->assertStatus(401);
        $this->postJson('/api/v1/admin/blogs', [])->assertStatus(401);
    }

    public function test_super_admin_admin_and_editor_can_list_and_create_blogs(): void
    {
        foreach ([$this->superAdmin, $this->admin, $this->editor] as $user) {
            Sanctum::actingAs($user, ['access']);

            $this->getJson('/api/v1/admin/blogs')->assertStatus(200);

            $res = $this->postJson('/api/v1/admin/blogs', [
                'category_id' => $this->aiCategory->id,
                'title_en' => "Blog by {$user->role}",
                'title_ar' => "مدونة بواسطة {$user->role}",
                'status' => 'draft',
            ]);

            $res->assertStatus(201);
            $this->assertDatabaseHas('blogs', [
                'id' => $res->json('data.id'),
                'title_en' => "Blog by {$user->role}",
            ]);
        }
    }

    public function test_only_admin_and_super_admin_can_permanently_delete_blogs(): void
    {
        $blog1 = Blog::create([
            'category_id' => $this->aiCategory->id,
            'title_en' => 'Delete Test 1',
            'status' => 'draft',
        ]);
        $blog2 = Blog::create([
            'category_id' => $this->aiCategory->id,
            'title_en' => 'Delete Test 2',
            'status' => 'draft',
        ]);

        // Editor attempt must return 403 Forbidden
        Sanctum::actingAs($this->editor, ['access']);
        $this->deleteJson("/api/v1/admin/blogs/{$blog1->id}")
            ->assertStatus(403);
        $this->assertDatabaseHas('blogs', ['id' => $blog1->id]);

        // Admin can delete
        Sanctum::actingAs($this->admin, ['access']);
        $this->deleteJson("/api/v1/admin/blogs/{$blog1->id}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('blogs', ['id' => $blog1->id]);

        // Super Admin can delete
        Sanctum::actingAs($this->superAdmin, ['access']);
        $this->deleteJson("/api/v1/admin/blogs/{$blog2->id}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('blogs', ['id' => $blog2->id]);
    }

    // =========================================================================
    // 2. Listing, Search, Pagination, and Filters
    // =========================================================================

    public function test_admin_blogs_listing_pagination_and_per_page(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        for ($i = 1; $i <= 15; $i++) {
            Blog::create([
                'category_id' => $this->aiCategory->id,
                'title_en' => "Pagination Blog {$i}",
                'status' => 'draft',
            ]);
        }

        $response = $this->getJson('/api/v1/admin/blogs');
        $response->assertStatus(200);
        $response->assertJsonCount(10, 'data'); // Default 10 per page
        $this->assertSame(15, $response->json('meta.total'));
    }

    public function test_admin_blogs_search_filter(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        Blog::create([
            'category_id' => $this->aiCategory->id,
            'title_en' => 'Deep Learning Breakthrough',
            'title_ar' => 'اختراق في التعلم العميق',
            'status' => 'draft',
        ]);
        Blog::create([
            'category_id' => $this->aiCategory->id,
            'title_en' => 'Frontend Modern UI',
            'title_ar' => 'واجهات المستخدم الحديثة',
            'status' => 'draft',
        ]);

        $searchEn = $this->getJson('/api/v1/admin/blogs?search=Learning');
        $searchEn->assertStatus(200);
        $searchEn->assertJsonCount(1, 'data');
        $this->assertSame('Deep Learning Breakthrough', $searchEn->json('data.0.title_en'));

        $searchAr = $this->getJson('/api/v1/admin/blogs?search=الحديثة');
        $searchAr->assertStatus(200);
        $searchAr->assertJsonCount(1, 'data');
        $this->assertSame('Frontend Modern UI', $searchAr->json('data.0.title_en'));

        // Empty search string returns all records gracefully without error
        $searchEmpty = $this->getJson('/api/v1/admin/blogs?search=');
        $searchEmpty->assertStatus(200);
        $searchEmpty->assertJsonCount(2, 'data');
    }

    public function test_admin_blogs_category_and_status_filters(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        Blog::create([
            'category_id' => $this->aiCategory->id,
            'title_en' => 'AI Draft',
            'status' => 'draft',
        ]);
        Blog::create([
            'category_id' => $this->aiCategory->id,
            'title_en' => 'AI Published',
            'status' => 'published',
            'published_at' => now(),
        ]);
        Blog::create([
            'category_id' => $this->toolsCategory->id,
            'title_en' => 'Tools Draft',
            'status' => 'draft',
        ]);

        // Filter by category_id
        $catRes = $this->getJson("/api/v1/admin/blogs?category_id={$this->aiCategory->id}");
        $catRes->assertStatus(200);
        $catRes->assertJsonCount(2, 'data');

        // Filter by status=published
        $statusRes = $this->getJson('/api/v1/admin/blogs?status=published');
        $statusRes->assertStatus(200);
        $statusRes->assertJsonCount(1, 'data');
        $this->assertSame('AI Published', $statusRes->json('data.0.title_en'));
    }

    // =========================================================================
    // 3. Image Upload Endpoint
    // =========================================================================

    public function test_admin_upload_image_endpoint(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $res = $this->postJson('/api/v1/admin/blogs/images', [
            'image' => $this->fakeJpeg('cover-upload.jpg'),
            'type' => 'cover',
        ]);

        $res->assertStatus(201);
        $res->assertJsonStructure([
            'success',
            'data' => ['id', 'url'],
        ]);

        $imageId = $res->json('data.id');
        $this->assertDatabaseHas('blog_images', [
            'id' => $imageId,
            'uploaded_by' => $this->editor->id,
        ]);
    }

    // =========================================================================
    // 4. Create Blog, Validation, and Category Restrictions
    // =========================================================================

    public function test_cannot_assign_news_category_to_blog(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $res = $this->postJson('/api/v1/admin/blogs', [
            'category_id' => $this->newsCategory->id, // Type is 'news'
            'title_en' => 'Invalid Category Blog',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['category_id']);
    }

    public function test_cannot_assign_invalid_featured_image_id(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $res = $this->postJson('/api/v1/admin/blogs', [
            'category_id' => $this->aiCategory->id,
            'title_en' => 'Invalid Image ID Blog',
            'featured_image_id' => 999999, // non-existent
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['featured_image_id']);
    }

    public function test_editor_cannot_claim_unattached_image_uploaded_by_another_user(): void
    {
        $otherEditor = User::factory()->editor()->create();
        $stolenImage = $this->uploadBlogR2Image('cover', $otherEditor);

        Sanctum::actingAs($this->editor, ['access']);

        $res = $this->postJson('/api/v1/admin/blogs', [
            'category_id' => $this->aiCategory->id,
            'title_en' => 'Stealing Image Blog',
            'featured_image_id' => $stolenImage->id,
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['featured_image_id']);
    }

    public function test_admin_can_use_unattached_image_uploaded_by_any_user(): void
    {
        $otherEditor = User::factory()->editor()->create();
        $image = $this->uploadBlogR2Image('cover', $otherEditor);

        Sanctum::actingAs($this->admin, ['access']);

        $res = $this->postJson('/api/v1/admin/blogs', [
            'category_id' => $this->aiCategory->id,
            'title_en' => 'Admin Using Editor Image',
            'featured_image_id' => $image->id,
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('blogs', [
            'id' => $res->json('data.id'),
            'featured_image_id' => $image->id,
        ]);
    }

    // =========================================================================
    // 5. Publish and Lifecycle Requirements
    // =========================================================================

    public function test_publish_requires_all_fields_and_featured_image(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $res = $this->postJson('/api/v1/admin/blogs', [
            'category_id' => $this->aiCategory->id,
            'title_en' => 'Incomplete Published Blog',
            'action' => 'publish',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors([
            'title_ar',
            'author_name_ar',
            'author_name_en',
            'short_description_ar',
            'short_description_en',
            'content_ar',
            'content_en',
            'featured_image',
        ]);
    }

    public function test_successful_publish_and_unpublish_lifecycle_and_slug_immutability(): void
    {
        Sanctum::actingAs($this->editor, ['access']);
        $cover = $this->uploadBlogR2Image('cover', $this->editor);

        // 1. Create and Publish
        $createRes = $this->postJson('/api/v1/admin/blogs', [
            'category_id' => $this->aiCategory->id,
            'title_en' => 'Artificial Intelligence Trends 2026',
            'title_ar' => 'اتجاهات الذكاء الاصطناعي 2026',
            'author_name_en' => 'John Doe',
            'author_name_ar' => 'جون دو',
            'short_description_en' => 'A comprehensive review of 2026 AI trends.',
            'short_description_ar' => 'مراجعة شاملة لاتجاهات الذكاء الاصطناعي في 2026.',
            'content_en' => '<p>Meaningful English content text.</p>',
            'content_ar' => '<p>محتوى عربي ذو معنى ومفيد.</p>',
            'featured_image_id' => $cover->id,
            'action' => 'publish',
        ]);

        $createRes->assertStatus(201);
        $blogId = $createRes->json('data.id');
        $initialSlug = $createRes->json('data.slug');
        $publishedAt = $createRes->json('data.published_at');

        $this->assertSame('artificial-intelligence-trends-2026-'.$blogId, $initialSlug);
        $this->assertNotNull($publishedAt);
        $this->assertSame('published', $createRes->json('data.status'));

        // 2. Unpublish back to draft
        $unpublishRes = $this->postJson("/api/v1/admin/blogs/{$blogId}/unpublish");
        $unpublishRes->assertStatus(200);
        $this->assertSame('draft', $unpublishRes->json('data.status'));
        $this->assertSame($initialSlug, $unpublishRes->json('data.slug'));
        $this->assertSame($publishedAt, $unpublishRes->json('data.published_at'));

        // 3. Update title while in draft state after being published: SLUG MUST REMAIN IMMUTABLE
        $updateRes = $this->patchJson("/api/v1/admin/blogs/{$blogId}", [
            'title_en' => 'Changed Title After Unpublishing',
        ]);
        $updateRes->assertStatus(200);
        $this->assertSame($initialSlug, $updateRes->json('data.slug')); // Still immutable!

        // 4. Republish
        $republishRes = $this->postJson("/api/v1/admin/blogs/{$blogId}/publish");
        $republishRes->assertStatus(200);
        $this->assertSame('published', $republishRes->json('data.status'));
        $this->assertSame($publishedAt, $republishRes->json('data.published_at')); // Original preserved!
        $this->assertSame($initialSlug, $republishRes->json('data.slug'));
    }

    // =========================================================================
    // 6. Partial Updates and Rich Text Sanitizer
    // =========================================================================

    public function test_partial_update_preserves_omitted_fields(): void
    {
        Sanctum::actingAs($this->editor, ['access']);
        $cover = $this->uploadBlogR2Image('cover', $this->editor);

        $blog = Blog::create([
            'category_id' => $this->aiCategory->id,
            'title_en' => 'Original English Title',
            'title_ar' => 'العنوان العربي الأصلي',
            'author_name_en' => 'Original Author',
            'short_description_en' => 'Original Short Desc',
            'featured_image_id' => $cover->id,
            'featured_image_url' => $cover->storage_key,
            'status' => 'draft',
        ]);

        $res = $this->patchJson("/api/v1/admin/blogs/{$blog->id}", [
            'short_description_en' => 'Updated Short Desc Only',
        ]);

        $res->assertStatus(200);
        $this->assertSame('Updated Short Desc Only', $res->json('data.short_description_en'));
        $this->assertSame('Original English Title', $res->json('data.title_en'));
        $this->assertSame('العنوان العربي الأصلي', $res->json('data.title_ar'));
        $this->assertSame('Original Author', $res->json('data.author_name_en'));
        $this->assertSame($cover->id, $res->json('data.featured_image_id'));
    }

    public function test_rich_text_sanitizer_cleans_xss_and_syncs_images(): void
    {
        Sanctum::actingAs($this->editor, ['access']);
        $editorImg = $this->uploadBlogR2Image('editor', $this->editor);

        $htmlWithXss = sprintf(
            '<p>Article body</p><script>alert("hacked")</script><img src="https://pub-r2.waai.test/%s" data-image-id="%d">',
            $editorImg->storage_key,
            $editorImg->id
        );

        $res = $this->postJson('/api/v1/admin/blogs', [
            'category_id' => $this->aiCategory->id,
            'title_en' => 'Sanitization Test Blog',
            'content_en' => $htmlWithXss,
            'status' => 'draft',
        ]);

        $res->assertStatus(201);
        $blogId = $res->json('data.id');

        $this->assertStringNotContainsString('<script>', $res->json('data.content_en'));
        $this->assertStringContainsString('Article body', $res->json('data.content_en'));
        $this->assertCount(1, $res->json('data.editor_images'));
        $this->assertSame($editorImg->id, $res->json('data.editor_images.0.id'));

        // Verify pivot table
        $this->assertDatabaseHas('blog_article_images', [
            'blog_id' => $blogId,
            'blog_image_id' => $editorImg->id,
            'role' => 'editor',
        ]);
    }
}
