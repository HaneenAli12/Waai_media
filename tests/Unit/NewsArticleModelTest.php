<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\NewsArticle;
use App\Models\NewsFailedDeletion;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NewsArticleModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);
    }

    public function test_can_create_draft_article_with_null_slug(): void
    {
        $category = Category::where('type', 'news')->whereDoesntHave('children')->firstOrFail();
        $author = User::factory()->editor()->create();

        $article = NewsArticle::create([
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => NewsArticle::STATUS_DRAFT,
            'title_en' => 'Draft Title in English',
            'title_ar' => 'عنوان المسودة بالعربية',
            'slug' => null,
            'summary_en' => null,
            'summary_ar' => null,
            'content_en' => null,
            'content_ar' => null,
            'cover_image_key' => null,
            'published_at' => null,
        ]);

        $this->assertDatabaseHas('news_articles', [
            'id' => $article->id,
            'status' => NewsArticle::STATUS_DRAFT,
            'slug' => null,
        ]);
        $this->assertNull($article->slug);
        $this->assertNull($article->published_at);
        $this->assertFalse($article->hasBeenPublished());
    }

    public function test_multiple_drafts_with_null_slug_can_coexist_without_collision(): void
    {
        $category = Category::where('type', 'news')->whereDoesntHave('children')->firstOrFail();
        $author = User::factory()->editor()->create();

        $draft1 = NewsArticle::create([
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => NewsArticle::STATUS_DRAFT,
            'title_en' => 'Draft 1',
            'title_ar' => 'مسودة 1',
            'slug' => null,
        ]);

        $draft2 = NewsArticle::create([
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => NewsArticle::STATUS_DRAFT,
            'title_en' => null,
            'title_ar' => 'مسودة 2 بدون عنوان إنجليزي',
            'slug' => null,
        ]);

        $this->assertDatabaseCount('news_articles', 2);
        $this->assertNull($draft1->slug);
        $this->assertNull($draft2->slug);
    }

    public function test_news_article_belongs_to_category_and_author(): void
    {
        $category = Category::where('type', 'news')->where('slug', 'health')->firstOrFail();
        $author = User::factory()->admin()->create();

        $article = NewsArticle::factory()->create([
            'category_id' => $category->id,
            'author_id' => $author->id,
        ]);

        $this->assertInstanceOf(Category::class, $article->category);
        $this->assertSame($category->id, $article->category->id);
        $this->assertSame('health', $article->category->slug);

        $this->assertInstanceOf(User::class, $article->author);
        $this->assertSame($author->id, $article->author->id);
        $this->assertTrue($article->author->isAdmin());
    }

    public function test_published_scope_excludes_drafts_and_unpublished_articles(): void
    {
        $published = NewsArticle::factory()->published()->create([
            'title_en' => 'Active Published Article',
            'published_at' => now()->subDay(),
        ]);

        $draft = NewsArticle::factory()->draft()->create([
            'title_en' => 'Draft Article',
        ]);

        $unpublished = NewsArticle::factory()->unpublished()->create([
            'title_en' => 'Unpublished Article',
        ]);

        $results = NewsArticle::published()->get();

        $this->assertTrue($results->contains('id', $published->id));
        $this->assertFalse($results->contains('id', $draft->id));
        $this->assertFalse($results->contains('id', $unpublished->id));
    }

    public function test_published_scope_excludes_future_published_at_dates(): void
    {
        $pastPublished = NewsArticle::factory()->published()->create([
            'title_en' => 'Past Published Article',
            'published_at' => now()->subMinute(),
        ]);

        $futurePublished = NewsArticle::factory()->published()->create([
            'title_en' => 'Future Scheduled Article',
            'published_at' => now()->addDay(),
        ]);

        $results = NewsArticle::published()->get();

        $this->assertTrue($results->contains('id', $pastPublished->id));
        $this->assertFalse($results->contains('id', $futurePublished->id));
    }

    public function test_for_category_scope_handles_single_id_and_array_of_category_ids(): void
    {
        $health = Category::where('type', 'news')->where('slug', 'health')->firstOrFail();
        $food = Category::where('type', 'news')->where('slug', 'food')->firstOrFail();
        $sports = Category::where('type', 'news')->where('slug', 'sports')->firstOrFail();

        $healthArticle = NewsArticle::factory()->published()->create(['category_id' => $health->id]);
        $foodArticle = NewsArticle::factory()->published()->create(['category_id' => $food->id]);
        $sportsArticle = NewsArticle::factory()->published()->create(['category_id' => $sports->id]);

        // Single ID
        $singleResults = NewsArticle::forCategory($health->id)->get();
        $this->assertTrue($singleResults->contains('id', $healthArticle->id));
        $this->assertFalse($singleResults->contains('id', $foodArticle->id));

        // Array of IDs (e.g. aggregated child categories)
        $multiResults = NewsArticle::forCategory([$health->id, $food->id])->get();
        $this->assertTrue($multiResults->contains('id', $healthArticle->id));
        $this->assertTrue($multiResults->contains('id', $foodArticle->id));
        $this->assertFalse($multiResults->contains('id', $sportsArticle->id));
    }

    public function test_ordering_scopes_order_deterministically(): void
    {
        $article1 = NewsArticle::factory()->published()->create([
            'published_at' => now()->subHours(3),
            'created_at' => now()->subHours(1),
        ]);

        $article2 = NewsArticle::factory()->published()->create([
            'published_at' => now()->subHours(1),
            'created_at' => now()->subHours(3),
        ]);

        // Public ordering: published_at DESC, id DESC
        $publicOrdered = NewsArticle::orderedForPublic()->get();
        $this->assertSame($article2->id, $publicOrdered->first()->id);

        // Admin ordering: created_at DESC, id DESC
        $adminOrdered = NewsArticle::orderedForAdmin()->get();
        $this->assertSame($article1->id, $adminOrdered->first()->id);
    }

    public function test_article_deletion_is_permanent_and_does_not_soft_delete(): void
    {
        $article = NewsArticle::factory()->published()->create();
        $id = $article->id;

        $article->delete();

        $this->assertDatabaseMissing('news_articles', ['id' => $id]);
        $this->assertNull(NewsArticle::find($id));
    }

    public function test_category_deletion_is_restricted_when_referenced_by_articles(): void
    {
        $category = Category::where('type', 'news')->whereDoesntHave('children')->firstOrFail();
        NewsArticle::factory()->create(['category_id' => $category->id]);

        $this->expectException(QueryException::class);
        $category->delete();
    }

    public function test_author_deletion_is_restricted_when_referenced_by_articles(): void
    {
        $author = User::factory()->editor()->create();
        NewsArticle::factory()->create(['author_id' => $author->id]);

        $this->expectException(QueryException::class);
        $author->forceDelete();
    }

    public function test_failed_deletion_records_and_deduplicates_by_key(): void
    {
        $key = 'news/covers/sample-uuid.jpg';

        $record1 = NewsFailedDeletion::record($key, 'Connection timeout');
        $this->assertSame(1, $record1->attempts);
        $this->assertSame('Connection timeout', $record1->reason);

        // Record again with same key -> increments attempts and updates timestamp
        $record2 = NewsFailedDeletion::record($key, 'Connection refused');
        $this->assertSame($record1->id, $record2->id);
        $this->assertSame(2, $record2->attempts);
        $this->assertSame('Connection refused', $record2->reason);

        $this->assertDatabaseCount('news_failed_deletions', 1);
    }

    public function test_has_been_published_reflects_presence_of_published_at(): void
    {
        $draft = NewsArticle::factory()->draft()->create();
        $this->assertFalse($draft->hasBeenPublished());

        $published = NewsArticle::factory()->published()->create();
        $this->assertTrue($published->hasBeenPublished());

        $unpublished = NewsArticle::factory()->unpublished()->create();
        $this->assertTrue($unpublished->hasBeenPublished());
    }

    public function test_news_article_migrations_rollback_and_remigrate_safely(): void
    {
        $this->assertTrue(Schema::hasTable('news_articles'));
        $this->assertTrue(Schema::hasTable('news_failed_deletions'));

        // Rollback news migrations in reverse dependency order
        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_10_21_000002_create_news_failed_deletions_table.php',
        ]);
        $this->assertFalse(Schema::hasTable('news_failed_deletions'));

        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_10_21_000001_create_news_articles_table.php',
        ]);
        $this->assertFalse(Schema::hasTable('news_articles'));

        // Re-run migrations
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_10_21_000001_create_news_articles_table.php',
        ]);
        $this->assertTrue(Schema::hasTable('news_articles'));

        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_10_21_000002_create_news_failed_deletions_table.php',
        ]);
        $this->assertTrue(Schema::hasTable('news_failed_deletions'));
    }
}
