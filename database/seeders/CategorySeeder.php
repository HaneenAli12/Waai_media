<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CategorySeeder extends Seeder
{
    /**
     * Authoritative static categories definition.
     * Exactly 33 categories across News, Blogs, and Portfolio.
     */
    public const STATIC_CATEGORIES = [
        // --- NEWS (9 Top-Level + 4 Children = 13 records) ---
        ['type' => 'news', 'sort_order' => 1, 'slug' => 'health', 'name_en' => 'Health', 'name_ar' => 'الصحة', 'parent_slug' => null],
        ['type' => 'news', 'sort_order' => 2, 'slug' => 'food', 'name_en' => 'Food', 'name_ar' => 'الطعام', 'parent_slug' => null],
        ['type' => 'news', 'sort_order' => 3, 'slug' => 'fashion-beauty', 'name_en' => 'Fashion & Beauty', 'name_ar' => 'الموضة والجمال', 'parent_slug' => null],
        ['type' => 'news', 'sort_order' => 4, 'slug' => 'travel', 'name_en' => 'Travel', 'name_ar' => 'السفر', 'parent_slug' => null],
        ['type' => 'news', 'sort_order' => 5, 'slug' => 'economy', 'name_en' => 'Economy', 'name_ar' => 'الاقتصاد', 'parent_slug' => null],
        ['type' => 'news', 'sort_order' => 6, 'slug' => 'sports', 'name_en' => 'Sports', 'name_ar' => 'الرياضة', 'parent_slug' => null],
        ['type' => 'news', 'sort_order' => 7, 'slug' => 'entertainment', 'name_en' => 'Entertainment', 'name_ar' => 'الترفيه', 'parent_slug' => null],
        ['type' => 'news', 'sort_order' => 8, 'slug' => 'podcast', 'name_en' => 'Podcast', 'name_ar' => 'بودكاست', 'parent_slug' => null],
        ['type' => 'news', 'sort_order' => 9, 'slug' => 'events', 'name_en' => 'Events', 'name_ar' => 'الفعاليات', 'parent_slug' => null],

        // Entertainment Children (sort_order 1 to 4)
        ['type' => 'news', 'sort_order' => 1, 'slug' => 'celebrities', 'name_en' => 'Celebrities', 'name_ar' => 'المشاهير', 'parent_slug' => 'entertainment'],
        ['type' => 'news', 'sort_order' => 2, 'slug' => 'in-theaters', 'name_en' => 'In Theaters', 'name_ar' => 'في دور السينما', 'parent_slug' => 'entertainment'],
        ['type' => 'news', 'sort_order' => 3, 'slug' => 'tv-shows', 'name_en' => 'TV Shows', 'name_ar' => 'البرامج والمسلسلات التلفزيونية', 'parent_slug' => 'entertainment'],
        ['type' => 'news', 'sort_order' => 4, 'slug' => 'book-reviews', 'name_en' => 'Book Reviews', 'name_ar' => 'مراجعات الكتب', 'parent_slug' => 'entertainment'],

        // --- BLOGS (5 Categories) ---
        ['type' => 'blogs', 'sort_order' => 1, 'slug' => 'digital-marketing-trends', 'name_en' => 'Digital Marketing Trends', 'name_ar' => 'اتجاهات التسويق الرقمي', 'parent_slug' => null],
        ['type' => 'blogs', 'sort_order' => 2, 'slug' => 'case-studies', 'name_en' => 'Case Studies', 'name_ar' => 'دراسات الحالة', 'parent_slug' => null],
        ['type' => 'blogs', 'sort_order' => 3, 'slug' => 'tips-guides', 'name_en' => 'Tips & Guides', 'name_ar' => 'نصائح وأدلة إرشادية', 'parent_slug' => null],
        ['type' => 'blogs', 'sort_order' => 4, 'slug' => 'creative-ideas', 'name_en' => 'Creative Ideas', 'name_ar' => 'أفكار إبداعية', 'parent_slug' => null],
        ['type' => 'blogs', 'sort_order' => 5, 'slug' => 'behind-the-scenes', 'name_en' => 'Behind The Scenes', 'name_ar' => 'خلف الكواليس', 'parent_slug' => null],

        // --- PORTFOLIO (6 Parents + 9 Children = 15 Categories) ---
        // Top-Level Categories
        ['type' => 'portfolio', 'sort_order' => 1, 'slug' => 'social-media-campaign', 'name_en' => 'Social Media Campaign', 'name_ar' => 'حملات وسائل التواصل الاجتماعي', 'parent_slug' => null],
        ['type' => 'portfolio', 'sort_order' => 2, 'slug' => 'media-production', 'name_en' => 'Media Production', 'name_ar' => 'الإنتاج الإعلامي', 'parent_slug' => null],
        ['type' => 'portfolio', 'sort_order' => 3, 'slug' => 'pr', 'name_en' => 'PR', 'name_ar' => 'العلاقات العامة', 'parent_slug' => null],
        ['type' => 'portfolio', 'sort_order' => 4, 'slug' => 'events', 'name_en' => 'Events', 'name_ar' => 'الفعاليات', 'parent_slug' => null],
        ['type' => 'portfolio', 'sort_order' => 5, 'slug' => 'opening', 'name_en' => 'Opening', 'name_ar' => 'الافتتاحات', 'parent_slug' => null],
        ['type' => 'portfolio', 'sort_order' => 6, 'slug' => 'web-design', 'name_en' => 'Web Design', 'name_ar' => 'تصميم المواقع الإلكترونية', 'parent_slug' => null],

        // Social Media Campaign Children
        ['type' => 'portfolio', 'sort_order' => 1, 'slug' => 'graphic-design', 'name_en' => 'Graphic Design', 'name_ar' => 'التصميم الجرافيكي', 'parent_slug' => 'social-media-campaign'],
        ['type' => 'portfolio', 'sort_order' => 2, 'slug' => 'branding', 'name_en' => 'Branding', 'name_ar' => 'الهوية البصرية', 'parent_slug' => 'social-media-campaign'],

        // Media Production Children
        ['type' => 'portfolio', 'sort_order' => 1, 'slug' => 'videos', 'name_en' => 'Videos', 'name_ar' => 'الفيديوهات', 'parent_slug' => 'media-production'],

        // PR Children
        ['type' => 'portfolio', 'sort_order' => 1, 'slug' => 'media-covering', 'name_en' => 'Media Covering', 'name_ar' => 'التغطية الإعلامية', 'parent_slug' => 'pr'],
        ['type' => 'portfolio', 'sort_order' => 2, 'slug' => 'press-covering', 'name_en' => 'Press Covering', 'name_ar' => 'التغطية الصحفية', 'parent_slug' => 'pr'],

        // Events Children (parent-scoped unique slugs to satisfy compound unique['type', 'slug'])
        ['type' => 'portfolio', 'sort_order' => 1, 'slug' => 'events-image', 'name_en' => 'Image', 'name_ar' => 'الصور', 'parent_slug' => 'events'],
        ['type' => 'portfolio', 'sort_order' => 2, 'slug' => 'events-video', 'name_en' => 'Video', 'name_ar' => 'الفيديو', 'parent_slug' => 'events'],

        // Opening Children (parent-scoped unique slugs to satisfy compound unique['type', 'slug'])
        ['type' => 'portfolio', 'sort_order' => 1, 'slug' => 'opening-image', 'name_en' => 'Image', 'name_ar' => 'الصور', 'parent_slug' => 'opening'],
        ['type' => 'portfolio', 'sort_order' => 2, 'slug' => 'opening-video', 'name_en' => 'Video', 'name_ar' => 'الفيديو', 'parent_slug' => 'opening'],
    ];

    /**
     * Run the database seeds idempotently.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // Handle legacy blog slug transition safely and idempotently
            Category::where('type', 'blogs')
                ->where('slug', 'case-studied')
                ->update([
                    'slug' => 'case-studies',
                    'name_en' => 'Case Studies',
                ]);

            // Pass 1: Seed all top-level categories
            foreach (self::STATIC_CATEGORIES as $item) {
                if ($item['parent_slug'] === null) {
                    Category::updateOrCreate(
                        ['type' => $item['type'], 'slug' => $item['slug']],
                        [
                            'name_en' => $item['name_en'],
                            'name_ar' => $item['name_ar'],
                            'sort_order' => $item['sort_order'],
                            'parent_id' => null,
                        ]
                    );
                }
            }

            // Pass 2: Seed child categories after verifying parent existence and module alignment
            foreach (self::STATIC_CATEGORIES as $item) {
                if ($item['parent_slug'] !== null) {
                    $parent = Category::where('type', $item['type'])
                        ->where('slug', $item['parent_slug'])
                        ->first();

                    if (! $parent) {
                        throw new RuntimeException("Parent category with slug '{$item['parent_slug']}' not found for type '{$item['type']}'.");
                    }

                    Category::updateOrCreate(
                        ['type' => $item['type'], 'slug' => $item['slug']],
                        [
                            'name_en' => $item['name_en'],
                            'name_ar' => $item['name_ar'],
                            'sort_order' => $item['sort_order'],
                            'parent_id' => $parent->id,
                        ]
                    );
                }
            }
        });
    }
}
