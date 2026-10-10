<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Check if the user is an Administrator.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if the user is an Editor.
     */
    public function isEditor(): bool
    {
        return $this->role === 'editor';
    }

    /**
     * Sessions for this user.
     */
    public function userSessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * The single currently active session.
     */
    public function activeSession(): HasOne
    {
        return $this->hasOne(UserSession::class)->whereNull('revoked_at')->latestOfMany();
    }

    /**
     * Audit logs generated for or by this user.
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Count active (non-soft-deleted) administrators in the system.
     */
    public static function activeAdminsCount(): int
    {
        return static::where('role', 'admin')->count();
    }

    /**
     * Determine if this user is the last remaining active administrator.
     */
    public function isLastActiveAdmin(): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }

        return static::activeAdminsCount() <= 1;
    }
}
