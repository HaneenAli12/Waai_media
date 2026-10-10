<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ActivityLog extends Model
{
    use HasFactory;

    protected $table = 'activity_logs';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'ip_address',
        'user_agent',
        'details',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Protect audit log integrity: activity logs are strictly append-only.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Activity logs are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new RuntimeException('Activity logs are append-only and cannot be deleted.');
        });
    }

    /**
     * User associated with the action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
