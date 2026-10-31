<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Support\Portfolio\PortfolioKind;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioKindTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);
    }

    private function cat(string $slug): Category
    {
        return Category::where('type', 'portfolio')->where('slug', $slug)->firstOrFail();
    }

    public function test_every_seeded_portfolio_subcategory_resolves_to_exactly_one_kind(): void
    {
        $expected = [
            'graphic-design' => PortfolioKind::IMAGES,
            'branding' => PortfolioKind::IMAGES,
            'media-covering' => PortfolioKind::IMAGES,
            'events-image' => PortfolioKind::IMAGES,
            'opening-image' => PortfolioKind::IMAGES,
            'press-covering' => PortfolioKind::IMAGES_WITH_URL,
            'videos' => PortfolioKind::VIDEO_URLS,
            'events-video' => PortfolioKind::VIDEO_URLS,
            'opening-video' => PortfolioKind::VIDEO_URLS,
        ];

        $children = Category::where('type', 'portfolio')->whereNotNull('parent_id')->get();
        expect($children)->toHaveCount(count($expected));

        foreach ($children as $child) {
            expect(PortfolioKind::resolve($child->parent, $child))->toBe($expected[$child->slug], $child->slug);
        }
    }

    public function test_web_design_resolves_without_a_subcategory_and_rejects_one(): void
    {
        $web = $this->cat('web-design');

        expect(PortfolioKind::resolve($web, null))->toBe(PortfolioKind::WEB_DESIGN);
        expect(PortfolioKind::resolve($web, $this->cat('branding')))->toBeNull();
    }

    public function test_parent_categories_with_children_do_not_resolve_without_a_subcategory(): void
    {
        foreach (['social-media-campaign', 'media-production', 'pr', 'events', 'opening'] as $slug) {
            expect(PortfolioKind::resolve($this->cat($slug), null))->toBeNull($slug);
        }
        expect(PortfolioKind::resolve(null, null))->toBeNull();
    }

    public function test_unmapped_subcategories_fail_closed(): void
    {
        $parent = $this->cat('pr');
        $unknown = Category::create(['type' => 'portfolio', 'slug' => 'brand-new', 'name_en' => 'New', 'name_ar' => 'جديد', 'parent_id' => $parent->id, 'sort_order' => 9]);

        expect(PortfolioKind::resolve($parent, $unknown))->toBeNull();
    }

    public function test_events_and_opening_children_have_distinct_identities(): void
    {
        $ids = collect(['events-image', 'events-video', 'opening-image', 'opening-video'])->map(fn ($s) => $this->cat($s)->id);

        expect($ids->unique())->toHaveCount(4);
        expect($this->cat('events-image')->parent_id)->not->toBe($this->cat('opening-image')->parent_id);
    }

    public function test_news_categories_are_never_treated_as_portfolio_categories(): void
    {
        $news = Category::where('type', 'news')->whereNull('parent_id')->first();

        expect(PortfolioKind::resolve($news, null))->toBeNull();
    }
}
