<?php

namespace Tests\Feature;

use App\Models\Category;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed initial static categories
        $this->seed(CategorySeeder::class);
    }

    public function test_public_endpoint_is_accessible_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/categories?type=news');

        $response->assertStatus(200);
    }

    public function test_missing_type_parameter_returns_422(): void
    {
        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type'])
            ->assertJsonPath('errors.type.0', 'The category type is required.');
    }

    public function test_invalid_type_parameter_returns_422(): void
    {
        $response = $this->getJson('/api/v1/categories?type=invalid_type');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type'])
            ->assertJsonPath('errors.type.0', 'The selected category type is invalid. Supported types are news, blogs, and portfolio.');
    }

    public function test_news_categories_returns_exact_count_and_order_in_english_by_default(): void
    {
        $response = $this->getJson('/api/v1/categories?type=news');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'children' => [
                            '*' => ['id', 'name', 'slug', 'parent_id'],
                        ],
                    ],
                ],
            ]);

        $data = $response->json('data');

        // Exactly 9 top-level News categories
        $this->assertCount(9, $data);

        $expectedNews = [
            1 => ['slug' => 'health', 'name' => 'Health', 'childrenCount' => 0],
            2 => ['slug' => 'food', 'name' => 'Food', 'childrenCount' => 0],
            3 => ['slug' => 'fashion-beauty', 'name' => 'Fashion & Beauty', 'childrenCount' => 0],
            4 => ['slug' => 'travel', 'name' => 'Travel', 'childrenCount' => 0],
            5 => ['slug' => 'economy', 'name' => 'Economy', 'childrenCount' => 0],
            6 => ['slug' => 'sports', 'name' => 'Sports', 'childrenCount' => 0],
            7 => ['slug' => 'entertainment', 'name' => 'Entertainment', 'childrenCount' => 4],
            8 => ['slug' => 'podcast', 'name' => 'Podcast', 'childrenCount' => 0],
            9 => ['slug' => 'events', 'name' => 'Events', 'childrenCount' => 0],
        ];

        foreach ($expectedNews as $index => $expected) {
            $item = $data[$index - 1];
            $this->assertSame($expected['slug'], $item['slug']);
            $this->assertSame($expected['name'], $item['name']);
            $this->assertIsInt($item['id']);
            $this->assertIsArray($item['children']);
            $this->assertCount($expected['childrenCount'], $item['children']);
        }

        // Verify Entertainment children hierarchy (exact 4 children in order)
        $entertainment = $data[6];
        $this->assertSame('entertainment', $entertainment['slug']);

        $expectedChildren = [
            1 => ['slug' => 'celebrities', 'name' => 'Celebrities'],
            2 => ['slug' => 'in-theaters', 'name' => 'In Theaters'],
            3 => ['slug' => 'tv-shows', 'name' => 'TV Shows'],
            4 => ['slug' => 'book-reviews', 'name' => 'Book Reviews'],
        ];

        foreach ($expectedChildren as $childIndex => $expectedChild) {
            $child = $entertainment['children'][$childIndex - 1];
            $this->assertSame($expectedChild['slug'], $child['slug']);
            $this->assertSame($expectedChild['name'], $child['name']);
            $this->assertIsInt($child['id']);
            $this->assertSame($entertainment['id'], $child['parent_id']);
        }
    }

    public function test_news_categories_returns_exact_arabic_names_with_accept_language_ar(): void
    {
        $response = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/categories?type=news');

        $response->assertStatus(200);
        $data = $response->json('data');

        $expectedArabicNews = [
            'الصحة',
            'الطعام',
            'الموضة والجمال',
            'السفر',
            'الاقتصاد',
            'الرياضة',
            'الترفيه',
            'بودكاست',
            'الفعاليات',
        ];

        foreach ($expectedArabicNews as $index => $expectedArabicName) {
            $this->assertSame($expectedArabicName, $data[$index]['name']);
        }

        // Verify Arabic names for Entertainment children
        $entertainmentChildren = $data[6]['children'];
        $this->assertCount(4, $entertainmentChildren);

        $expectedArabicChildren = [
            'المشاهير',
            'في دور السينما',
            'البرامج والمسلسلات التلفزيونية',
            'مراجعات الكتب',
        ];

        foreach ($expectedArabicChildren as $index => $expectedChildName) {
            $this->assertSame($expectedChildName, $entertainmentChildren[$index]['name']);
        }
    }

    public function test_blogs_categories_returns_exact_5_flat_categories_in_english_by_default(): void
    {
        $response = $this->getJson('/api/v1/categories?type=blogs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug'],
                ],
            ]);

        $data = $response->json('data');

        // Exactly 5 categories
        $this->assertCount(5, $data);

        $expectedBlogs = [
            1 => ['slug' => 'digital-marketing-trends', 'name' => 'Digital Marketing Trends'],
            2 => ['slug' => 'case-studies', 'name' => 'Case Studies'],
            3 => ['slug' => 'tips-guides', 'name' => 'Tips & Guides'],
            4 => ['slug' => 'creative-ideas', 'name' => 'Creative Ideas'],
            5 => ['slug' => 'behind-the-scenes', 'name' => 'Behind The Scenes'],
        ];

        foreach ($expectedBlogs as $index => $expected) {
            $item = $data[$index - 1];
            $this->assertSame($expected['slug'], $item['slug']);
            $this->assertSame($expected['name'], $item['name']);
            $this->assertIsInt($item['id']);
            // Flat structure: must NOT have 'children' key
            $this->assertArrayNotHasKey('children', $item);
        }

        // Database checks for Blogs integrity
        $this->assertSame(5, Category::where('type', 'blogs')->count());
        $this->assertSame(5, Category::where('type', 'blogs')->whereNull('parent_id')->count());
        $this->assertSame(0, Category::where('type', 'blogs')->whereNotNull('parent_id')->count());
    }

    public function test_blogs_categories_returns_exact_arabic_names_with_accept_language_ar(): void
    {
        $response = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/categories?type=blogs');

        $response->assertStatus(200);
        $data = $response->json('data');

        $expectedArabicBlogs = [
            'اتجاهات التسويق الرقمي',
            'دراسات الحالة',
            'نصائح وأدلة إرشادية',
            'أفكار إبداعية',
            'خلف الكواليس',
        ];

        foreach ($expectedArabicBlogs as $index => $expectedName) {
            $this->assertSame($expectedName, $data[$index]['name']);
            $this->assertArrayNotHasKey('children', $data[$index]);
        }
    }

    public function test_portfolio_categories_returns_exact_6_categories_with_hierarchy_in_english_by_default(): void
    {
        $response = $this->getJson('/api/v1/categories?type=portfolio');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'children' => [
                            '*' => ['id', 'name', 'slug', 'parent_id'],
                        ],
                    ],
                ],
            ]);

        $data = $response->json('data');

        // Exactly 6 top-level categories
        $this->assertCount(6, $data);

        $expectedPortfolio = [
            1 => [
                'slug' => 'social-media-campaign',
                'name' => 'Social Media Campaign',
                'children' => [
                    ['slug' => 'graphic-design', 'name' => 'Graphic Design'],
                    ['slug' => 'branding', 'name' => 'Branding'],
                ],
            ],
            2 => [
                'slug' => 'media-production',
                'name' => 'Media Production',
                'children' => [
                    ['slug' => 'videos', 'name' => 'Videos'],
                ],
            ],
            3 => [
                'slug' => 'pr',
                'name' => 'PR',
                'children' => [
                    ['slug' => 'media-covering', 'name' => 'Media Covering'],
                    ['slug' => 'press-covering', 'name' => 'Press Covering'],
                ],
            ],
            4 => [
                'slug' => 'events',
                'name' => 'Events',
                'children' => [
                    ['slug' => 'events-image', 'name' => 'Image'],
                    ['slug' => 'events-video', 'name' => 'Video'],
                ],
            ],
            5 => [
                'slug' => 'opening',
                'name' => 'Opening',
                'children' => [
                    ['slug' => 'opening-image', 'name' => 'Image'],
                    ['slug' => 'opening-video', 'name' => 'Video'],
                ],
            ],
            6 => [
                'slug' => 'web-design',
                'name' => 'Web Design',
                'children' => [],
            ],
        ];

        foreach ($expectedPortfolio as $index => $expected) {
            $item = $data[$index - 1];
            $this->assertSame($expected['slug'], $item['slug']);
            $this->assertSame($expected['name'], $item['name']);
            $this->assertIsInt($item['id']);
            $this->assertIsArray($item['children']);
            $this->assertCount(count($expected['children']), $item['children']);

            foreach ($expected['children'] as $cIndex => $expectedChild) {
                $child = $item['children'][$cIndex];
                $this->assertSame($expectedChild['slug'], $child['slug']);
                $this->assertSame($expectedChild['name'], $child['name']);
                $this->assertIsInt($child['id']);
                $this->assertSame($item['id'], $child['parent_id']);
            }
        }

        // Explicit verification that Events and Opening have distinct Image and Video records
        $eventsCategory = $data[3];
        $openingCategory = $data[4];

        $eventsImage = $eventsCategory['children'][0];
        $eventsVideo = $eventsCategory['children'][1];
        $openingImage = $openingCategory['children'][0];
        $openingVideo = $openingCategory['children'][1];

        $this->assertNotSame($eventsImage['id'], $openingImage['id']);
        $this->assertNotSame($eventsImage['slug'], $openingImage['slug']);
        $this->assertNotSame($eventsImage['parent_id'], $openingImage['parent_id']);
        $this->assertSame($eventsCategory['id'], $eventsImage['parent_id']);
        $this->assertSame($openingCategory['id'], $openingImage['parent_id']);

        $this->assertNotSame($eventsVideo['id'], $openingVideo['id']);
        $this->assertNotSame($eventsVideo['slug'], $openingVideo['slug']);
        $this->assertNotSame($eventsVideo['parent_id'], $openingVideo['parent_id']);
        $this->assertSame($eventsCategory['id'], $eventsVideo['parent_id']);
        $this->assertSame($openingCategory['id'], $openingVideo['parent_id']);
    }

    public function test_portfolio_categories_returns_exact_arabic_names_with_accept_language_ar(): void
    {
        $response = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/categories?type=portfolio');

        $response->assertStatus(200);
        $data = $response->json('data');

        $expectedArabicPortfolio = [
            0 => [
                'name' => 'حملات وسائل التواصل الاجتماعي',
                'children' => ['التصميم الجرافيكي', 'الهوية البصرية'],
            ],
            1 => [
                'name' => 'الإنتاج الإعلامي',
                'children' => ['الفيديوهات'],
            ],
            2 => [
                'name' => 'العلاقات العامة',
                'children' => ['التغطية الإعلامية', 'التغطية الصحفية'],
            ],
            3 => [
                'name' => 'الفعاليات',
                'children' => ['الصور', 'الفيديو'],
            ],
            4 => [
                'name' => 'الافتتاحات',
                'children' => ['الصور', 'الفيديو'],
            ],
            5 => [
                'name' => 'تصميم المواقع الإلكترونية',
                'children' => [],
            ],
        ];

        foreach ($expectedArabicPortfolio as $index => $expected) {
            $this->assertSame($expected['name'], $data[$index]['name']);
            $this->assertCount(count($expected['children']), $data[$index]['children']);

            foreach ($expected['children'] as $cIndex => $childName) {
                $this->assertSame($childName, $data[$index]['children'][$cIndex]['name']);
            }
        }
    }

    public function test_localization_supports_regional_tags_and_quality_weights(): void
    {
        // 1. Regional tag ar-SA should resolve to Arabic
        $resAr = $this->withHeader('Accept-Language', 'ar-SA,ar;q=0.9,en;q=0.8')
            ->getJson('/api/v1/categories?type=news');
        $resAr->assertStatus(200);
        $this->assertSame('الصحة', $resAr->json('data.0.name'));

        // 2. English primary preference over Arabic
        $resEn = $this->withHeader('Accept-Language', 'en-US,en;q=0.9,ar;q=0.8')
            ->getJson('/api/v1/categories?type=news');
        $resEn->assertStatus(200);
        $this->assertSame('Health', $resEn->json('data.0.name'));

        // 3. Higher weight on Arabic (q=0.9 vs q=0.5)
        $resWeightedAr = $this->withHeader('Accept-Language', 'en;q=0.5,ar;q=0.9')
            ->getJson('/api/v1/categories?type=news');
        $resWeightedAr->assertStatus(200);
        $this->assertSame('الصحة', $resWeightedAr->json('data.0.name'));

        // 4. Unsupported language (e.g. French/Spanish) falls back to English default
        $resFallback = $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9,es;q=0.8')
            ->getJson('/api/v1/categories?type=news');
        $resFallback->assertStatus(200);
        $this->assertSame('Health', $resFallback->json('data.0.name'));

        // 5. Empty header falls back to English default
        $resEmpty = $this->withHeader('Accept-Language', '')
            ->getJson('/api/v1/categories?type=news');
        $resEmpty->assertStatus(200);
        $this->assertSame('Health', $resEmpty->json('data.0.name'));
    }

    public function test_seeder_is_idempotent_preserves_stable_ids_and_never_duplicates(): void
    {
        // Initial state seeded in setUp (33 total: 13 News + 5 Blogs + 15 Portfolio)
        $this->assertSame(33, Category::count());
        $this->assertSame(13, Category::where('type', 'news')->count());
        $this->assertSame(5, Category::where('type', 'blogs')->count());
        $this->assertSame(15, Category::where('type', 'portfolio')->count());

        $initialRows = Category::orderBy('id')->get(['id', 'type', 'name_en', 'name_ar', 'slug', 'parent_id', 'sort_order'])->toArray();

        // Run seeder second time
        $seeder = new CategorySeeder;
        $seeder->run();

        // Count must remain exactly 33
        $this->assertSame(33, Category::count());
        $this->assertSame($initialRows, Category::orderBy('id')->get(['id', 'type', 'name_en', 'name_ar', 'slug', 'parent_id', 'sort_order'])->toArray());

        // Run seeder third time
        $seeder->run();
        $this->assertSame(33, Category::count());
        $this->assertSame($initialRows, Category::orderBy('id')->get(['id', 'type', 'name_en', 'name_ar', 'slug', 'parent_id', 'sort_order'])->toArray());
    }

    public function test_no_n_plus_one_queries_when_fetching_news_categories(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('/api/v1/categories?type=news');
        $response->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Exactly 2 queries:
        // 1. SELECT * FROM `categories` WHERE `type` = 'news' AND `parent_id` IS NULL ORDER BY `sort_order` ASC
        // 2. SELECT * FROM `categories` WHERE `categories`.`parent_id` IN (...) ORDER BY `sort_order` ASC
        $this->assertCount(2, $queries, 'Unexpected number of database queries executed. Possible N+1 issue detected.');
    }
}
