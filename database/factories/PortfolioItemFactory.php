<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Client;
use App\Models\PortfolioItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortfolioItem>
 */
class PortfolioItemFactory extends Factory
{
    /**
     * @var class-string<PortfolioItem>
     */
    protected $model = PortfolioItem::class;

    /**
     * Defaults to a draft in Social Media Campaign / Graphic Design (requires the seeded portfolio categories).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $parent = Category::where('type', 'portfolio')->where('slug', 'social-media-campaign')->first();
        $child = Category::where('type', 'portfolio')->where('slug', 'graphic-design')->first();

        return [
            // each factory item gets its own client, so default items never collide on the client/category slot
            'client_id' => Client::factory(),
            'category_id' => $parent?->id,
            'subcategory_id' => $child?->id,
            'title_en' => fake()->sentence(3),
            'title_ar' => 'مشروع '.fake()->numerify('###'),
            'status' => PortfolioItem::STATUS_DRAFT,
        ];
    }

    public function withCover(?string $key = null): static
    {
        return $this->state(fn () => ['cover_image_path' => $key ?? 'portfolio/images/'.fake()->uuid().'.jpg']);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => PortfolioItem::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    public function inSubcategory(string $subcategorySlug): static
    {
        return $this->state(function () use ($subcategorySlug) {
            $child = Category::where('type', 'portfolio')->where('slug', $subcategorySlug)->firstOrFail();

            return ['category_id' => $child->parent_id, 'subcategory_id' => $child->id];
        });
    }

    public function webDesign(): static
    {
        return $this->state(function () {
            $parent = Category::where('type', 'portfolio')->where('slug', 'web-design')->firstOrFail();

            return [
                'category_id' => $parent->id,
                'subcategory_id' => null,
                'link_1' => 'https://example.com/one',
                'link_2' => 'https://example.com/two',
            ];
        });
    }
}
