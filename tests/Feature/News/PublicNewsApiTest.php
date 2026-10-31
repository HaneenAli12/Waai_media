<?php

namespace Tests\Feature\News;

use App\Models\Category;
use App\Models\News;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicNewsApiTest extends TestCase
{
    use RefreshDatabase;

    private Category $healthCategory;

    private Category $sportsCategory;

    private Category $entertainmentParent;

    private Category $celebritiesChild;

    private Category $inTheatersChild;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);

        $this->healthCategory = Category::where('type', 'news')->where('slug', 'health')->firstOrFail();
        $this->sportsCategory = Category::where('type', 'news')->where('slug', 'sports')->firstOrFail();
        $this->entertainmentParent = Category::where('type', 'news')->where('slug', 'entertainment')->firstOrFail();
        $this->celebritiesChild = Category::where('type', 'news')->where('slug', 'celebrities')->firstOrFail();
        $this->inTheatersChild = Category::where('type', 'news')->where('slug', 'in-theaters')->firstOrFail();

        Storage::fake('r2');
        Config::set('filesystems.disks.r2.url', 'https://pub-r2.waai.test');
    }

    public function test_public_listing_and_detail_endpoints_strictly_exclude_drafts(): void
    {
        $published = News::factory()->published()->create([
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Published Health Breakthrough',
            'title_ar' => 'إنجاز صحي منشور',
            'slug' => 'published-health-breakthrough-1',
        ]);

        $draft = News::factory()->draft()->create([
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Confidential Draft News',
            'title_ar' => 'خبر سري مسودة',
            'slug' => 'confidential-draft-news-2',
        ]);

        // 1. Public index returns only published
        $res = $this->getJson('/api/v1/news')->assertStatus(200);
        $res->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id)
            ->assertJsonPath('data.0.slug', 'published-health-breakthrough-1');

        // 2. Public show by slug or ID returns 200 for published, 404 for draft
        $this->getJson("/api/v1/news/{$published->slug}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $published->id)
            ->assertJsonPath('data.slug', $published->slug);

        $this->getJson("/api/v1/news/{$published->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $published->id)
            ->assertJsonPath('data.slug', $published->slug);

        $this->getJson("/api/v1/news/{$draft->slug}")->assertStatus(404);
        $this->getJson("/api/v1/news/{$draft->id}")->assertStatus(404);
    }

    public function test_public_endpoints_negotiate_arabic_and_english_localization_and_return_both_category_names(): void
    {
        $published = News::factory()->published()->create([
            'category_id' => $this->celebritiesChild->id,
            'title_en' => 'Red Carpet Highlights',
            'title_ar' => 'أبرز لقطات السجادة الحمراء',
            'author_name_en' => 'Nadia Kamal',
            'author_name_ar' => 'نادية كمال',
            'short_description_en' => 'Summary in English',
            'short_description_ar' => 'ملخص باللغة العربية',
            'content_en' => '<p>English body</p>',
            'content_ar' => '<p>النص العربي</p>',
            'slug' => 'red-carpet-highlights-10',
        ]);

        // Default (English)
        $enRes = $this->getJson("/api/v1/news/{$published->slug}")->assertStatus(200);
        $enRes->assertJsonPath('data.title', 'Red Carpet Highlights')
            ->assertJsonPath('data.slug', 'red-carpet-highlights-10')
            ->assertJsonPath('data.author_name', 'Nadia Kamal')
            ->assertJsonPath('data.short_description', 'Summary in English')
            ->assertJsonPath('data.content', '<p>English body</p>')
            ->assertJsonPath('data.category.name', 'Celebrities')
            ->assertJsonPath('data.category.name_en', 'Celebrities')
            ->assertJsonPath('data.category.name_ar', 'المشاهير')
            ->assertJsonPath('data.category_name_en', 'Celebrities')
            ->assertJsonPath('data.category_name_ar', 'المشاهير')
            ->assertJsonPath('data.category.parent.name_en', 'Entertainment')
            ->assertJsonPath('data.category.parent.name_ar', 'الترفيه');

        // Arabic via Accept-Language
        $arRes = $this->withHeader('Accept-Language', 'ar-SA,ar;q=0.9,en;q=0.8')
            ->getJson("/api/v1/news/{$published->slug}")
            ->assertStatus(200);

        $arRes->assertJsonPath('data.title', 'أبرز لقطات السجادة الحمراء')
            ->assertJsonPath('data.author_name', 'نادية كمال')
            ->assertJsonPath('data.short_description', 'ملخص باللغة العربية')
            ->assertJsonPath('data.content', '<p>النص العربي</p>')
            ->assertJsonPath('data.category.name', 'المشاهير')
            ->assertJsonPath('data.category.name_en', 'Celebrities')
            ->assertJsonPath('data.category.name_ar', 'المشاهير')
            ->assertJsonPath('data.category_name_en', 'Celebrities')
            ->assertJsonPath('data.category_name_ar', 'المشاهير')
            ->assertJsonPath('data.category.parent.name', 'الترفيه');
    }

    public function test_homepage_latest_endpoint_returns_latest_three_published_articles_ordered_by_published_at_desc(): void
    {
        $n1 = News::factory()->published()->create([
            'category_id' => $this->healthCategory->id,
            'title_en' => 'News 1 (Oldest)',
            'published_at' => now()->subHours(5),
        ]);
        $n2 = News::factory()->published()->create([
            'category_id' => $this->sportsCategory->id,
            'title_en' => 'News 2',
            'published_at' => now()->subHours(4),
        ]);
        $n3 = News::factory()->published()->create([
            'category_id' => $this->celebritiesChild->id,
            'title_en' => 'News 3',
            'published_at' => now()->subHours(3),
        ]);
        $n4 = News::factory()->published()->create([
            'category_id' => $this->healthCategory->id,
            'title_en' => 'News 4 ( newest )',
            'published_at' => now()->subHours(1),
        ]);
        News::factory()->draft()->create([
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Draft Should Not Appear',
        ]);

        $res = $this->getJson('/api/v1/news/latest')->assertStatus(200);

        $res->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $n4->id)
            ->assertJsonPath('data.1.id', $n3->id)
            ->assertJsonPath('data.2.id', $n2->id);

        // Verify both Arabic and English category names are present in homepage response
        $res->assertJsonPath('data.0.category.name_en', 'Health')
            ->assertJsonPath('data.0.category.name_ar', 'الصحة')
            ->assertJsonPath('data.1.category.name_en', 'Celebrities')
            ->assertJsonPath('data.1.category.name_ar', 'المشاهير');
    }

    public function test_public_category_filtering_supports_entertainment_parent_and_child_subcategories(): void
    {
        $healthArticle = News::factory()->published()->create([
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Health Article',
        ]);
        $celebArticle = News::factory()->published()->create([
            'category_id' => $this->celebritiesChild->id,
            'title_en' => 'Celeb Article',
        ]);
        $theaterArticle = News::factory()->published()->create([
            'category_id' => $this->inTheatersChild->id,
            'title_en' => 'Theater Article',
        ]);

        // Filter by Entertainment parent slug -> returns both Celebrities and In Theaters articles
        $entRes = $this->getJson('/api/v1/news?category=entertainment')->assertStatus(200);
        $entIds = collect($entRes->json('data'))->pluck('id')->all();
        $this->assertCount(2, $entIds);
        $this->assertContains($celebArticle->id, $entIds);
        $this->assertContains($theaterArticle->id, $entIds);

        // Filter by specific child subcategory (celebrities) -> returns only Celebrities article
        $celebRes = $this->getJson('/api/v1/news?category=celebrities')->assertStatus(200);
        $celebRes->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $celebArticle->id);

        // Filter by leaf category ID (Health) -> returns only Health article
        $healthRes = $this->getJson("/api/v1/news?category_id={$this->healthCategory->id}")->assertStatus(200);
        $healthRes->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $healthArticle->id);
    }

    public function test_public_numeric_identifier_lookup_behavior_and_ambiguity_handling(): void
    {
        // 1. Published article with ID 5
        $articleWithId5 = News::factory()->published()->create([
            'id' => 5,
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Article With Identifier Five',
            'slug' => 'article-with-identifier-five-5',
        ]);

        // 2. Querying by '5' matches ID 5 successfully
        $res = $this->getJson('/api/v1/news/5')->assertStatus(200);
        $res->assertJsonPath('data.id', 5)
            ->assertJsonPath('data.slug', 'article-with-identifier-five-5');

        // 3. Querying by a non-existent numeric identifier returns 404
        $this->getJson('/api/v1/news/999999')->assertStatus(404);
    }
}
