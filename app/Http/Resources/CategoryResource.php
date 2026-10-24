<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Ensures no accidental lazy loading or N+1 queries occur.
     * For News & Portfolio: includes 'children' array (hierarchical categories with their subcategories).
     * For Blogs: returns a flat category object without a 'children' key.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $acceptLanguage = $request->header('Accept-Language');

        $data = [
            'id' => (int) $this->id,
            'name' => (string) $this->getLocalizedName($acceptLanguage),
            'slug' => (string) $this->slug,
        ];

        if (in_array($this->type, ['news', 'portfolio'], true)) {
            $children = $this->relationLoaded('children')
                ? $this->children
                : collect();

            $data['children'] = CategoryChildResource::collection($children);
        }

        return $data;
    }
}
