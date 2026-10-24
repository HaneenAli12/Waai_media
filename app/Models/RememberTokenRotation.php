<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class RememberTokenRotation extends Model
{
    use HasFactory;

    protected $table = 'remember_token_rotations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_session_id',
        'token_hash',
        'rotated_at',
        'grace_period_expires_at',
        'descendant_access_token_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rotated_at' => 'datetime',
            'grace_period_expires_at' => 'datetime',
        ];
    }

    /**
     * The device session this rotated token belonged to.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(UserSession::class, 'user_session_id');
    }

    /**
     * The immediate descendant access token issued at rotation.
     */
    public function descendantAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'descendant_access_token_id');
    }

    /**
     * Whether this rotated token is currently within its benign concurrency grace period.
     */
    public function isWithinGracePeriod(): bool
    {
        return $this->grace_period_expires_at && $this->grace_period_expires_at->isFuture();
    }
}
