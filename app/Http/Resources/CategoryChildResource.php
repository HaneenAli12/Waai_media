<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
class CategoryChildResource extends JsonResource
{
    /**
     * Transform the child category into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $acceptLanguage = $request->header('Accept-Language');

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->getLocalizedName($acceptLanguage),
            'slug' => (string) $this->slug,
            'parent_id' => (int) $this->parent_id,
        ];
    }
}
