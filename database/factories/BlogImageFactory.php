<?php

namespace Database\Factories;

use App\Models\BlogImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogImage>
 */
class BlogImageFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<BlogImage>
     */
    protected $model = BlogImage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = fake()->uuid();

        return [
            'disk' => 'r2',
            'storage_key' => "blogs/covers/{$uuid}.webp",
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', $uuid),
            'uploaded_by' => null,
            'deleting_at' => null,
        ];
    }

    /**
     * Editor image state under blogs/editor/ prefix.
     */
    public function editor(): static
    {
        return $this->state(function (array $attributes) {
            $uuid = fake()->uuid();

            return [
                'storage_key' => "blogs/editor/{$uuid}.webp",
            ];
        });
    }
}
