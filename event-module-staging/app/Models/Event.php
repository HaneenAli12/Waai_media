<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'events';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name_en',
        'name_ar',
        'slug',
        'event_category_id',
        'category_en',
        'category_ar',
        'description_en',
        'description_ar',
        'place_en',
        'place_ar',
        'event_date',
        'cover_image_url',
        'author_name',
        'author_name_en',
        'author_name_ar',
        'url',
        'status',
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
            'event_date' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Alias accessor for journalist_name to support both author and journalist terminology.
     */
    public function getJournalistNameAttribute(): ?string
    {
        return $this->author_name_en ?? $this->author_name;
    }

    /**
     * Alias mutator for journalist_name to map to author_name.
     */
    public function setJournalistNameAttribute(?string $value): void
    {
        $this->author_name = $value;
        if ($this->author_name_en === null) {
            $this->author_name_en = $value;
        }
    }

    /**
     * Scope to filter published events eligible for public display.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope to filter upcoming events (relative to current UTC timestamp).
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('event_date', '>=', now())
            ->orderBy('event_date', 'asc');
    }

    /**
     * Scope to filter past events (relative to current UTC timestamp).
     */
    public function scopePast(Builder $query): Builder
    {
        return $query->where('event_date', '<', now())
            ->orderBy('event_date', 'desc');
    }

    /**
     * Get the category that the event belongs to.
     *
     * @return BelongsTo<EventCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'event_category_id');
    }

    /**
     * Scope to filter events by bilingual category string or related category slug/name.
     */
    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where(function (Builder $q) use ($category) {
            $q->where('category_en', $category)
                ->orWhere('category_ar', $category)
                ->orWhereHas('category', function (Builder $categoryQuery) use ($category) {
                    $categoryQuery->where('slug', $category)
                        ->orWhere('name_en', $category)
                        ->orWhere('name_ar', $category);
                });
        });
    }

    /**
     * Scope to order events for public listing (chronological by event date).
     */
    public function scopeOrderedForPublic(Builder $query): Builder
    {
        return $query->orderBy('event_date', 'asc')->orderBy('id', 'asc');
    }

    /**
     * Scope to order events for admin dashboard listing.
     */
    public function scopeOrderedForAdmin(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
    }

    /**
     * Determine whether the event has ever been published.
     */
    public function hasBeenPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * Determine if the event is currently in published status.
     */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Determine if the event is currently in draft status.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
