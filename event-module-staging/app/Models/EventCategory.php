<?php

namespace App\Models;

use Database\Factories\EventCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventCategory extends Model
{
    /** @use HasFactory<EventCategoryFactory> */
    use HasFactory;

    protected $table = 'event_categories';

    protected $fillable = [
        'name_en',
        'name_ar',
        'slug',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get all events associated with this category.
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'event_category_id');
    }

    /**
     * Scope to filter active categories only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope ordered by sort_order asc, name_en asc.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('name_en', 'asc');
    }
}
