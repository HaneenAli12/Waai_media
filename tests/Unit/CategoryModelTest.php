<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Rules\ValidCategoryForModule;
use App\Support\LocaleNegotiator;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CategoryModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);
    }

    public function test_category_parent_and_children_relationships(): void
    {
        $entertainment = Category::where('type', 'news')->where('slug', 'entertainment')->firstOrFail();
        $celebrities = Category::where('type', 'news')->where('slug', 'celebrities')->firstOrFail();

        $this->assertTrue($entertainment->isOrganizationalParent());
        $this->assertFalse($celebrities->isOrganizationalParent());

        $this->assertCount(4, $entertainment->children);
        $this->assertSame($entertainment->id, $celebrities->parent->id);
        $this->assertNull($entertainment->parent);
    }

    public function test_scope_for_type_returns_only_top_level_categories_in_order(): void
    {
        $newsParents = Category::forType('news')->get();
        $this->assertCount(9, $newsParents);
        foreach ($newsParents as $category) {
            $this->assertNull($category->parent_id);
            $this->assertSame('news', $category->type);
        }

        $blogs = Category::forType('blogs')->get();
        $this->assertCount(5, $blogs);

        $portfolio = Category::forType('portfolio')->get();
        $this->assertCount(6, $portfolio);
    }

    public function test_category_get_localized_name(): void
    {
        $health = Category::where('type', 'news')->where('slug', 'health')->firstOrFail();

        $this->assertSame('Health', $health->getLocalizedName('en'));
        $this->assertSame('الصحة', $health->getLocalizedName('ar'));
        $this->assertSame('الصحة', $health->getLocalizedName('ar-SA'));
        $this->assertSame('Health', $health->getLocalizedName('unknown-lang'));
        $this->assertSame('Health', $health->getLocalizedName(null));
    }

    public function test_locale_negotiator_weighted_resolution(): void
    {
        $this->assertSame('ar', LocaleNegotiator::negotiate('ar,en;q=0.8'));
        $this->assertSame('en', LocaleNegotiator::negotiate('en,ar;q=0.8'));
        $this->assertSame('ar', LocaleNegotiator::negotiate('fr,ar;q=0.9,en;q=0.5'));
        $this->assertSame('en', LocaleNegotiator::negotiate('fr,es,de'));
        $this->assertSame('en', LocaleNegotiator::negotiate(''));
        $this->assertSame('en', LocaleNegotiator::negotiate(null));
    }

    public function test_valid_category_for_module_accepts_valid_leaf_category(): void
    {
        $health = Category::where('type', 'news')->where('slug', 'health')->firstOrFail();

        $validator = Validator::make(
            ['category_id' => $health->id],
            ['category_id' => [new ValidCategoryForModule('news')]]
        );

        $this->assertTrue($validator->passes());
    }

    public function test_valid_category_for_module_rejects_parent_with_children_for_news(): void
    {
        $entertainment = Category::where('type', 'news')->where('slug', 'entertainment')->firstOrFail();

        $validator = Validator::make(
            ['category_id' => $entertainment->id],
            ['category_id' => [new ValidCategoryForModule('news')]]
        );

        $this->assertFalse($validator->passes());
        $this->assertContains(
            'The selected category id is an organizational parent category and cannot be assigned directly. Please select a subcategory.',
            $validator->errors()->all()
        );
    }

    public function test_valid_category_for_module_accepts_entertainment_child_for_news(): void
    {
        $celebrities = Category::where('type', 'news')->where('slug', 'celebrities')->firstOrFail();

        $validator = Validator::make(
            ['category_id' => $celebrities->id],
            ['category_id' => [new ValidCategoryForModule('news')]]
        );

        $this->assertTrue($validator->passes());
    }

    public function test_valid_category_for_module_rejects_cross_module_category(): void
    {
        $blogCategory = Category::where('type', 'blogs')->where('slug', 'case-studies')->firstOrFail();

        // Attempt to validate a Blog category for a News article
        $validatorNews = Validator::make(
            ['category_id' => $blogCategory->id],
            ['category_id' => [new ValidCategoryForModule('news')]]
        );

        $this->assertFalse($validatorNews->passes());
        $this->assertContains(
            'The selected category id does not belong to the news module.',
            $validatorNews->errors()->all()
        );

        // Valid for blogs
        $validatorBlog = Validator::make(
            ['category_id' => $blogCategory->id],
            ['category_id' => [new ValidCategoryForModule('blogs')]]
        );

        $this->assertTrue($validatorBlog->passes());
    }

    public function test_valid_category_for_module_rejects_non_existent_and_invalid_ids(): void
    {
        $validatorNonExistent = Validator::make(
            ['category_id' => 99999],
            ['category_id' => [new ValidCategoryForModule('news')]]
        );

        $this->assertFalse($validatorNonExistent->passes());
        $this->assertContains(
            'The selected category id does not exist.',
            $validatorNonExistent->errors()->all()
        );

        $validatorInvalid = Validator::make(
            ['category_id' => 'not-an-id'],
            ['category_id' => [new ValidCategoryForModule('news')]]
        );

        $this->assertFalse($validatorInvalid->passes());
        $this->assertContains(
            'The selected category id is invalid.',
            $validatorInvalid->errors()->all()
        );
    }

    public function test_portfolio_category_parent_and_children_relationships(): void
    {
        // 1. Social Media Campaign -> Graphic Design, Branding
        $social = Category::where('type', 'portfolio')->where('slug', 'social-media-campaign')->firstOrFail();
        $this->assertTrue($social->isOrganizationalParent());
        $this->assertCount(2, $social->children);
        $graphicDesign = Category::where('type', 'portfolio')->where('slug', 'graphic-design')->firstOrFail();
        $branding = Category::where('type', 'portfolio')->where('slug', 'branding')->firstOrFail();
        $this->assertSame($social->id, $graphicDesign->parent_id);
        $this->assertSame($social->id, $branding->parent_id);
        $this->assertSame('Graphic Design', $graphicDesign->name_en);
        $this->assertSame('Branding', $branding->name_en);

        // 2. Media Production -> Videos
        $mediaProduction = Category::where('type', 'portfolio')->where('slug', 'media-production')->firstOrFail();
        $this->assertTrue($mediaProduction->isOrganizationalParent());
        $this->assertCount(1, $mediaProduction->children);
        $videos = Category::where('type', 'portfolio')->where('slug', 'videos')->firstOrFail();
        $this->assertSame($mediaProduction->id, $videos->parent_id);
        $this->assertSame('Videos', $videos->name_en);

        // 3. PR -> Media Covering, Press Covering
        $pr = Category::where('type', 'portfolio')->where('slug', 'pr')->firstOrFail();
        $this->assertTrue($pr->isOrganizationalParent());
        $this->assertCount(2, $pr->children);
        $mediaCovering = Category::where('type', 'portfolio')->where('slug', 'media-covering')->firstOrFail();
        $pressCovering = Category::where('type', 'portfolio')->where('slug', 'press-covering')->firstOrFail();
        $this->assertSame($pr->id, $mediaCovering->parent_id);
        $this->assertSame($pr->id, $pressCovering->parent_id);
        $this->assertSame('Media Covering', $mediaCovering->name_en);
        $this->assertSame('Press Covering', $pressCovering->name_en);

        // 4 & 5. Events & Opening -> Distinct Image & Video records
        $events = Category::where('type', 'portfolio')->where('slug', 'events')->firstOrFail();
        $opening = Category::where('type', 'portfolio')->where('slug', 'opening')->firstOrFail();
        $this->assertTrue($events->isOrganizationalParent());
        $this->assertTrue($opening->isOrganizationalParent());
        $this->assertCount(2, $events->children);
        $this->assertCount(2, $opening->children);

        $eventsImage = Category::where('type', 'portfolio')->where('slug', 'events-image')->firstOrFail();
        $openingImage = Category::where('type', 'portfolio')->where('slug', 'opening-image')->firstOrFail();
        $eventsVideo = Category::where('type', 'portfolio')->where('slug', 'events-video')->firstOrFail();
        $openingVideo = Category::where('type', 'portfolio')->where('slug', 'opening-video')->firstOrFail();

        // Image under Events and Image under Opening are distinct records
        $this->assertNotSame($eventsImage->id, $openingImage->id);
        $this->assertSame($events->id, $eventsImage->parent_id);
        $this->assertSame($opening->id, $openingImage->parent_id);
        $this->assertSame('Image', $eventsImage->name_en);
        $this->assertSame('Image', $openingImage->name_en);

        // Video under Events and Video under Opening are distinct records
        $this->assertNotSame($eventsVideo->id, $openingVideo->id);
        $this->assertSame($events->id, $eventsVideo->parent_id);
        $this->assertSame($opening->id, $openingVideo->parent_id);
        $this->assertSame('Video', $eventsVideo->name_en);
        $this->assertSame('Video', $openingVideo->name_en);

        // 6. Web design is a top-level category without children
        $webDesign = Category::where('type', 'portfolio')->where('slug', 'web-design')->firstOrFail();
        $this->assertFalse($webDesign->isOrganizationalParent());
        $this->assertCount(0, $webDesign->children);

        // Subcategories must not be organizational parents
        $allChildren = [$graphicDesign, $branding, $videos, $mediaCovering, $pressCovering, $eventsImage, $eventsVideo, $openingImage, $openingVideo];
        $this->assertCount(9, $allChildren);
        foreach ($allChildren as $child) {
            $this->assertFalse($child->isOrganizationalParent());
            $this->assertCount(0, $child->children);
            $this->assertNotNull($child->parent_id);
        }

        // Exact category counts
        $this->assertSame(6, Category::where('type', 'portfolio')->whereNull('parent_id')->count());
        $this->assertSame(9, Category::where('type', 'portfolio')->whereNotNull('parent_id')->count());
        $this->assertSame(5, Category::where('type', 'blogs')->whereNull('parent_id')->count());
        $this->assertSame(0, Category::where('type', 'blogs')->whereNotNull('parent_id')->count());
    }
}
