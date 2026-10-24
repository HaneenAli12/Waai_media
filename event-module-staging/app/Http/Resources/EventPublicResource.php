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
     * Localized public presentation according to negotiated locale (Accept-Language).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = LocaleNegotiator::negotiate($request->header('Accept-Language'));

        $localizedAuthor = $locale === 'ar'
            ? ($this->author_name_ar ?? $this->author_name_en ?? $this->author_name)
            : ($this->author_name_en ?? $this->author_name_ar ?? $this->author_name);

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
            'event_category_id' => $this->event_category_id !== null ? (int) $this->event_category_id : null,
            'event_category' => $this->category ? [
                'id' => (int) $this->category->id,
                'name_en' => (string) $this->category->name_en,
                'name_ar' => (string) $this->category->name_ar,
                'slug' => (string) $this->category->slug,
            ] : null,
            'category' => $locale === 'ar'
                ? ($this->category_ar ?? $this->category_en)
                : ($this->category_en ?? $this->category_ar),
            'place' => $locale === 'ar'
                ? ($this->place_ar ?? $this->place_en)
                : ($this->place_en ?? $this->place_ar),
            'event_date' => $this->event_date?->toISOString(),
            'cover_image_url' => app(EventCoverImageService::class)->resolveUrl($this->cover_image_url),
            'author_name' => $localizedAuthor,
            'author_name_en' => $this->author_name_en,
            'author_name_ar' => $this->author_name_ar,
            'journalist_name' => $localizedAuthor,
            'url' => $this->url,
        ];
    }
}
