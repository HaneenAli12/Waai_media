<?php

namespace Tests\Feature\Portfolio;

use App\Http\Requests\Admin\Portfolio\StorePortfolioRequest;
use App\Http\Requests\Admin\Portfolio\UpdatePortfolioRequest;
use App\Models\Event;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\User;
use App\Services\Portfolio\PortfolioItemService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Portfolio\Concerns\PortfolioTestHelpers;
use Tests\TestCase;

/**
 * Final adjustments: publication lifecycle parity with Events, no per-item image-count limits,
 * and incremental image management (add / remove / replace without touching the rest).
 */
class PortfolioAdjustmentsTest extends TestCase
{
    use PortfolioTestHelpers;
    use RefreshDatabase;

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

    private function patch_(PortfolioItem $item, array $data): TestResponse
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
    // 1. Publication lifecycle: identical to Events
    // =========================================================================

    public function test_portfolio_publication_lifecycle_matches_events(): void
    {
        $t1 = Carbon::parse('2026-11-01 10:00:00');
        $t2 = Carbon::parse('2026-11-02 11:00:00');
        $t3 = Carbon::parse('2026-11-03 12:00:00');

        // Events baseline: a complete draft event (factory content) driven through its real endpoints
        $event = Event::factory()->published()->create();
        $event->forceFill(['status' => 'draft', 'published_at' => null])->save();
        $portfolio = $this->makeItem('graphic-design', [['image' => true]], ['title_en' => 'E', 'title_ar' => 'ع']);

        $publishedAt = fn ($r) => $r->json('data.published_at');
        $events = [];
        $portfolios = [];

        // save as draft: not published
        $events['draft'] = $this->patchJson("/api/v1/admin/events/{$event->id}", ['category_en' => 'Edited'])->assertStatus(200)->json('data.status').'|'.var_export($event->fresh()->published_at, true);
        $portfolios['draft'] = $this->patch_($portfolio, ['title_en' => 'Edited'])->assertStatus(200)->json('data.status').'|'.var_export($portfolio->fresh()->published_at, true);

        // publish: timestamp = the actual publish time
        Carbon::setTestNow($t1);
        $events['publish'] = $publishedAt($this->postJson("/api/v1/admin/events/{$event->id}/publish")->assertStatus(200));
        $portfolios['publish'] = $publishedAt($this->postJson(self::ADMIN."/{$portfolio->id}/publish")->assertStatus(200));

        // publishing an already published item keeps the original timestamp
        Carbon::setTestNow($t2);
        $events['publish_again'] = $publishedAt($this->postJson("/api/v1/admin/events/{$event->id}/publish")->assertStatus(200));
        $portfolios['publish_again'] = $publishedAt($this->postJson(self::ADMIN."/{$portfolio->id}/publish")->assertStatus(200));

        // editing a published item does not change the timestamp
        $events['edit'] = $this->patchJson("/api/v1/admin/events/{$event->id}", ['category_en' => 'Again'])->json('data.published_at');
        $portfolios['edit'] = $this->patch_($portfolio, ['title_en' => 'Again'])->json('data.published_at');

        // unpublish: draft, timestamp cleared, hidden publicly
        $events['unpublish'] = $this->postJson("/api/v1/admin/events/{$event->id}/unpublish")->assertStatus(200)->json('data.status').'|'.var_export($event->fresh()->published_at, true);
        $portfolios['unpublish'] = $this->postJson(self::ADMIN."/{$portfolio->id}/unpublish")->assertStatus(200)->json('data.status').'|'.var_export($portfolio->fresh()->published_at, true);
        $events['hidden'] = count($this->getJson('/api/v1/events')->json('data'));
        $portfolios['hidden'] = count($this->getJson(self::PUBLIC)->json('data'));

        // republish: a fresh timestamp (the previous one was cleared on unpublish)
        Carbon::setTestNow($t3);
        $events['republish'] = $publishedAt($this->postJson("/api/v1/admin/events/{$event->id}/publish")->assertStatus(200));
        $portfolios['republish'] = $publishedAt($this->postJson(self::ADMIN."/{$portfolio->id}/publish")->assertStatus(200));

        expect($portfolios)->toBe($events);

        // and the concrete expected values (not only parity)
        expect($portfolios['draft'])->toBe('draft|NULL');
        expect(Carbon::parse($portfolios['publish'])->equalTo($t1))->toBeTrue();
        expect($portfolios['publish_again'])->toBe($portfolios['publish'])->and($portfolios['edit'])->toBe($portfolios['publish']);
        expect($portfolios['unpublish'])->toBe('draft|NULL')->and($portfolios['hidden'])->toBe(0);
        expect(Carbon::parse($portfolios['republish'])->equalTo($t3))->toBeTrue();
    }

    public function test_save_as_draft_never_publishes_and_unpublished_items_are_hidden(): void
    {
        $created = $this->post(self::ADMIN, $this->sel('graphic-design') + $this->titles() + $this->withCover() + ['media' => $this->images(1)], self::JSON_HEADERS)
            ->assertStatus(201)->assertJsonPath('data.status', 'draft')->assertJsonPath('data.published_at', null);
        $id = $created->json('data.id');

        $this->getJson(self::PUBLIC."/{$id}")->assertStatus(404);
        $this->postJson(self::ADMIN."/{$id}/publish")->assertStatus(200);
        $this->getJson(self::PUBLIC."/{$id}")->assertStatus(200);
        $this->postJson(self::ADMIN."/{$id}/unpublish")->assertStatus(200);
        $this->getJson(self::PUBLIC."/{$id}")->assertStatus(404);
    }

    public function test_public_eligibility_matches_events_published_status_and_a_recorded_non_future_timestamp(): void
    {
        $noTimestamp = $this->makeItem('graphic-design', [['image' => true]], ['status' => 'published', 'published_at' => null]);
        $future = $this->makeItem('graphic-design', [['image' => true]], ['status' => 'published', 'published_at' => now()->addDay()]);
        $live = $this->makeItem('graphic-design', [['image' => true]], published: true);

        $ids = collect($this->getJson(self::PUBLIC)->json('data'))->pluck('id')->all();

        expect($ids)->toBe([$live->id]);
        $this->getJson(self::PUBLIC."/{$noTimestamp->id}")->assertStatus(404);
        $this->getJson(self::PUBLIC."/{$future->id}")->assertStatus(404);
    }

    public function test_no_scheduling_input_is_accepted_to_set_a_publication_time(): void
    {
        $r = $this->post(self::ADMIN, $this->sel('graphic-design') + $this->titles() + [
            'media' => $this->images(1), 'status' => 'published', 'cover_image' => $this->jpeg(), 'published_at' => '2030-01-01 00:00:00', 'publish_at' => '2030-01-01',
        ], self::JSON_HEADERS)->assertStatus(201);

        expect(Carbon::parse($r->json('data.published_at'))->lessThanOrEqualTo(now()))->toBeTrue();
    }

    // =========================================================================
    // 2. No application-level image-count limits
    // =========================================================================

    public function test_no_per_request_or_per_item_count_limit_exists_in_the_code(): void
    {
        expect(defined(PortfolioItemService::class.'::MAX_MEDIA_PER_ITEM'))->toBeFalse();

        foreach ([StorePortfolioRequest::class, UpdatePortfolioRequest::class] as $class) {
            $rules = (new $class)->rules();
            foreach (['media', 'remove_media_ids'] as $field) {
                expect(collect($rules[$field])->filter(fn ($r) => is_string($r) && str_starts_with($r, 'max:'))->all())->toBe([], "{$class} {$field}");
            }
        }
    }

    public function test_more_than_the_old_limits_are_accepted_in_one_request(): void
    {
        $response = $this->post(self::ADMIN, $this->sel('branding') + $this->titles() + $this->withCover() + ['status' => 'published', 'media' => $this->images(55)], self::JSON_HEADERS)
            ->assertStatus(201);

        expect($response->json('data.media'))->toHaveCount(55);
        expect($this->r2Files())->toHaveCount(55);
    }

    public function test_fifteen_images_plus_twenty_more_later_results_in_thirty_five(): void
    {
        $created = $this->post(self::ADMIN, $this->sel('branding') + $this->titles() + ['media' => $this->images(15)], self::JSON_HEADERS)->assertStatus(201);
        $id = $created->json('data.id');
        $originalIds = collect($created->json('data.media'))->pluck('id')->all();
        $originalKeys = PortfolioMedia::where('portfolio_item_id', $id)->orderBy('sort_order')->pluck('image_path')->all();

        $updated = $this->patch_(PortfolioItem::find($id), ['media' => $this->images(20)])->assertStatus(200);

        $media = $updated->json('data.media');
        expect($media)->toHaveCount(35);
        expect(array_slice(array_column($media, 'id'), 0, 15))->toBe($originalIds);          // originals first, untouched
        expect(PortfolioMedia::where('portfolio_item_id', $id)->orderBy('sort_order')->pluck('image_path')->take(15)->all())->toBe($originalKeys);
        expect(array_column($media, 'sort_order'))->toBe(range(0, 34));                       // appended, order preserved
        expect($this->r2Files())->toHaveCount(35);
        foreach ($originalKeys as $key) {
            Storage::disk('r2')->assertExists($key);
        }
    }

    public function test_images_can_be_added_through_many_separate_update_requests(): void
    {
        $id = $this->post(self::ADMIN, $this->sel('events-image') + $this->titles() + ['media' => $this->images(5)], self::JSON_HEADERS)->json('data.id');

        foreach (range(1, 6) as $round) {
            $this->patch_(PortfolioItem::find($id), ['media' => $this->images(10)])->assertStatus(200);
        }

        expect(PortfolioMedia::where('portfolio_item_id', $id)->count())->toBe(65);
        expect($this->r2Files())->toHaveCount(65);
    }

    public function test_many_images_can_be_removed_in_one_request(): void
    {
        $item = $this->makeItem('branding', array_fill(0, 60, ['image' => true]));
        $ids = $item->media->pluck('id')->take(55)->all();

        $this->patch_($item, ['remove_media_ids' => $ids])->assertStatus(200);

        expect(PortfolioMedia::where('portfolio_item_id', $item->id)->count())->toBe(5);
        expect($this->r2Files())->toHaveCount(5);
    }

    public function test_the_ten_megabyte_per_image_limit_still_applies(): void
    {
        $big = UploadedFile::fake()->create('big.png', 10 * 1024 + 1, 'image/png');

        $this->post(self::ADMIN, $this->sel('branding') + $this->titles() + ['media' => [['image' => $big]]], self::JSON_HEADERS)
            ->assertStatus(422)->assertJsonValidationErrors(['media.0.image']);
    }

    // =========================================================================
    // 3. Incremental image management
    // =========================================================================

    public function test_adding_images_never_replaces_the_existing_collection(): void
    {
        $item = $this->makeItem('branding', [['image' => true], ['image' => true], ['image' => true]]);
        $before = $item->media->map(fn ($m) => [$m->id, $m->image_path, $m->sort_order])->all();

        $this->patch_($item, ['media' => $this->images(2), 'title_en' => 'Renamed'])->assertStatus(200);

        $after = PortfolioMedia::where('portfolio_item_id', $item->id)->orderBy('sort_order')->get();
        expect($after)->toHaveCount(5);
        expect($after->take(3)->map(fn ($m) => [$m->id, $m->image_path, $m->sort_order])->all())->toBe($before);
    }

    public function test_removing_and_replacing_selected_images_keeps_every_other_image_and_its_metadata(): void
    {
        $item = $this->makeItem('branding', [['image' => true], ['image' => true], ['image' => true], ['image' => true]]);
        [$a, $b, $c, $d] = $item->media->all();

        $this->patch_($item, [
            'remove_media_ids' => [$b->id],
            'media' => [['id' => $c->id, 'image' => $this->png()]],
        ])->assertStatus(200);

        $rows = PortfolioMedia::where('portfolio_item_id', $item->id)->orderBy('sort_order')->get()->keyBy('id');
        expect($rows->keys()->all())->toBe([$a->id, $c->id, $d->id]);
        expect($rows[$a->id]->image_path)->toBe($a->image_path)->and($rows[$d->id]->image_path)->toBe($d->image_path);
        expect($rows[$c->id]->image_path)->not->toBe($c->image_path);
        expect([$rows[$a->id]->sort_order, $rows[$c->id]->sort_order, $rows[$d->id]->sort_order])->toBe([$a->sort_order, $c->sort_order, $d->sort_order]);

        $disk = Storage::disk('r2');
        $disk->assertExists($a->image_path);
        $disk->assertExists($d->image_path);
        $disk->assertMissing($b->image_path);
        $disk->assertMissing($c->image_path);
        expect($this->r2Files())->toHaveCount(3);
    }

    public function test_press_covering_new_images_need_their_own_url_and_existing_pairs_are_preserved(): void
    {
        $item = $this->makeItem('press-covering', [
            ['image' => true, 'url' => 'https://example.com/one'],
            ['image' => true, 'url' => 'https://example.com/two'],
        ], ['title_en' => 'P', 'title_ar' => 'ص'], published: true);
        [$one, $two] = $item->media->all();

        // a new image without its URL is rejected and nothing changes
        $this->patch_($item, ['media' => [['image' => $this->png()]]])->assertStatus(422)->assertJsonValidationErrors(['media.0.url']);
        expect(PortfolioMedia::where('portfolio_item_id', $item->id)->count())->toBe(2);
        expect($this->r2Files())->toHaveCount(2);

        // a new image with its URL is appended; the existing pairs keep their URLs
        $this->patch_($item, ['media' => [['image' => $this->png(), 'url' => 'https://example.com/three']]])->assertStatus(200);
        $rows = PortfolioMedia::where('portfolio_item_id', $item->id)->orderBy('sort_order')->get();
        expect($rows->pluck('url')->all())->toBe(['https://example.com/one', 'https://example.com/two', 'https://example.com/three']);
        expect($rows[0]->image_path)->toBe($one->image_path)->and($rows[1]->image_path)->toBe($two->image_path);

        // replacing only the image keeps that pair's URL
        $this->patch_($item, ['media' => [['id' => $one->id, 'image' => $this->jpeg()]]])->assertStatus(200);
        $replaced = $one->fresh();
        expect($replaced->url)->toBe('https://example.com/one')->and($replaced->image_path)->not->toBe($one->image_path);

        // an existing pair's URL can be changed but not cleared
        $this->patch_($item, ['media' => [['id' => $two->id, 'url' => null]]])->assertStatus(422)->assertJsonValidationErrors(['media.0.url']);
        $this->patch_($item, ['media' => [['id' => $two->id, 'url' => 'https://example.com/two-updated']]])->assertStatus(200);
        expect($two->fresh()->url)->toBe('https://example.com/two-updated');
    }

    public function test_removing_one_press_pair_does_not_affect_the_others(): void
    {
        $item = $this->makeItem('press-covering', [
            ['image' => true, 'url' => 'https://example.com/a'],
            ['image' => true, 'url' => 'https://example.com/b'],
            ['image' => true, 'url' => 'https://example.com/c'],
        ], ['title_en' => 'P', 'title_ar' => 'ص'], published: true);
        [$a, $b, $c] = $item->media->all();

        $this->patch_($item, ['remove_media_ids' => [$b->id]])->assertStatus(200)->assertJsonPath('data.status', 'published');

        $rows = PortfolioMedia::where('portfolio_item_id', $item->id)->orderBy('sort_order')->get();
        expect($rows->map(fn ($r) => [$r->image_path, $r->url])->all())->toBe([[$a->image_path, 'https://example.com/a'], [$c->image_path, 'https://example.com/c']]);
        Storage::disk('r2')->assertMissing($b->image_path);
        Storage::disk('r2')->assertExists($a->image_path);
        Storage::disk('r2')->assertExists($c->image_path);
    }

    public function test_removal_never_deletes_an_image_still_referenced_by_another_entry(): void
    {
        $item = $this->makeItem('branding', [['image' => true], ['image' => true]]);
        $other = $this->makeItem('graphic-design');
        [$a, $b] = $item->media->all();
        PortfolioMedia::create(['portfolio_item_id' => $other->id, 'image_path' => $a->image_path, 'sort_order' => 0]);

        $this->patch_($item, ['remove_media_ids' => [$a->id]])->assertStatus(200);

        Storage::disk('r2')->assertExists($a->image_path); // shared with $other
        expect(PortfolioMedia::where('portfolio_item_id', $item->id)->pluck('id')->all())->toBe([$b->id]);
    }

    public function test_web_design_keeps_exactly_one_featured_image(): void
    {
        $item = $this->makeItem('web-design', [['image' => true]], ['title_en' => 'W', 'title_ar' => 'و']);

        $this->patch_($item, ['featured_image' => $this->png()])->assertStatus(200);
        $this->patch_($item, ['featured_image' => $this->jpeg()])->assertStatus(200);
        $this->patch_($item, ['media' => [['image' => $this->png()]]])->assertStatus(422);

        expect(PortfolioMedia::where('portfolio_item_id', $item->id)->count())->toBe(1);
        expect($this->r2Files())->toHaveCount(1);
    }
}
