<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public const DESIGNATED_SUPER_ADMIN_EMAIL = 'waaimedia@gmail.com';

    /**
     * Check if the user is a Super Administrator.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * Check if the user is an Administrator.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if the user has administrative privileges (Super Admin or Admin).
     */
    public function isAnyAdmin(): bool
    {
        return in_array($this->role, ['super_admin', 'admin'], true);
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
     * Count active (non-soft-deleted) regular administrators in the system.
     */
    public static function activeAdminsCount(): int
    {
        return static::where('role', 'admin')->count();
    }

    /**
     * Count all active administrators (super_admin + admin).
     */
    public static function totalActiveAdminsCount(): int
    {
        return static::whereIn('role', ['super_admin', 'admin'])->count();
    }

    /**
     * Determine if this user is the last remaining active regular administrator.
     */
    public function isLastActiveAdmin(): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }

        return static::activeAdminsCount() <= 1;
    }

    /**
     * Permanent system setting key for legacy protected first admin.
     */
    public const PROTECTED_ADMIN_SETTING_KEY = 'protected_first_admin_user_id';

    /**
     * Determine if this user is the designated protected Super Admin account.
     * The protection is tied directly and unconditionally to waaimedia@gmail.com,
     * independent of system_settings or database user IDs.
     */
    public function isProtectedSuperAdmin(): bool
    {
        return strtolower(trim((string) $this->email)) === strtolower(self::DESIGNATED_SUPER_ADMIN_EMAIL);
    }

    /**
     * Safely register the given user ID into legacy system_settings if needed.
     */
    public static function registerProtectedFirstAdminId(int $userId): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        try {
            DB::table('system_settings')->insertOrIgnore([
                'key' => self::PROTECTED_ADMIN_SETTING_KEY,
                'value' => (string) $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            // Silently ignore if already claimed concurrently or table state temporarily busy
        }
    }

    /**
     * Get the ID of the permanently protected first Administrator account from system settings.
     */
    public static function protectedFirstAdminId(): ?int
    {
        if (! Schema::hasTable('system_settings')) {
            return null;
        }

        $val = SystemSetting::get(self::PROTECTED_ADMIN_SETTING_KEY);

        return $val !== null ? (int) $val : null;
    }

    /**
     * Determine if this user is the permanently protected first Administrator account (legacy).
     */
    public function isFirstAdminEverCreated(): bool
    {
        if ($this->isProtectedSuperAdmin()) {
            return true;
        }

        $protectedId = static::protectedFirstAdminId();

        return $protectedId !== null && (int) $this->id === $protectedId;
    }

    /**
     * Deprecated alias for backward compatibility.
     */
    public static function firstAdminEverCreated(): ?self
    {
        $superAdmin = static::withTrashed()->where('email', self::DESIGNATED_SUPER_ADMIN_EMAIL)->first();
        if ($superAdmin) {
            return $superAdmin;
        }

        $protectedId = static::protectedFirstAdminId();
        if ($protectedId !== null) {
            return static::withTrashed()->find($protectedId);
        }

        return null;
    }

    /**
     * Deprecated alias for backward compatibility.
     */
    public static function oldestActiveAdmin(): ?self
    {
        return static::firstAdminEverCreated();
    }

    /**
     * Deprecated alias for backward compatibility.
     */
    public function isOldestActiveAdmin(): bool
    {
        return $this->isFirstAdminEverCreated();
    }
}
