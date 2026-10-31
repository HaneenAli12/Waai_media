<?php

namespace Tests\Feature\Portfolio;

use App\Models\Category;
use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\User;
use App\Services\Portfolio\PortfolioImageService;
use App\Services\Storage\Exceptions\InvalidImageException;
use App\Services\Storage\ImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Portfolio\Concerns\PortfolioTestHelpers;
use Tests\TestCase;

class PortfolioAdminApiTest extends TestCase
{
    use PortfolioTestHelpers;
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPortfolio();
        $this->admin = User::factory()->admin()->create();
        $this->editor = User::factory()->editor()->create();
        $this->superAdmin = User::factory()->superAdmin()->create();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user, ['access']);

        return $this;
    }

    private function create(array $data): TestResponse
    {
        // publishing requires a cover image; add one unless the test sends its own
        if (($data['status'] ?? null) === 'published' && ! isset($data['cover_image'])) {
            $data['cover_image'] = $this->jpeg('cover.jpg');
        }

        return $this->post(self::ADMIN, $data, self::JSON_HEADERS);
    }

    private function update(PortfolioItem $item, array $data): TestResponse
    {
        return $this->post(self::ADMIN."/{$item->id}", ['_method' => 'PATCH'] + $data, self::JSON_HEADERS);
    }

    // =========================================================================
    // Categories
    // =========================================================================

    public function test_categories_endpoint_returns_exactly_six_parents_with_correct_hierarchy(): void
    {
        $this->as($this->editor);

        $response = $this->getJson(self::ADMIN.'/categories')->assertStatus(200);
        $data = collect($response->json('data'));

        expect($data)->toHaveCount(6);
        expect($data->pluck('slug')->all())->toBe(['social-media-campaign', 'media-production', 'pr', 'events', 'opening', 'web-design']);

        $children = $data->mapWithKeys(fn ($c) => [$c['slug'] => collect($c['subcategories'])->pluck('slug')->all()]);
        expect($children['social-media-campaign'])->toBe(['graphic-design', 'branding']);
        expect($children['media-production'])->toBe(['videos']);
        expect($children['pr'])->toBe(['media-covering', 'press-covering']);
        expect($children['events'])->toBe(['events-image', 'events-video']);
        expect($children['opening'])->toBe(['opening-image', 'opening-video']);
        expect($children['web-design'])->toBe([]);
    }

    public function test_web_design_has_no_subcategories_and_exposes_its_own_input_contract(): void
    {
        $this->as($this->editor);

        $web = collect($this->getJson(self::ADMIN.'/categories')->json('data'))->firstWhere('slug', 'web-design');

        expect($web['has_subcategories'])->toBeFalse();
        expect($web['kind'])->toBe('web_design');
        expect($web['inputs']['required_links'])->toBe(['link_1', 'link_2'])->and($web['inputs']['optional_links'])->toBe(['link_3']);
    }

    public function test_same_named_subcategories_under_different_parents_are_distinct(): void
    {
        $this->as($this->editor);
        $data = collect($this->getJson(self::ADMIN.'/categories')->json('data'));

        $eventsImage = collect($data->firstWhere('slug', 'events')['subcategories'])->firstWhere('slug', 'events-image');
        $openingImage = collect($data->firstWhere('slug', 'opening')['subcategories'])->firstWhere('slug', 'opening-image');

        expect($eventsImage['name_en'])->toBe($openingImage['name_en']);
        expect($eventsImage['id'])->not->toBe($openingImage['id']);
        expect($eventsImage['kind'])->toBe('images')->and($openingImage['kind'])->toBe('images');
    }

    public function test_category_names_are_localized_by_accept_language(): void
    {
        $this->as($this->editor);

        $ar = $this->getJson(self::ADMIN.'/categories', ['Accept-Language' => 'ar'])->json('data.0');
        $en = $this->getJson(self::ADMIN.'/categories', ['Accept-Language' => 'en'])->json('data.0');

        expect($ar['name'])->toBe($ar['name_ar'])->and($en['name'])->toBe($en['name_en']);
        expect($ar['name'])->not->toBe($en['name']);
    }

    public function test_a_subcategory_of_another_parent_is_rejected(): void
    {
        $this->as($this->editor);
        $pr = $this->cat('pr');

        $this->create(['client_id' => Client::factory()->create()->id, 'category_id' => $pr->id, 'subcategory_id' => $this->cat('graphic-design')->id] + $this->titles())
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);

        // Events/Image under Opening is the same kind of mismatch
        $this->create(['client_id' => Client::factory()->create()->id, 'category_id' => $this->cat('opening')->id, 'subcategory_id' => $this->cat('events-image')->id] + $this->titles())
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);

        $this->assertDatabaseCount('portfolio_items', 0);
    }

    public function test_parent_with_children_requires_a_subcategory_and_web_design_rejects_one(): void
    {
        $this->as($this->editor);

        $this->create(['client_id' => Client::factory()->create()->id, 'category_id' => $this->cat('pr')->id] + $this->titles() + ['status' => 'published'])
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);

        $this->create(['client_id' => Client::factory()->create()->id, 'category_id' => $this->cat('web-design')->id, 'subcategory_id' => $this->cat('branding')->id] + $this->titles())
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);

        $this->create($this->titles() + ['client_id' => Client::factory()->create()->id])->assertStatus(422)->assertJsonValidationErrors(['category_id']);
        $this->create(['client_id' => Client::factory()->create()->id, 'category_id' => 999999] + $this->titles())->assertStatus(422)->assertJsonValidationErrors(['category_id']);
        // a news category is not a portfolio category
        $news = Category::where('type', 'news')->whereNull('parent_id')->first();
        $this->create(['client_id' => Client::factory()->create()->id, 'category_id' => $news->id] + $this->titles())->assertStatus(422)->assertJsonValidationErrors(['category_id']);
    }

    // =========================================================================
    // Create: drafts and publishing
    // =========================================================================

    public function test_create_draft_with_minimal_data(): void
    {
        $this->as($this->editor);

        $this->create($this->sel('graphic-design') + ['title_en' => 'Only English'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.title_en', 'Only English')
            ->assertJsonPath('data.title_ar', null)
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('data.category.slug', 'social-media-campaign')
            ->assertJsonPath('data.subcategory.slug', 'graphic-design')
            ->assertJsonPath('data.kind', 'images');
    }

    public function test_create_requires_at_least_one_title(): void
    {
        $this->as($this->editor);

        $this->create($this->sel('graphic-design'))->assertStatus(422)->assertJsonValidationErrors(['title_en']);
        $this->assertDatabaseCount('portfolio_items', 0);
    }

    public function test_create_and_publish_a_complete_image_item(): void
    {
        $this->as($this->editor);

        $response = $this->create($this->sel('graphic-design') + $this->titles('Brand', 'علامة') + [
            'status' => 'published',
            'media' => [['image' => $this->png()], ['image' => $this->jpeg()]],
        ])->assertStatus(201)->assertJsonPath('data.status', 'published');

        expect($response->json('data.published_at'))->not->toBeNull();
        expect($response->json('data.media'))->toHaveCount(2);
        expect($response->json('data.media.0.image_url'))->toStartWith('https://pub-r2.waai.test/portfolio/images/');
        expect($response->json('data.media.0.sort_order'))->toBe(0)->and($response->json('data.media.1.sort_order'))->toBe(1);
        expect($this->r2Files())->toHaveCount(2);
    }

    public function test_incomplete_publication_is_rejected_and_leaves_nothing_behind(): void
    {
        $this->as($this->editor);

        // missing image
        $this->create($this->sel('graphic-design') + $this->titles() + ['status' => 'published'])
            ->assertStatus(422)->assertJsonValidationErrors(['media']);

        // missing Arabic title (both languages are required to publish)
        $this->create($this->sel('graphic-design') + ['title_en' => 'Only EN', 'status' => 'published', 'media' => [['image' => $this->png()]]])
            ->assertStatus(422)->assertJsonValidationErrors(['title_ar']);

        $this->assertDatabaseCount('portfolio_items', 0);
        $this->assertDatabaseCount('portfolio_media', 0);
        expect($this->r2Files())->toBeEmpty();
    }

    public function test_status_must_be_draft_or_published(): void
    {
        $this->as($this->editor);

        $this->create($this->sel('graphic-design') + $this->titles() + ['status' => 'archived'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_arabic_and_english_titles_are_stored_independently(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]], ['title_en' => 'English', 'title_ar' => 'عربي']);

        $this->update($item, ['title_en' => 'English changed'])->assertStatus(200)
            ->assertJsonPath('data.title_en', 'English changed')
            ->assertJsonPath('data.title_ar', 'عربي');
    }

    // =========================================================================
    // Category kinds
    // =========================================================================

    public function test_images_subcategories_reject_urls_links_and_featured_image(): void
    {
        $this->as($this->editor);

        $this->create($this->sel('branding') + $this->titles() + ['media' => [['image' => $this->png(), 'url' => 'https://example.com/x']]])
            ->assertStatus(422)->assertJsonValidationErrors(['media.0.url']);

        $this->create($this->sel('branding') + $this->titles() + ['link_1' => 'https://example.com/x'])
            ->assertStatus(422)->assertJsonValidationErrors(['link_1']);

        $this->create($this->sel('branding') + $this->titles() + ['featured_image' => $this->png()])
            ->assertStatus(422)->assertJsonValidationErrors(['featured_image']);

        // a new media entry needs its file
        $this->create($this->sel('branding') + $this->titles() + ['media' => [['url' => null]]])
            ->assertStatus(422)->assertJsonValidationErrors(['media.0.image']);

        $this->assertDatabaseCount('portfolio_items', 0);
    }

    public function test_media_covering_accepts_images_without_links(): void
    {
        $this->as($this->editor);

        $this->create($this->sel('media-covering') + $this->titles() + ['status' => 'published', 'media' => [['image' => $this->jpeg()]]])
            ->assertStatus(201)->assertJsonPath('data.subcategory.slug', 'media-covering');
    }

    public function test_press_covering_keeps_each_image_associated_with_its_own_url(): void
    {
        $this->as($this->editor);

        $response = $this->create($this->sel('press-covering') + $this->titles() + ['status' => 'published', 'media' => [
            ['image' => $this->png(), 'url' => 'https://example.com/article-1'],
            ['image' => $this->jpeg(), 'url' => 'https://example.com/article-2'],
        ]])->assertStatus(201);

        $media = $response->json('data.media');
        expect($media[0]['url'])->toBe('https://example.com/article-1');
        expect($media[1]['url'])->toBe('https://example.com/article-2');
        expect($media[0]['image_url'])->not->toBe($media[1]['image_url']);

        // the association is stored on one row
        $rows = PortfolioMedia::orderBy('sort_order')->get();
        expect($rows[0]->url)->toBe('https://example.com/article-1')->and($rows[0]->image_path)->not->toBeNull();
        expect($rows[1]->url)->toBe('https://example.com/article-2');
    }

    public function test_press_covering_requires_a_url_for_every_image_and_publication_checks_stored_pairs(): void
    {
        $this->as($this->editor);

        // a new press image without its URL is rejected, even as a draft, and nothing is stored
        $this->create($this->sel('press-covering') + $this->titles() + ['status' => 'published', 'media' => [
            ['image' => $this->png(), 'url' => 'https://example.com/a'],
            ['image' => $this->jpeg()],
        ]])->assertStatus(422)->assertJsonValidationErrors(['media.1.url']);

        $this->create($this->sel('press-covering') + $this->titles() + ['media' => [['image' => $this->jpeg()]]])
            ->assertStatus(422)->assertJsonValidationErrors(['media.0.url']);

        $this->assertDatabaseCount('portfolio_items', 0);
        expect($this->r2Files())->toBeEmpty();

        // publication still validates stored pairs (e.g. a pair stored without a URL by other means)
        $item = $this->makeItem('press-covering', [['image' => true, 'url' => null]], ['title_en' => 'E', 'title_ar' => 'ع']);
        $this->post(self::ADMIN."/{$item->id}/publish", [], self::JSON_HEADERS)->assertStatus(422)->assertJsonValidationErrors(['media.0.url']);

        $mediaId = $item->media->first()->id;
        $this->post(self::ADMIN."/{$item->id}", ['_method' => 'PATCH', 'media' => [['id' => $mediaId, 'url' => 'https://example.com/done']], 'status' => 'published'], self::JSON_HEADERS)
            ->assertStatus(200)->assertJsonPath('data.status', 'published')->assertJsonPath('data.media.0.url', 'https://example.com/done');
    }

    public function test_invalid_and_unsafe_urls_are_rejected(): void
    {
        $this->as($this->editor);

        foreach (['javascript:alert(1)', 'ftp://example.com/file', 'not a url', 'data:text/html;base64,AAAA'] as $bad) {
            $this->create($this->sel('press-covering') + $this->titles() + ['media' => [['image' => $this->png(), 'url' => $bad]]])
                ->assertStatus(422)->assertJsonValidationErrors(['media.0.url']);
        }

        $this->assertDatabaseCount('portfolio_items', 0);
    }

    public function test_video_subcategories_accept_urls_only(): void
    {
        $this->as($this->editor);

        foreach (['videos', 'events-video', 'opening-video'] as $slug) {
            $response = $this->create($this->sel($slug) + $this->titles() + ['status' => 'published', 'media' => [
                ['url' => 'https://www.youtube.com/watch?v=abc'],
                ['url' => 'https://vimeo.com/123'],
            ]])->assertStatus(201)->assertJsonPath('data.subcategory.slug', $slug)->assertJsonPath('data.kind', 'video_urls');

            expect($response->json('data.media'))->toHaveCount(2);
            expect($response->json('data.media.0.image_url'))->toBeNull();
        }

        expect($this->r2Files())->toBeEmpty();
    }

    public function test_video_subcategories_reject_files_missing_urls_and_invalid_urls(): void
    {
        $this->as($this->editor);

        $this->create($this->sel('videos') + $this->titles() + ['media' => [['image' => $this->png()]]])
            ->assertStatus(422)->assertJsonValidationErrors(['media.0.image']);

        $video = UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4');
        $this->create($this->sel('videos') + $this->titles() + ['media' => [['image' => $video]]])
            ->assertStatus(422);

        $this->create($this->sel('videos') + $this->titles() + ['media' => [['url' => null]]])
            ->assertStatus(422)->assertJsonValidationErrors(['media.0.url']);

        $this->create($this->sel('videos') + $this->titles() + ['media' => [['url' => 'javascript:alert(1)']]])
            ->assertStatus(422)->assertJsonValidationErrors(['media.0.url']);

        $this->create($this->sel('videos') + $this->titles() + ['status' => 'published'])
            ->assertStatus(422)->assertJsonValidationErrors(['media']);

        expect($this->r2Files())->toBeEmpty();
        $this->assertDatabaseCount('portfolio_items', 0);
    }

    public function test_events_and_opening_subcategories_keep_distinct_identities(): void
    {
        $this->as($this->editor);

        $events = $this->create($this->sel('events-image') + $this->titles() + ['media' => [['image' => $this->png()]]])->assertStatus(201);
        $opening = $this->create($this->sel('opening-image') + $this->titles() + ['media' => [['image' => $this->png()]]])->assertStatus(201);

        expect($events->json('data.category.slug'))->toBe('events')->and($opening->json('data.category.slug'))->toBe('opening');
        expect($events->json('data.subcategory.id'))->not->toBe($opening->json('data.subcategory.id'));

        // filtering by one never returns the other
        $list = $this->getJson(self::ADMIN.'?subcategory_id='.$this->cat('events-image')->id)->assertStatus(200);
        expect(collect($list->json('data'))->pluck('id')->all())->toBe([$events->json('data.id')]);
    }

    public function test_image_subcategories_reject_video_urls_as_the_only_media(): void
    {
        $this->as($this->editor);

        $this->create($this->sel('events-image') + $this->titles() + ['media' => [['url' => 'https://youtube.com/watch?v=1']]])
            ->assertStatus(422);
    }

    // =========================================================================
    // Web design
    // =========================================================================

    public function test_web_design_publishes_with_two_required_links_and_an_optional_third(): void
    {
        $this->as($this->editor);

        $response = $this->create($this->sel('web-design') + $this->titles() + [
            'status' => 'published',
            'featured_image' => $this->png(),
            'link_1' => 'https://example.com/one',
            'link_2' => 'https://example.com/two',
        ])->assertStatus(201)->assertJsonPath('data.status', 'published');

        expect($response->json('data.link_3'))->toBeNull();
        expect($response->json('data.subcategory'))->toBeNull();
        expect($response->json('data.media'))->toHaveCount(1);
        expect($response->json('data.media.0.image_url'))->toStartWith('https://pub-r2.waai.test/portfolio/images/');

        $this->update(PortfolioItem::find($response->json('data.id')), ['link_3' => 'https://example.com/three'])
            ->assertStatus(200)->assertJsonPath('data.link_3', 'https://example.com/three');
    }

    public function test_web_design_publish_requires_featured_image_and_both_first_links(): void
    {
        $this->as($this->editor);

        $this->create($this->sel('web-design') + $this->titles() + ['status' => 'published'])
            ->assertStatus(422)->assertJsonValidationErrors(['featured_image', 'link_1', 'link_2']);

        $this->create($this->sel('web-design') + $this->titles() + [
            'status' => 'published', 'featured_image' => $this->png(), 'link_1' => 'https://example.com/one',
        ])->assertStatus(422)->assertJsonValidationErrors(['link_2'])->assertJsonMissingValidationErrors(['link_1', 'link_3']);

        $this->assertDatabaseCount('portfolio_items', 0);
        expect($this->r2Files())->toBeEmpty();

        // a draft is allowed with nothing but a title
        $this->create($this->sel('web-design') + $this->titles())->assertStatus(201)->assertJsonPath('data.status', 'draft');
    }

    public function test_web_design_rejects_media_entries_and_invalid_links(): void
    {
        $this->as($this->editor);

        $this->create($this->sel('web-design') + $this->titles() + ['media' => [['image' => $this->png()]]])
            ->assertStatus(422)->assertJsonValidationErrors(['media']);

        $this->create($this->sel('web-design') + $this->titles() + ['link_3' => 'not-a-url'])
            ->assertStatus(422)->assertJsonValidationErrors(['link_3']);
    }

    public function test_web_design_featured_image_replacement_removes_the_old_object_after_commit(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('web-design', [['image' => true]], ['title_en' => 'W', 'title_ar' => 'و']);
        $old = $item->media->first()->image_path;

        $this->update($item, ['featured_image' => $this->jpeg()])->assertStatus(200);

        $new = $item->fresh()->media->first()->image_path;
        expect($new)->not->toBe($old);
        Storage::disk('r2')->assertMissing($old);
        Storage::disk('r2')->assertExists($new);
        expect($item->fresh()->media)->toHaveCount(1);
    }

    // =========================================================================
    // Update
    // =========================================================================

    public function test_update_without_media_keys_preserves_existing_media(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true], ['image' => true]], ['title_en' => 'Before']);
        $keys = $item->media->pluck('image_path')->all();

        $this->update($item, ['title_en' => 'After'])->assertStatus(200)->assertJsonPath('data.title_en', 'After');

        expect($item->fresh()->media->pluck('image_path')->all())->toBe($keys);
        foreach ($keys as $key) {
            Storage::disk('r2')->assertExists($key);
        }
    }

    public function test_update_can_append_remove_and_replace_images(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true], ['image' => true]]);
        [$first, $second] = $item->media->all();

        $response = $this->update($item, [
            'remove_media_ids' => [$first->id],
            'media' => [
                ['id' => $second->id, 'image' => $this->png()],
                ['image' => $this->jpeg()],
            ],
        ])->assertStatus(200);

        $media = $response->json('data.media');
        expect($media)->toHaveCount(2);
        expect($media[0]['id'])->toBe($second->id);             // replaced in place, keeps its position
        expect($media[1]['sort_order'])->toBeGreaterThan($media[0]['sort_order']);

        Storage::disk('r2')->assertMissing($first->image_path);
        Storage::disk('r2')->assertMissing($second->image_path);
        expect($this->r2Files())->toHaveCount(2);
    }

    public function test_update_rejects_media_ids_of_other_items(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]]);
        $other = $this->makeItem('graphic-design', [['image' => true]]);
        $foreign = $other->media->first();

        $this->update($item, ['media' => [['id' => $foreign->id, 'image' => $this->png()]]])
            ->assertStatus(422)->assertJsonValidationErrors(['media.0.id']);
        $this->update($item, ['remove_media_ids' => [$foreign->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['remove_media_ids']);

        Storage::disk('r2')->assertExists($foreign->image_path);
    }

    public function test_empty_update_and_multipart_over_real_patch_are_rejected(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]]);

        $this->patchJson(self::ADMIN."/{$item->id}", [])->assertStatus(422)->assertJsonValidationErrors(['request']);

        $this->patch(self::ADMIN."/{$item->id}", [], self::JSON_HEADERS + ['Content-Type' => 'multipart/form-data; boundary=x'])
            ->assertStatus(422)->assertJsonPath('errors.request.0', 'Multipart/form-data is not supported on PATCH or PUT. Send a POST request with _method=PATCH.');
    }

    public function test_update_missing_item_returns_404(): void
    {
        $this->as($this->editor);

        $this->patchJson(self::ADMIN.'/999999', ['title_en' => 'x'])->assertStatus(404);
        $this->getJson(self::ADMIN.'/999999')->assertStatus(404);
        $this->getJson(self::ADMIN.'/abc')->assertStatus(404);
    }

    // =========================================================================
    // Status transitions
    // =========================================================================

    public function test_publish_and_unpublish_endpoints_change_status(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]], ['title_en' => 'E', 'title_ar' => 'ع']);

        $published = $this->post(self::ADMIN."/{$item->id}/publish", [], self::JSON_HEADERS)->assertStatus(200)
            ->assertJsonPath('data.status', 'published');
        expect($published->json('data.published_at'))->not->toBeNull();

        $this->post(self::ADMIN."/{$item->id}/unpublish", [], self::JSON_HEADERS)->assertStatus(200)
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.published_at', null);
    }

    public function test_publish_of_an_incomplete_item_is_rejected_and_keeps_it_a_draft(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [], ['title_en' => 'E', 'title_ar' => 'ع']);

        $this->post(self::ADMIN."/{$item->id}/publish", [], self::JSON_HEADERS)->assertStatus(422)->assertJsonValidationErrors(['media']);

        expect($item->fresh()->status)->toBe('draft');
    }

    public function test_update_can_publish_and_return_to_draft_through_the_status_field(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]], ['title_en' => 'E', 'title_ar' => 'ع']);

        $this->update($item, ['status' => 'published'])->assertStatus(200)->assertJsonPath('data.status', 'published');
        $this->update($item, ['status' => 'draft'])->assertStatus(200)->assertJsonPath('data.status', 'draft');
    }

    public function test_a_published_item_cannot_be_updated_into_an_incomplete_state(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]], ['title_en' => 'E', 'title_ar' => 'ع'], published: true);
        $media = $item->media->first();

        $this->update($item, ['title_ar' => ''])->assertStatus(422);
        $this->update($item, ['remove_media_ids' => [$media->id]])->assertStatus(422)->assertJsonValidationErrors(['media']);

        $fresh = $item->fresh();
        expect($fresh->status)->toBe('published')->and($fresh->title_ar)->toBe('ع');
        Storage::disk('r2')->assertExists($media->image_path);
    }

    public function test_unpublishing_then_editing_a_draft_allows_incomplete_content(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]], ['title_en' => 'E', 'title_ar' => 'ع'], published: true);

        $this->update($item, ['status' => 'draft', 'title_ar' => ''])->assertStatus(200)->assertJsonPath('data.status', 'draft');
    }

    // =========================================================================
    // Category changes
    // =========================================================================

    public function test_changing_between_subcategories_of_the_same_kind_keeps_media(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]]);
        $key = $item->media->first()->image_path;

        $this->update($item, $this->sel('branding'))->assertStatus(200)->assertJsonPath('data.subcategory.slug', 'branding');

        Storage::disk('r2')->assertExists($key);
        expect($item->fresh()->media)->toHaveCount(1);
    }

    public function test_changing_to_a_different_kind_requires_explicit_confirmation(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true], ['image' => true]]);
        $keys = $item->media->pluck('image_path')->all();

        $this->update($item, $this->sel('videos'))->assertStatus(422)->assertJsonValidationErrors(['confirm_media_reset']);

        // nothing changed or deleted
        expect($item->fresh()->subcategory_id)->toBe($this->cat('graphic-design')->id);
        expect($item->fresh()->media)->toHaveCount(2);
        foreach ($keys as $key) {
            Storage::disk('r2')->assertExists($key);
        }

        $this->update($item, $this->sel('videos') + ['confirm_media_reset' => true, 'media' => [['url' => 'https://youtu.be/abc']]])
            ->assertStatus(200)->assertJsonPath('data.kind', 'video_urls');

        foreach ($keys as $key) {
            Storage::disk('r2')->assertMissing($key);
        }
        expect($item->fresh()->media)->toHaveCount(1)->and($item->fresh()->media->first()->image_path)->toBeNull();
    }

    public function test_changing_kind_without_stored_media_needs_no_confirmation(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design');

        $this->update($item, $this->sel('videos'))->assertStatus(200)->assertJsonPath('data.kind', 'video_urls');
    }

    public function test_moving_to_web_design_clears_media_and_requires_confirmation(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]]);
        $key = $item->media->first()->image_path;

        // subcategory must be cleared explicitly when moving to a category without subcategories
        $this->update($item, ['category_id' => $this->cat('web-design')->id, 'subcategory_id' => null, 'confirm_media_reset' => false])
            ->assertStatus(422)->assertJsonValidationErrors(['confirm_media_reset']);
        Storage::disk('r2')->assertExists($key);

        $this->update($item, ['category_id' => $this->cat('web-design')->id, 'subcategory_id' => null, 'confirm_media_reset' => true, 'link_1' => 'https://example.com/one'])
            ->assertStatus(200)->assertJsonPath('data.kind', 'web_design')->assertJsonPath('data.link_1', 'https://example.com/one');
        Storage::disk('r2')->assertMissing($key);
    }

    public function test_leaving_web_design_clears_its_links_only_after_confirmation(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('web-design', [['image' => true]], ['link_1' => 'https://example.com/one', 'link_2' => 'https://example.com/two']);

        $this->update($item, $this->sel('graphic-design'))->assertStatus(422)->assertJsonValidationErrors(['confirm_media_reset']);
        expect($item->fresh()->link_1)->not->toBeNull();

        $this->update($item, $this->sel('graphic-design') + ['confirm_media_reset' => true])->assertStatus(200);
        $fresh = $item->fresh();
        expect($fresh->link_1)->toBeNull()->and($fresh->link_2)->toBeNull();
    }

    // =========================================================================
    // Media validation and R2 safety
    // =========================================================================

    public function test_disguised_and_unsupported_images_are_rejected(): void
    {
        $this->as($this->editor);

        $fake = UploadedFile::fake()->createWithContent('malicious.jpg', '<?php echo "pwned"; ?>');
        $this->create($this->sel('graphic-design') + $this->titles() + ['media' => [['image' => $fake]]])
            ->assertStatus(422)->assertJsonValidationErrors(['media.0.image']);

        $svg = UploadedFile::fake()->createWithContent('vector.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $this->create($this->sel('graphic-design') + $this->titles() + ['media' => [['image' => $svg]]])
            ->assertStatus(422);

        $webp = UploadedFile::fake()->createWithContent('photo.png', 'RIFF'.pack('V', 12).'WEBPVP8 '.str_repeat("\0", 16));
        $this->create($this->sel('graphic-design') + $this->titles() + ['media' => [['image' => $webp]]])
            ->assertStatus(422);

        $gif = UploadedFile::fake()->createWithContent('anim.gif', base64_decode('R0lGODlhAQABAAAAACw='));
        $this->create($this->sel('graphic-design') + $this->titles() + ['media' => [['image' => $gif]]])
            ->assertStatus(422);

        $this->assertDatabaseCount('portfolio_items', 0);
        expect($this->r2Files())->toBeEmpty();
    }

    public function test_service_level_sniffing_rejects_non_png_jpeg_even_if_form_rules_pass(): void
    {
        $webp = UploadedFile::fake()->createWithContent('photo.png', 'RIFF'.pack('V', 12).'WEBPVP8 '.str_repeat("\0", 16));

        expect(fn () => app(PortfolioImageService::class)->upload($webp))
            ->toThrow(InvalidImageException::class);
        expect($this->r2Files())->toBeEmpty();
    }

    public function test_images_over_ten_megabytes_are_rejected_and_up_to_ten_are_accepted_by_the_rule(): void
    {
        $this->as($this->editor);

        $this->create($this->sel('graphic-design') + $this->titles() + [
            'media' => [['image' => UploadedFile::fake()->create('huge.png', 10 * 1024 + 1, 'image/png')]],
        ])->assertStatus(422)->assertJsonValidationErrors(['media.0.image']);

        $this->create($this->sel('web-design') + $this->titles() + [
            'featured_image' => UploadedFile::fake()->create('huge.jpg', 11 * 1024, 'image/jpeg'),
        ])->assertStatus(422)->assertJsonValidationErrors(['featured_image']);

        expect(PortfolioImageService::MAX_SIZE_BYTES)->toBe(10 * 1024 * 1024);
        expect($this->r2Files())->toBeEmpty();
    }

    public function test_storage_keys_are_server_generated_and_never_client_controlled(): void
    {
        $this->as($this->editor);

        $evil = UploadedFile::fake()->createWithContent('../../etc/passwd.png', base64_decode(self::FIXTURE_1X1_PNG_BASE64));
        $this->create($this->sel('graphic-design') + $this->titles() + ['media' => [['image' => $evil, 'image_path' => 'portfolio/images/../../x.png']]])
            ->assertStatus(201);

        $row = PortfolioMedia::first();
        expect($row->image_path)->toMatch('#^portfolio/images/[0-9a-f-]{36}\.png$#');
        expect(strpos($row->image_path, 'passwd'))->toBeFalse();
    }

    public function test_storage_upload_failure_creates_no_record(): void
    {
        $this->as($this->editor);
        Config::set('filesystems.disks.r2.url', null);

        $this->create($this->sel('graphic-design') + $this->titles() + ['media' => [['image' => $this->png()]]])
            ->assertStatus(500);

        $this->assertDatabaseCount('portfolio_items', 0);
        expect($this->r2Files())->toBeEmpty();
    }

    public function test_failure_on_a_later_upload_removes_the_earlier_ones(): void
    {
        $this->as($this->editor);

        $real = app(PortfolioImageService::class);
        $calls = 0;
        $mock = \Mockery::mock(PortfolioImageService::class, [app(ImageStorageService::class)])->makePartial();
        $mock->shouldReceive('upload')->andReturnUsing(function ($file) use ($real, &$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('r2 down');
            }

            return $real->upload($file);
        });
        $this->app->instance(PortfolioImageService::class, $mock);

        $this->create($this->sel('graphic-design') + $this->titles() + ['media' => [['image' => $this->png()], ['image' => $this->jpeg()]]])
            ->assertStatus(500);

        $this->assertDatabaseCount('portfolio_items', 0);
        expect($this->r2Files())->toBeEmpty();
    }

    public function test_database_failure_after_upload_removes_new_objects_and_the_item(): void
    {
        $this->as($this->editor);
        PortfolioMedia::creating(fn () => throw new \RuntimeException('DB down'));

        $this->create($this->sel('graphic-design') + $this->titles() + ['media' => [['image' => $this->png()], ['image' => $this->jpeg()]]])
            ->assertStatus(500);

        $this->assertDatabaseCount('portfolio_items', 0);
        $this->assertDatabaseCount('portfolio_media', 0);
        expect($this->r2Files())->toBeEmpty();
    }

    public function test_database_failure_on_replacement_preserves_old_image_and_removes_new_upload(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]]);
        $row = $item->media->first();
        PortfolioMedia::updating(fn () => throw new \RuntimeException('DB down'));

        $this->update($item, ['media' => [['id' => $row->id, 'image' => $this->png()]]])->assertStatus(500);

        expect($row->fresh()->image_path)->toBe($row->image_path);
        Storage::disk('r2')->assertExists($row->image_path);
        expect($this->r2Files())->toHaveCount(1);
    }

    public function test_old_image_is_not_deleted_when_the_replacement_fails_validation(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]]);
        $row = $item->media->first();
        $fake = UploadedFile::fake()->createWithContent('x.jpg', 'not an image');

        $this->update($item, ['media' => [['id' => $row->id, 'image' => $fake]]])->assertStatus(422);

        Storage::disk('r2')->assertExists($row->image_path);
    }

    public function test_replacement_never_deletes_an_image_shared_with_another_media_entry(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]]);
        $row = $item->media->first();
        $other = $this->makeItem('branding');
        PortfolioMedia::create(['portfolio_item_id' => $other->id, 'image_path' => $row->image_path, 'sort_order' => 0]);

        $this->update($item, ['media' => [['id' => $row->id, 'image' => $this->png()]]])->assertStatus(200);

        Storage::disk('r2')->assertExists($row->image_path);
    }

    public function test_concurrent_replacement_deletes_the_committed_image_not_the_stale_one(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]]);
        $row = $item->media->first();
        $stale = $row->image_path;

        $competing = 'portfolio/images/'.fake()->uuid().'.jpg';
        Storage::disk('r2')->put($competing, 'competing');

        $fired = false;
        PortfolioItem::retrieved(function () use (&$fired, $row, $stale, $competing) {
            if ($fired) {
                return;
            }
            $fired = true;
            DB::table('portfolio_media')->where('id', $row->id)->update(['image_path' => $competing]);
            Storage::disk('r2')->delete($stale);
        });

        $this->update($item, ['media' => [['id' => $row->id, 'image' => $this->png()]]])->assertStatus(200);

        $new = $row->fresh()->image_path;
        expect($new)->not->toBe($competing)->and($new)->not->toBe($stale);
        Storage::disk('r2')->assertExists($new);
        Storage::disk('r2')->assertMissing($competing);
        expect($this->r2Files())->toHaveCount(1);
    }

    // =========================================================================
    // Delete
    // =========================================================================

    public function test_admin_and_super_admin_delete_item_and_media(): void
    {
        foreach ([$this->admin, $this->superAdmin] as $actor) {
            $this->as($actor);
            $item = $this->makeItem('graphic-design', [['image' => true], ['image' => true]]);
            $keys = $item->media->pluck('image_path')->all();

            $this->deleteJson(self::ADMIN."/{$item->id}")->assertStatus(200)->assertJsonPath('message', 'Portfolio item deleted successfully.');

            $this->assertDatabaseMissing('portfolio_items', ['id' => $item->id]);
            $this->assertDatabaseMissing('portfolio_media', ['portfolio_item_id' => $item->id]);
            foreach ($keys as $key) {
                Storage::disk('r2')->assertMissing($key);
            }
        }
    }

    public function test_editor_cannot_delete(): void
    {
        $this->as($this->editor);
        $item = $this->makeItem('graphic-design', [['image' => true]]);
        $key = $item->media->first()->image_path;

        $this->deleteJson(self::ADMIN."/{$item->id}")->assertStatus(403);

        $this->assertDatabaseHas('portfolio_items', ['id' => $item->id]);
        Storage::disk('r2')->assertExists($key);
    }

    public function test_delete_keeps_images_still_referenced_elsewhere(): void
    {
        $this->as($this->admin);
        $item = $this->makeItem('graphic-design', [['image' => true]]);
        $other = $this->makeItem('branding');
        $key = $item->media->first()->image_path;
        PortfolioMedia::create(['portfolio_item_id' => $other->id, 'image_path' => $key, 'sort_order' => 0]);

        $this->deleteJson(self::ADMIN."/{$item->id}")->assertStatus(200);

        Storage::disk('r2')->assertExists($key);
    }

    public function test_delete_succeeds_even_if_storage_deletion_fails(): void
    {
        $this->as($this->admin);
        $item = $this->makeItem('graphic-design', [['image' => true]]);

        $mock = \Mockery::mock(PortfolioImageService::class, [app(ImageStorageService::class)])->makePartial();
        $mock->shouldReceive('deleteIfUnshared')->andReturn(false);
        $this->app->instance(PortfolioImageService::class, $mock);

        $this->deleteJson(self::ADMIN."/{$item->id}")->assertStatus(200);
        $this->assertDatabaseMissing('portfolio_items', ['id' => $item->id]);
    }

    public function test_repeated_and_unknown_deletes_return_404(): void
    {
        $this->as($this->admin);
        $item = $this->makeItem('graphic-design');

        $this->deleteJson(self::ADMIN."/{$item->id}")->assertStatus(200);
        $this->deleteJson(self::ADMIN."/{$item->id}")->assertStatus(404);
        $this->deleteJson(self::ADMIN.'/999999')->assertStatus(404);
    }

    // =========================================================================
    // Authorization
    // =========================================================================

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $item = $this->makeItem('graphic-design');

        $this->getJson(self::ADMIN)->assertStatus(401);
        $this->getJson(self::ADMIN.'/categories')->assertStatus(401);
        $this->postJson(self::ADMIN, [])->assertStatus(401);
        $this->getJson(self::ADMIN."/{$item->id}")->assertStatus(401);
        $this->patchJson(self::ADMIN."/{$item->id}", [])->assertStatus(401);
        $this->postJson(self::ADMIN."/{$item->id}/publish")->assertStatus(401);
        $this->postJson(self::ADMIN."/{$item->id}/unpublish")->assertStatus(401);
        $this->deleteJson(self::ADMIN."/{$item->id}")->assertStatus(401);
    }

    public function test_admin_roles_can_create_update_and_publish(): void
    {
        foreach ([$this->admin, $this->superAdmin, $this->editor] as $actor) {
            $this->as($actor);

            $created = $this->create($this->sel('graphic-design') + $this->titles() + $this->withCover() + ['media' => [['image' => $this->png()]]])->assertStatus(201);
            $id = $created->json('data.id');

            $this->post(self::ADMIN."/{$id}", ['_method' => 'PATCH', 'title_en' => 'Edited'], self::JSON_HEADERS)->assertStatus(200);
            $this->post(self::ADMIN."/{$id}/publish", [], self::JSON_HEADERS)->assertStatus(200)->assertJsonPath('data.status', 'published');
            $this->getJson(self::ADMIN."/{$id}")->assertStatus(200);
        }
    }

    // =========================================================================
    // List, search, filters, pagination
    // =========================================================================

    public function test_list_search_filters_and_pagination_work_together(): void
    {
        $this->as($this->editor);
        $this->makeItem('graphic-design', [], ['title_en' => 'Alpha Campaign', 'title_ar' => 'حملة ألفا']);
        $this->makeItem('graphic-design', [['image' => true]], ['title_en' => 'Beta Campaign', 'title_ar' => 'حملة بيتا'], published: true);
        $this->makeItem('branding', [], ['title_en' => 'Gamma Brand', 'title_ar' => 'هوية جاما']);
        $this->makeItem('videos', [['url' => 'https://youtu.be/1']], ['title_en' => 'Delta Video', 'title_ar' => 'فيديو دلتا'], published: true);
        $this->makeItem('web-design', [], ['title_en' => 'Epsilon Site', 'title_ar' => 'موقع إبسيلون']);

        $ids = fn ($response) => collect($response->json('data'))->pluck('title_en')->all();

        // search in English and Arabic
        expect($ids($this->getJson(self::ADMIN.'?search=Campaign')))->toHaveCount(2);
        expect($ids($this->getJson(self::ADMIN.'?search='.urlencode('حملة'))))->toHaveCount(2);
        expect($ids($this->getJson(self::ADMIN.'?search='.urlencode('بيتا'))))->toBe(['Beta Campaign']);
        expect($ids($this->getJson(self::ADMIN.'?search=nothing-matches')))->toBe([]);

        // status
        expect($ids($this->getJson(self::ADMIN.'?status=published')))->toHaveCount(2);
        expect($ids($this->getJson(self::ADMIN.'?status=draft')))->toHaveCount(3);

        // main category and subcategory
        expect($ids($this->getJson(self::ADMIN.'?category_id='.$this->cat('social-media-campaign')->id)))->toHaveCount(3);
        expect($ids($this->getJson(self::ADMIN.'?category_id='.$this->cat('web-design')->id)))->toBe(['Epsilon Site']);
        expect($ids($this->getJson(self::ADMIN.'?subcategory_id='.$this->cat('branding')->id)))->toBe(['Gamma Brand']);

        // combined filters + pagination metadata
        $combined = $this->getJson(self::ADMIN.'?category_id='.$this->cat('social-media-campaign')->id.'&status=draft&search=a&per_page=1&page=2')
            ->assertStatus(200);
        expect($combined->json('meta.total'))->toBe(2)->and($combined->json('meta.per_page'))->toBe(1)->and($combined->json('meta.current_page'))->toBe(2);
        expect($combined->json('data'))->toHaveCount(1);
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $this->as($this->editor);
        $this->makeItem('graphic-design', [], ['title_en' => '100% Real', 'title_ar' => 'x']);
        $this->makeItem('graphic-design', [], ['title_en' => 'Plain', 'title_ar' => 'y']);

        $res = $this->getJson(self::ADMIN.'?search='.urlencode('%'))->assertStatus(200);
        expect(collect($res->json('data'))->pluck('title_en')->all())->toBe(['100% Real']);
    }

    public function test_pagination_has_the_standard_structure_and_deterministic_order(): void
    {
        $this->as($this->editor);
        $same = now()->startOfSecond();
        foreach (range(1, 12) as $i) {
            $this->makeItem('graphic-design', [], ['title_en' => "Item {$i}", 'created_at' => $same, 'updated_at' => $same]);
        }

        $page1 = $this->getJson(self::ADMIN)->assertStatus(200)->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to']]);
        $page2 = $this->getJson(self::ADMIN.'?page=2')->assertStatus(200);

        expect($page1->json('meta.per_page'))->toBe(10)->and($page1->json('meta.total'))->toBe(12);
        $all = array_merge(collect($page1->json('data'))->pluck('id')->all(), collect($page2->json('data'))->pluck('id')->all());
        expect($all)->toHaveCount(12)->and(array_unique($all))->toHaveCount(12);
        expect($all)->toBe(collect($all)->sortDesc()->values()->all());
    }

    public function test_list_items_expose_the_dashboard_fields_without_storage_keys(): void
    {
        $this->as($this->editor);
        $this->makeItem('press-covering', [['image' => true, 'url' => 'https://example.com/a']], ['title_en' => 'T', 'title_ar' => 'ع']);

        $row = $this->getJson(self::ADMIN, ['Accept-Language' => 'ar'])->assertStatus(200)->json('data.0');

        expect($row)->toHaveKeys(['id', 'title', 'title_en', 'title_ar', 'status', 'category', 'subcategory', 'kind', 'cover_image_url', 'media_count', 'created_at']);
        expect($row['title'])->toBe('ع')->and($row['media_count'])->toBe(1);
        expect($row['category']['name'])->toBe($this->cat('pr')->name_ar);
        expect(json_encode($row))->not->toContain('image_path');
    }

    public function test_invalid_filter_values_and_pagination_parameters_are_rejected(): void
    {
        $this->as($this->editor);

        foreach ([
            '?status=archived', '?category_id=999999', '?subcategory_id=999999', '?category_id=abc',
            '?per_page=0', '?per_page=101', '?per_page=abc', '?page=0', '?search='.str_repeat('a', 256),
        ] as $query) {
            $this->getJson(self::ADMIN.$query)->assertStatus(422);
        }

        // a subcategory that belongs to a different parent
        $this->getJson(self::ADMIN.'?category_id='.$this->cat('pr')->id.'&subcategory_id='.$this->cat('branding')->id)
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);
        // a subcategory id is not accepted as a main category filter
        $this->getJson(self::ADMIN.'?category_id='.$this->cat('branding')->id)->assertStatus(422);
    }
}
