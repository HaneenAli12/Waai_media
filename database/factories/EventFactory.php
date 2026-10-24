<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Event>
     */
    protected $model = Event::class;

    /**
     * Define the model's default state (Draft).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name_en' => fake()->sentence(3),
            'name_ar' => 'فعالية تجريبية باللغة العربية',
            'slug' => null,
            'category_en' => fake()->randomElement(['Technology', 'Business', 'Arts & Culture', 'Health', 'Media']),
            'category_ar' => fake()->randomElement(['التكنولوجيا', 'الأعمال', 'الفنون والثقافة', 'الصحة', 'الإعلام']),
            'description_en' => fake()->paragraph(2),
            'description_ar' => 'وصف تفصيلي للفعالية باللغة العربية بدون وسوم تنسيقية.',
            'place_en' => 'Riyadh Exhibition Center',
            'place_ar' => 'مركز الرياض للمعارض',
            'event_date' => now()->addDays(fake()->numberBetween(1, 30))->format('Y-m-d'),
            'event_time' => '10:00:00',
            'cover_image_url' => null,
            'url' => fake()->optional()->url(),
            'status' => Event::STATUS_DRAFT,
            'published_at' => null,
        ];
    }

    /**
     * Define the event as published.
     */
    public function published(): static
    {
        return $this->state(function (array $attributes) {
            $nameEn = $attributes['name_en'] ?? fake()->sentence(3);
            $normalizedSlug = Str::slug($nameEn, '-', 'en');
            if ($normalizedSlug === '') {
                $normalizedSlug = 'event';
            }

            return [
                'status' => Event::STATUS_PUBLISHED,
                'name_en' => $nameEn,
                'name_ar' => $attributes['name_ar'] ?? 'فعالية منشورة باللغة العربية',
                'slug' => "{$normalizedSlug}-".fake()->unique()->numberBetween(1, 999999),
                'category_en' => $attributes['category_en'] ?? 'Technology',
                'category_ar' => $attributes['category_ar'] ?? 'التكنولوجيا',
                'description_en' => $attributes['description_en'] ?? fake()->paragraph(2),
                'description_ar' => $attributes['description_ar'] ?? 'وصف الفعالية المنشورة باللغة العربية بدون وسوم تنسيقية.',
                'place_en' => $attributes['place_en'] ?? 'Riyadh Exhibition Center',
                'place_ar' => $attributes['place_ar'] ?? 'مركز الرياض للمعارض',
                'event_date' => $attributes['event_date'] ?? now()->addDays(7)->format('Y-m-d'),
                'event_time' => $attributes['event_time'] ?? '18:30:00',
                'cover_image_url' => $attributes['cover_image_url'] ?? 'https://media.waaimedia.com/events/covers/'.fake()->uuid().'.jpg',
                'url' => $attributes['url'] ?? fake()->optional()->url(),
                'published_at' => $attributes['published_at'] ?? now()->subHour(),
            ];
        });
    }

    /**
     * Explicit draft state with null slug and null published_at.
     */
    public function draft(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => Event::STATUS_DRAFT,
                'slug' => null,
                'published_at' => null,
            ];
        });
    }

    /**
     * State for upcoming events (date in the future).
     */
    public function upcoming(int $daysInFuture = 14): static
    {
        return $this->state(function (array $attributes) use ($daysInFuture) {
            return [
                'event_date' => now()->addDays($daysInFuture)->format('Y-m-d'),
                'event_time' => '12:00:00',
            ];
        });
    }

    /**
     * State for past events (date in the past).
     */
    public function past(int $daysInPast = 14): static
    {
        return $this->state(function (array $attributes) use ($daysInPast) {
            return [
                'event_date' => now()->subDays($daysInPast)->format('Y-m-d'),
                'event_time' => '12:00:00',
            ];
        });
    }
}
