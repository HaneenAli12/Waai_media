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
        if (! $user->isAdmin()) {
            return true;
        }

        // If keeping as admin, it is safe
        if ($targetRole === 'admin') {
            return true;
        }

        // Check if this is the last active admin
        return ! $user->isLastActiveAdmin();
    }

    /**
     * Guard against deleting or demoting the last active administrator.
     *
     * @throws AuthorizationException
     */
    public function ensureCanDeleteOrDemote(User $user, ?string $targetRole = null): void
    {
        if (! $this->canDeleteOrDemote($user, $targetRole)) {
            throw new AuthorizationException('Cannot delete or demote the last remaining active administrator.');
        }
    }

    /**
     * Guard against deleting the first administrator account ever created in the database.
     * The first admin ever created is permanently protected from deletion.
     *
     * @throws AuthorizationException
     */
    public function ensureNotFirstAdmin(User $user): void
    {
        if ($user->isFirstAdminEverCreated()) {
            throw new AuthorizationException('The first administrator account ever created in the database is protected and cannot be deleted.');
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
