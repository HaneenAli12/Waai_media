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
}
