<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class AdminSafetyService
{
    /**
     * Determine if an administrator can be deleted or demoted.
     */
    public function canDeleteOrDemote(User $user, ?string $targetRole = null): bool
    {
        // 1. Designated protected super admin account cannot be deleted or demoted under any circumstances
        if ($user->isProtectedSuperAdmin()) {
            return false;
        }

        if (! $user->isAnyAdmin()) {
            return true;
        }

        // If keeping as regular admin or super admin, it is safe
        if ($targetRole === 'admin' || $targetRole === 'super_admin') {
            return true;
        }

        // Guard against leaving zero active admins in the system
        return User::totalActiveAdminsCount() > 1;
    }

    /**
     * Guard against deleting or demoting the last active administrator or the protected super admin.
     *
     * @throws AuthorizationException
     */
    public function ensureCanDeleteOrDemote(User $user, ?string $targetRole = null): void
    {
        if ($user->isProtectedSuperAdmin()) {
            throw new AuthorizationException('The designated Super Admin account is permanently protected and cannot be deleted or demoted.');
        }

        if (! $this->canDeleteOrDemote($user, $targetRole)) {
            throw new AuthorizationException('Cannot delete or demote the last remaining active administrator.');
        }
    }

    /**
     * Guard against deleting the protected Super Admin or first administrator account ever created in the database.
     *
     * @throws AuthorizationException
     */
    public function ensureNotFirstAdmin(User $user): void
    {
        if ($user->isProtectedSuperAdmin()) {
            throw new AuthorizationException('The designated Super Admin account is permanently protected and cannot be deleted.');
        }

        if ($user->isFirstAdminEverCreated()) {
            throw new AuthorizationException('The designated Super Admin account is permanently protected and cannot be deleted.');
        }
    }

    /**
     * Alias for backward compatibility.
     *
     * @throws AuthorizationException
     */
    public function ensureNotOldestAdmin(User $user): void
    {
        $this->ensureNotFirstAdmin($user);
    }
}
