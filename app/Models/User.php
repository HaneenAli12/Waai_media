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

    /**
     * Permanent system setting key for the protected first admin.
     */
    public const PROTECTED_ADMIN_SETTING_KEY = 'protected_first_admin_user_id';

    /**
     * Bootstrap model events to capture the very first admin created in the system.
     */
    protected static function booted(): void
    {
        static::created(function (User $user) {
            if ($user->role === 'admin') {
                static::registerProtectedFirstAdminId((int) $user->id);
            }
        });
    }

    /**
     * Safely and atomically register the given user ID as the protected first admin
     * if no protected first admin ID has been registered yet.
     * Uses atomic insertOrIgnore on the primary key so concurrent calls will never duplicate
     * or raise unhandled exceptions.
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
     * Get the ID of the permanently protected first Administrator account.
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
     * Get the first Administrator account ever created in the database.
     * Uses the permanently stored ID from system_settings. If not yet initialized,
     * falls back to the earliest created admin and registers it.
     */
    public static function firstAdminEverCreated(): ?self
    {
        $protectedId = static::protectedFirstAdminId();

        if ($protectedId !== null) {
            return static::withTrashed()->find($protectedId);
        }

        // Fallback if not yet recorded: find earliest admin and register atomically
        $earliest = static::withTrashed()
            ->where('role', 'admin')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        if ($earliest) {
            static::registerProtectedFirstAdminId((int) $earliest->id);
        }

        return $earliest;
    }

    /**
     * Determine if this user is the permanently protected first Administrator account.
     */
    public function isFirstAdminEverCreated(): bool
    {
        $protectedId = static::protectedFirstAdminId();

        if ($protectedId !== null) {
            return (int) $this->id === $protectedId;
        }

        $firstAdmin = static::firstAdminEverCreated();

        return $firstAdmin !== null && (int) $firstAdmin->id === (int) $this->id;
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
