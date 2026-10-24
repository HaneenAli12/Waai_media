<?php

namespace Database\Seeders;

use App\Models\EventCategory;
use Illuminate\Database\Seeder;

class EventCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name_en' => 'Media & Journalism',
                'name_ar' => 'الإعلام والصحافة',
                'slug' => 'media-journalism',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name_en' => 'Conferences & Summits',
                'name_ar' => 'المؤتمرات والقمم',
                'slug' => 'conferences-summits',
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name_en' => 'Workshops & Training',
                'name_ar' => 'ورش العمل والتدريب',
                'slug' => 'workshops-training',
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name_en' => 'Cultural Events',
                'name_ar' => 'الفعاليات الثقافية',
                'slug' => 'cultural-events',
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'name_en' => 'General',
                'name_ar' => 'عام',
                'slug' => 'general',
                'is_active' => true,
                'sort_order' => 5,
            ],
        ];

        foreach ($categories as $category) {
            EventCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category
            );
        }
    }
}
