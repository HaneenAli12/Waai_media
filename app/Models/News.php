<?php

namespace App\Models;

use Database\Factories\NewsFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class News extends Model
{
    /** @use HasFactory<NewsFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
    ];

    protected $table = 'news';

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
        static::saving(function (News $news): void {
            if ($news->featured_image_id !== null) {
                if ($news->isDirty('featured_image_id') || $news->featured_image_url === null) {
                    $storageKey = NewsImage::where('id', $news->featured_image_id)->value('storage_key');
                    $news->featured_image_url = $storageKey;
                    if ($storageKey === null) {
                        $news->featured_image_id = null;
                    }
                }
            } elseif ($news->isDirty('featured_image_id')) {
                $news->featured_image_url = null;
            }
        });
    }

    /**
     * Shared category assigned to this news article.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Featured image record associated with this news article.
     */
    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(NewsImage::class, 'featured_image_id');
    }

    /**
     * All images associated with this news article (featured and editor).
     */
    public function images(): BelongsToMany
    {
        return $this->belongsToMany(NewsImage::class, 'news_article_images', 'news_id', 'news_image_id')
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
     * Scope to filter published news articles eligible for public display.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope to order news articles for admin dashboard listing.
     */
    public function scopeOrderedForAdmin(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
    }

    /**
     * Scope to order published news articles for public listing.
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
