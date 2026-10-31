<?php

namespace App\Http\Resources;

use App\Models\Blog;
use App\Services\Blog\BlogImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Blog
 */
class BlogPublicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Localized public presentation according to negotiated locale (Accept-Language),
     * while also returning both Arabic and English category names.
     * Never exposes internal admin fields (e.g. uploader IDs, storage keys, pivot metadata).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $imageService = app(BlogImageService::class);

        $category = $this->relationLoaded('category') ? $this->category : $this->category()->first();
        $parentCategory = $category && $category->parent_id !== null
            ? ($category->relationLoaded('parent') ? $category->parent : $category->parent()->first())
            : null;

        $featuredImage = $this->relationLoaded('featuredImage')
            ? $this->featuredImage
            : ($this->featured_image_id ? $this->featuredImage()->first() : null);

        $featuredUrl = $featuredImage
            ? $imageService->resolveUrl($featuredImage)
            : ($this->featured_image_id === null ? $imageService->resolveUrl($this->featured_image_url) : null);

        return [
            'id' => (int) $this->id,
            'slug' => (string) $this->slug,
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'author_name_ar' => $this->author_name_ar,
            'author_name_en' => $this->author_name_en,
            'short_description_ar' => $this->short_description_ar,
            'short_description_en' => $this->short_description_en,
            'content_ar' => $this->content_ar,
            'content_en' => $this->content_en,
            'category_id' => (int) $this->category_id,
            'category_name_ar' => $category?->name_ar,
            'category_name_en' => $category?->name_en,
            'category' => $category ? [
                'id' => (int) $category->id,
                'slug' => (string) $category->slug,
                'name_ar' => (string) $category->name_ar,
                'name_en' => (string) $category->name_en,
                'parent_id' => $category->parent_id !== null ? (int) $category->parent_id : null,
                'parent' => $parentCategory ? [
                    'id' => (int) $parentCategory->id,
                    'slug' => (string) $parentCategory->slug,
                    'name_ar' => (string) $parentCategory->name_ar,
                    'name_en' => (string) $parentCategory->name_en,
                ] : null,
            ] : null,
            'featured_image_url' => $featuredUrl,
            'published_at' => $this->published_at?->toISOString(),
        ];
    }
}
