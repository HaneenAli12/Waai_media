<?php

namespace Database\Factories;

use App\Models\NewsImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsImage>
 */
class NewsImageFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<NewsImage>
     */
    protected $model = NewsImage::class;

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
            'storage_key' => "news/covers/{$uuid}.webp",
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', $uuid),
            'uploaded_by' => null,
        ];
    }

    /**
     * Editor image state under news/editor/ prefix.
     */
    public function editor(): static
    {
        return $this->state(function (array $attributes) {
            $uuid = fake()->uuid();

            return [
                'storage_key' => "news/editor/{$uuid}.webp",
            ];
        });
    }
}
