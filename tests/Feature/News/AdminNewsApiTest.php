<?php

namespace Tests\Feature\News;

use App\Models\Category;
use App\Models\News;
use App\Models\NewsImage;
use App\Models\User;
use App\Services\News\NewsImageService;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminNewsApiTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_1X1_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private const FIXTURE_1X1_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const FIXTURE_1X1_WEBP_BASE64 = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=';

    private User $superAdmin;

    private User $admin;

    private User $editor;

    private Category $healthCategory;

    private Category $sportsCategory;

    private Category $entertainmentParent;

    private Category $celebritiesChild;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);

        $this->superAdmin = User::factory()->superAdmin()->create([
            'email' => User::DESIGNATED_SUPER_ADMIN_EMAIL,
        ]);
        $this->admin = User::factory()->admin()->create();
        $this->editor = User::factory()->editor()->create();

        $this->healthCategory = Category::where('type', 'news')->where('slug', 'health')->firstOrFail();
        $this->sportsCategory = Category::where('type', 'news')->where('slug', 'sports')->firstOrFail();
        $this->entertainmentParent = Category::where('type', 'news')->where('slug', 'entertainment')->firstOrFail();
        $this->celebritiesChild = Category::where('type', 'news')->where('slug', 'celebrities')->firstOrFail();

        Storage::fake('r2');
        Config::set('filesystems.disks.r2.url', 'https://pub-r2.waai.test');
    }

    private function fakeWebp(string $name = 'image.webp'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_WEBP_BASE64));
    }

    private function fakePng(string $name = 'image.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_PNG_BASE64));
    }

    private function uploadR2Image(string $type = 'featured', ?User $uploader = null): NewsImage
    {
        $service = app(NewsImageService::class);
        $dir = $type === 'featured' ? NewsImageService::COVERS_DIRECTORY : NewsImageService::EDITOR_DIRECTORY;
        $userId = $uploader ? $uploader->id : $this->admin->id;

        return $service->uploadAndCreate($this->fakeWebp(), $dir, $userId);
    }

    // =========================================================================
    // 1. Authentication & Role Authorization
    // =========================================================================

    public function test_unauthenticated_requests_are_denied(): void
    {
        $news = News::factory()->draft()->create(['category_id' => $this->healthCategory->id]);

        $this->getJson('/api/v1/admin/news')->assertStatus(401);
        $this->postJson('/api/v1/admin/news', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/news/images', [])->assertStatus(401);
        $this->getJson("/api/v1/admin/news/{$news->id}")->assertStatus(401);
        $this->patchJson("/api/v1/admin/news/{$news->id}", [])->assertStatus(401);
        $this->postJson("/api/v1/admin/news/{$news->id}/publish", [])->assertStatus(401);
        $this->postJson("/api/v1/admin/news/{$news->id}/unpublish", [])->assertStatus(401);
        $this->deleteJson("/api/v1/admin/news/{$news->id}")->assertStatus(401);
    }

    public function test_super_admin_admin_and_editor_permissions_and_deletion_enforcement(): void
    {
        $featured = $this->uploadR2Image('featured', $this->editor);

        // 1. Editor can view, create draft, edit, publish, and unpublish, but CANNOT delete
        Sanctum::actingAs($this->editor, ['access']);

        $createRes = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Editor Draft News',
        ]);
        $createRes->assertStatus(201);
        $newsId = $createRes->json('data.id');

        $this->getJson('/api/v1/admin/news')->assertStatus(200);
        $this->getJson("/api/v1/admin/news/{$newsId}")->assertStatus(200);

        $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'title_ar' => 'خبر المحرر',
            'author_name_ar' => 'محرر وعي',
            'author_name_en' => 'Waai Editor',
            'short_description_ar' => 'ملخص الخبر',
            'short_description_en' => 'Short summary',
            'content_ar' => '<p>محتوى الخبر بالعربية</p>',
            'content_en' => '<p>News content in English</p>',
            'featured_image_id' => $featured->id,
        ])->assertStatus(200);

        $this->postJson("/api/v1/admin/news/{$newsId}/publish")->assertStatus(200)
            ->assertJsonPath('data.status', 'published');

        $this->postJson("/api/v1/admin/news/{$newsId}/unpublish")->assertStatus(200)
            ->assertJsonPath('data.status', 'draft');

        // Editor delete attempt -> 403 Forbidden
        $this->deleteJson("/api/v1/admin/news/{$newsId}")->assertStatus(403);
        $this->assertDatabaseHas('news', ['id' => $newsId]);

        // 2. Admin can permanently delete
        Sanctum::actingAs($this->admin, ['access']);
        $this->deleteJson("/api/v1/admin/news/{$newsId}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'News article permanently deleted successfully.');
        $this->assertDatabaseMissing('news', ['id' => $newsId]);

        // 3. Super Admin has full access to all News admin operations (list, show, upload image, create draft, create published, update, publish, unpublish, associate other user's unattached image, and permanently delete)
        $otherEditorImage = $this->uploadR2Image('featured', $this->editor);
        Sanctum::actingAs($this->superAdmin, ['access']);

        $uploadRes = $this->postJson('/api/v1/admin/news/images', [
            'image' => $this->fakeWebp('super-admin-image.webp'),
            'type' => 'featured',
        ])->assertStatus(201);
        $superUploadedId = $uploadRes->json('data.id');

        $superDraftRes = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Super Admin Draft News',
            'featured_image_id' => $otherEditorImage->id,
        ])->assertStatus(201);

        $superNewsId = $superDraftRes->json('data.id');

        $this->getJson('/api/v1/admin/news')->assertStatus(200);
        $this->getJson("/api/v1/admin/news/{$superNewsId}")->assertStatus(200);

        $this->patchJson("/api/v1/admin/news/{$superNewsId}", [
            'title_ar' => 'خبر المدير العام',
            'author_name_ar' => 'الإدارة العليا',
            'author_name_en' => 'Super Admin Author',
            'short_description_ar' => 'ملخص المدير العام',
            'short_description_en' => 'Super Admin Summary',
            'content_ar' => '<p>محتوى المدير العام بالعربية</p>',
            'content_en' => '<p>Super Admin Content in English</p>',
            'featured_image_id' => $superUploadedId,
        ])->assertStatus(200);

        $this->postJson("/api/v1/admin/news/{$superNewsId}/publish")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'published');

        $this->postJson("/api/v1/admin/news/{$superNewsId}/unpublish")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'draft');

        $this->deleteJson("/api/v1/admin/news/{$superNewsId}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'News article permanently deleted successfully.');
        $this->assertDatabaseMissing('news', ['id' => $superNewsId]);
    }

    public function test_unauthorized_user_or_token_without_access_ability_is_forbidden_across_admin_news_endpoints(): void
    {
        $news = News::factory()->draft()->create(['category_id' => $this->healthCategory->id]);

        // 1. Authenticated user with a token lacking the 'access' ability (e.g., refresh token) -> 403 Forbidden
        Sanctum::actingAs($this->superAdmin, ['refresh']);

        $this->getJson('/api/v1/admin/news')->assertStatus(403);
        $this->postJson('/api/v1/admin/news', [])->assertStatus(403);
        $this->postJson('/api/v1/admin/news/images', [])->assertStatus(403);
        $this->getJson("/api/v1/admin/news/{$news->id}")->assertStatus(403);
        $this->patchJson("/api/v1/admin/news/{$news->id}", [])->assertStatus(403);
        $this->postJson("/api/v1/admin/news/{$news->id}/publish", [])->assertStatus(403);
        $this->postJson("/api/v1/admin/news/{$news->id}/unpublish", [])->assertStatus(403);
        $this->deleteJson("/api/v1/admin/news/{$news->id}")->assertStatus(403);

        // 2. Authenticated user whose in-memory role is not in [super_admin, admin, editor] -> 403 Forbidden
        $unauthorizedUser = User::factory()->editor()->create();
        $unauthorizedUser->role = 'viewer';
        Sanctum::actingAs($unauthorizedUser, ['access']);

        $this->getJson('/api/v1/admin/news')->assertStatus(403);
        $this->postJson('/api/v1/admin/news', ['category_id' => $this->healthCategory->id, 'title_en' => 'Blocked'])->assertStatus(403);
        $this->getJson("/api/v1/admin/news/{$news->id}")->assertStatus(403);
        $this->patchJson("/api/v1/admin/news/{$news->id}", ['title_en' => 'Blocked'])->assertStatus(403);
        $this->postJson("/api/v1/admin/news/{$news->id}/publish")->assertStatus(403);
        $this->postJson("/api/v1/admin/news/{$news->id}/unpublish")->assertStatus(403);
        $this->deleteJson("/api/v1/admin/news/{$news->id}")->assertStatus(403);
    }

    public function test_standardized_admin_news_resource_structure_and_excluded_redundant_fields(): void
    {
        Sanctum::actingAs($this->superAdmin, ['access']);

        // 1. Sparse draft response matches exact standardized contract
        $draftRes = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Waai Media Launches Regional Creative Summit',
            'short_description_en' => 'Initial draft summary for the upcoming creative summit.',
        ])->assertStatus(201);

        $data = $draftRes->json('data');

        $expectedKeys = [
            'id',
            'slug',
            'status',
            'title_ar',
            'title_en',
            'author_name_ar',
            'author_name_en',
            'short_description_ar',
            'short_description_en',
            'content_ar',
            'content_en',
            'category_id',
            'category',
            'featured_image_id',
            'featured_image_url',
            'editor_images',
            'published_at',
            'created_at',
            'updated_at',
        ];

        $this->assertSame($expectedKeys, array_keys($data));

        // Verify removed redundant fields are strictly absent while slug is present
        foreach (['title', 'author_name', 'short_description', 'category_name_ar', 'category_name_en', 'featured_image'] as $removedField) {
            $this->assertArrayNotHasKey($removedField, $data);
        }
        $this->assertSame("waai-media-launches-regional-creative-summit-{$data['id']}", $data['slug']);

        // Verify exact category structure for top-level leaf category
        $this->assertSame([
            'id' => $this->healthCategory->id,
            'name_ar' => 'الصحة',
            'name_en' => 'Health',
            'parent_id' => null,
            'parent' => null,
        ], $data['category']);

        $this->assertNull($data['title_ar']);
        $this->assertSame('Waai Media Launches Regional Creative Summit', $data['title_en']);
        $this->assertNull($data['author_name_ar']);
        $this->assertNull($data['author_name_en']);
        $this->assertNull($data['short_description_ar']);
        $this->assertSame('Initial draft summary for the upcoming creative summit.', $data['short_description_en']);
        $this->assertNull($data['content_ar']);
        $this->assertNull($data['content_en']);
        $this->assertNull($data['featured_image_id']);
        $this->assertNull($data['featured_image_url']);
        $this->assertSame([], $data['editor_images']);
        $this->assertNull($data['published_at']);

        // 2. Child category (Celebrities under Entertainment) has standardized parent structure without slug/name
        $childDraftRes = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->celebritiesChild->id,
            'title_ar' => 'خبر فني',
        ])->assertStatus(201);

        $this->assertSame([
            'id' => $this->celebritiesChild->id,
            'name_ar' => 'المشاهير',
            'name_en' => 'Celebrities',
            'parent_id' => $this->entertainmentParent->id,
            'parent' => [
                'id' => $this->entertainmentParent->id,
                'name_ar' => 'الترفيه',
                'name_en' => 'Entertainment',
            ],
        ], $childDraftRes->json('data.category'));
    }

    // =========================================================================
    // 2. Draft & Publish Validation (Arabic-only, English-only, Bilingual)
    // =========================================================================

    public function test_draft_accepts_arabic_only_title_or_english_only_title_and_rejects_neither(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        // Neither title -> 422
        $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['title']);

        // Arabic-only title -> 201 Created
        $arDraft = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_ar' => 'مسودة باللغة العربية فقط',
        ]);
        $arDraft->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.title_ar', 'مسودة باللغة العربية فقط')
            ->assertJsonPath('data.title_en', null)
            ->assertJsonPath('data.slug', null);

        // English-only title -> 201 Created with slug
        $enDraft = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'English Only Draft Article',
        ]);
        $enDraft->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.title_en', 'English Only Draft Article')
            ->assertJsonPath('data.title_ar', null);

        $enId = $enDraft->json('data.id');
        $this->assertSame("english-only-draft-article-{$enId}", $enDraft->json('data.slug'));
    }

    public function test_publishing_requires_all_bilingual_fields_and_featured_image(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        // Attempt to create directly as published with incomplete fields -> 422
        $res = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Incomplete Published Article',
            'status' => 'published',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors([
                'title_ar',
                'author_name_ar',
                'author_name_en',
                'short_description_ar',
                'short_description_en',
                'content_ar',
                'content_en',
                'featured_image',
            ]);

        // Now create with all mandatory bilingual fields and featured image -> 201 Published
        $featured = $this->uploadR2Image('featured');

        $pubRes = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->celebritiesChild->id,
            'title_ar' => 'أحدث أخبار المشاهير',
            'title_en' => 'Latest Celebrity News',
            'author_name_ar' => 'سارة المنصور',
            'author_name_en' => 'Sara Al-Mansour',
            'short_description_ar' => 'تغطية شاملة لأهم الفعاليات الفنية.',
            'short_description_en' => 'Comprehensive coverage of top artistic events.',
            'content_ar' => '<p>تفاصيل الخبر باللغة العربية.</p>',
            'content_en' => '<p>Full article details in English.</p>',
            'featured_image_id' => $featured->id,
            'action' => 'publish',
        ]);

        $pubRes->assertStatus(201)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.category.id', $this->celebritiesChild->id)
            ->assertJsonPath('data.category.name_ar', 'المشاهير')
            ->assertJsonPath('data.category.name_en', 'Celebrities')
            ->assertJsonPath('data.category.parent.id', $this->entertainmentParent->id)
            ->assertJsonPath('data.category.parent.name_en', 'Entertainment')
            ->assertJsonPath('data.category.parent.name_ar', 'الترفيه')
            ->assertJsonPath('data.featured_image_id', $featured->id);

        $this->assertNotNull($pubRes->json('data.published_at'));
    }

    // =========================================================================
    // 3. Category & Entertainment Parent/Child Restrictions
    // =========================================================================

    public function test_rejects_entertainment_parent_category_and_cross_module_categories(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        // 1. Entertainment parent is rejected
        $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->entertainmentParent->id,
            'title_en' => 'Direct Entertainment Parent Assignment',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);

        // 2. Entertainment child (Celebrities) is accepted
        $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->celebritiesChild->id,
            'title_en' => 'Valid Entertainment Subcategory Assignment',
        ])->assertStatus(201);

        // 3. Blogs category is rejected for News
        $blogCategory = Category::where('type', 'blogs')->firstOrFail();
        $this->postJson('/api/v1/admin/news', [
            'category_id' => $blogCategory->id,
            'title_en' => 'Cross Module Category Test',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    // =========================================================================
    // 4. Publication Timestamp Preservation & Saving Published Article as Draft
    // =========================================================================

    public function test_published_at_is_set_on_first_publication_and_preserved_across_edits_unpublish_and_republish(): void
    {
        Sanctum::actingAs($this->admin, ['access']);
        $featured = $this->uploadR2Image('featured');

        $this->travelTo(now()->subDays(5));

        $draftRes = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_ar' => 'الطب الحديث',
            'title_en' => 'Modern Medicine',
            'author_name_ar' => 'د. خالد',
            'author_name_en' => 'Dr. Khaled',
            'short_description_ar' => 'مقدمة قصيرة',
            'short_description_en' => 'Short intro',
            'content_ar' => '<p>محتوى عربي</p>',
            'content_en' => '<p>English content</p>',
            'featured_image_id' => $featured->id,
        ])->assertStatus(201);

        $newsId = $draftRes->json('data.id');
        $this->assertNull($draftRes->json('data.published_at'));

        // First publication at T - 3 days
        $firstPublishedTime = now()->addDays(2)->startOfSecond();
        $this->travelTo($firstPublishedTime);

        $pubRes = $this->postJson("/api/v1/admin/news/{$newsId}/publish")->assertStatus(200);
        $initialPublishedAt = $pubRes->json('data.published_at');
        $initialSlug = $pubRes->json('data.slug');
        $this->assertNotNull($initialPublishedAt);

        // Edit published article at T - 1 day (without changing status -> stays published & preserves published_at & slug)
        $this->travelTo($firstPublishedTime->copy()->addDays(2));

        $editRes = $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'title_en' => 'Modern Medicine Updated Title',
        ])->assertStatus(200);

        $editRes->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_at', $initialPublishedAt)
            ->assertJsonPath('data.slug', $initialSlug);

        // Save published article as a draft using action=draft on update -> preserves original published_at & slug
        $this->travelTo($firstPublishedTime->copy()->addDays(2)->addHours(4));
        $draftByActionRes = $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'action' => 'draft',
            'title_en' => 'Draft Via Action Update',
        ])->assertStatus(200);
        $draftByActionRes->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', $initialPublishedAt)
            ->assertJsonPath('data.slug', $initialSlug);

        // Republish via status=published on update -> preserves original published_at
        $this->travelTo($firstPublishedTime->copy()->addDays(2)->addHours(8));
        $repubByUpdateRes = $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'status' => 'published',
        ])->assertStatus(200);
        $repubByUpdateRes->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_at', $initialPublishedAt);

        // Save published article as a draft using status=draft on update -> preserves original published_at
        $this->travelTo($firstPublishedTime->copy()->addDays(2)->addHours(12));
        $draftByStatusRes = $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'status' => 'draft',
        ])->assertStatus(200);
        $draftByStatusRes->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', $initialPublishedAt);

        // Republish 1 day later via /publish -> preserves original published_at
        $this->travelTo($firstPublishedTime->copy()->addDays(3));
        $repubRes = $this->postJson("/api/v1/admin/news/{$newsId}/publish")->assertStatus(200);
        $repubRes->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_at', $initialPublishedAt);

        // Unpublish via /unpublish -> becomes draft, preserves original published_at
        $unpubRes = $this->postJson("/api/v1/admin/news/{$newsId}/unpublish")->assertStatus(200);
        $unpubRes->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', $initialPublishedAt);
    }

    // =========================================================================
    // 5. Search (Including Literal Wildcard Characters %, _, !), Filters & Pagination
    // =========================================================================

    public function test_admin_listing_supports_pagination_search_category_and_status_filters(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        News::factory()->count(8)->draft()->create([
            'category_id' => $this->healthCategory->id,
            'title_en' => 'General Health Update',
        ]);

        $targetPublished = News::factory()->published()->create([
            'category_id' => $this->celebritiesChild->id,
            'title_en' => 'Exclusive Cinema Festival Report',
            'title_ar' => 'تقرير حصري عن مهرجان السينما',
        ]);

        $targetDraft = News::factory()->draft()->create([
            'category_id' => $this->sportsCategory->id,
            'title_en' => 'Championship Finals Preview',
        ]);

        // Default pagination (10 per page)
        $listRes = $this->getJson('/api/v1/admin/news')->assertStatus(200);
        $listRes->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 10);

        // Case-insensitive search (English)
        $searchEn = $this->getJson('/api/v1/admin/news?search=cInEmA fEsTiVaL')->assertStatus(200);
        $searchEn->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $targetPublished->id);

        // Search (Arabic)
        $searchAr = $this->getJson('/api/v1/admin/news?search=مهرجان السينما')->assertStatus(200);
        $searchAr->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $targetPublished->id);

        // Filter by Entertainment parent category ID -> includes child category articles
        $catFilter = $this->getJson("/api/v1/admin/news?category_id={$this->entertainmentParent->id}")->assertStatus(200);
        $catFilter->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $targetPublished->id);

        // Filter by status: published, draft, all
        $this->getJson('/api/v1/admin/news?status=published')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/admin/news?status=draft')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 9);

        $this->getJson('/api/v1/admin/news?status=all')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 10);
    }

    public function test_admin_search_treats_percent_underscore_and_exclamation_characters_literally(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $percentArticle = News::factory()->draft()->create([
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Market Surge 100% Growth',
            'title_ar' => 'نمو بنسبة 100% في القطاع',
        ]);

        $underscoreArticle = News::factory()->draft()->create([
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Special Q3_Report Analysis',
            'title_ar' => 'تقرير_الربع الثالث',
        ]);

        $exclamationArticle = News::factory()->draft()->create([
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Breaking! Urgent Update',
            'title_ar' => 'عاجل! تحديث فوري',
        ]);

        News::factory()->draft()->create([
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Market Surge 1000 Growth and Q3XReport',
            'title_ar' => 'نمو بنسبة 1000 في القطاع وتقريرXالربع',
        ]);

        // 1. Literal '%' does not match '1000'
        $resPercent = $this->getJson('/api/v1/admin/news?search='.urlencode('100%'))->assertStatus(200);
        $resPercent->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $percentArticle->id);

        // 2. Literal '_' does not match 'Q3XReport'
        $resUnderscore = $this->getJson('/api/v1/admin/news?search='.urlencode('Q3_Report'))->assertStatus(200);
        $resUnderscore->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $underscoreArticle->id);

        // 3. Literal '!' (the ESCAPE character itself) is escaped properly and matches only 'Breaking!'
        $resExclamation = $this->getJson('/api/v1/admin/news?search='.urlencode('Breaking!'))->assertStatus(200);
        $resExclamation->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $exclamationArticle->id);
    }

    // =========================================================================
    // 6. R2 Image Upload, Editor References, Replacement & Shared Image Protection
    // =========================================================================

    public function test_image_upload_endpoint_returns_id_and_url_and_persists_metadata(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $res = $this->postJson('/api/v1/admin/news/images', [
            'image' => $this->fakePng('editor-photo.png'),
            'type' => 'editor',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'url'],
            ]);

        $imageId = $res->json('data.id');
        $imageUrl = $res->json('data.url');

        $imageRecord = NewsImage::findOrFail($imageId);
        $this->assertStringStartsWith('news/editor/', $imageRecord->storage_key);
        $this->assertSame('image/png', $imageRecord->mime_type);
        $this->assertSame($this->editor->id, $imageRecord->uploaded_by);
        $this->assertSame("https://pub-r2.waai.test/{$imageRecord->storage_key}", $imageUrl);
        Storage::disk('r2')->assertExists($imageRecord->storage_key);
    }

    public function test_replacing_featured_image_and_removing_editor_image_cleans_up_unshared_objects_after_commit(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $cover1 = $this->uploadR2Image('featured');
        $cover2 = $this->uploadR2Image('featured');
        $editorImg1 = $this->uploadR2Image('editor');
        $editorImg2 = $this->uploadR2Image('editor');

        $createRes = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Article With Embedded Images',
            'featured_image_id' => $cover1->id,
            'content_en' => sprintf(
                '<p>Intro</p><img data-image-id="%d" src="https://pub-r2.waai.test/%s" /><img data-image-id="%d" src="https://pub-r2.waai.test/%s" />',
                $editorImg1->id,
                $editorImg1->storage_key,
                $editorImg2->id,
                $editorImg2->storage_key
            ),
            'content_ar' => sprintf(
                '<p>مقدمة</p><img data-image-id="%d" src="https://pub-r2.waai.test/%s" />',
                $editorImg1->id,
                $editorImg1->storage_key
            ),
        ])->assertStatus(201);

        $newsId = $createRes->json('data.id');
        $this->assertCount(2, $createRes->json('data.editor_images'));

        // Now update article: replace cover1 with cover2, and remove editorImg2 from content_en (keeping editorImg1)
        $updateRes = $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'featured_image_id' => $cover2->id,
            'content_en' => sprintf(
                '<p>Updated</p><img data-image-id="%d" src="https://pub-r2.waai.test/%s" />',
                $editorImg1->id,
                $editorImg1->storage_key
            ),
        ])->assertStatus(200);

        $updateRes->assertJsonPath('data.featured_image_id', $cover2->id);
        $this->assertCount(1, $updateRes->json('data.editor_images'));

        // Superseded cover1 and removed editorImg2 should be deleted from R2 and DB
        Storage::disk('r2')->assertMissing($cover1->storage_key);
        Storage::disk('r2')->assertMissing($editorImg2->storage_key);
        $this->assertDatabaseMissing('news_images', ['id' => $cover1->id]);
        $this->assertDatabaseMissing('news_images', ['id' => $editorImg2->id]);

        // Active cover2 and editorImg1 remain intact
        Storage::disk('r2')->assertExists($cover2->storage_key);
        Storage::disk('r2')->assertExists($editorImg1->storage_key);
    }

    public function test_shared_images_across_articles_are_never_deleted_when_one_article_is_updated_or_deleted(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $sharedCover = $this->uploadR2Image('featured');
        $sharedEditor = $this->uploadR2Image('editor');

        $html = sprintf('<p>Shared</p><img data-image-id="%d" src="https://pub-r2.waai.test/%s" />', $sharedEditor->id, $sharedEditor->storage_key);

        $art1 = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Article One',
            'featured_image_id' => $sharedCover->id,
            'content_en' => $html,
        ])->assertStatus(201)->json('data.id');

        $art2 = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Article Two',
            'featured_image_id' => $sharedCover->id,
            'content_en' => $html,
        ])->assertStatus(201)->json('data.id');

        // Delete Article One
        $this->deleteJson("/api/v1/admin/news/{$art1}")->assertStatus(200);

        // Shared images must still exist because Article Two references them
        Storage::disk('r2')->assertExists($sharedCover->storage_key);
        Storage::disk('r2')->assertExists($sharedEditor->storage_key);
        $this->assertDatabaseHas('news_images', ['id' => $sharedCover->id]);
        $this->assertDatabaseHas('news_images', ['id' => $sharedEditor->id]);

        // Now delete Article Two -> shared images are now unreferenced and deleted
        $this->deleteJson("/api/v1/admin/news/{$art2}")->assertStatus(200);
        Storage::disk('r2')->assertMissing($sharedCover->storage_key);
        Storage::disk('r2')->assertMissing($sharedEditor->storage_key);
    }

    // =========================================================================
    // 7. Image Ownership & Authorization Policy Enforcement
    // =========================================================================

    public function test_image_ownership_policy_blocks_editor_from_attaching_other_users_unattached_upload_but_allows_shared_same_article_and_admin_override(): void
    {
        $otherEditor = User::factory()->editor()->create();

        // Unattached images uploaded by $otherEditor
        $unattachedCoverByOther = $this->uploadR2Image('featured', $otherEditor);
        $unattachedEditorByOther = $this->uploadR2Image('editor', $otherEditor);

        // Unattached images uploaded by $this->editor
        $ownCover = $this->uploadR2Image('featured', $this->editor);
        $ownEditor = $this->uploadR2Image('editor', $this->editor);

        Sanctum::actingAs($this->editor, ['access']);

        // 1. Editor CANNOT associate another user's unattached featured_image_id on store
        $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Unauthorized Featured Image Attempt',
            'featured_image_id' => $unattachedCoverByOther->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['featured_image_id']);

        // 2. Editor CANNOT associate another user's unattached editor image in content_en or content_ar on store
        $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Unauthorized Content En Image Attempt',
            'content_en' => sprintf('<p>Test</p><img data-image-id="%d" />', $unattachedEditorByOther->id),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['content_en']);

        $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Unauthorized Content Ar Image Attempt',
            'content_ar' => sprintf('<p>اختبار</p><img data-image-id="%d" />', $unattachedEditorByOther->id),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['content_ar']);

        // 3. Editor CAN associate their own unattached uploads
        $ownArticleRes = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Editor Own Upload Article',
            'title_ar' => 'مقال المحرر',
            'author_name_en' => 'Editor',
            'author_name_ar' => 'محرر',
            'short_description_en' => 'Desc',
            'short_description_ar' => 'وصف',
            'featured_image_id' => $ownCover->id,
            'content_en' => sprintf('<p>Body</p><img data-image-id="%d" />', $ownEditor->id),
            'content_ar' => '<p>محتوى</p>',
        ])->assertStatus(201);

        $ownArticleId = $ownArticleRes->json('data.id');

        // 4. Editor CANNOT swap to another user's unattached image on update or publish
        $this->patchJson("/api/v1/admin/news/{$ownArticleId}", [
            'featured_image_id' => $unattachedCoverByOther->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['featured_image_id']);

        $this->postJson("/api/v1/admin/news/{$ownArticleId}/publish", [
            'featured_image_id' => $unattachedCoverByOther->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['featured_image_id']);

        // 5. Admin CAN associate $otherEditor's unattached upload (Admin override)
        Sanctum::actingAs($this->admin, ['access']);
        $adminArticleRes = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Admin Override Article',
            'featured_image_id' => $unattachedCoverByOther->id,
            'content_en' => sprintf('<p>Admin body</p><img data-image-id="%d" />', $unattachedEditorByOther->id),
        ])->assertStatus(201);

        $adminArticleId = $adminArticleRes->json('data.id');

        // 6. Once $unattachedCoverByOther is attached to $adminArticleId, it is now a shared article image:
        //    $this->editor CAN edit $adminArticleId keeping its attached images, and CAN reuse shared images across articles
        Sanctum::actingAs($this->editor, ['access']);

        $this->patchJson("/api/v1/admin/news/{$adminArticleId}", [
            'title_en' => 'Editor Updating Admin Article Preserving Attached Images',
        ])->assertStatus(200)
            ->assertJsonPath('data.featured_image_id', $unattachedCoverByOther->id);

        $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Editor Reusing Shared Library Image',
            'featured_image_id' => $unattachedCoverByOther->id,
        ])->assertStatus(201);
    }

    // =========================================================================
    // 8. Featured Image ID/URL Consistency Across Publish, Update, Clear & Replace
    // =========================================================================

    public function test_featured_image_id_and_url_remain_consistent_across_explicit_null_omitted_and_replacement_flows(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $cover1 = $this->uploadR2Image('featured');
        $cover2 = $this->uploadR2Image('featured');

        $draftRes = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_ar' => 'عنوان الخبر',
            'title_en' => 'Consistent Image News',
            'author_name_ar' => 'الكاتب',
            'author_name_en' => 'The Author',
            'short_description_ar' => 'ملخص',
            'short_description_en' => 'Summary',
            'content_ar' => '<p>محتوى عربي</p>',
            'content_en' => '<p>English content</p>',
            'featured_image_id' => $cover1->id,
        ])->assertStatus(201);

        $newsId = $draftRes->json('data.id');
        $this->assertDatabaseHas('news', [
            'id' => $newsId,
            'featured_image_id' => $cover1->id,
            'featured_image_url' => $cover1->storage_key,
        ]);

        // 1. Explicitly sending featured_image_id: null during publish MUST fail validation (422)
        //    and MUST NOT leave a null ID with a stale URL in the database.
        $this->postJson("/api/v1/admin/news/{$newsId}/publish", [
            'featured_image_id' => null,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['featured_image']);

        $this->assertDatabaseHas('news', [
            'id' => $newsId,
            'status' => 'draft',
            'featured_image_id' => $cover1->id,
            'featured_image_url' => $cover1->storage_key,
        ]);

        // 2. Omitting featured_image_id during publish uses the existing featured image and keeps ID + URL consistent
        $pubRes = $this->postJson("/api/v1/admin/news/{$newsId}/publish")->assertStatus(200);
        $pubRes->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.featured_image_id', $cover1->id)
            ->assertJsonPath('data.featured_image_url', "https://pub-r2.waai.test/{$cover1->storage_key}");

        // 3. Explicitly sending featured_image_id: null on a published article via update fails with 422
        $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'featured_image_id' => null,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['featured_image']);

        $this->assertDatabaseHas('news', [
            'id' => $newsId,
            'featured_image_id' => $cover1->id,
            'featured_image_url' => $cover1->storage_key,
        ]);

        // 4. Replacing featured_image_id with cover2 updates both featured_image_id and featured_image_url
        $replaceRes = $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'featured_image_id' => $cover2->id,
        ])->assertStatus(200);

        $replaceRes->assertJsonPath('data.featured_image_id', $cover2->id)
            ->assertJsonPath('data.featured_image_url', "https://pub-r2.waai.test/{$cover2->storage_key}");

        $this->assertDatabaseHas('news', [
            'id' => $newsId,
            'featured_image_id' => $cover2->id,
            'featured_image_url' => $cover2->storage_key,
        ]);

        // 5. Converting to draft and explicitly clearing featured_image_id: null clears BOTH ID and URL
        $clearRes = $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'status' => 'draft',
            'featured_image_id' => null,
        ])->assertStatus(200);

        $clearRes->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.featured_image_id', null)
            ->assertJsonPath('data.featured_image_url', null)
            ->assertJsonMissingPath('data.featured_image');

        $this->assertDatabaseHas('news', [
            'id' => $newsId,
            'featured_image_id' => null,
            'featured_image_url' => null,
        ]);
    }

    public function test_news_slug_lifecycle_and_edge_cases(): void
    {
        Sanctum::actingAs($this->editor, ['access']);
        $featured = $this->uploadR2Image('featured', $this->editor);

        // 1. Client-supplied slug is ignored on Store
        $storeRes = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Draft Initial Title',
            'slug' => 'malicious-client-supplied-slug',
        ])->assertStatus(201);

        $newsId = $storeRes->json('data.id');
        $expectedInitialSlug = "draft-initial-title-{$newsId}";

        $storeRes->assertJsonPath('data.slug', $expectedInitialSlug);
        $this->assertDatabaseHas('news', [
            'id' => $newsId,
            'slug' => $expectedInitialSlug,
        ]);
        $this->assertDatabaseMissing('news', ['slug' => 'malicious-client-supplied-slug']);

        // 2. Draft title update regenerates slug with same ID suffix
        $updateTitleRes = $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'title_en' => 'Draft Revised Title',
            'slug' => 'another-injected-slug', // client attempt to override on update
        ])->assertStatus(200);

        $expectedUpdatedSlug = "draft-revised-title-{$newsId}";
        $updateTitleRes->assertJsonPath('data.slug', $expectedUpdatedSlug);
        $this->assertDatabaseHas('news', [
            'id' => $newsId,
            'slug' => $expectedUpdatedSlug,
        ]);
        $this->assertDatabaseMissing('news', ['slug' => 'another-injected-slug']);

        // 3. Draft update without title_en preserves existing slug
        $updateOtherFieldRes = $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'short_description_en' => 'Updated summary only without title',
        ])->assertStatus(200);

        $updateOtherFieldRes->assertJsonPath('data.slug', $expectedUpdatedSlug);
        $this->assertDatabaseHas('news', [
            'id' => $newsId,
            'slug' => $expectedUpdatedSlug,
        ]);

        // 4. Maximum length tests (250 and 255 character titles) and database persistence
        $longTitle250 = str_repeat('a', 250);
        $res250 = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => $longTitle250,
        ])->assertStatus(201);

        $id250 = $res250->json('data.id');
        $slug250 = $res250->json('data.slug');
        $this->assertLessThanOrEqual(255, strlen($slug250));
        $this->assertStringEndsWith("-{$id250}", $slug250);
        $this->assertStringNotContainsString("--{$id250}", $slug250);
        $this->assertDatabaseHas('news', ['id' => $id250, 'slug' => $slug250]);

        $longTitle255 = str_repeat('b', 255);
        $res255 = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => $longTitle255,
        ])->assertStatus(201);

        $id255 = $res255->json('data.id');
        $slug255 = $res255->json('data.slug');
        $this->assertSame(255, strlen($slug255));
        $this->assertStringEndsWith("-{$id255}", $slug255);
        $this->assertStringNotContainsString("--{$id255}", $slug255);
        $this->assertDatabaseHas('news', ['id' => $id255, 'slug' => $slug255]);

        // Updating a draft to a 255-char title preserves safety and persists in DB
        $updateDraftLong = $this->patchJson("/api/v1/admin/news/{$newsId}", [
            'title_en' => str_repeat('c', 255),
        ])->assertStatus(200);

        $updatedLongSlug = $updateDraftLong->json('data.slug');
        $this->assertSame(255, strlen($updatedLongSlug));
        $this->assertStringEndsWith("-{$newsId}", $updatedLongSlug);
        $this->assertDatabaseHas('news', ['id' => $newsId, 'slug' => $updatedLongSlug]);

        // 5. Published slug permanence across title changes, unpublish, and republish
        $publishable = $this->postJson('/api/v1/admin/news', [
            'category_id' => $this->healthCategory->id,
            'title_en' => 'Stable News Article',
            'title_ar' => 'خبر ثابت العنوان',
            'author_name_en' => 'Editorial Staff',
            'author_name_ar' => 'هيئة التحرير',
            'short_description_en' => 'Short summary for stability test',
            'short_description_ar' => 'ملخص قصير لاختبار الثبات',
            'content_en' => '<p>Article body for stability test</p>',
            'content_ar' => '<p>نص الخبر لاختبار الثبات</p>',
            'featured_image_id' => $featured->id,
        ])->assertStatus(201);

        $publishableId = $publishable->json('data.id');
        $initialPublishedSlug = "stable-news-article-{$publishableId}";
        $publishable->assertJsonPath('data.slug', $initialPublishedSlug);

        // Publish the article
        $this->postJson("/api/v1/admin/news/{$publishableId}/publish")->assertStatus(200)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.slug', $initialPublishedSlug);

        // Update English title on published article -> slug MUST remain unchanged
        $editPublishedRes = $this->patchJson("/api/v1/admin/news/{$publishableId}", [
            'title_en' => 'Completely Different Published Title',
        ])->assertStatus(200);

        $editPublishedRes->assertJsonPath('data.slug', $initialPublishedSlug);
        $this->assertDatabaseHas('news', [
            'id' => $publishableId,
            'slug' => $initialPublishedSlug,
            'title_en' => 'Completely Different Published Title',
        ]);

        // Unpublish the article back to draft -> slug MUST remain unchanged
        $unpublishRes = $this->postJson("/api/v1/admin/news/{$publishableId}/unpublish")->assertStatus(200)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.slug', $initialPublishedSlug);

        // Edit title while temporarily unpublished (hasBeenPublished is still true because published_at is not null)
        $editUnpublishedRes = $this->patchJson("/api/v1/admin/news/{$publishableId}", [
            'title_en' => 'Another Altered Title While In Draft',
        ])->assertStatus(200);

        $editUnpublishedRes->assertJsonPath('data.slug', $initialPublishedSlug);

        // Re-publish the article -> slug MUST remain unchanged
        $republishRes = $this->postJson("/api/v1/admin/news/{$publishableId}/publish")->assertStatus(200)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.slug', $initialPublishedSlug);

        $this->assertDatabaseHas('news', [
            'id' => $publishableId,
            'slug' => $initialPublishedSlug,
            'status' => 'published',
        ]);
    }
}
