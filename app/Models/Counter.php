<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Counter extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'counters';

    /**
     * The attributes that are mass assignable.
     * Only the numerical value can be updated by users.
     * Names, keys, and orders are fixed system metadata.
     *
     * @var list<string>
     */
    protected $fillable = [
        'value',
    ];

    /**
     * Stable counter keys.
     */
    public const KEY_CLIENTS_SERVED = 'clients_served';

    public const KEY_CAMPAIGNS_LAUNCHED = 'campaigns_launched';

    public const KEY_SOCIAL_CONTENT_PUBLISHED = 'social_content_published';

    public const KEY_INDUSTRIES_COVERED = 'industries_covered';

    public const KEY_MARKETING_EXPERTS = 'marketing_experts';

    /**
     * The 5 fixed predefined system counters with their display order and initial values.
     *
     * @var list<array{key: string, name: string, value: int, order: int}>
     */
    public const FIXED_COUNTERS = [
        [
            'key' => self::KEY_CLIENTS_SERVED,
            'name' => 'Clients Served',
            'value' => 15,
            'order' => 1,
        ],
        [
            'key' => self::KEY_CAMPAIGNS_LAUNCHED,
            'name' => 'Campaigns Launched',
            'value' => 400,
            'order' => 2,
        ],
        [
            'key' => self::KEY_SOCIAL_CONTENT_PUBLISHED,
            'name' => 'Social Content Published',
            'value' => 2000,
            'order' => 3,
        ],
        [
            'key' => self::KEY_INDUSTRIES_COVERED,
            'name' => 'Industries Covered',
            'value' => 18,
            'order' => 4,
        ],
        [
            'key' => self::KEY_MARKETING_EXPERTS,
            'name' => 'Marketing Experts',
            'value' => 10,
            'order' => 5,
        ],
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'order' => 'integer',
        ];
    }

    /**
     * Scope query to order counters by display order.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order', 'asc');
    }

    /**
     * Get all valid predefined counter keys.
     *
     * @return list<string>
     */
    public static function validKeys(): array
    {
        return array_column(self::FIXED_COUNTERS, 'key');
    }

    /**
     * Get all valid predefined counter orders / IDs.
     *
     * @return list<int>
     */
    public static function validOrders(): array
    {
        return array_column(self::FIXED_COUNTERS, 'order');
    }

    /**
     * Check if a given key is a recognized system counter.
     */
    public static function isValidKey(string $key): bool
    {
        return in_array($key, self::validKeys(), true);
    }
}
