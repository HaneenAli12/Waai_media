<?php

namespace App\Models;

use Database\Factories\NewsArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsArticle extends Model
{
    /** @use HasFactory<NewsArticleFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_UNPUBLISHED = 'unpublished';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_UNPUBLISHED,
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'news_articles';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'author_id',
        'status',
        'title_en',
        'title_ar',
        'slug',
        'summary_en',
        'summary_ar',
        'content_en',
        'content_ar',
        'cover_image_key',
        'published_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    /**
     * Category that this article belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Author (user) who created this article.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Scope to filter published articles eligible for public display.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope to filter articles by single or multiple category IDs.
     *
     * @param  int|array<int>  $categoryIds
     */
    public function scopeForCategory(Builder $query, int|array $categoryIds): Builder
    {
        if (is_array($categoryIds)) {
            return $query->whereIn('category_id', $categoryIds);
        }

        return $query->where('category_id', $categoryIds);
    }

    /**
     * Scope to order articles for public listing.
     */
    public function scopeOrderedForPublic(Builder $query): Builder
    {
        return $query->orderBy('published_at', 'desc')->orderBy('id', 'desc');
    }

    /**
     * Scope to order articles for admin dashboard listing.
     */
    public function scopeOrderedForAdmin(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
    }

    /**
     * Determine whether the article has ever been published.
     */
    public function hasBeenPublished(): bool
    {
        return $this->published_at !== null || $this->status === self::STATUS_UNPUBLISHED;
    }
}
