<?php

namespace Tests\Feature\Portfolio;

use App\Models\PortfolioItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Portfolio\Concerns\PortfolioTestHelpers;
use Tests\TestCase;

class PortfolioPublicApiTest extends TestCase
{
    use PortfolioTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPortfolio();
    }

    public function test_only_published_items_are_listed_without_authentication(): void
    {
        $published = $this->makeItem('graphic-design', [['image' => true]], ['title_en' => 'Live', 'title_ar' => 'منشور'], published: true);
        $this->makeItem('graphic-design', [['image' => true]], ['title_en' => 'Hidden', 'title_ar' => 'مسودة']);

        $response = $this->getJson(self::PUBLIC)->assertStatus(200);

        expect(collect($response->json('data'))->pluck('id')->all())->toBe([$published->id]);
        expect($response->json('meta.total'))->toBe(1);
    }

    public function test_draft_details_are_not_accessible_publicly(): void
    {
        $draft = $this->makeItem('graphic-design', [['image' => true]]);

        $this->getJson(self::PUBLIC."/{$draft->id}")->assertStatus(404);
        $this->getJson(self::PUBLIC.'/999999')->assertStatus(404);
        $this->getJson(self::PUBLIC.'/abc')->assertStatus(404);
    }

    public function test_published_details_are_visible_and_unpublishing_hides_them_again(): void
    {
        $item = $this->makeItem('graphic-design', [['image' => true]], published: true);

        $this->getJson(self::PUBLIC."/{$item->id}")->assertStatus(200)->assertJsonPath('data.id', $item->id);

        $item->forceFill(['status' => PortfolioItem::STATUS_DRAFT, 'published_at' => null])->save();

        $this->getJson(self::PUBLIC."/{$item->id}")->assertStatus(404);
        expect($this->getJson(self::PUBLIC)->json('data'))->toBe([]);
    }

    public function test_responses_are_localized_by_accept_language(): void
    {
        $item = $this->makeItem('pr', [], [], published: true);
        $press = $this->makeItem('press-covering', [['image' => true, 'url' => 'https://example.com/a']], ['title_en' => 'English title', 'title_ar' => 'عنوان عربي'], published: true);

        $ar = $this->getJson(self::PUBLIC."/{$press->id}", ['Accept-Language' => 'ar'])->assertStatus(200);
        $en = $this->getJson(self::PUBLIC."/{$press->id}", ['Accept-Language' => 'en'])->assertStatus(200);
        $default = $this->getJson(self::PUBLIC."/{$press->id}")->assertStatus(200);

        expect($ar->json('data.title'))->toBe('عنوان عربي')->and($en->json('data.title'))->toBe('English title');
        expect($default->json('data.title'))->toBe('English title');
        expect($ar->json('data.category.name'))->toBe($this->cat('pr')->name_ar);
        expect($ar->json('data.subcategory.name'))->toBe($this->cat('press-covering')->name_ar);
        expect($en->json('data.subcategory.name'))->toBe($this->cat('press-covering')->name_en);
    }

    public function test_category_and_subcategory_filters_and_pagination(): void
    {
        $a = $this->makeItem('graphic-design', [['image' => true]], published: true);
        $b = $this->makeItem('branding', [['image' => true]], published: true);
        $c = $this->makeItem('videos', [['url' => 'https://youtu.be/1']], published: true);
        $draftBranding = $this->makeItem('branding', [['image' => true]]);

        $ids = fn ($r) => collect($r->json('data'))->pluck('id')->sort()->values()->all();

        expect($ids($this->getJson(self::PUBLIC.'?category_id='.$this->cat('social-media-campaign')->id)))->toBe([$a->id, $b->id]);
        expect($ids($this->getJson(self::PUBLIC.'?subcategory_id='.$this->cat('branding')->id)))->toBe([$b->id]);
        expect($ids($this->getJson(self::PUBLIC.'?category_id='.$this->cat('media-production')->id)))->toBe([$c->id]);
        expect($ids($this->getJson(self::PUBLIC.'?category_id='.$this->cat('social-media-campaign')->id.'&subcategory_id='.$this->cat('branding')->id)))->toBe([$b->id]);

        $page = $this->getJson(self::PUBLIC.'?per_page=2&page=2')->assertStatus(200);
        expect($page->json('meta.total'))->toBe(3)->and($page->json('meta.last_page'))->toBe(2)->and($page->json('data'))->toHaveCount(1);
        expect(collect($this->getJson(self::PUBLIC)->json('data'))->pluck('id')->all())->not->toContain($draftBranding->id);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        foreach (['?category_id=999999', '?subcategory_id=999999', '?per_page=0', '?per_page=101', '?page=0', '?category_id='.$this->cat('branding')->id] as $query) {
            $this->getJson(self::PUBLIC.$query)->assertStatus(422);
        }

        $this->getJson(self::PUBLIC.'?category_id='.$this->cat('pr')->id.'&subcategory_id='.$this->cat('branding')->id)->assertStatus(422);
    }

    public function test_public_payload_has_media_and_links_but_no_internal_data(): void
    {
        $web = $this->makeItem('web-design', [['image' => true]], ['link_1' => 'https://example.com/one', 'link_2' => 'https://example.com/two', 'link_3' => 'https://example.com/three'], published: true);
        $press = $this->makeItem('press-covering', [['image' => true, 'url' => 'https://example.com/a']], published: true);
        $video = $this->makeItem('opening-video', [['url' => 'https://youtu.be/9']], published: true);

        $webData = $this->getJson(self::PUBLIC."/{$web->id}")->json('data');
        expect($webData['kind'])->toBe('web_design')->and($webData['subcategory'])->toBeNull();
        expect($webData['link_1'])->toBe('https://example.com/one')->and($webData['link_2'])->toBe('https://example.com/two')->and($webData['link_3'])->toBe('https://example.com/three');
        expect($webData['media'][0]['image_url'])->toStartWith('https://pub-r2.waai.test/portfolio/images/');

        $pressData = $this->getJson(self::PUBLIC."/{$press->id}")->json('data');
        expect($pressData['media'][0]['url'])->toBe('https://example.com/a')->and($pressData['media'][0]['image_url'])->not->toBeNull();

        $videoData = $this->getJson(self::PUBLIC."/{$video->id}")->json('data');
        expect($videoData['media'][0]['url'])->toBe('https://youtu.be/9')->and($videoData['media'][0]['image_url'])->toBeNull();
        expect($videoData['category']['slug'])->toBe('opening')->and($videoData['subcategory']['slug'])->toBe('opening-video');

        $raw = json_encode($this->getJson(self::PUBLIC)->json());
        foreach (['image_path', 'portfolio/images/../', '"status"', 'title_en', 'title_ar', 'updated_at'] as $leak) {
            expect($raw)->not->toContain($leak);
        }
    }

    public function test_public_endpoints_are_read_only(): void
    {
        $item = $this->makeItem('graphic-design', [['image' => true]], published: true);

        $this->postJson(self::PUBLIC, [])->assertStatus(405);
        $this->deleteJson(self::PUBLIC."/{$item->id}")->assertStatus(405);
        $this->putJson(self::PUBLIC."/{$item->id}", [])->assertStatus(405);
    }

    public function test_listing_order_is_newest_published_first_and_stable(): void
    {
        $first = $this->makeItem('graphic-design', [['image' => true]], ['published_at' => now()->subDays(2)], published: true);
        $second = $this->makeItem('graphic-design', [['image' => true]], ['published_at' => now()->subDay()], published: true);
        $third = $this->makeItem('graphic-design', [['image' => true]], ['published_at' => now()->subDay()], published: true);

        $order = collect($this->getJson(self::PUBLIC)->json('data'))->pluck('id')->all();

        expect($order)->toBe([$third->id, $second->id, $first->id]);
    }
}
