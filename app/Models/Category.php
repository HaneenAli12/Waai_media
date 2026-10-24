<?php

namespace App\Models;

use App\Support\LocaleNegotiator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $table = 'categories';

    protected $fillable = [
        'type',
        'name_en',
        'name_ar',
        'slug',
        'parent_id',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * Parent category relationship.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Child categories relationship (for hierarchical modules like News).
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order', 'asc');
    }

    /**
     * Scope to filter top-level categories by module type ordered by sort_order.
     */
    public function scopeForType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type)
            ->whereNull('parent_id')
            ->orderBy('sort_order', 'asc');
    }

    /**
     * Resolve the localized name for this category.
     */
    public function getLocalizedName(?string $acceptLanguage = null): string
    {
        $locale = LocaleNegotiator::negotiate($acceptLanguage);

        return ($locale === 'ar') ? $this->name_ar : $this->name_en;
    }

    /**
     * Determine if this category is an organizational parent with children.
     */
    public function isOrganizationalParent(): bool
    {
        return $this->children()->exists();
    }
}
