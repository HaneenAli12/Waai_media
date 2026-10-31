<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Blog extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
    ];

    protected $table = 'blogs';

    protected $fillable = [
        'category_id',
        'featured_image_id',
        'featured_image_url',
        'title_ar',
        'title_en',
        'slug',
        'author_name_ar',
        'author_name_en',
        'short_description_ar',
        'short_description_en',
        'content_ar',
        'content_en',
        'status',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'featured_image_id' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Keep featured_image_id and featured_image_url synchronized on save.
     */
    protected static function booted(): void
    {
        static::saving(function (Blog $blog): void {
            if ($blog->featured_image_id !== null) {
                if ($blog->isDirty('featured_image_id') || $blog->featured_image_url === null) {
                    $storageKey = BlogImage::where('id', $blog->featured_image_id)->value('storage_key');
                    $blog->featured_image_url = $storageKey;
                    if ($storageKey === null) {
                        $blog->featured_image_id = null;
                    }
                }
            } elseif ($blog->isDirty('featured_image_id')) {
                $blog->featured_image_url = null;
            }
        });
    }

    /**
     * Shared category assigned to this blog article.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Featured image record associated with this blog article.
     */
    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(BlogImage::class, 'featured_image_id');
    }

    /**
     * All images associated with this blog article (featured and editor).
     */
    public function images(): BelongsToMany
    {
        return $this->belongsToMany(BlogImage::class, 'blog_article_images', 'blog_id', 'blog_image_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Editor images embedded inside the rich-text HTML content.
     */
    public function editorImages(): BelongsToMany
    {
        return $this->images()->wherePivot('role', 'editor');
    }

    /**
     * Scope to filter published blog articles eligible for public display.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope to order blog articles for admin dashboard listing.
     */
    public function scopeOrderedForAdmin(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
    }

    /**
     * Scope to order published blog articles for public listing.
     */
    public function scopeOrderedForPublic(Builder $query): Builder
    {
        return $query->orderBy('published_at', 'desc')->orderBy('id', 'desc');
    }

    /**
     * Determine whether the article has ever been published.
     */
    public function hasBeenPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * Determine if the article is currently in published status.
     */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Determine if the article is currently in draft status.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
