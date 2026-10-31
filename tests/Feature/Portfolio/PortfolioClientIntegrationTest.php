<?php

namespace Tests\Feature\Portfolio;

use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\User;
use App\Services\Portfolio\PortfolioItemService;
use App\Services\Storage\ImageStorageService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Portfolio\Concerns\PortfolioTestHelpers;
use Tests\TestCase;

/**
 * Portfolio <-> Client relationship, slot uniqueness, drafts/publish rules, dedicated cover, public Portfolio
 * and Client-based browsing, and admin filters.
 */
class PortfolioClientIntegrationTest extends TestCase
{
    use PortfolioTestHelpers;
    use RefreshDatabase;

    private const CLIENTS = '/api/v1/portfolio/clients';

    private User $editor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPortfolio();
        $this->editor = User::factory()->editor()->create();
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->editor, ['access']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function create(array $data): TestResponse
    {
        return $this->post(self::ADMIN, $data, self::JSON_HEADERS);
    }

    private function patch_(PortfolioItem $item, array $data): TestResponse
    {
        return $this->post(self::ADMIN."/{$item->id}", ['_method' => 'PATCH'] + $data, self::JSON_HEADERS);
    }

    /**
     * A stored item of $client in the slot identified by $slug (subcategory slug, or web-design).
     */
    private function itemFor(Client $client, string $slug, bool $published = true, array $attributes = []): PortfolioItem
    {
        return $this->makeItem($slug, [['image' => true, 'url' => null]], ['client_id' => $client->id] + $attributes, $published);
    }

    // =========================================================================
    // Relationships and uniqueness
    // =========================================================================

    public function test_item_belongs_to_a_client_category_and_subcategory(): void
    {
        $client = Client::factory()->create();

        $response = $this->create($this->sel('branding', $client->id) + $this->titles())->assertStatus(201);

        $response->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.client.name', $client->name)
            ->assertJsonPath('data.category.slug', 'social-media-campaign')
            ->assertJsonPath('data.subcategory.slug', 'branding')
            ->assertJsonPath('data.status', 'draft');

        $item = PortfolioItem::findOrFail($response->json('data.id'));
        expect($item->client->is($client))->toBeTrue()
            ->and($item->category->slug)->toBe('social-media-campaign')
            ->and($item->subcategory->slug)->toBe('branding');
    }

    public function test_a_client_can_hold_several_subcategories_and_several_main_categories(): void
    {
        $client = Client::factory()->create();

        foreach (['branding', 'graphic-design', 'press-covering', 'events-image', 'opening-image', 'events-video', 'opening-video', 'videos', 'web-design'] as $slug) {
            $this->create($this->sel($slug, $client->id) + $this->titles())->assertStatus(201);
        }

        expect(PortfolioItem::where('client_id', $client->id)->count())->toBe(9);
    }

    public function test_the_same_subcategory_is_blocked_for_a_client_even_across_draft_and_published(): void
    {
        $client = Client::factory()->create();
        $first = $this->create($this->sel('branding', $client->id) + $this->titles())->assertStatus(201)->json('data.id');

        // draft vs draft
        $this->create($this->sel('branding', $client->id) + $this->titles())
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);

        // a published item also holds the slot
        $this->itemFor($client, 'graphic-design');
        $this->create($this->sel('graphic-design', $client->id) + $this->titles())
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);

        expect(PortfolioItem::where('client_id', $client->id)->count())->toBe(2)
            ->and(PortfolioItem::find($first))->not->toBeNull();
    }

    public function test_web_design_is_unique_per_client(): void
    {
        $client = Client::factory()->create();
        $this->create($this->sel('web-design', $client->id) + $this->titles())->assertStatus(201);

        $this->create($this->sel('web-design', $client->id) + $this->titles())
            ->assertStatus(422)->assertJsonValidationErrors(['category_id']);
    }

    public function test_different_clients_may_use_the_same_subcategory(): void
    {
        $this->create($this->sel('branding') + $this->titles())->assertStatus(201);
        $this->create($this->sel('branding') + $this->titles())->assertStatus(201);

        expect(PortfolioItem::count())->toBe(2);
    }

    public function test_events_image_and_opening_image_are_different_slots(): void
    {
        $client = Client::factory()->create();

        $this->create($this->sel('events-image', $client->id) + $this->titles())->assertStatus(201);
        $this->create($this->sel('opening-image', $client->id) + $this->titles())->assertStatus(201);
    }

    public function test_moving_an_item_into_an_occupied_slot_is_rejected_and_its_own_slot_is_fine(): void
    {
        $client = Client::factory()->create();
        $branding = $this->itemFor($client, 'branding', false);
        $graphic = $this->itemFor($client, 'graphic-design', false);

        // keeping the same assignment is not a conflict
        $this->patch_($branding, $this->selection('branding') + ['title_en' => 'Renamed'])->assertStatus(200);

        // the occupied slot
        $this->patch_($branding, $this->selection('graphic-design'))
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);
        expect($branding->fresh()->subcategory->slug)->toBe('branding');

        // changing the client onto a client that already owns the slot
        $other = Client::factory()->create();
        $this->itemFor($other, 'branding', false);
        $this->patch_($branding, ['client_id' => $other->id])
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);

        // moving to a free slot works (kind-compatible, so no media reset needed)
        $this->patch_($graphic, ['client_id' => Client::factory()->create()->id])->assertStatus(200);
    }

    public function test_database_unique_index_blocks_duplicates_including_null_subcategory(): void
    {
        $client = Client::factory()->create();
        $web = $this->cat('web-design');
        $branding = $this->cat('branding');

        PortfolioItem::factory()->create(['client_id' => $client->id, 'category_id' => $branding->parent_id, 'subcategory_id' => $branding->id]);
        expect(fn () => PortfolioItem::factory()->create(['client_id' => $client->id, 'category_id' => $branding->parent_id, 'subcategory_id' => $branding->id]))
            ->toThrow(UniqueConstraintViolationException::class);

        // a nullable subcategory must not allow duplicates (plain composite UNIQUE would)
        PortfolioItem::factory()->create(['client_id' => $client->id, 'category_id' => $web->id, 'subcategory_id' => null]);
        expect(fn () => PortfolioItem::factory()->create(['client_id' => $client->id, 'category_id' => $web->id, 'subcategory_id' => null]))
            ->toThrow(UniqueConstraintViolationException::class);

        // other clients are unaffected
        PortfolioItem::factory()->create(['client_id' => Client::factory(), 'category_id' => $web->id, 'subcategory_id' => null]);
    }

    public function test_a_concurrent_duplicate_that_passes_the_application_check_is_caught_by_the_database(): void
    {
        $client = Client::factory()->create();
        $branding = $this->cat('branding');
        $fields = ['client_id' => $client->id, 'category_id' => $branding->parent_id, 'subcategory_id' => $branding->id, 'title_en' => 'A'];

        // the competing request committed after this one validated: the service goes straight to the insert
        PortfolioItem::factory()->create($fields);

        try {
            app(PortfolioItemService::class)->save(null, ['fields' => $fields]);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('subcategory_id');
        }

        expect(PortfolioItem::where('client_id', $client->id)->count())->toBe(1);
    }

    public function test_an_unpublished_item_still_reserves_its_slot(): void
    {
        $client = Client::factory()->create();
        $item = $this->itemFor($client, 'branding', true);

        $this->postJson(self::ADMIN."/{$item->id}/unpublish")->assertStatus(200)->assertJsonPath('data.status', 'draft');

        $this->create($this->sel('branding', $client->id) + $this->titles())->assertStatus(422);
    }

    public function test_deleting_a_client_deletes_its_portfolio_items_media_covers_and_logo(): void
    {
        $client = Client::factory()->create();
        $logo = 'clients/logos/'.fake()->uuid().'.jpg';
        Storage::disk('r2')->put($logo, 'logo');
        $client->forceFill(['logo_path' => $logo])->save();

        $brand = $this->itemFor($client, 'branding');
        $press = $this->makeItem('press-covering', [['image' => true, 'url' => 'https://a.test'], ['image' => true, 'url' => 'https://b.test']], ['client_id' => $client->id], true);
        $draft = $this->itemFor($client, 'videos', false);
        $keys = array_merge(
            [$brand->cover_image_path, $press->cover_image_path, $draft->cover_image_path],
            PortfolioMedia::whereIn('portfolio_item_id', [$brand->id, $press->id, $draft->id])->whereNotNull('image_path')->pluck('image_path')->all()
        );
        expect($keys)->toHaveCount(7);

        $keepClient = Client::factory()->create();
        $kept = $this->itemFor($keepClient, 'branding');
        $keptKeys = array_merge([$kept->cover_image_path], $kept->media->pluck('image_path')->all());

        Sanctum::actingAs($this->admin, ['access']);
        $this->deleteJson("/api/v1/admin/clients/{$client->id}")->assertStatus(200)->assertJsonPath('message', 'Client deleted successfully.');

        expect(Client::find($client->id))->toBeNull()
            ->and(PortfolioItem::where('client_id', $client->id)->count())->toBe(0)
            ->and(PortfolioMedia::whereIn('portfolio_item_id', [$brand->id, $press->id, $draft->id])->count())->toBe(0);
        Storage::disk('r2')->assertMissing(array_merge($keys, [$logo]));

        // another client's data is untouched
        expect(PortfolioItem::find($kept->id))->not->toBeNull();
        Storage::disk('r2')->assertExists($keptKeys);
        Storage::disk('r2')->assertExists($keptKeys);

        // deleted and draft items are not exposed publicly
        foreach ([$brand, $press, $draft] as $gone) {
            $this->getJson(self::PUBLIC."/{$gone->id}")->assertStatus(404);
        }
        $this->getJson(self::PUBLIC)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $kept->id);
        $this->getJson(self::CLIENTS.'?category_id='.$this->cat('social-media-campaign')->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $keepClient->id);
    }

    public function test_client_deletion_never_deletes_objects_still_referenced_elsewhere(): void
    {
        $client = Client::factory()->create();
        $doomed = $this->itemFor($client, 'branding');
        $other = $this->itemFor(Client::factory()->create(), 'graphic-design');

        // the other item references the doomed item's cover and work image
        $other->forceFill(['cover_image_path' => $doomed->cover_image_path])->save();
        $sharedMedia = $doomed->media->first()->image_path;
        PortfolioMedia::create(['portfolio_item_id' => $other->id, 'image_path' => $sharedMedia, 'sort_order' => 5]);

        Sanctum::actingAs($this->admin, ['access']);
        $this->deleteJson("/api/v1/admin/clients/{$client->id}")->assertStatus(200);

        Storage::disk('r2')->assertExists([$doomed->cover_image_path, $sharedMedia]);
        expect(PortfolioItem::find($doomed->id))->toBeNull();
    }

    public function test_a_database_failure_rolls_back_the_client_and_all_portfolio_deletions(): void
    {
        $client = Client::factory()->create();
        $a = $this->itemFor($client, 'branding');
        $b = $this->itemFor($client, 'graphic-design');
        $keys = [$a->cover_image_path, $b->cover_image_path, $a->media->first()->image_path, $b->media->first()->image_path];

        // fails after the portfolio rows were already removed inside the transaction
        Client::deleting(fn () => throw new \RuntimeException('DB down'));

        Sanctum::actingAs($this->admin, ['access']);
        $this->deleteJson("/api/v1/admin/clients/{$client->id}")->assertStatus(500);

        expect(Client::find($client->id))->not->toBeNull()
            ->and(PortfolioItem::where('client_id', $client->id)->count())->toBe(2)
            ->and(PortfolioMedia::whereIn('portfolio_item_id', [$a->id, $b->id])->count())->toBe(2);
        Storage::disk('r2')->assertExists($keys);
    }

    public function test_r2_failure_after_commit_does_not_undo_the_client_deletion_and_is_logged(): void
    {
        $client = Client::factory()->create();
        $item = $this->itemFor($client, 'branding');
        $cover = $item->cover_image_path;

        $storage = \Mockery::mock(ImageStorageService::class)->makePartial();
        $storage->shouldReceive('delete')->andThrow(new \RuntimeException('R2 down'));
        $this->app->instance(ImageStorageService::class, $storage);
        Log::spy();

        Sanctum::actingAs($this->admin, ['access']);
        $this->deleteJson("/api/v1/admin/clients/{$client->id}")->assertStatus(200);

        expect(Client::find($client->id))->toBeNull()->and(PortfolioItem::find($item->id))->toBeNull();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'Compensating storage cleanup failed'))->atLeast()->once();
        // the object is left behind as an orphan for the weekly cleanup
        Storage::disk('r2')->assertExists($cover);
    }

    public function test_editors_cannot_delete_clients(): void
    {
        $client = Client::factory()->create();
        $item = $this->itemFor($client, 'branding');

        $this->deleteJson("/api/v1/admin/clients/{$client->id}")->assertStatus(403);

        expect(Client::find($client->id))->not->toBeNull()->and(PortfolioItem::find($item->id))->not->toBeNull();
    }

    public function test_admin_deletes_a_portfolio_permanently_with_media_cover_and_cleanup(): void
    {
        $item = $this->itemFor(Client::factory()->create(), 'branding');
        $keys = array_merge([$item->cover_image_path], $item->media->pluck('image_path')->all());

        $this->deleteJson(self::ADMIN."/{$item->id}")->assertStatus(403);
        expect(PortfolioItem::find($item->id))->not->toBeNull();
        Storage::disk('r2')->assertExists($keys);

        Sanctum::actingAs($this->admin, ['access']);
        $this->deleteJson(self::ADMIN."/{$item->id}")->assertStatus(200);

        // hard delete: no soft-delete row and no media rows are left
        expect(DB::table('portfolio_items')->where('id', $item->id)->exists())->toBeFalse()
            ->and(DB::table('portfolio_media')->where('portfolio_item_id', $item->id)->exists())->toBeFalse();
        Storage::disk('r2')->assertMissing($keys);
        $this->getJson(self::PUBLIC."/{$item->id}")->assertStatus(404);
        $this->getJson(self::ADMIN."/{$item->id}")->assertStatus(404);

        // the freed slot can be used again
        $this->create($this->sel('branding', $item->client_id) + $this->titles())->assertStatus(201);
    }

    public function test_portfolio_deletion_survives_an_r2_failure_and_logs_it(): void
    {
        $item = $this->itemFor(Client::factory()->create(), 'branding');

        $storage = \Mockery::mock(ImageStorageService::class)->makePartial();
        $storage->shouldReceive('delete')->andThrow(new \RuntimeException('R2 down'));
        $this->app->instance(ImageStorageService::class, $storage);
        Log::spy();

        Sanctum::actingAs($this->admin, ['access']);
        $this->deleteJson(self::ADMIN."/{$item->id}")->assertStatus(200);

        expect(PortfolioItem::find($item->id))->toBeNull();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'Compensating storage cleanup failed'))->atLeast()->once();
    }

    // =========================================================================
    // Draft and publish rules
    // =========================================================================

    public function test_a_draft_needs_a_title_a_client_a_main_category_and_its_subcategory(): void
    {
        $client = Client::factory()->create();

        // title + client + category + correct subcategory; no cover, no media
        $this->create(['client_id' => $client->id, 'category_id' => $this->cat('pr')->id, 'subcategory_id' => $this->cat('media-covering')->id, 'title_ar' => 'مسودة'])
            ->assertStatus(201)->assertJsonPath('data.status', 'draft')->assertJsonPath('data.subcategory.slug', 'media-covering')
            ->assertJsonPath('data.cover_image_url', null)->assertJsonPath('data.media_count', 0);

        // Web Design has no subcategories, so a draft without one is complete
        $this->create(['client_id' => $client->id, 'category_id' => $this->cat('web-design')->id, 'title_en' => 'Site'])
            ->assertStatus(201)->assertJsonPath('data.subcategory', null);

        expect($this->r2AllFiles())->toBeEmpty();
    }

    public function test_a_draft_without_a_subcategory_is_rejected_for_every_category_that_has_subcategories(): void
    {
        $client = Client::factory()->create();

        foreach (['social-media-campaign', 'media-production', 'pr', 'events', 'opening'] as $parent) {
            $this->create(['client_id' => $client->id, 'category_id' => $this->cat($parent)->id, 'title_en' => 'x'])
                ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id'])
                ->assertJsonPath('errors.subcategory_id.0', 'A subcategory is required for the selected category.');
        }

        $this->create(['client_id' => $client->id, 'category_id' => $this->cat('pr')->id, 'subcategory_id' => null, 'title_en' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);

        expect(PortfolioItem::count())->toBe(0);
    }

    public function test_wrong_parent_and_subcategory_combinations_are_rejected(): void
    {
        $client = Client::factory()->create();

        $this->create(['client_id' => $client->id, 'category_id' => $this->cat('pr')->id, 'subcategory_id' => $this->cat('branding')->id, 'title_en' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);
        $this->create(['client_id' => $client->id, 'category_id' => $this->cat('opening')->id, 'subcategory_id' => $this->cat('events-image')->id, 'title_en' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);
        $this->create(['client_id' => $client->id, 'category_id' => $this->cat('web-design')->id, 'subcategory_id' => $this->cat('branding')->id, 'title_en' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);
    }

    public function test_every_operation_that_leaves_an_item_a_draft_keeps_the_subcategory_requirement(): void
    {
        $item = $this->itemFor(Client::factory()->create(), 'branding', false);

        // update a draft
        $this->patch_($item, ['subcategory_id' => null])->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);
        // save a published item as draft while dropping the subcategory
        $published = $this->itemFor(Client::factory()->create(), 'graphic-design');
        $this->patch_($published, ['status' => 'draft', 'subcategory_id' => null])->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);

        expect($item->fresh()->subcategory_id)->not->toBeNull()
            ->and($published->fresh()->status)->toBe('published')->and($published->fresh()->subcategory_id)->not->toBeNull();

        // a valid change of subcategory within the category still works
        $this->patch_($item, ['subcategory_id' => $this->cat('graphic-design')->id])->assertStatus(200)->assertJsonPath('data.subcategory.slug', 'graphic-design');
    }

    public function test_existing_drafts_without_a_subcategory_cannot_bypass_the_rule(): void
    {
        $client = Client::factory()->create();
        $legacy = $this->makeItem('branding', [], ['client_id' => $client->id]);
        DB::table('portfolio_items')->where('id', $legacy->id)->update(['subcategory_id' => null]);

        // updating anything else is refused until the subcategory is chosen
        $this->patch_($legacy, ['title_en' => 'New title'])->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);
        $this->patch_($legacy, ['status' => 'draft'])->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);
        // the status endpoints go through the service and are refused as well
        $this->postJson(self::ADMIN."/{$legacy->id}/publish")->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);
        $this->postJson(self::ADMIN."/{$legacy->id}/unpublish")->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);
        expect($legacy->fresh()->title_en)->not->toBe('New title');

        $this->patch_($legacy, ['subcategory_id' => $this->cat('branding')->id])->assertStatus(200);
    }

    public function test_draft_validation_still_applies(): void
    {
        $client = Client::factory()->create();
        $pr = $this->cat('pr');
        $media = $this->cat('media-covering');

        $this->create(['category_id' => $pr->id, 'subcategory_id' => $media->id] + $this->titles())->assertStatus(422)->assertJsonValidationErrors(['client_id']);
        $this->create(['client_id' => 999999, 'category_id' => $pr->id, 'subcategory_id' => $media->id] + $this->titles())->assertStatus(422)->assertJsonValidationErrors(['client_id']);
        $this->create(['client_id' => $client->id] + $this->titles())->assertStatus(422)->assertJsonValidationErrors(['category_id']);
        $this->create(['client_id' => $client->id, 'category_id' => $pr->id, 'subcategory_id' => $media->id])->assertStatus(422)->assertJsonValidationErrors(['title_en']);
        // unsafe/invalid files are rejected even for drafts
        $this->create(['client_id' => $client->id, 'category_id' => $pr->id, 'subcategory_id' => $media->id] + $this->titles() + ['cover_image' => UploadedFile::fake()->createWithContent('x.png', 'not an image')])
            ->assertStatus(422)->assertJsonValidationErrors(['cover_image']);
        // Press Covering keeps its image + URL pairing even in a draft
        $this->create(['client_id' => $client->id, 'category_id' => $pr->id, 'subcategory_id' => $this->cat('press-covering')->id] + $this->titles() + ['media' => [['image' => $this->jpeg()]]])
            ->assertStatus(422)->assertJsonValidationErrors(['media.0.url']);

        expect(PortfolioItem::count())->toBe(0)->and($this->r2AllFiles())->toBeEmpty();
    }

    public function test_publishing_an_incomplete_draft_fails_with_every_missing_requirement(): void
    {
        $client = Client::factory()->create();
        $draft = $this->create(['client_id' => $client->id, 'category_id' => $this->cat('pr')->id, 'subcategory_id' => $this->cat('press-covering')->id, 'title_en' => 'Only English'])
            ->assertStatus(201)->json('data.id');

        $this->postJson(self::ADMIN."/{$draft}/publish")
            ->assertStatus(422)->assertJsonValidationErrors(['title_ar', 'cover_image', 'media']);
        expect(PortfolioItem::find($draft)->status)->toBe('draft');
    }

    public function test_publish_requires_both_titles_a_client_a_cover_and_the_category_content(): void
    {
        $item = $this->makeItem('branding', [['image' => true]], ['title_ar' => null]);
        $this->postJson(self::ADMIN."/{$item->id}/publish")->assertStatus(422)->assertJsonValidationErrors(['title_ar']);

        // no cover
        $noCover = $this->makeItem('graphic-design', [['image' => true]], ['cover_image_path' => null]);
        $this->postJson(self::ADMIN."/{$noCover->id}/publish")->assertStatus(422)->assertJsonValidationErrors(['cover_image']);

        // legacy row without a client
        $legacy = $this->makeItem('videos', [['url' => 'https://youtu.be/x']]);
        DB::table('portfolio_items')->where('id', $legacy->id)->update(['client_id' => null]);
        $this->postJson(self::ADMIN."/{$legacy->id}/publish")->assertStatus(422)->assertJsonValidationErrors(['client_id']);

        // missing category-specific content
        $empty = $this->makeItem('events-video', []);
        $this->postJson(self::ADMIN."/{$empty->id}/publish")->assertStatus(422)->assertJsonValidationErrors(['media']);

        // complete
        $ok = $this->makeItem('media-covering', [['image' => true]]);
        $this->postJson(self::ADMIN."/{$ok->id}/publish")->assertStatus(200)->assertJsonPath('data.status', 'published');
    }

    public function test_a_legacy_item_without_a_client_must_be_given_one_on_update(): void
    {
        $item = $this->makeItem('branding', [['image' => true]]);
        DB::table('portfolio_items')->where('id', $item->id)->update(['client_id' => null]);

        $this->patch_($item, ['title_en' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['client_id']);
        $this->patch_($item, ['title_en' => 'x', 'client_id' => Client::factory()->create()->id])->assertStatus(200);
    }

    public function test_web_design_needs_both_the_cover_and_the_featured_image_to_publish(): void
    {
        $cover = 'portfolio/images/'.fake()->uuid().'.jpg';
        Storage::disk('r2')->put($cover, 'x');

        $onlyFeatured = $this->makeItem('web-design', [['image' => true]], ['cover_image_path' => null, 'link_1' => 'https://a.test', 'link_2' => 'https://b.test']);
        $this->postJson(self::ADMIN."/{$onlyFeatured->id}/publish")->assertStatus(422)->assertJsonValidationErrors(['cover_image'])
            ->assertJsonMissingValidationErrors(['featured_image']);

        $onlyCover = $this->makeItem('web-design', [], ['link_1' => 'https://a.test', 'link_2' => 'https://b.test']);
        $this->postJson(self::ADMIN."/{$onlyCover->id}/publish")->assertStatus(422)->assertJsonValidationErrors(['featured_image'])
            ->assertJsonMissingValidationErrors(['cover_image']);

        $both = $this->makeItem('web-design', [['image' => true]], ['link_1' => 'https://a.test', 'link_2' => 'https://b.test']);
        $this->postJson(self::ADMIN."/{$both->id}/publish")->assertStatus(200);
        expect($both->fresh()->cover_image_path)->not->toBeNull()
            ->and($both->media()->count())->toBe(1)
            ->and($both->fresh()->cover_image_path)->not->toBe($both->media->first()->image_path);
    }

    public function test_publication_lifecycle_is_unchanged_with_the_new_fields(): void
    {
        $t1 = Carbon::parse('2026-01-01 10:00:00');
        Carbon::setTestNow($t1);
        $item = $this->makeItem('branding', [['image' => true]]);

        $this->postJson(self::ADMIN."/{$item->id}/publish")->assertStatus(200);
        expect($item->fresh()->published_at->equalTo($t1))->toBeTrue();

        Carbon::setTestNow($t1->copy()->addDay());
        $this->patch_($item, ['title_en' => 'Edited'])->assertStatus(200);
        expect($item->fresh()->published_at->equalTo($t1))->toBeTrue();

        $this->postJson(self::ADMIN."/{$item->id}/unpublish")->assertStatus(200);
        expect($item->fresh()->published_at)->toBeNull();
        $this->getJson(self::PUBLIC."/{$item->id}")->assertStatus(404);

        Carbon::setTestNow($t1->copy()->addDays(2));
        $this->postJson(self::ADMIN."/{$item->id}/publish")->assertStatus(200);
        expect($item->fresh()->published_at->equalTo($t1->copy()->addDays(2)))->toBeTrue();
    }

    // =========================================================================
    // Cover image
    // =========================================================================

    public function test_cover_is_optional_for_drafts_and_stored_separately_from_work_images(): void
    {
        $client = Client::factory()->create();
        $response = $this->create($this->sel('branding', $client->id) + $this->titles() + $this->withCover() + [
            'media' => [['image' => $this->png()], ['image' => $this->jpeg()]],
        ])->assertStatus(201);

        $item = PortfolioItem::findOrFail($response->json('data.id'));
        expect($item->cover_image_path)->toStartWith('portfolio/images/')
            ->and($item->media)->toHaveCount(2)
            ->and($item->media->pluck('image_path')->all())->not->toContain($item->cover_image_path);

        $response->assertJsonPath('data.cover_image_url', 'https://pub-r2.waai.test/'.$item->cover_image_path)
            ->assertJsonCount(2, 'data.media');
        Storage::disk('r2')->assertExists($item->cover_image_path);
    }

    public function test_cover_replacement_removes_the_old_object_only_after_success_and_keeps_other_data(): void
    {
        $item = $this->makeItem('branding', [['image' => true]]);
        $oldCover = $item->cover_image_path;
        $mediaKey = $item->media->first()->image_path;

        $this->patch_($item, $this->withCover())->assertStatus(200);

        $item->refresh();
        expect($item->cover_image_path)->not->toBe($oldCover)->and($item->media->first()->image_path)->toBe($mediaKey);
        Storage::disk('r2')->assertMissing($oldCover);
        Storage::disk('r2')->assertExists([$item->cover_image_path, $mediaKey]);

        // no replacement requested: the cover stays
        $kept = $item->cover_image_path;
        $this->patch_($item, ['title_en' => 'Same cover'])->assertStatus(200);
        expect($item->fresh()->cover_image_path)->toBe($kept);
    }

    public function test_failed_cover_replacement_keeps_the_old_cover_and_discards_the_new_upload(): void
    {
        $item = $this->makeItem('branding', [['image' => true]]);
        $oldCover = $item->cover_image_path;
        $before = $this->r2AllFiles();

        // invalid content
        $this->patch_($item, ['cover_image' => UploadedFile::fake()->createWithContent('c.jpg', 'not an image')])->assertStatus(422);
        // database failure after the upload
        PortfolioItem::updating(fn () => throw new \RuntimeException('DB down'));
        $this->patch_($item, $this->withCover())->assertStatus(500);

        expect($item->fresh()->cover_image_path)->toBe($oldCover)
            ->and($this->r2AllFiles())->toEqualCanonicalizing($before);
    }

    public function test_cover_limits_are_enforced(): void
    {
        $item = $this->makeItem('branding', [['image' => true]]);

        $this->patch_($item, ['cover_image' => UploadedFile::fake()->create('big.png', 10 * 1024 + 1, 'image/png')])
            ->assertStatus(422)->assertJsonValidationErrors(['cover_image']);
        $this->patch_($item, ['cover_image' => UploadedFile::fake()->createWithContent('p.png', 'RIFF'.pack('V', 12).'WEBPVP8 '.str_repeat("\0", 16))])
            ->assertStatus(422)->assertJsonValidationErrors(['cover_image']);
        // a cover can only be a file, never a URL/path string
        $this->patch_($item, ['cover_image' => 'portfolio/images/other.jpg'])->assertStatus(422);
    }

    public function test_a_cover_shared_with_another_row_is_never_deleted(): void
    {
        $item = $this->makeItem('branding', [['image' => true]]);
        $other = $this->makeItem('graphic-design', []);
        $other->forceFill(['cover_image_path' => $item->cover_image_path])->save();
        $shared = $item->cover_image_path;

        $this->patch_($item, $this->withCover())->assertStatus(200);
        Storage::disk('r2')->assertExists($shared);

        // and a media row referencing a cover key protects it too
        PortfolioMedia::create(['portfolio_item_id' => $item->id, 'image_path' => $other->fresh()->cover_image_path, 'sort_order' => 9]);
        $this->deleteJson(self::ADMIN."/{$other->id}")->assertStatus(403);
        Sanctum::actingAs($this->admin, ['access']);
        $this->deleteJson(self::ADMIN."/{$other->id}")->assertStatus(200);
        Storage::disk('r2')->assertExists($shared);
    }

    public function test_deleting_an_item_removes_its_cover_and_the_orphan_cleanup_keeps_covers(): void
    {
        $item = $this->makeItem('branding', [['image' => true]]);
        $cover = $item->cover_image_path;
        $media = $item->media->first()->image_path;

        // old, unreferenced files are removed by the cleanup; the live cover and media are not
        foreach ([$cover, $media] as $key) {
            touch(Storage::disk('r2')->path($key), Carbon::now()->subHours(72)->getTimestamp());
        }
        $orphan = 'portfolio/images/'.fake()->uuid().'.jpg';
        Storage::disk('r2')->put($orphan, 'x');
        touch(Storage::disk('r2')->path($orphan), Carbon::now()->subHours(72)->getTimestamp());

        $this->artisan('portfolio:clean-orphaned-images', ['--force' => true])->assertExitCode(0);
        Storage::disk('r2')->assertExists([$cover, $media]);
        Storage::disk('r2')->assertMissing($orphan);

        Sanctum::actingAs($this->admin, ['access']);
        $this->deleteJson(self::ADMIN."/{$item->id}")->assertStatus(200);
        Storage::disk('r2')->assertMissing([$cover, $media]);
    }

    // =========================================================================
    // Public Portfolio listing (Experience A)
    // =========================================================================

    public function test_public_listing_returns_portfolio_cards_not_clients(): void
    {
        $client = Client::factory()->create();
        $a = $this->itemFor($client, 'branding');
        $b = $this->itemFor($client, 'graphic-design');
        $this->itemFor($client, 'press-covering', false);

        $response = $this->getJson(self::PUBLIC)->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'title', 'cover_image_url', 'client' => ['id', 'name', 'logo_url'], 'category' => ['id', 'slug', 'name'], 'subcategory', 'published_at']], 'links', 'meta']);

        expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())->toBe([$a->id, $b->id])
            ->and($response->json('data.0'))->not->toHaveKeys(['media', 'status', 'link_1']);
        $response->assertJsonPath('data.0.cover_image_url', fn ($url) => str_starts_with($url, 'https://pub-r2.waai.test/portfolio/images/'));
    }

    public function test_public_filters_by_main_category_subcategory_client_and_combinations(): void
    {
        $c1 = Client::factory()->create();
        $c2 = Client::factory()->create();
        $brand1 = $this->itemFor($c1, 'branding');
        $graphic1 = $this->itemFor($c1, 'graphic-design');
        $brand2 = $this->itemFor($c2, 'branding');
        $pr2 = $this->itemFor($c2, 'media-covering');
        $draft = $this->itemFor($c1, 'press-covering', false);

        $ids = fn (string $query) => collect($this->getJson(self::PUBLIC.$query)->assertStatus(200)->json('data'))->pluck('id')->sort()->values()->all();
        $social = $this->cat('social-media-campaign')->id;

        expect($ids("?category_id={$social}"))->toBe([$brand1->id, $graphic1->id, $brand2->id])
            ->and($ids("?category_id={$social}&subcategory_id={$this->cat('branding')->id}"))->toBe([$brand1->id, $brand2->id])
            ->and($ids("?subcategory_id={$this->cat('media-covering')->id}"))->toBe([$pr2->id])
            ->and($ids("?client_id={$c1->id}"))->toBe([$brand1->id, $graphic1->id])
            ->and($ids("?client_id={$c2->id}&category_id={$this->cat('pr')->id}"))->toBe([$pr2->id])
            ->and($ids("?client_id={$c1->id}&category_id={$this->cat('pr')->id}"))->toBe([]);

        // drafts never appear
        expect($ids(''))->not->toContain($draft->id);
    }

    public function test_public_filter_validation_rejects_bad_combinations(): void
    {
        $client = Client::factory()->create();

        $this->getJson(self::PUBLIC.'?category_id='.$this->cat('pr')->id.'&subcategory_id='.$this->cat('branding')->id)
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);
        $this->getJson(self::PUBLIC.'?category_id='.$this->cat('branding')->id)->assertStatus(422)->assertJsonValidationErrors(['category_id']);
        $this->getJson(self::PUBLIC.'?client_id=999999')->assertStatus(422)->assertJsonValidationErrors(['client_id']);
        $this->getJson(self::PUBLIC."?client_id={$client->id}&per_page=101")->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }

    public function test_homepage_latest_three_ordered_by_publication_time_with_stable_tiebreak(): void
    {
        $same = Carbon::parse('2026-03-01 12:00:00');
        $oldest = $this->itemFor(Client::factory()->create(), 'branding', true, ['published_at' => $same->copy()->subDays(2)]);
        $tieLow = $this->itemFor(Client::factory()->create(), 'branding', true, ['published_at' => $same]);
        $tieHigh = $this->itemFor(Client::factory()->create(), 'branding', true, ['published_at' => $same]);
        $newest = $this->itemFor(Client::factory()->create(), 'branding', true, ['published_at' => $same->copy()->addDay()]);
        $this->itemFor(Client::factory()->create(), 'branding', false);

        $response = $this->getJson(self::PUBLIC.'?per_page=3&page=1')->assertStatus(200)
            ->assertJsonCount(3, 'data')->assertJsonPath('meta.per_page', 3)->assertJsonPath('meta.total', 4);

        expect(collect($response->json('data'))->pluck('id')->all())->toBe([$newest->id, $tieHigh->id, $tieLow->id]);
        $this->getJson(self::PUBLIC.'?per_page=3&page=2')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $oldest->id);
    }

    public function test_empty_results_keep_the_pagination_contract(): void
    {
        $this->getJson(self::PUBLIC)->assertStatus(200)->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
        $this->getJson(self::CLIENTS.'?category_id='.$this->cat('pr')->id)->assertStatus(200)->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_titles_are_localized_by_accept_language(): void
    {
        $this->itemFor(Client::factory()->create(), 'branding', true, ['title_en' => 'English title', 'title_ar' => 'عنوان عربي']);

        $this->getJson(self::PUBLIC, ['Accept-Language' => 'ar'])->assertJsonPath('data.0.title', 'عنوان عربي')
            ->assertJsonPath('data.0.category.name', $this->cat('social-media-campaign')->name_ar);
        $this->getJson(self::PUBLIC, ['Accept-Language' => 'en'])->assertJsonPath('data.0.title', 'English title');
    }

    // =========================================================================
    // Client-based browsing (Experience B)
    // =========================================================================

    public function test_clients_listing_returns_each_client_once_per_main_category(): void
    {
        $both = Client::factory()->create();
        $draftOnly = Client::factory()->create();
        $other = Client::factory()->create();

        $this->itemFor($both, 'branding');
        $this->itemFor($both, 'graphic-design');
        $this->itemFor($both, 'media-covering');
        $this->itemFor($draftOnly, 'branding', false);
        $this->itemFor($other, 'press-covering');

        $social = $this->getJson(self::CLIENTS.'?category_id='.$this->cat('social-media-campaign')->id)->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $both->id)
            ->assertJsonPath('data.0.name', $both->name)
            ->assertJsonPath('data.0.published_items_count', 2)
            ->assertJsonPath('data.0.category.slug', 'social-media-campaign')
            ->assertJsonStructure(['data' => [['id', 'name', 'logo_url', 'category' => ['id', 'slug', 'name'], 'published_items_count']], 'links', 'meta']);
        expect(array_keys($social->json('data.0')))->not->toContain('created_at', 'logo_path');

        $pr = $this->getJson(self::CLIENTS.'?category_id='.$this->cat('pr')->id)->assertStatus(200);
        expect(collect($pr->json('data'))->pluck('id')->sort()->values()->all())->toBe(collect([$both->id, $other->id])->sort()->values()->all());
        // the same client appears again in a different main category, once
        $this->getJson(self::CLIENTS.'?category_id='.$this->cat('web-design')->id)->assertJsonCount(0, 'data');
    }

    public function test_clients_listing_validates_input_paginates_and_does_not_n_plus_one(): void
    {
        $this->getJson(self::CLIENTS)->assertStatus(422)->assertJsonValidationErrors(['category_id']);
        $this->getJson(self::CLIENTS.'?category_id='.$this->cat('branding')->id)->assertStatus(422)->assertJsonValidationErrors(['category_id']);
        $this->getJson(self::CLIENTS.'?category_id=999999')->assertStatus(422);

        $pr = $this->cat('pr')->id;
        foreach (range(1, 5) as $i) {
            $client = Client::factory()->create();
            $this->itemFor($client, 'media-covering');
            $this->itemFor($client, 'press-covering', true, ['title_en' => "p{$i}"]);
        }

        $this->getJson(self::CLIENTS."?category_id={$pr}&per_page=2&page=2")->assertStatus(200)
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.per_page', 2);

        DB::enableQueryLog();
        $this->getJson(self::CLIENTS."?category_id={$pr}&per_page=5")->assertStatus(200)->assertJsonCount(5, 'data');
        $few = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson(self::CLIENTS."?category_id={$pr}&per_page=1")->assertStatus(200);
        $one = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($few)->toBe($one);
    }

    public function test_client_navigation_returns_only_published_subcategories_as_tabs(): void
    {
        $client = Client::factory()->create();
        $graphic = $this->itemFor($client, 'graphic-design');
        $brand = $this->itemFor($client, 'branding');
        $this->itemFor($client, 'media-covering');           // other main category
        $social = $this->cat('social-media-campaign')->id;

        $response = $this->getJson(self::CLIENTS."/{$client->id}?category_id={$social}")->assertStatus(200)
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.category.slug', 'social-media-campaign')
            ->assertJsonCount(2, 'data.items');

        // tabs follow the configured subcategory order (Graphic Design, Branding), then expose stable item ids
        expect(collect($response->json('data.items'))->pluck('portfolio_id')->all())->toBe([$graphic->id, $brand->id])
            ->and($response->json('data.default_portfolio_id'))->toBe($graphic->id)
            ->and($response->json('data.items.0.subcategory.slug'))->toBe('graphic-design');

        // unpublishing one removes its tab
        $this->postJson(self::ADMIN."/{$graphic->id}/unpublish")->assertStatus(200);
        $this->getJson(self::CLIENTS."/{$client->id}?category_id={$social}")->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.portfolio_id', $brand->id);
    }

    public function test_client_navigation_hides_draft_only_categories_and_unknown_clients(): void
    {
        $client = Client::factory()->create();
        $this->itemFor($client, 'branding', false);
        $social = $this->cat('social-media-campaign')->id;

        $this->getJson(self::CLIENTS."/{$client->id}?category_id={$social}")->assertStatus(404);
        $this->getJson(self::CLIENTS."/999999?category_id={$social}")->assertStatus(404);
        $this->getJson(self::CLIENTS."/{$client->id}")->assertStatus(422)->assertJsonValidationErrors(['category_id']);
    }

    public function test_client_navigation_for_web_design_returns_the_item_directly(): void
    {
        $client = Client::factory()->create();
        $web = $this->makeItem('web-design', [['image' => true]], ['client_id' => $client->id, 'link_1' => 'https://a.test', 'link_2' => 'https://b.test'], true);

        $this->getJson(self::CLIENTS."/{$client->id}?category_id=".$this->cat('web-design')->id)->assertStatus(200)
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.portfolio_id', $web->id)
            ->assertJsonPath('data.items.0.subcategory', null)->assertJsonPath('data.default_portfolio_id', $web->id);
    }

    // =========================================================================
    // Direct navigation / details
    // =========================================================================

    public function test_details_identify_the_item_and_list_published_siblings_of_the_same_client_and_category(): void
    {
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $brand = $this->itemFor($client, 'branding');
        $graphic = $this->itemFor($client, 'graphic-design');
        $this->itemFor($client, 'press-covering');   // different main category
        $this->itemFor($other, 'graphic-design');    // different client

        $draft = Client::factory()->create();
        $draftSibling = $this->itemFor($draft, 'graphic-design', false);

        $response = $this->getJson(self::PUBLIC."/{$brand->id}")->assertStatus(200)
            ->assertJsonPath('data.id', $brand->id)
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.subcategory.slug', 'branding')
            ->assertJsonPath('data.kind', 'images')
            ->assertJsonStructure(['data' => ['cover_image_url', 'media', 'link_1', 'link_2', 'link_3', 'client_category_items']]);

        $siblings = collect($response->json('data.client_category_items'));
        expect($siblings->pluck('id')->all())->toBe([$graphic->id, $brand->id])
            ->and($siblings->firstWhere('is_current', true)['id'])->toBe($brand->id)
            ->and($siblings->where('is_current', true))->toHaveCount(1);

        // the other tab opens its own content
        $this->getJson(self::PUBLIC."/{$graphic->id}")->assertJsonPath('data.id', $graphic->id)->assertJsonPath('data.subcategory.slug', 'graphic-design');
        // drafts are not reachable and a draft sibling never leaks
        $this->getJson(self::PUBLIC."/{$draftSibling->id}")->assertStatus(404);
    }

    public function test_public_responses_never_expose_storage_keys_or_admin_data(): void
    {
        $item = $this->itemFor(Client::factory()->create(), 'branding');

        foreach ([self::PUBLIC, self::PUBLIC."/{$item->id}", self::CLIENTS.'?category_id='.$this->cat('social-media-campaign')->id] as $url) {
            $body = $this->getJson($url)->assertStatus(200)->getContent();
            expect($body)->not->toContain('cover_image_path')->not->toContain('image_path')->not->toContain('"status"')->not->toContain('logo_path')
                ->not->toContain('title_en')->not->toContain('slot_subcategory_key');
        }
    }

    // =========================================================================
    // Admin listing and filters
    // =========================================================================

    public function test_admin_filters_work_individually_and_together(): void
    {
        $c1 = Client::factory()->create(['name' => 'Alpha']);
        $c2 = Client::factory()->create(['name' => 'Beta']);
        $brand1 = $this->itemFor($c1, 'branding', true, ['title_ar' => 'هوية ألفا', 'title_en' => 'Alpha brand']);
        $graphic1 = $this->itemFor($c1, 'graphic-design', false, ['title_ar' => 'تصميم', 'title_en' => 'Alpha graphic']);
        $pr2 = $this->itemFor($c2, 'media-covering', true, ['title_en' => 'Beta cover', 'title_ar' => 'تغطية']);

        $ids = fn (string $query) => collect($this->getJson(self::ADMIN.$query)->assertStatus(200)->json('data'))->pluck('id')->sort()->values()->all();
        $social = $this->cat('social-media-campaign')->id;
        $sorted = fn (array $v) => collect($v)->sort()->values()->all();

        expect($ids('?client_id='.$c1->id))->toBe($sorted([$brand1->id, $graphic1->id]))
            ->and($ids("?category_id={$social}"))->toBe($sorted([$brand1->id, $graphic1->id]))
            ->and($ids('?category_id='.$this->cat('pr')->id))->toBe([$pr2->id])
            ->and($ids('?subcategory_id='.$this->cat('graphic-design')->id))->toBe([$graphic1->id])
            ->and($ids('?status=draft&client_id='.$c1->id))->toBe([$graphic1->id])
            ->and($ids('?status=published'))->toBe($sorted([$brand1->id, $pr2->id]))
            ->and($ids('?search='.urlencode('ألفا')."&category_id={$social}"))->toBe([$brand1->id])
            ->and($ids('?search=Alpha&status=published&client_id='.$c1->id.'&subcategory_id='.$this->cat('branding')->id))->toBe([$brand1->id])
            ->and($ids('?search=Beta&client_id='.$c1->id))->toBe([]);

        $this->getJson(self::ADMIN.'?client_id='.$c1->id)->assertJsonPath('data.0.client.name', 'Alpha')
            ->assertJsonStructure(['data' => [['id', 'client_id', 'client' => ['id', 'name', 'logo_url'], 'cover_image_url', 'status', 'published_at']], 'links', 'meta']);
        $this->getJson(self::ADMIN.'?client_id=999999')->assertStatus(422);
        $this->getJson(self::ADMIN."?category_id={$social}&subcategory_id=".$this->cat('media-covering')->id)->assertStatus(422);
        $this->getJson(self::ADMIN.'?per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
    }

    public function test_authorization_is_unchanged(): void
    {
        $item = $this->itemFor(Client::factory()->create(), 'branding');

        $this->deleteJson(self::ADMIN."/{$item->id}")->assertStatus(403);

        Sanctum::actingAs($this->admin, ['access']);
        $this->deleteJson(self::ADMIN."/{$item->id}")->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->getJson(self::ADMIN)->assertStatus(401);
    }
}
