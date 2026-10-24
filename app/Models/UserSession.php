<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'access_token_id',
        'remember_token_id',
        'ip_address',
        'user_agent',
        'device_name',
        'is_remember',
        'original_login_at',
        'session_expires_at',
        'last_refreshed_at',
        'revocation_reason',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_remember' => 'boolean',
            'original_login_at' => 'datetime',
            'session_expires_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
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
     * Legacy token association.
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }

    /**
     * Active Access Token associated with this session.
     */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'access_token_id');
    }

    /**
     * Active Remember Token associated with this session.
     */
    public function rememberToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'remember_token_id');
    }

    /**
     * Rotated token history for replay detection.
     */
    public function rotations(): HasMany
    {
        return $this->hasMany(RememberTokenRotation::class, 'user_session_id');
    }

    /**
     * Whether this session is currently active.
     */
    public function isActive(): bool
    {
        if (! is_null($this->revoked_at)) {
            return false;
        }

        if ($this->is_remember && $this->session_expires_at && $this->session_expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Remaining lifetime in seconds until the fixed 30-day session expires.
     */
    public function remainingSessionSeconds(): int
    {
        if (! $this->session_expires_at) {
            return 3600;
        }

        return max(0, (int) now()->diffInSeconds($this->session_expires_at, false));
    }
}
