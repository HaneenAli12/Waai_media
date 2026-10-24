<?php

namespace App\Http\Resources;

use App\Models\EventCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventCategory
 */
class EventCategoryPublicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name_en' => (string) $this->name_en,
            'name_ar' => (string) $this->name_ar,
            'slug' => (string) $this->slug,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
