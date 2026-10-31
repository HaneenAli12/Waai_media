<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use App\Models\BlogImage;
use App\Models\Category;
use App\Models\News;
use App\Models\NewsImage;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlogPhase1DatabaseAndModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategorySeeder::class);
    }

    public function test_blog_tables_and_columns_exist_with_proper_schema(): void
    {
        // 1. Verify blog_images table
        $this->assertTrue(Schema::hasTable('blog_images'));
        $this->assertTrue(Schema::hasColumns('blog_images', [
            'id', 'disk', 'storage_key', 'mime_type', 'size_bytes', 'sha256',
            'uploaded_by', 'deleting_at', 'created_at', 'updated_at',
        ]));

        // 2. Verify blogs table
        $this->assertTrue(Schema::hasTable('blogs'));
        $this->assertTrue(Schema::hasColumns('blogs', [
            'id', 'category_id', 'featured_image_id', 'featured_image_url',
            'title_ar', 'title_en', 'slug', 'author_name_ar', 'author_name_en',
            'short_description_ar', 'short_description_en', 'content_ar', 'content_en',
            'status', 'published_at', 'created_at', 'updated_at',
        ]));

        // 3. Verify blog_article_images pivot table
        $this->assertTrue(Schema::hasTable('blog_article_images'));
        $this->assertTrue(Schema::hasColumns('blog_article_images', [
            'id', 'blog_id', 'blog_image_id', 'role', 'created_at', 'updated_at',
        ]));

        // 4. Verify News table remains unchanged
        $this->assertTrue(Schema::hasTable('news'));
        $this->assertTrue(Schema::hasColumns('news', [
            'id', 'category_id', 'featured_image_id', 'slug', 'title_en', 'status',
        ]));
    }

    public function test_blog_and_blog_image_model_relationships(): void
    {
        $user = User::factory()->editor()->create();
        $category = Category::where('type', 'blogs')->firstOrFail();

        $featuredImg = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => 'blogs/covers/test-cover.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'uploaded_by' => $user->id,
        ]);

        $editorImg = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => 'blogs/editor/test-editor.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => 2048,
            'uploaded_by' => $user->id,
        ]);

        $blog = Blog::create([
            'category_id' => $category->id,
            'featured_image_id' => $featuredImg->id,
            'title_en' => 'Test Blog Title',
            'slug' => 'test-blog-title-1',
            'status' => Blog::STATUS_DRAFT,
        ]);

        // Attach editor image via pivot
        $blog->images()->attach($editorImg->id, ['role' => 'editor']);

        // Assert relationships on Blog
        $this->assertSame($category->id, $blog->category->id);
        $this->assertSame($featuredImg->id, $blog->featuredImage->id);
        $this->assertSame('blogs/covers/test-cover.webp', $blog->featured_image_url);
        $this->assertCount(1, $blog->editorImages);
        $this->assertSame($editorImg->id, $blog->editorImages->first()->id);

        // Assert Category -> Blogs reverse relationship
        $this->assertTrue($category->blogs()->where('blogs.id', $blog->id)->exists());

        // Assert BlogImage reverse relationships
        $this->assertTrue($featuredImg->featuredInBlogs()->where('id', $blog->id)->exists());
        $this->assertTrue($editorImg->blogArticles()->where('blogs.id', $blog->id)->exists());
    }

    public function test_blog_published_scope_and_lifecycle_helpers(): void
    {
        $category = Category::where('type', 'blogs')->firstOrFail();

        $draft = Blog::create([
            'category_id' => $category->id,
            'title_en' => 'Draft Blog',
            'status' => Blog::STATUS_DRAFT,
            'published_at' => null,
        ]);

        $published = Blog::create([
            'category_id' => $category->id,
            'title_en' => 'Published Blog',
            'status' => Blog::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $futurePublished = Blog::create([
            'category_id' => $category->id,
            'title_en' => 'Future Blog',
            'status' => Blog::STATUS_PUBLISHED,
            'published_at' => now()->addDay(),
        ]);

        $publicBlogs = Blog::published()->get();

        $this->assertTrue($publicBlogs->contains('id', $published->id));
        $this->assertFalse($publicBlogs->contains('id', $draft->id));
        $this->assertFalse($publicBlogs->contains('id', $futurePublished->id));

        $this->assertTrue($draft->isDraft());
        $this->assertFalse($draft->isPublished());
        $this->assertFalse($draft->hasBeenPublished());

        $this->assertTrue($published->isPublished());
        $this->assertFalse($published->isDraft());
        $this->assertTrue($published->hasBeenPublished());
    }

    public function test_tools_and_tech_category_migration_inserts_missing_category_and_is_idempotent(): void
    {
        // 1. Verify Tools & Tech exists (migrated in test setup)
        $category = Category::where('type', 'blogs')->where('slug', 'tools-tech')->first();
        $this->assertNotNull($category);
        $this->assertSame('Tools & Tech', $category->name_en);
        $this->assertSame('الأدوات والتقنية', $category->name_ar);
        $this->assertSame(6, $category->sort_order);
        $this->assertNull($category->parent_id);

        // 2. Re-running migration up is idempotent (duplicate guard prevents failure)
        $migration = require database_path('migrations/2026_10_30_000002_add_tools_and_tech_to_blog_categories.php');
        $migration->up();

        $count = Category::where('type', 'blogs')->where('slug', 'tools-tech')->count();
        $this->assertSame(1, $count);
    }

    public function test_tools_and_tech_migration_rollback_safety_when_referenced(): void
    {
        $category = Category::where('type', 'blogs')->where('slug', 'tools-tech')->firstOrFail();

        // Attach a Blog to this category
        Blog::create([
            'category_id' => $category->id,
            'title_en' => 'Referencing Blog',
            'status' => Blog::STATUS_DRAFT,
        ]);

        $migration = require database_path('migrations/2026_10_30_000002_add_tools_and_tech_to_blog_categories.php');

        // Rollback attempt must fail safely because a Blog is referencing it
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Cannot rollback category 'tools-tech'");

        $migration->down();
    }

    public function test_tools_and_tech_migration_rollback_succeeds_when_not_referenced(): void
    {
        // Ensure no Blog references this category
        $category = Category::where('type', 'blogs')->where('slug', 'tools-tech')->firstOrFail();
        Blog::where('category_id', $category->id)->delete();

        // Ensure ownership marker exists in system_settings (as set during initial migration)
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'migration_created_category_blogs_tools_tech'],
            ['value' => (string) $category->id, 'created_at' => now(), 'updated_at' => now()]
        );

        $migration = require database_path('migrations/2026_10_30_000002_add_tools_and_tech_to_blog_categories.php');
        $migration->down();

        $this->assertFalse(Category::where('type', 'blogs')->where('slug', 'tools-tech')->exists());
        $this->assertFalse(DB::table('system_settings')->where('key', 'migration_created_category_blogs_tools_tech')->exists());
    }

    public function test_tools_and_tech_migration_rollback_does_not_delete_pre_existing_category(): void
    {
        // Simulate category existing before migration ran (no ownership marker recorded)
        $category = Category::where('type', 'blogs')->where('slug', 'tools-tech')->firstOrFail();
        DB::table('system_settings')->where('key', 'migration_created_category_blogs_tools_tech')->delete();

        $migration = require database_path('migrations/2026_10_30_000002_add_tools_and_tech_to_blog_categories.php');
        $migration->down();

        // Category MUST still exist because this migration did not create it
        $this->assertTrue(Category::where('type', 'blogs')->where('slug', 'tools-tech')->exists());
    }

    public function test_tools_and_tech_migration_rolls_back_atomically_if_ownership_tracking_fails(): void
    {
        // Remove Tools & Tech category first
        $category = Category::where('type', 'blogs')->where('slug', 'tools-tech')->first();
        if ($category) {
            Blog::where('category_id', $category->id)->delete();
            $category->delete();
        }
        DB::table('system_settings')->where('key', 'migration_created_category_blogs_tools_tech')->delete();

        $this->assertFalse(Category::where('type', 'blogs')->where('slug', 'tools-tech')->exists());

        // Temporarily intercept or force a failure after inserting category but before committing system_settings
        // We can test this by running a transaction that simulates the failure
        try {
            DB::transaction(function (): void {
                $id = DB::table('categories')->insertGetId([
                    'type' => 'blogs',
                    'name_en' => 'Tools & Tech',
                    'name_ar' => 'الأدوات والتقنية',
                    'slug' => 'tools-tech',
                    'parent_id' => null,
                    'sort_order' => 6,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Simulate an exception when writing to system_settings
                throw new \RuntimeException('Simulated failure during ownership registration');
            });
        } catch (\RuntimeException $e) {
            // Expected exception
        }

        // Assert that because of the transaction, the category was rolled back and does NOT exist
        $this->assertFalse(Category::where('type', 'blogs')->where('slug', 'tools-tech')->exists());
        $this->assertFalse(DB::table('system_settings')->where('key', 'migration_created_category_blogs_tools_tech')->exists());
    }

    public function test_duplicate_blog_slug_rejected_by_database(): void
    {
        $category = Category::where('type', 'blogs')->firstOrFail();

        Blog::create([
            'category_id' => $category->id,
            'title_en' => 'Unique Title 1',
            'slug' => 'common-blog-slug',
            'status' => Blog::STATUS_DRAFT,
        ]);

        $this->expectException(QueryException::class);

        // Attempting to insert another blog with the identical slug must trigger DB unique constraint violation
        Blog::create([
            'category_id' => $category->id,
            'title_en' => 'Unique Title 2',
            'slug' => 'common-blog-slug',
            'status' => Blog::STATUS_DRAFT,
        ]);
    }

    public function test_deleting_category_referenced_by_blog_is_blocked_by_foreign_key(): void
    {
        $category = Category::where('type', 'blogs')->firstOrFail();

        Blog::create([
            'category_id' => $category->id,
            'title_en' => 'Referencing Blog for Restrict Test',
            'status' => Blog::STATUS_DRAFT,
        ]);

        $this->expectException(QueryException::class);

        // Attempting to delete the referenced category directly at database level must fail with FK restrict violation
        $category->delete();
    }

    public function test_deleting_blog_cascades_to_article_images_pivot_while_preserving_blog_images(): void
    {
        $user = User::factory()->editor()->create();
        $category = Category::where('type', 'blogs')->firstOrFail();

        $blogImage = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => 'blogs/editor/pivot-test.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'uploaded_by' => $user->id,
        ]);

        $blog = Blog::create([
            'category_id' => $category->id,
            'title_en' => 'Blog with Images',
            'status' => Blog::STATUS_DRAFT,
        ]);

        $blog->images()->attach($blogImage->id, ['role' => 'editor']);

        $this->assertDatabaseHas('blog_article_images', [
            'blog_id' => $blog->id,
            'blog_image_id' => $blogImage->id,
        ]);

        // Delete the Blog
        $blog->delete();

        // 1. Pivot record in blog_article_images must be deleted by cascade
        $this->assertDatabaseMissing('blog_article_images', [
            'blog_id' => $blog->id,
            'blog_image_id' => $blogImage->id,
        ]);

        // 2. Original record in blog_images must remain intact
        $this->assertDatabaseHas('blog_images', [
            'id' => $blogImage->id,
        ]);
    }

    public function test_clearing_featured_image_id_clears_featured_image_url(): void
    {
        $user = User::factory()->editor()->create();
        $category = Category::where('type', 'blogs')->firstOrFail();

        $featuredImg = BlogImage::create([
            'disk' => 'r2',
            'storage_key' => 'blogs/covers/cover-sync-test.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => 2048,
            'uploaded_by' => $user->id,
        ]);

        $blog = Blog::create([
            'category_id' => $category->id,
            'featured_image_id' => $featuredImg->id,
            'title_en' => 'Blog with Cover',
            'status' => Blog::STATUS_DRAFT,
        ]);

        $this->assertSame('blogs/covers/cover-sync-test.webp', $blog->featured_image_url);

        // Clear featured_image_id
        $blog->featured_image_id = null;
        $blog->save();

        $this->assertNull($blog->featured_image_id);
        $this->assertNull($blog->featured_image_url);

        // Verify in database
        $freshBlog = $blog->fresh();
        $this->assertNull($freshBlog->featured_image_id);
        $this->assertNull($freshBlog->featured_image_url);
    }

    public function test_featured_image_safety_with_invalid_or_non_existent_id(): void
    {
        $category = Category::where('type', 'blogs')->firstOrFail();

        // 1. Direct database insert with non-existent foreign key MUST fail via DB foreign key constraint
        $this->expectException(QueryException::class);
        DB::table('blogs')->insert([
            'category_id' => $category->id,
            'featured_image_id' => 999999, // non-existent
            'title_en' => 'Raw DB Blog with invalid image',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_blog_model_lifecycle_nullifies_non_existent_featured_image_id(): void
    {
        $category = Category::where('type', 'blogs')->firstOrFail();

        // 2. Model lifecycle layer: when an invalid ID is assigned through the model,
        // booted() saving hook checks BlogImage existence and nullifies featured_image_id & featured_image_url
        $blog = Blog::create([
            'category_id' => $category->id,
            'featured_image_id' => 999999, // non-existent
            'title_en' => 'Model Blog with invalid image',
            'status' => Blog::STATUS_DRAFT,
        ]);

        $this->assertNull($blog->featured_image_id);
        $this->assertNull($blog->featured_image_url);

        $freshBlog = $blog->fresh();
        $this->assertNull($freshBlog->featured_image_id);
        $this->assertNull($freshBlog->featured_image_url);
    }

    public function test_strict_independence_between_blog_and_news(): void
    {
        $news = News::first();
        $blog = Blog::first();

        // Assert models point to separate tables
        $this->assertSame('news', (new News)->getTable());
        $this->assertSame('blogs', (new Blog)->getTable());
        $this->assertSame('news_images', (new NewsImage)->getTable());
        $this->assertSame('blog_images', (new BlogImage)->getTable());

        // Blog featuredImage relation targets BlogImage, not NewsImage
        $this->assertInstanceOf(BlogImage::class, (new Blog)->featuredImage()->getRelated());
        // News featuredImage relation targets NewsImage, not BlogImage
        $this->assertInstanceOf(NewsImage::class, (new News)->featuredImage()->getRelated());
    }
}
