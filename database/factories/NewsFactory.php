<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\News;
use App\Models\NewsImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<News>
 */
class NewsFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<News>
     */
    protected $model = News::class;

    /**
     * Define the model's default state (Draft).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => function () {
                return Category::where('type', 'news')
                    ->where('slug', 'health')
                    ->value('id') ?? Category::create([
                        'type' => 'news',
                        'name_en' => 'Health',
                        'name_ar' => 'الصحة',
                        'slug' => 'health',
                        'sort_order' => 1,
                    ])->id;
            },
            'featured_image_id' => null,
            'featured_image_url' => null,
            'title_en' => fake()->sentence(5),
            'title_ar' => 'عنوان خبر تجريبي باللغة العربية',
            'slug' => null,
            'author_name_en' => fake()->name(),
            'author_name_ar' => 'أحمد محمد',
            'short_description_en' => fake()->paragraph(1),
            'short_description_ar' => 'ملخص قصير للخبر باللغة العربية.',
            'content_en' => '<p>'.fake()->paragraph(3).'</p>',
            'content_ar' => '<p>محتوى المقال الإخباري باللغة العربية بالتفصيل.</p>',
            'status' => News::STATUS_DRAFT,
            'published_at' => null,
        ];
    }

    /**
     * Define the news article as published.
     */
    public function published(): static
    {
        return $this->state(function (array $attributes) {
            $titleEn = $attributes['title_en'] ?? fake()->sentence(5);
            $normalizedSlug = Str::slug($titleEn, '-', 'en');
            if ($normalizedSlug === '') {
                $normalizedSlug = 'news';
            }

            $featuredImageId = $attributes['featured_image_id'] ?? null;
            $featuredImageUrl = $attributes['featured_image_url'] ?? null;

            if ($featuredImageId === null && $featuredImageUrl === null) {
                $image = NewsImage::factory()->create();
                $featuredImageId = $image->id;
                $featuredImageUrl = $image->storage_key;
            }

            return [
                'status' => News::STATUS_PUBLISHED,
                'title_en' => $titleEn,
                'title_ar' => $attributes['title_ar'] ?? 'عنوان خبر منشور باللغة العربية',
                'slug' => "{$normalizedSlug}-".fake()->unique()->numberBetween(1, 999999),
                'author_name_en' => $attributes['author_name_en'] ?? fake()->name(),
                'author_name_ar' => $attributes['author_name_ar'] ?? 'سارة العلي',
                'short_description_en' => $attributes['short_description_en'] ?? fake()->paragraph(1),
                'short_description_ar' => $attributes['short_description_ar'] ?? 'وصف مختصر للمقال الإخباري المنشور.',
                'content_en' => $attributes['content_en'] ?? '<p>'.fake()->paragraph(3).'</p>',
                'content_ar' => $attributes['content_ar'] ?? '<p>محتوى المقال الإخباري المنشور باللغة العربية.</p>',
                'featured_image_id' => $featuredImageId,
                'featured_image_url' => $featuredImageUrl,
                'published_at' => $attributes['published_at'] ?? now()->subHour(),
            ];
        });
    }

    /**
     * Explicit draft state.
     */
    public function draft(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => News::STATUS_DRAFT,
                'slug' => null,
                'published_at' => null,
            ];
        });
    }
}
