<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class UserSession extends Model
{
    use HasFactory;

    protected $table = 'user_sessions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'token_id',
        'ip_address',
        'user_agent',
        'device_name',
        'revocation_reason',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * User who owns the session.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Sanctum token associated with this session.
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }

    /**
     * Whether this session is currently active.
     */
    public function isActive(): bool
    {
        return is_null($this->revoked_at);
    }
}
