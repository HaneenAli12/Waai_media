<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use App\Models\Category;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicBlogApiTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_1X1_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private Category $marketingCategory;

    private Category $tipsCategory;

    private Category $newsCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);

        $this->marketingCategory = Category::where('type', 'blogs')->where('slug', 'digital-marketing-trends')->firstOrFail();
        $this->tipsCategory = Category::where('type', 'blogs')->where('slug', 'tips-guides')->firstOrFail();
        $this->newsCategory = Category::where('type', 'news')->firstOrFail();

        Storage::fake('r2');
        Config::set('filesystems.disks.r2.url', 'https://pub-r2.waai.test');
    }

    private function createFakeImage(string $name = 'cover.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_JPEG_BASE64));
    }

    // =========================================================================
    // 1. Homepage Endpoint (/blogs/latest)
    // =========================================================================

    public function test_homepage_latest_endpoint_returns_latest_3_published_blogs(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            Blog::create([
                'category_id' => $this->marketingCategory->id,
                'title_en' => "Blog Article {$i}",
                'title_ar' => "مقال مدونة {$i}",
                'slug' => "blog-article-{$i}",
                'short_description_en' => "Short desc {$i}",
                'short_description_ar' => "وصف قصير {$i}",
                'status' => Blog::STATUS_PUBLISHED,
                'published_at' => now()->subMinutes(10 - $i), // #5 is newest
            ]);
        }

        // Create a draft that must never be returned
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'title_en' => 'Draft Blog Article',
            'status' => Blog::STATUS_DRAFT,
            'published_at' => null,
        ]);

        $res = $this->getJson('/api/v1/blogs/latest');

        $res->assertStatus(200);
        $res->assertJsonCount(3, 'data');

        // Verify order: newest published_at first (#5, #4, #3)
        $this->assertSame('Blog Article 5', $res->json('data.0.title_en'));
        $this->assertSame('Blog Article 4', $res->json('data.1.title_en'));
        $this->assertSame('Blog Article 3', $res->json('data.2.title_en'));

        // Verify card contents
        $res->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'slug',
                    'title_ar',
                    'title_en',
                    'short_description_ar',
                    'short_description_en',
                    'category_id',
                    'category_name_ar',
                    'category_name_en',
                    'category' => ['id', 'slug', 'name_ar', 'name_en'],
                    'featured_image_url',
                    'published_at',
                ],
            ],
        ]);
    }

    // =========================================================================
    // 2. Listing Endpoint (/blogs)
    // =========================================================================

    public function test_listing_pagination_and_order(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            Blog::create([
                'category_id' => $this->marketingCategory->id,
                'title_en' => "Listing Blog {$i}",
                'slug' => "listing-blog-{$i}",
                'status' => Blog::STATUS_PUBLISHED,
                'published_at' => now()->subMinutes(20 - $i),
            ]);
        }

        $res = $this->getJson('/api/v1/blogs');

        $res->assertStatus(200);
        $res->assertJsonCount(6, 'data'); // Default 6 per page for public tabs
        $this->assertSame(10, $res->json('meta.total'));
        $this->assertSame('Listing Blog 10', $res->json('data.0.title_en')); // Newest first
    }

    public function test_listing_category_filtering(): void
    {
        Blog::create([
            'category_id' => $this->marketingCategory->id,
            'title_en' => 'Marketing Blog',
            'slug' => 'marketing-blog',
            'status' => Blog::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        Blog::create([
            'category_id' => $this->tipsCategory->id,
            'title_en' => 'Tips Blog',
            'slug' => 'tips-blog',
            'status' => Blog::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        // Filter by category_id
        $resId = $this->getJson("/api/v1/blogs?category_id={$this->marketingCategory->id}");
        $resId->assertStatus(200);
        $resId->assertJsonCount(1, 'data');
        $this->assertSame('Marketing Blog', $resId->json('data.0.title_en'));

        // Filter by category slug
        $resSlug = $this->getJson('/api/v1/blogs?category=tips-guides');
        $resSlug->assertStatus(200);
        $resSlug->assertJsonCount(1, 'data');
        $this->assertSame('Tips Blog', $resSlug->json('data.0.title_en'));
    }

    public function test_cannot_filter_by_news_category(): void
    {
        $res = $this->getJson("/api/v1/blogs?category_id={$this->newsCategory->id}");
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['category_id']);
    }

    // =========================================================================
    // 3. Bilingual Fields and Exclusion of Generic Duplicate Fields
    // =========================================================================

    public function test_public_blog_exposes_bilingual_fields_and_excludes_generic_duplicates(): void
    {
        $blog = Blog::create([
            'category_id' => $this->marketingCategory->id,
            'title_en' => 'English Title Only',
            'title_ar' => 'عنوان عربي',
            'short_description_en' => 'English Short Desc',
            'short_description_ar' => 'وصف قصير عربي',
            'content_en' => '<p>English Content</p>',
            'content_ar' => '<p>محتوى عربي</p>',
            'status' => Blog::STATUS_PUBLISHED,
            'published_at' => now(),
            'slug' => 'bilingual-title-1',
        ]);

        $res = $this->getJson("/api/v1/blogs/{$blog->slug}");
        $res->assertStatus(200);

        // Assert bilingual fields are present
        $res->assertJsonPath('data.title_en', 'English Title Only');
        $res->assertJsonPath('data.title_ar', 'عنوان عربي');
        $res->assertJsonPath('data.short_description_en', 'English Short Desc');
        $res->assertJsonPath('data.short_description_ar', 'وصف قصير عربي');
        $res->assertJsonPath('data.content_en', '<p>English Content</p>');
        $res->assertJsonPath('data.content_ar', '<p>محتوى عربي</p>');
        $res->assertJsonPath('data.category_name_en', $this->marketingCategory->name_en);
        $res->assertJsonPath('data.category_name_ar', $this->marketingCategory->name_ar);

        // Assert generic duplicate fields are strictly EXCLUDED
        $json = $res->json('data');
        $this->assertArrayNotHasKey('title', $json);
        $this->assertArrayNotHasKey('author_name', $json);
        $this->assertArrayNotHasKey('short_description', $json);
        $this->assertArrayNotHasKey('content', $json);
        $this->assertArrayNotHasKey('category_name', $json);
        $this->assertArrayNotHasKey('name', $json['category']);
    }

    // =========================================================================
    // 4. Details Endpoint (/blogs/{identifier})
    // =========================================================================

    public function test_details_by_slug_and_numeric_id(): void
    {
        $blog = Blog::create([
            'category_id' => $this->marketingCategory->id,
            'title_en' => 'Detail Blog Title',
            'slug' => 'detail-blog-title-99',
            'status' => Blog::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        // By slug
        $this->getJson("/api/v1/blogs/{$blog->slug}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $blog->id)
            ->assertJsonPath('data.slug', $blog->slug);

        // By numeric ID
        $this->getJson("/api/v1/blogs/{$blog->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $blog->id)
            ->assertJsonPath('data.slug', $blog->slug);
    }

    public function test_draft_returns_404(): void
    {
        $draft = Blog::create([
            'category_id' => $this->marketingCategory->id,
            'title_en' => 'Draft Blog Title',
            'slug' => 'draft-blog-title-101',
            'status' => Blog::STATUS_DRAFT,
            'published_at' => null,
        ]);

        $this->getJson("/api/v1/blogs/{$draft->slug}")->assertStatus(404);
        $this->getJson("/api/v1/blogs/{$draft->id}")->assertStatus(404);
    }

    public function test_unknown_identifier_returns_404(): void
    {
        $this->getJson('/api/v1/blogs/non-existent-slug')->assertStatus(404);
        $this->getJson('/api/v1/blogs/999999')->assertStatus(404);
    }

    public function test_public_api_never_exposes_internal_admin_fields(): void
    {
        $blog = Blog::create([
            'category_id' => $this->marketingCategory->id,
            'title_en' => 'Clean Public Data',
            'slug' => 'clean-public-data-1',
            'status' => Blog::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $res = $this->getJson("/api/v1/blogs/{$blog->slug}")->assertStatus(200);

        $json = $res->json('data');

        // Ensure internal or admin-only fields are NOT present
        $this->assertArrayNotHasKey('uploaded_by', $json);
        $this->assertArrayNotHasKey('storage_key', $json);
        $this->assertArrayNotHasKey('deleting_at', $json);
        $this->assertArrayNotHasKey('sha256', $json);
        $this->assertArrayNotHasKey('size_bytes', $json);
        $this->assertArrayNotHasKey('editor_images', $json);
    }

    public function test_empty_results_return_empty_data_array(): void
    {
        $res = $this->getJson('/api/v1/blogs');
        $res->assertStatus(200);
        $res->assertJsonCount(0, 'data');
    }
}
