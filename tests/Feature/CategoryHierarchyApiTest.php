<?php

namespace Tests\Feature;

use App\Models\Category;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Optional hierarchy modes of GET /api/v1/categories (backward compatible: without them the response is unchanged).
 */
class CategoryHierarchyApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/categories';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);
    }

    private function portfolio(string $slug): Category
    {
        return Category::where('type', 'portfolio')->where('slug', $slug)->firstOrFail();
    }

    public function test_default_response_is_unchanged_parents_with_children(): void
    {
        $response = $this->getJson(self::URL.'?type=portfolio')->assertStatus(200)->assertJsonCount(6, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'children']]]);

        $web = collect($response->json('data'))->firstWhere('slug', 'web-design');
        expect($web['children'])->toBe([]);
    }

    public function test_parents_only_mode_returns_the_six_main_categories_without_children(): void
    {
        foreach (['level=parent', 'include_children=0', 'level=parent&include_children=0'] as $query) {
            $response = $this->getJson(self::URL."?type=portfolio&{$query}")->assertStatus(200)->assertJsonCount(6, 'data');

            foreach ($response->json('data') as $category) {
                expect($category)->not->toHaveKey('children')->and(array_keys($category))->toBe(['id', 'name', 'slug']);
            }
        }
    }

    public function test_children_of_a_selected_parent_only(): void
    {
        $pr = $this->portfolio('pr');

        $response = $this->getJson(self::URL."?type=portfolio&parent_id={$pr->id}")->assertStatus(200)->assertJsonCount(2, 'data');

        expect(collect($response->json('data'))->pluck('slug')->all())->toBe(['media-covering', 'press-covering'])
            ->and(collect($response->json('data'))->pluck('parent_id')->unique()->all())->toBe([$pr->id]);

        $this->getJson(self::URL.'?type=portfolio&parent_id='.$this->portfolio('web-design')->id)->assertStatus(200)->assertJsonPath('data', []);
        $this->getJson(self::URL.'?type=portfolio&parent_id='.$this->portfolio('events')->id)->assertJsonCount(2, 'data');
        $this->getJson(self::URL.'?type=portfolio&parent_id='.$this->portfolio('opening')->id)->assertJsonCount(2, 'data');
    }

    public function test_parent_with_children_mode(): void
    {
        $this->getJson(self::URL.'?type=portfolio&include_children=1')->assertStatus(200)->assertJsonCount(6, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'children' => [['id', 'name', 'slug', 'parent_id']]]]]);
        $this->getJson(self::URL.'?type=portfolio&level=all')->assertStatus(200)->assertJsonStructure(['data' => [['children']]]);
    }

    public function test_names_follow_accept_language_in_every_mode(): void
    {
        $pr = $this->portfolio('pr');

        $this->getJson(self::URL.'?type=portfolio&level=parent', ['Accept-Language' => 'ar'])->assertJsonPath('data.2.name', $pr->name_ar);
        $this->getJson(self::URL.'?type=portfolio&level=parent', ['Accept-Language' => 'en'])->assertJsonPath('data.2.name', $pr->name_en);
        $this->getJson(self::URL."?type=portfolio&parent_id={$pr->id}", ['Accept-Language' => 'ar'])
            ->assertJsonPath('data.0.name', $this->portfolio('media-covering')->name_ar);
        $this->getJson(self::URL.'?type=portfolio&include_children=1', ['Accept-Language' => 'ar'])
            ->assertJsonPath('data.2.children.0.name', $this->portfolio('media-covering')->name_ar);
    }

    public function test_invalid_hierarchy_parameters_are_rejected(): void
    {
        $pr = $this->portfolio('pr');
        $child = $this->portfolio('media-covering');
        $newsParent = Category::where('type', 'news')->whereNull('parent_id')->firstOrFail();

        // a child is not a parent, nor is a category of another module
        $this->getJson(self::URL."?type=portfolio&parent_id={$child->id}")->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
        $this->getJson(self::URL."?type=portfolio&parent_id={$newsParent->id}")->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
        $this->getJson(self::URL.'?type=portfolio&parent_id=999999')->assertStatus(422);
        $this->getJson(self::URL.'?type=portfolio&level=children')->assertStatus(422)->assertJsonValidationErrors(['level']);
        $this->getJson(self::URL."?type=portfolio&parent_id={$pr->id}&level=parent")->assertStatus(422);
        $this->getJson(self::URL.'?type=portfolio&level=parent&include_children=1')->assertStatus(422)->assertJsonValidationErrors(['include_children']);
    }

    public function test_other_category_consumers_stay_compatible(): void
    {
        $this->getJson(self::URL.'?type=news')->assertStatus(200)->assertJsonStructure(['data' => [['id', 'name', 'slug', 'children']]]);
        $blogs = $this->getJson(self::URL.'?type=blogs')->assertStatus(200);
        expect($blogs->json('data.0'))->not->toHaveKey('children');

        $news = $this->getJson(self::URL.'?type=news&level=parent')->assertStatus(200);
        expect($news->json('data.0'))->not->toHaveKey('children');

        $newsParent = Category::where('type', 'news')->whereNull('parent_id')->whereHas('children')->firstOrFail();
        $this->getJson(self::URL."?type=news&parent_id={$newsParent->id}")->assertStatus(200)->assertJsonStructure(['data' => [['id', 'name', 'slug', 'parent_id']]]);
    }

    public function test_portfolio_category_ids_and_slugs_are_the_seeded_ones(): void
    {
        $slugs = Category::where('type', 'portfolio')->whereNull('parent_id')->orderBy('sort_order')->pluck('slug')->all();
        expect($slugs)->toBe(['social-media-campaign', 'media-production', 'pr', 'events', 'opening', 'web-design']);
        expect(Category::where('type', 'portfolio')->whereNotNull('parent_id')->count())->toBe(9);
    }
}
