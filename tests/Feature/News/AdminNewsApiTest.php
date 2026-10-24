<?php

namespace Tests\Feature\News;

use App\Models\Category;
use App\Models\NewsArticle;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminNewsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    private Category $leafCategory;

    private Category $otherLeafCategory;

    private Category $parentCategory;

    private Category $childCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);

        $this->admin = User::factory()->admin()->create();
        $this->editor = User::factory()->editor()->create();

        $this->leafCategory = Category::where('type', 'news')->where('slug', 'health')->firstOrFail();
        $this->otherLeafCategory = Category::where('type', 'news')->where('slug', 'food')->firstOrFail();
        $this->parentCategory = Category::where('type', 'news')->where('slug', 'entertainment')->firstOrFail();
        $this->childCategory = Category::where('type', 'news')->where('slug', 'celebrities')->firstOrFail();
    }

    // =========================================================================
    // 1. Authentication & Authorization
    // =========================================================================

    public function test_unauthenticated_user_cannot_access_any_admin_news_endpoint(): void
    {
        $article = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'author_id' => $this->admin->id,
        ]);

        $this->getJson('/api/v1/admin/news')->assertStatus(401);
        $this->postJson('/api/v1/admin/news', [])->assertStatus(401);
        $this->getJson("/api/v1/admin/news/{$article->id}")->assertStatus(401);
        $this->patchJson("/api/v1/admin/news/{$article->id}", [])->assertStatus(401);
        $this->postJson("/api/v1/admin/news/{$article->id}/publish", [])->assertStatus(401);
        $this->postJson("/api/v1/admin/news/{$article->id}/unpublish", [])->assertStatus(401);
        $this->deleteJson("/api/v1/admin/news/{$article->id}")->assertStatus(401);
    }

    public function test_admin_and_editor_can_list_and_show_news(): void
    {
        $article = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'author_id' => $this->admin->id,
        ]);

        // Admin can list and show
        Sanctum::actingAs($this->admin, ['access']);
        $this->getJson('/api/v1/admin/news')->assertStatus(200);
        $this->getJson("/api/v1/admin/news/{$article->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $article->id);

        // Editor can list and show
        Sanctum::actingAs($this->editor, ['access']);
        $this->getJson('/api/v1/admin/news')->assertStatus(200);
        $this->getJson("/api/v1/admin/news/{$article->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $article->id);
    }

    public function test_editor_cannot_delete_news_article(): void
    {
        $article = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'author_id' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->editor, ['access']);

        $response = $this->deleteJson("/api/v1/admin/news/{$article->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('news_articles', ['id' => $article->id]);
    }

    public function test_admin_can_permanently_delete_news_article(): void
    {
        $article = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'author_id' => $this->editor->id,
        ]);

        Sanctum::actingAs($this->admin, ['access']);

        $response = $this->deleteJson("/api/v1/admin/news/{$article->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'News article permanently deleted successfully.',
            ]);

        $this->assertDatabaseMissing('news_articles', ['id' => $article->id]);

        // Subsequent show should return 404
        $this->getJson("/api/v1/admin/news/{$article->id}")->assertStatus(404);
    }

    public function test_delete_nonexistent_article_returns_404(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $this->deleteJson('/api/v1/admin/news/99999')->assertStatus(404);
    }

    public function test_client_supplied_role_escalation_is_ineffective(): void
    {
        $article = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'author_id' => $this->editor->id,
        ]);

        Sanctum::actingAs($this->editor, ['access']);

        // Attempt to pass role=admin in payload or headers
        $response = $this->deleteJson("/api/v1/admin/news/{$article->id}", [
            'role' => 'admin',
        ], [
            'X-User-Role' => 'admin',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('news_articles', ['id' => $article->id]);
    }

    // =========================================================================
    // 2. Draft Creation & Persistence
    // =========================================================================

    public function test_draft_creation_succeeds_with_valid_english_title_and_generates_id_slug(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $payload = [
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Major Medical Innovation Unveiled',
            'title_ar' => 'الكشف عن ابتكار طبي كبير',
            'summary_en' => 'Brief summary of the innovation.',
            'content_en' => '<p>Detailed article content goes here.</p>',
        ];

        $response = $this->postJson('/api/v1/admin/news', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.title_en', 'Major Medical Innovation Unveiled')
            ->assertJsonPath('data.status', NewsArticle::STATUS_DRAFT)
            ->assertJsonPath('data.author_id', $this->editor->id);

        $createdId = $response->json('data.id');
        $expectedSlug = "major-medical-innovation-unveiled-{$createdId}";

        $this->assertSame($expectedSlug, $response->json('data.slug'));
        $this->assertDatabaseHas('news_articles', [
            'id' => $createdId,
            'slug' => $expectedSlug,
            'status' => NewsArticle::STATUS_DRAFT,
            'author_id' => $this->editor->id,
        ]);
    }

    public function test_draft_creation_with_arabic_only_title_leaves_slug_null(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $payload = [
            'category_id' => $this->leafCategory->id,
            'title_ar' => 'خبر باللغة العربية فقط بدون عنوان إنجليزي',
        ];

        $response = $this->postJson('/api/v1/admin/news', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.slug', null)
            ->assertJsonPath('data.title_en', null)
            ->assertJsonPath('data.title_ar', 'خبر باللغة العربية فقط بدون عنوان إنجليزي');

        $createdId = $response->json('data.id');
        $this->assertDatabaseHas('news_articles', [
            'id' => $createdId,
            'slug' => null,
            'title_en' => null,
        ]);
    }

    public function test_draft_creation_fails_when_no_title_provided(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $response = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->leafCategory->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title_en', 'title_ar']);
    }

    public function test_draft_creation_fails_with_parent_category(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $response = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->parentCategory->id,
            'title_en' => 'Draft in Parent Category',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_draft_creation_sanitizes_html_content(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $response = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Sanitization Test Article',
            'content_en' => '<script>alert("xss")</script><p>Clean English paragraph</p>',
            'content_ar' => '<iframe src="http://evil.com"></iframe><p>فقرة عربية نظيفة</p>',
        ]);

        $response->assertStatus(201);
        $article = NewsArticle::findOrFail($response->json('data.id'));

        $this->assertStringNotContainsString('<script>', $article->content_en);
        $this->assertStringContainsString('<p>Clean English paragraph</p>', $article->content_en);

        $this->assertStringNotContainsString('<iframe>', $article->content_ar);
        $this->assertStringContainsString('<p>فقرة عربية نظيفة</p>', $article->content_ar);
    }

    public function test_client_supplied_protected_fields_are_ignored_on_creation(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $response = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Protected Fields Injection Test',
            'slug' => 'client-injected-slug',
            'status' => NewsArticle::STATUS_PUBLISHED,
            'published_at' => '2020-01-01 00:00:00',
            'author_id' => 9999,
        ]);

        $response->assertStatus(201);
        $createdId = $response->json('data.id');

        $this->assertDatabaseHas('news_articles', [
            'id' => $createdId,
            'slug' => "protected-fields-injection-test-{$createdId}",
            'status' => NewsArticle::STATUS_DRAFT,
            'published_at' => null,
            'author_id' => $this->editor->id,
        ]);
    }

    // =========================================================================
    // 3. Update Semantics
    // =========================================================================

    public function test_partial_update_preserves_omitted_fields(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $article = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'author_id' => $this->editor->id,
            'title_en' => 'Original English Title',
            'title_ar' => 'العنوان العربي الأصلي',
            'summary_en' => 'Original English Summary',
            'summary_ar' => 'الملخص العربي الأصلي',
            'content_en' => '<p>Original Content</p>',
        ]);

        $response = $this->patchJson("/api/v1/admin/news/{$article->id}", [
            'summary_en' => 'Updated English Summary Only',
        ]);

        $response->assertStatus(200);

        $article->refresh();
        $this->assertSame('Original English Title', $article->title_en);
        $this->assertSame('العنوان العربي الأصلي', $article->title_ar);
        $this->assertSame('Updated English Summary Only', $article->summary_en);
        $this->assertSame('الملخص العربي الأصلي', $article->summary_ar);
        $this->assertSame('<p>Original Content</p>', $article->content_en);
    }

    public function test_update_draft_regenerates_slug_when_english_title_changes(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $article = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Initial Title',
            'slug' => null,
            'status' => NewsArticle::STATUS_DRAFT,
        ]);
        $article->update(['slug' => "initial-title-{$article->id}"]);

        $response = $this->patchJson("/api/v1/admin/news/{$article->id}", [
            'title_en' => 'Brand New Revised Title',
        ]);

        $response->assertStatus(200);

        $article->refresh();
        $this->assertSame("brand-new-revised-title-{$article->id}", $article->slug);
    }

    public function test_update_draft_preserves_slug_when_english_title_omitted(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $article = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Keep This Title',
            'status' => NewsArticle::STATUS_DRAFT,
        ]);
        $article->update(['slug' => "keep-this-title-{$article->id}"]);

        $response = $this->patchJson("/api/v1/admin/news/{$article->id}", [
            'summary_en' => 'Just changing summary',
        ]);

        $response->assertStatus(200);

        $article->refresh();
        $this->assertSame("keep-this-title-{$article->id}", $article->slug);
    }

    public function test_published_slug_never_changes_on_subsequent_update(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $article = NewsArticle::factory()->published()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Published Article Title',
            'slug' => 'published-article-title-42',
        ]);

        $response = $this->patchJson("/api/v1/admin/news/{$article->id}", [
            'title_en' => 'Altered Title After Publication',
        ]);

        $response->assertStatus(200);

        $article->refresh();
        $this->assertSame('Altered Title After Publication', $article->title_en);
        $this->assertSame('published-article-title-42', $article->slug);
    }

    // =========================================================================
    // 4. Publish & Unpublish Lifecycle
    // =========================================================================

    public function test_incomplete_draft_cannot_be_published(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $article = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Incomplete Draft',
            'title_ar' => null,
            'summary_en' => null,
            'content_en' => null,
            'cover_image_key' => null,
            'status' => NewsArticle::STATUS_DRAFT,
        ]);

        $response = $this->postJson("/api/v1/admin/news/{$article->id}/publish", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title_ar', 'summary_en', 'summary_ar', 'content_en', 'content_ar', 'cover_image_key']);
    }

    public function test_complete_draft_publication_sets_status_published_at_and_generates_slug(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $article = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Complete Draft Ready For Release',
            'title_ar' => 'مسودة كاملة جاهزة للنشر الفوري',
            'summary_en' => 'English summary for publication.',
            'summary_ar' => 'ملخص عربي جاهز للنشر لا يتجاوز خمس مئة حرف.',
            'content_en' => '<p>Full English content body.</p>',
            'content_ar' => '<p>محتوى المقال العربي بالكامل.</p>',
            'cover_image_key' => 'news/covers/00000000-0000-0000-0000-000000000001.jpg',
            'slug' => null,
            'status' => NewsArticle::STATUS_DRAFT,
            'published_at' => null,
        ]);

        $response = $this->postJson("/api/v1/admin/news/{$article->id}/publish", []);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', NewsArticle::STATUS_PUBLISHED);

        $article->refresh();
        $this->assertSame(NewsArticle::STATUS_PUBLISHED, $article->status);
        $this->assertNotNull($article->published_at);
        $this->assertSame("complete-draft-ready-for-release-{$article->id}", $article->slug);
    }

    public function test_republishing_already_published_article_preserves_original_published_at(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $originalTime = now()->subDays(10)->startOfSecond();

        $article = NewsArticle::factory()->published()->create([
            'category_id' => $this->leafCategory->id,
            'published_at' => $originalTime,
            'cover_image_key' => 'news/covers/00000000-0000-0000-0000-000000000002.jpg',
        ]);

        $response = $this->postJson("/api/v1/admin/news/{$article->id}/publish", []);

        $response->assertStatus(200);

        $article->refresh();
        $this->assertSame($originalTime->toIso8601String(), $article->published_at->toIso8601String());
    }

    public function test_unpublish_sets_status_unpublished_and_clears_published_at(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $article = NewsArticle::factory()->published()->create([
            'category_id' => $this->leafCategory->id,
            'slug' => 'permanent-slug-99',
        ]);

        $response = $this->postJson("/api/v1/admin/news/{$article->id}/unpublish", []);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', NewsArticle::STATUS_UNPUBLISHED)
            ->assertJsonPath('data.published_at', null);

        $article->refresh();
        $this->assertSame(NewsArticle::STATUS_UNPUBLISHED, $article->status);
        $this->assertNull($article->published_at);
        $this->assertSame('permanent-slug-99', $article->slug);
    }

    public function test_unpublished_article_slug_remains_permanently_immutable_on_update(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $article = NewsArticle::factory()->unpublished()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Unpublished Article Title',
            'slug' => 'unpublished-article-title-88',
        ]);

        $response = $this->patchJson("/api/v1/admin/news/{$article->id}", [
            'title_en' => 'Brand New Title Attempt For Slug Regeneration',
        ]);

        $response->assertStatus(200);

        $article->refresh();
        $this->assertSame('Brand New Title Attempt For Slug Regeneration', $article->title_en);
        $this->assertSame('unpublished-article-title-88', $article->slug);
    }

    // =========================================================================
    // 5. List, Search, Filters & Fixed Pagination
    // =========================================================================

    public function test_fixed_pagination_returns_exactly_20_per_page(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        NewsArticle::factory()->count(25)->create([
            'category_id' => $this->leafCategory->id,
        ]);

        $response = $this->getJson('/api/v1/admin/news');

        $response->assertStatus(200)
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 25);
    }

    public function test_fixed_pagination_ignores_per_page_and_limit_query_parameters(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        NewsArticle::factory()->count(25)->create([
            'category_id' => $this->leafCategory->id,
        ]);

        $response = $this->getJson('/api/v1/admin/news?per_page=50&limit=100');

        $response->assertStatus(200)
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.per_page', 20);
    }

    public function test_search_matches_english_title_only_and_not_summary_or_content(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        // Article A has "Cardiology" in title_en
        $articleA = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Advances in Modern Cardiology Research',
            'summary_en' => 'General medical update.',
            'content_en' => '<p>Nothing special.</p>',
        ]);

        // Article B has "Cardiology" only in summary_en
        $articleB = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'General Hospital Report',
            'summary_en' => 'This summary mentions Cardiology in passing.',
            'content_en' => '<p>Body text.</p>',
        ]);

        // Article C has "Cardiology" only in content_en
        $articleC = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Another Medical Topic',
            'summary_en' => 'No match here.',
            'content_en' => '<p>Content mentions Cardiology deeply.</p>',
        ]);

        $response = $this->getJson('/api/v1/admin/news?search=Cardiology');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $articleA->id);
    }

    public function test_search_matches_arabic_title_only(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $articleA = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'title_ar' => 'تطورات جراحة القلب الحديثة',
        ]);

        $articleB = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'title_ar' => 'تقرير طبي عام',
            'summary_ar' => 'الملخص يذكر جراحة القلب',
        ]);

        $response = $this->getJson('/api/v1/admin/news?search=جراحة القلب');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $articleA->id);
    }

    public function test_search_escapes_like_wildcards(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $articleA = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => '100% Guaranteed Cure',
        ]);

        $articleB = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Regular Medical Article',
        ]);

        $response = $this->getJson('/api/v1/admin/news?search=100%');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $articleA->id);
    }

    public function test_category_filter_matches_leaf_category(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $articleHealth = NewsArticle::factory()->create(['category_id' => $this->leafCategory->id]);
        $articleFood = NewsArticle::factory()->create(['category_id' => $this->otherLeafCategory->id]);

        $response = $this->getJson("/api/v1/admin/news?category_id={$this->leafCategory->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $articleHealth->id);
    }

    public function test_category_filter_parent_includes_all_its_children(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        // Create article assigned to a child of Entertainment (Celebrities)
        $articleInChild = NewsArticle::factory()->create(['category_id' => $this->childCategory->id]);

        // Create article assigned to an unrelated category (Health)
        $articleInOther = NewsArticle::factory()->create(['category_id' => $this->leafCategory->id]);

        $response = $this->getJson("/api/v1/admin/news?category_id={$this->parentCategory->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $articleInChild->id);
    }

    public function test_status_filter_works_for_draft_published_and_unpublished(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $draft = NewsArticle::factory()->draft()->create(['category_id' => $this->leafCategory->id]);
        $published = NewsArticle::factory()->published()->create(['category_id' => $this->leafCategory->id]);
        $unpublished = NewsArticle::factory()->unpublished()->create(['category_id' => $this->leafCategory->id]);

        // Draft
        $this->getJson('/api/v1/admin/news?status=draft')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $draft->id);

        // Published
        $this->getJson('/api/v1/admin/news?status=published')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id);

        // Unpublished
        $this->getJson('/api/v1/admin/news?status=unpublished')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unpublished->id);

        // Invalid status returns 422
        $this->getJson('/api/v1/admin/news?status=invalid_status')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_search_and_filters_combined_do_not_bypass_category_or_status(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        // Matches search + matches category + matches status => MUST APPEAR
        $target = NewsArticle::factory()->draft()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Target Search Match Article',
        ]);

        // Matches search, but wrong category => MUST NOT APPEAR
        $wrongCategory = NewsArticle::factory()->draft()->create([
            'category_id' => $this->otherLeafCategory->id,
            'title_en' => 'Target Search Match In Other Category',
        ]);

        // Matches search and category, but wrong status => MUST NOT APPEAR
        $wrongStatus = NewsArticle::factory()->published()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Target Search Match But Published',
        ]);

        $response = $this->getJson("/api/v1/admin/news?search=Target&category_id={$this->leafCategory->id}&status=draft");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id);
    }

    // =========================================================================
    // 6. Localization & Accept-Language
    // =========================================================================

    public function test_resource_honors_accept_language_header(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $article = NewsArticle::factory()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'English Heading',
            'title_ar' => 'العنوان بالعربية',
            'summary_en' => 'English Brief',
            'summary_ar' => 'الموجز بالعربية',
        ]);

        // Arabic
        $arResponse = $this->withHeader('Accept-Language', 'ar')
            ->getJson("/api/v1/admin/news/{$article->id}");

        $arResponse->assertStatus(200)
            ->assertJsonPath('data.title', 'العنوان بالعربية')
            ->assertJsonPath('data.summary', 'الموجز بالعربية')
            ->assertJsonPath('data.title_en', 'English Heading')
            ->assertJsonPath('data.title_ar', 'العنوان بالعربية');

        // English
        $enResponse = $this->withHeader('Accept-Language', 'en')
            ->getJson("/api/v1/admin/news/{$article->id}");

        $enResponse->assertStatus(200)
            ->assertJsonPath('data.title', 'English Heading')
            ->assertJsonPath('data.summary', 'English Brief')
            ->assertJsonPath('data.title_en', 'English Heading')
            ->assertJsonPath('data.title_ar', 'العنوان بالعربية');
    }
}
