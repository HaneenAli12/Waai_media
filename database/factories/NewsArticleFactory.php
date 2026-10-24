<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsArticle>
 */
class NewsArticleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<NewsArticle>
     */
    protected $model = NewsArticle::class;

    /**
     * Define the model's default state (Draft).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => fn () => Category::where('type', 'news')
                ->whereDoesntHave('children')
                ->first()?->id ?? Category::create([
                    'type' => 'news',
                    'name_en' => 'Health',
                    'name_ar' => 'الصحة',
                    'slug' => 'health',
                    'parent_id' => null,
                    'sort_order' => 1,
                ])->id,
            'author_id' => fn () => User::factory()->editor()->create()->id,
            'status' => NewsArticle::STATUS_DRAFT,
            'title_en' => fake()->sentence(4),
            'title_ar' => 'عنوان المقال التجريبي باللغة العربية',
            'slug' => null,
            'summary_en' => null,
            'summary_ar' => null,
            'content_en' => null,
            'content_ar' => null,
            'cover_image_key' => null,
            'published_at' => null,
        ];
    }

    /**
     * Define the article as published.
     */
    public function published(): static
    {
        return $this->state(function (array $attributes) {
            $titleEn = $attributes['title_en'] ?? fake()->sentence(4);

            return [
                'status' => NewsArticle::STATUS_PUBLISHED,
                'title_en' => $titleEn,
                'title_ar' => 'عنوان المقال المنشور باللغة العربية',
                'slug' => 'published-news-'.fake()->unique()->numberBetween(1, 999999),
                'summary_en' => fake()->paragraph(2),
                'summary_ar' => 'ملخص يدوي للمقال الإخباري باللغة العربية لا يتجاوز خمسمائة حرف.',
                'content_en' => '<p>'.fake()->paragraphs(3, true).'</p>',
                'content_ar' => '<p>محتوى المقال الإخباري التجريبي باللغة العربية.</p>',
                'cover_image_key' => 'news/covers/'.fake()->uuid().'.jpg',
                'published_at' => now()->subHour(),
            ];
        });
    }

    /**
     * Define the article as unpublished.
     */
    public function unpublished(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => NewsArticle::STATUS_UNPUBLISHED,
                'published_at' => now()->subDays(2),
            ];
        });
    }

    /**
     * Explicit draft state with null slug.
     */
    public function draft(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => NewsArticle::STATUS_DRAFT,
                'slug' => null,
                'published_at' => null,
            ];
        });
    }
}
