<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordResetOtp extends Model
{
    use HasFactory;

    protected $table = 'password_reset_otps';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'otp_hash',
        'expires_at',
        'attempts',
        'reset_token_hash',
        'reset_token_expires_at',
        'used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'reset_token_expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * Determine if the OTP is currently expired.
     */
    public function isOtpExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Determine if max attempts (3) have been reached.
     */
    public function hasExceededAttempts(): bool
    {
        return $this->attempts >= config('auth.otp_max_attempts', 3);
    }

    /**
     * Determine if the reset token is currently valid and unused.
     */
    public function isResetTokenValid(): bool
    {
        return is_null($this->used_at)
            && ! is_null($this->reset_token_expires_at)
            && ! $this->reset_token_expires_at->isPast();
    }
}
