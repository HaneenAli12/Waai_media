<?php

namespace Tests\Feature\Portfolio;

use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Portfolio\Concerns\PortfolioTestHelpers;
use Tests\TestCase;

class PortfolioEnhancementsTest extends TestCase
{
    use PortfolioTestHelpers;
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPortfolio();
        $this->admin = User::factory()->admin()->create();
        $this->editor = User::factory()->editor()->create();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user, ['access']);

        return $this;
    }

    private function create(array $data): TestResponse
    {
        if (($data['status'] ?? null) === 'published' && ! isset($data['cover_image'])) {
            $data['cover_image'] = $this->jpeg('cover.jpg');
        }

        return $this->post(self::ADMIN, $data, self::JSON_HEADERS);
    }

    private function update(PortfolioItem $item, array $data): TestResponse
    {
        return $this->post(self::ADMIN."/{$item->id}", ['_method' => 'PATCH'] + $data, self::JSON_HEADERS);
    }

    /**
     * @return list<array{image: UploadedFile}>
     */
    private function images(int $count): array
    {
        return array_map(fn ($i) => ['image' => $i % 2 ? $this->png() : $this->jpeg()], range(1, $count));
    }

    // =========================================================================
    // 1. Slug Tests
    // =========================================================================

    public function test_create_portfolio_item_generates_slug_from_title_en(): void
    {
        $this->as($this->editor);
        $client = Client::factory()->create();

        $res = $this->create([
            'client_id' => $client->id,
            'category_id' => $this->cat('pr')->id,
            'subcategory_id' => $this->cat('media-covering')->id,
            'title_en' => 'Awesome Media Launch 2026',
        ])->assertStatus(201);

        $id = $res->json('data.id');
        $slug = $res->json('data.slug');

        expect($slug)->toBe("awesome-media-launch-2026-{$id}");
        expect($res->json('data'))->toHaveKey('slug');
    }

    public function test_slug_is_unique_even_with_identical_english_titles(): void
    {
        $this->as($this->editor);
        $client = Client::factory()->create();

        $res1 = $this->create([
            'client_id' => $client->id,
            'category_id' => $this->cat('pr')->id,
            'subcategory_id' => $this->cat('media-covering')->id,
            'title_en' => 'Duplicate Title',
        ])->assertStatus(201);

        $res2 = $this->create([
            'client_id' => $client->id,
            'category_id' => $this->cat('pr')->id,
            'subcategory_id' => $this->cat('media-covering')->id,
            'title_en' => 'Duplicate Title',
        ])->assertStatus(201);

        $id1 = $res1->json('data.id');
        $id2 = $res2->json('data.id');

        expect($res1->json('data.slug'))->toBe("duplicate-title-{$id1}");
        expect($res2->json('data.slug'))->toBe("duplicate-title-{$id2}");
        expect($res1->json('data.slug'))->not->toBe($res2->json('data.slug'));
    }

    public function test_slug_appears_in_admin_and_public_responses_and_public_details_by_slug(): void
    {
        $this->as($this->editor);
        $client = Client::factory()->create();

        $item = $this->makeItem('branding', [['image' => true]], [
            'client_id' => $client->id,
            'title_en' => 'Public Brand Project',
        ], published: true);

        // Admin show
        $adminRes = $this->getJson(self::ADMIN."/{$item->id}")->assertStatus(200);
        expect($adminRes->json('data.slug'))->toBe("public-brand-project-{$item->id}");

        // Public list
        $publicList = $this->getJson(self::PUBLIC)->assertStatus(200);
        expect($publicList->json('data.0.slug'))->toBe("public-brand-project-{$item->id}");

        // Public details by numeric ID
        $publicShowById = $this->getJson(self::PUBLIC."/{$item->id}")->assertStatus(200);
        expect($publicShowById->json('data.slug'))->toBe("public-brand-project-{$item->id}");

        // Public details by SLUG
        $publicShowBySlug = $this->getJson(self::PUBLIC."/{$item->slug}")->assertStatus(200);
        expect($publicShowBySlug->json('data.id'))->toBe($item->id);
        expect($publicShowBySlug->json('data.slug'))->toBe("public-brand-project-{$item->id}");
    }

    public function test_slug_update_policy_mutable_in_draft_immutable_once_published(): void
    {
        $this->as($this->editor);
        $client = Client::factory()->create();

        // 1. Draft item: updating title_en regenerates slug
        $draft = $this->create([
            'client_id' => $client->id,
            'category_id' => $this->cat('pr')->id,
            'subcategory_id' => $this->cat('media-covering')->id,
            'title_en' => 'Draft Initial',
        ])->assertStatus(201)->json('data');

        $this->update(PortfolioItem::find($draft['id']), ['title_en' => 'Draft Renamed'])
            ->assertStatus(200)
            ->assertJsonPath('data.slug', "draft-renamed-{$draft['id']}");

        // 2. Publish the item
        $item = PortfolioItem::find($draft['id']);
        $item->title_ar = 'عنوان عربي';
        $item->cover_image_path = 'portfolio/images/'.fake()->uuid().'.jpg';
        $item->status = PortfolioItem::STATUS_PUBLISHED;
        $item->published_at = now();
        $item->save();
        $item->media()->create(['image_path' => 'portfolio/images/'.fake()->uuid().'.jpg', 'sort_order' => 0]);

        $publishedSlug = $item->fresh()->slug;
        expect($publishedSlug)->toBe("draft-renamed-{$draft['id']}");

        // 3. Updating title_en after publication preserves the permanent slug
        $this->update($item->fresh(), ['title_en' => 'Brand New Title After Publish'])
            ->assertStatus(200)
            ->assertJsonPath('data.title_en', 'Brand New Title After Publish')
            ->assertJsonPath('data.slug', $publishedSlug);
    }

    // =========================================================================
    // 2. Title Validation Tests
    // =========================================================================

    public function test_create_draft_without_title_en_fails_validation(): void
    {
        $this->as($this->editor);
        $client = Client::factory()->create();

        // missing title_en
        $this->create([
            'client_id' => $client->id,
            'category_id' => $this->cat('pr')->id,
            'subcategory_id' => $this->cat('media-covering')->id,
            'title_ar' => 'عنوان عربي فقط',
        ])->assertStatus(422)->assertJsonValidationErrors(['title_en']);

        // empty whitespace title_en
        $this->create([
            'client_id' => $client->id,
            'category_id' => $this->cat('pr')->id,
            'subcategory_id' => $this->cat('media-covering')->id,
            'title_en' => '   ',
            'title_ar' => 'عنوان عربي',
        ])->assertStatus(422)->assertJsonValidationErrors(['title_en']);
    }

    public function test_create_draft_with_valid_title_en_succeeds(): void
    {
        $this->as($this->editor);
        $client = Client::factory()->create();

        $this->create([
            'client_id' => $client->id,
            'category_id' => $this->cat('pr')->id,
            'subcategory_id' => $this->cat('media-covering')->id,
            'title_en' => 'English Title Only',
        ])->assertStatus(201)->assertJsonPath('data.title_en', 'English Title Only');
    }

    public function test_create_published_without_title_en_fails_validation(): void
    {
        $this->as($this->editor);
        $client = Client::factory()->create();

        $this->create($this->sel('branding', $client->id) + $this->withCover() + [
            'status' => 'published',
            'title_ar' => 'عنوان',
            'media' => $this->images(1),
        ])->assertStatus(422)->assertJsonValidationErrors(['title_en']);
    }

    public function test_updating_title_en_with_empty_or_whitespace_value_is_rejected(): void
    {
        $this->as($this->editor);
        $client = Client::factory()->create();

        $item = PortfolioItem::factory()->create([
            'client_id' => $client->id,
            'category_id' => $this->cat('pr')->id,
            'subcategory_id' => $this->cat('media-covering')->id,
            'title_en' => 'Original English',
        ]);

        $this->update($item, ['title_en' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['title_en']);

        $this->update($item, ['title_en' => '   '])
            ->assertStatus(422)->assertJsonValidationErrors(['title_en']);
    }

    public function test_update_without_sending_title_en_preserves_current_title(): void
    {
        $this->as($this->editor);
        $client = Client::factory()->create();

        $item = PortfolioItem::factory()->create([
            'client_id' => $client->id,
            'category_id' => $this->cat('pr')->id,
            'subcategory_id' => $this->cat('media-covering')->id,
            'title_en' => 'Original English',
            'title_ar' => 'أصلي',
        ]);

        $this->update($item, ['title_ar' => 'عربي معدل'])
            ->assertStatus(200)
            ->assertJsonPath('data.title_en', 'Original English')
            ->assertJsonPath('data.title_ar', 'عربي معدل');

        expect($item->fresh()->title_en)->toBe('Original English');
    }

    // =========================================================================
    // 3. Sorting & Latest Works Tests
    // =========================================================================

    public function test_public_listing_and_latest_works_order_newest_published_first_with_id_tiebreak(): void
    {
        $client = Client::factory()->create();
        $baseDate = now()->subDays(5);

        $oldest = $this->makeItem('branding', [['image' => true]], [
            'client_id' => $client->id,
            'title_en' => 'Oldest',
            'published_at' => $baseDate->copy()->subDays(2),
        ], published: true);

        // Same published_at date to test ID desc tiebreak
        $sameDate = $baseDate->copy();
        $tieLowId = $this->makeItem('branding', [['image' => true]], [
            'client_id' => $client->id,
            'title_en' => 'Tie Low',
            'published_at' => $sameDate,
        ], published: true);

        $tieHighId = $this->makeItem('branding', [['image' => true]], [
            'client_id' => $client->id,
            'title_en' => 'Tie High',
            'published_at' => $sameDate,
        ], published: true);

        $newest = $this->makeItem('branding', [['image' => true]], [
            'client_id' => $client->id,
            'title_en' => 'Newest',
            'published_at' => $baseDate->copy()->addDays(2),
        ], published: true);

        // Draft item should not appear
        $this->makeItem('branding', [['image' => true]], ['client_id' => $client->id], published: false);

        // 1. Public listing
        $res = $this->getJson(self::PUBLIC)->assertStatus(200);
        $ids = collect($res->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$newest->id, $tieHighId->id, $tieLowId->id, $oldest->id]);

        // 2. Latest Works (homepage: per_page=3)
        $latestRes = $this->getJson(self::PUBLIC.'?per_page=3&page=1')->assertStatus(200);
        $latestIds = collect($latestRes->json('data'))->pluck('id')->all();
        expect($latestIds)->toBe([$newest->id, $tieHighId->id, $tieLowId->id]);
    }

    // =========================================================================
    // 4. Same Client in Same Subcategory Tests
    // =========================================================================

    public function test_creating_multiple_items_for_same_client_in_same_subcategory_succeeds_with_unique_ids_and_slugs(): void
    {
        $this->as($this->editor);
        $client = Client::factory()->create();

        $item1 = $this->create([
            'client_id' => $client->id,
            'category_id' => $this->cat('social-media-campaign')->id,
            'subcategory_id' => $this->cat('graphic-design')->id,
            'title_en' => 'Graphic Project 1',
        ])->assertStatus(201)->json('data');

        $item2 = $this->create([
            'client_id' => $client->id,
            'category_id' => $this->cat('social-media-campaign')->id,
            'subcategory_id' => $this->cat('graphic-design')->id,
            'title_en' => 'Graphic Project 2',
        ])->assertStatus(201)->json('data');

        $item3 = $this->create([
            'client_id' => $client->id,
            'category_id' => $this->cat('social-media-campaign')->id,
            'subcategory_id' => $this->cat('graphic-design')->id,
            'title_en' => 'Graphic Project 3',
        ])->assertStatus(201)->json('data');

        expect($item1['id'])->not->toBe($item2['id']);
        expect($item2['id'])->not->toBe($item3['id']);
        expect($item1['slug'])->not->toBe($item2['slug']);
        expect($item2['slug'])->not->toBe($item3['slug']);

        expect(PortfolioItem::where('client_id', $client->id)->count())->toBe(3);
    }

    // =========================================================================
    // 5. Client Portfolio Pagination Tests
    // =========================================================================

    public function test_client_portfolio_pagination_returns_6_per_page_ordered_newest_first(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        // Create 13 published items for client A with descending published dates
        $base = now()->subDays(20);
        $itemsA = [];
        for ($i = 1; $i <= 13; $i++) {
            $itemsA[] = $this->makeItem('graphic-design', [['image' => true]], [
                'client_id' => $clientA->id,
                'title_en' => "Project A {$i}",
                'published_at' => $base->copy()->addDays($i),
            ], published: true);
        }

        // Create 2 items for client B (must NOT appear in client A results)
        $this->makeItem('graphic-design', [['image' => true]], [
            'client_id' => $clientB->id,
            'title_en' => 'Project B',
            'published_at' => now(),
        ], published: true);

        // Page 1: exactly 6 items, newest first
        $page1 = $this->getJson(self::PUBLIC."?client_id={$clientA->id}&page=1")->assertStatus(200);
        expect($page1->json('data'))->toHaveCount(6);
        expect($page1->json('meta.current_page'))->toBe(1);
        expect($page1->json('meta.per_page'))->toBe(6);
        expect($page1->json('meta.total'))->toBe(13);
        expect($page1->json('meta.last_page'))->toBe(3);

        // The first item of page 1 should be the newest (Project A 13)
        expect($page1->json('data.0.id'))->toBe($itemsA[12]->id);
        expect($page1->json('data.5.id'))->toBe($itemsA[7]->id);

        // Page 2: exactly 6 items
        $page2 = $this->getJson(self::PUBLIC."?client_id={$clientA->id}&page=2")->assertStatus(200);
        expect($page2->json('data'))->toHaveCount(6);
        expect($page2->json('meta.current_page'))->toBe(2);
        expect($page2->json('data.0.id'))->toBe($itemsA[6]->id);
        expect($page2->json('data.5.id'))->toBe($itemsA[1]->id);

        // Page 3: exactly 1 item (oldest: Project A 1)
        $page3 = $this->getJson(self::PUBLIC."?client_id={$clientA->id}&page=3")->assertStatus(200);
        expect($page3->json('data'))->toHaveCount(1);
        expect($page3->json('meta.current_page'))->toBe(3);
        expect($page3->json('data.0.id'))->toBe($itemsA[0]->id);

        // Ensure Client B is completely isolated
        $allIds = collect($page1->json('data'))
            ->merge($page2->json('data'))
            ->merge($page3->json('data'))
            ->pluck('client.id')
            ->unique()
            ->all();

        expect($allIds)->toBe([$clientA->id]);
    }

    public function test_client_portfolio_pagination_strictly_caps_per_page_to_six(): void
    {
        $client = Client::factory()->create();

        for ($i = 1; $i <= 10; $i++) {
            $this->makeItem('graphic-design', [['image' => true]], [
                'client_id' => $client->id,
                'title_en' => "Capped Project {$i}",
            ], published: true);
        }

        // Even if client passes per_page=10 or 20, it is strictly capped at 6
        $res = $this->getJson(self::PUBLIC."?client_id={$client->id}&per_page=10")->assertStatus(200);
        expect($res->json('data'))->toHaveCount(6);
        expect($res->json('meta.per_page'))->toBe(6);

        // Standard listing without client_id can still use its own per_page (e.g. 10)
        $standardRes = $this->getJson(self::PUBLIC.'?per_page=10')->assertStatus(200);
        expect($standardRes->json('data'))->toHaveCount(10);
        expect($standardRes->json('meta.per_page'))->toBe(10);
    }

    // =========================================================================
    // 6. Backfill Command Tests
    // =========================================================================

    public function test_backfill_command_generates_slugs_for_items_without_slug(): void
    {
        $client = Client::factory()->create();

        // Direct DB insertion simulating legacy rows with null slug
        $id1 = DB::table('portfolio_items')->insertGetId([
            'client_id' => $client->id,
            'category_id' => $this->cat('pr')->id,
            'subcategory_id' => $this->cat('media-covering')->id,
            'title_en' => 'Legacy Media Launch',
            'title_ar' => 'مشروع قديم',
            'slug' => null,
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id2 = DB::table('portfolio_items')->insertGetId([
            'client_id' => $client->id,
            'category_id' => $this->cat('pr')->id,
            'subcategory_id' => $this->cat('media-covering')->id,
            'title_en' => null, // legacy draft without title_en
            'title_ar' => 'مسودة قديمة بدون إنجليزي',
            'slug' => null,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $existingItem = PortfolioItem::factory()->create([
            'title_en' => 'Existing Intact Project',
        ]);
        $existingSlugBefore = $existingItem->slug;

        // Run backfill command
        $this->artisan('portfolio:backfill-slugs')
            ->expectsOutputToContain('Found 2 portfolio item(s) without a valid slug.')
            ->expectsOutputToContain('Backfill complete: 2 item(s) successfully updated')
            ->assertExitCode(0);

        // Verify newly backfilled items
        $item1Slug = DB::table('portfolio_items')->where('id', $id1)->value('slug');
        $item2Slug = DB::table('portfolio_items')->where('id', $id2)->value('slug');

        expect($item1Slug)->toBe("legacy-media-launch-{$id1}");
        expect($item2Slug)->toBe("portfolio-{$id2}");

        // Verify existing slugs were not touched
        expect($existingItem->fresh()->slug)->toBe($existingSlugBefore);

        // Verify command is idempotent (re-run does nothing and skips already processed)
        $this->artisan('portfolio:backfill-slugs')
            ->expectsOutputToContain('No portfolio items require a slug backfill.')
            ->assertExitCode(0);
    }
}
