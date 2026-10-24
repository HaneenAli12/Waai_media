<?php

namespace App\Http\Resources;

use App\Models\Event;
use App\Services\Events\EventCoverImageService;
use App\Support\LocaleNegotiator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventPublicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Localized public presentation according to negotiated locale (Accept-Language),
     * with separate event_date and event_time, without author fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = LocaleNegotiator::negotiate($request->header('Accept-Language'));

        return [
            'id' => (int) $this->id,
            'slug' => (string) $this->slug,
            'name' => $locale === 'ar'
                ? ($this->name_ar ?? $this->name_en)
                : ($this->name_en ?? $this->name_ar),
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'description' => $locale === 'ar'
                ? ($this->description_ar ?? $this->description_en)
                : ($this->description_en ?? $this->description_ar),
            'category' => $locale === 'ar'
                ? ($this->category_ar ?? $this->category_en)
                : ($this->category_en ?? $this->category_ar),
            'category_en' => $this->category_en,
            'category_ar' => $this->category_ar,
            'place' => $locale === 'ar'
                ? ($this->place_ar ?? $this->place_en)
                : ($this->place_en ?? $this->place_ar),
            'place_en' => $this->place_en,
            'place_ar' => $this->place_ar,
            'event_date' => $this->event_date?->format('Y-m-d') ?? ($this->attributes['event_date'] ?? null),
            'event_time' => $this->event_time,
            'cover_image_url' => app(EventCoverImageService::class)->resolveUrl($this->cover_image_url),
            'url' => $this->url,
        ];
    }
}
