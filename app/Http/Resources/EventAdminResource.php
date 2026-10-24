<?php

namespace App\Http\Resources;

use App\Models\Event;
use App\Services\Events\EventCoverImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventAdminResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Full administrative view with all raw bilingual attributes and separate event_date & event_time.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'slug' => $this->slug,
            'status' => (string) $this->status,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'category_en' => $this->category_en,
            'category_ar' => $this->category_ar,
            'description_en' => $this->description_en,
            'description_ar' => $this->description_ar,
            'place_en' => $this->place_en,
            'place_ar' => $this->place_ar,
            'event_date' => $this->event_date?->format('Y-m-d') ?? ($this->attributes['event_date'] ?? null),
            'event_time' => $this->event_time,
            'cover_image_url' => app(EventCoverImageService::class)->resolveUrl($this->cover_image_url),
            'url' => $this->url,
            'published_at' => $this->published_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
