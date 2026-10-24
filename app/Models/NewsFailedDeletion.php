<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsFailedDeletion extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'news_failed_deletions';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'object_key',
        'reason',
        'attempts',
        'last_attempted_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
        ];
    }

    /**
     * Record or update a failed deletion entry idempotently.
     */
    public static function record(string $key, ?string $reason = null): self
    {
        $existing = static::where('object_key', $key)->first();

        if ($existing) {
            $existing->increment('attempts');
            $existing->update([
                'reason' => $reason ?? $existing->reason,
                'last_attempted_at' => now(),
            ]);

            return $existing;
        }

        return static::create([
            'object_key' => $key,
            'reason' => $reason,
            'attempts' => 1,
            'last_attempted_at' => now(),
        ]);
    }
}
