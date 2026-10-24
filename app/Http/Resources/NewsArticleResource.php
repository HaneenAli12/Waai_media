<?php

namespace App\Http\Resources;

use App\Models\NewsArticle;
use App\Support\LocaleNegotiator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NewsArticle
 */
class NewsArticleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = LocaleNegotiator::negotiate($request->header('Accept-Language'));

        return [
            'id' => (int) $this->id,
            'category_id' => (int) $this->category_id,
            'category' => $this->relationLoaded('category') && $this->category
                ? new CategoryResource($this->category)
                : null,
            'author_id' => (int) $this->author_id,
            'author' => $this->relationLoaded('author') && $this->author
                ? [
                    'id' => (int) $this->author->id,
                    'name' => (string) $this->author->name,
                    'email' => (string) $this->author->email,
                    'role' => (string) $this->author->role,
                ]
                : null,
            'status' => (string) $this->status,
            'title' => $locale === 'ar'
                ? ($this->title_ar ?? $this->title_en)
                : ($this->title_en ?? $this->title_ar),
            'summary' => $locale === 'ar'
                ? ($this->summary_ar ?? $this->summary_en)
                : ($this->summary_en ?? $this->summary_ar),
            'content' => $locale === 'ar'
                ? ($this->content_ar ?? $this->content_en)
                : ($this->content_en ?? $this->content_ar),
            'title_en' => $this->title_en,
            'title_ar' => $this->title_ar,
            'slug' => $this->slug,
            'summary_en' => $this->summary_en,
            'summary_ar' => $this->summary_ar,
            'content_en' => $this->content_en,
            'content_ar' => $this->content_ar,
            'cover_image_key' => $this->cover_image_key,
            'published_at' => $this->published_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
