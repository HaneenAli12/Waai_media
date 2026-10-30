<?php

namespace App\Http\Resources;

use App\Models\News;
use App\Services\News\NewsImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin News
 */
class NewsAdminResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Full administrative representation with all Arabic and English fields,
     * category details (with bilingual names), featured image ID and URL,
     * editor image references, status, and timestamps.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $imageService = app(NewsImageService::class);

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

        $editorImages = $this->relationLoaded('editorImages')
            ? $this->editorImages
            : collect();

        return [
            'id' => (int) $this->id,
            'slug' => $this->slug,
            'status' => (string) $this->status,
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'author_name_ar' => $this->author_name_ar,
            'author_name_en' => $this->author_name_en,
            'short_description_ar' => $this->short_description_ar,
            'short_description_en' => $this->short_description_en,
            'content_ar' => $this->content_ar,
            'content_en' => $this->content_en,
            'category_id' => (int) $this->category_id,
            'category' => $category ? [
                'id' => (int) $category->id,
                'name_ar' => (string) $category->name_ar,
                'name_en' => (string) $category->name_en,
                'parent_id' => $category->parent_id !== null ? (int) $category->parent_id : null,
                'parent' => $parentCategory ? [
                    'id' => (int) $parentCategory->id,
                    'name_ar' => (string) $parentCategory->name_ar,
                    'name_en' => (string) $parentCategory->name_en,
                ] : null,
            ] : null,
            'featured_image_id' => $this->featured_image_id !== null ? (int) $this->featured_image_id : null,
            'featured_image_url' => $featuredUrl,
            'editor_images' => $editorImages->map(fn ($img) => [
                'id' => (int) $img->id,
                'url' => $imageService->resolveUrl($img),
            ])->values()->all(),
            'published_at' => $this->published_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
