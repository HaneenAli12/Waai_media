<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
    protected $table = 'event_calendar';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name_en',
        'name_ar',
        'slug',
        'category_en',
        'category_ar',
        'description_en',
        'description_ar',
        'place_en',
        'place_ar',
        'event_date',
        'event_time',
        'cover_image_url',
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
            'event_date' => 'date:Y-m-d',
            'published_at' => 'datetime',
        ];
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
     * Scope to filter upcoming events (relative to current date).
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        $today = now()->format('Y-m-d');
        $currentTime = now()->format('H:i:s');

        return $query->where(function (Builder $q) use ($today, $currentTime) {
            $q->where('event_date', '>', $today)
                ->orWhere(function (Builder $sub) use ($today, $currentTime) {
                    $sub->where('event_date', '=', $today)
                        ->where(function (Builder $timeSub) use ($currentTime) {
                            $timeSub->whereNull('event_time')
                                ->orWhere('event_time', '>=', $currentTime);
                        });
                });
        })->orderBy('event_date', 'asc')->orderBy('event_time', 'asc');
    }

    /**
     * Scope to filter past events (relative to current date).
     */
    public function scopePast(Builder $query): Builder
    {
        $today = now()->format('Y-m-d');
        $currentTime = now()->format('H:i:s');

        return $query->where(function (Builder $q) use ($today, $currentTime) {
            $q->where('event_date', '<', $today)
                ->orWhere(function (Builder $sub) use ($today, $currentTime) {
                    $sub->where('event_date', '=', $today)
                        ->whereNotNull('event_time')
                        ->where('event_time', '<', $currentTime);
                });
        })->orderBy('event_date', 'desc')->orderBy('event_time', 'desc');
    }

    /**
     * Scope to filter events by bilingual category string.
     */
    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where(function (Builder $q) use ($category) {
            $q->where('category_en', $category)
                ->orWhere('category_ar', $category);
        });
    }

    /**
     * Scope to order events for public listing (chronological by event date then time).
     */
    public function scopeOrderedForPublic(Builder $query): Builder
    {
        return $query->orderBy('event_date', 'asc')->orderBy('event_time', 'asc')->orderBy('id', 'asc');
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
