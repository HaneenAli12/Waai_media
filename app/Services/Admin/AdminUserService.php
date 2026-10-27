<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\Auth\ActivityLogger;
use App\Services\Auth\AdminSafetyService;
use App\Services\Auth\SessionManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminUserService
{
    public function __construct(
        protected AdminSafetyService $adminSafety,
        protected SessionManager $sessionManager,
        protected ActivityLogger $activityLogger
    ) {}

    /**
     * List users with optional role filtering, search, and pagination.
     */
    public function listUsers(
        ?string $search = null,
        ?string $role = null,
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = User::query()->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        if ($role !== null && in_array($role, ['super_admin', 'admin', 'editor'], true)) {
            $query->where('role', $role);
        }

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'LIKE', "%{$escaped}%")
                    ->orWhere('email', 'LIKE', "%{$escaped}%");
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Create a new user (admin or editor) and record activity log.
     * Never permits super_admin assignment through ordinary creation.
     *
     * @param  array{name: string, email: string, password: string, role: string}  $data
     */
    public function createUser(array $data, Request $request): User
    {
        $actor = $request->user();
        $actorId = $actor?->id;

        if (! $actor || ! $actor->isAnyAdmin()) {
            throw new AuthorizationException('Forbidden. Only administrators can create accounts.');
        }

        // Security assertion: never allow super_admin creation via ordinary endpoints
        if ($data['role'] === 'super_admin') {
            throw new AuthorizationException('Assigning super_admin through ordinary user management is forbidden.');
        }

        if ($data['role'] === 'admin' && ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Forbidden. Regular administrators cannot create admin accounts.');
        }

        return DB::transaction(function () use ($data, $request, $actorId) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'], // Automatically hashed via model cast
                'role' => $data['role'],
            ]);

            $this->activityLogger->log($actorId, 'admin.user_created', $request, [
                'created_user_id' => $user->id,
                'created_email' => $user->email,
                'assigned_role' => $user->role,
            ]);

            return $user;
        });
    }

    /**
     * Update permitted user details. Password is optional.
     * Protected Super Admin cannot have email changed.
     *
     * @param  array{name: string, email: string, password?: string|null}  $data
     */
    public function updateUser(User $user, array $data, Request $request): User
    {
        $actor = $request->user();
        $actorId = $actor?->id;

        if (! $actor || ! $actor->isAnyAdmin()) {
            throw new AuthorizationException('Forbidden. Only administrators can update accounts.');
        }

        return DB::transaction(function () use ($user, $data, $request, $actor, $actorId) {
            /** @var User $lockedUser */
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            // Regular admin can only update Editor accounts
            if (! $actor->isSuperAdmin()) {
                if ($lockedUser->isAnyAdmin()) {
                    throw new AuthorizationException('Forbidden. Regular administrators can only update editor accounts.');
                }
            }

            // Protected Super Admin email cannot be modified through ordinary user management
            if ($lockedUser->isProtectedSuperAdmin()) {
                if (strtolower(trim($data['email'])) !== strtolower(User::DESIGNATED_SUPER_ADMIN_EMAIL)) {
                    throw new AuthorizationException('The protected Super Admin email address cannot be changed.');
                }
            }

            $updatePayload = [
                'name' => $data['name'],
                'email' => $data['email'],
            ];

            $passwordChanged = false;

            if (! empty($data['password'])) {
                $updatePayload['password'] = $data['password'];
                $passwordChanged = true;
            }

            $lockedUser->update($updatePayload);

            // If an admin updated their own password here, revoke other sessions
            if ($passwordChanged) {
                if ($currentToken = $lockedUser->currentAccessToken()) {
                    $this->sessionManager->revokeOtherSessionsForPasswordChange($lockedUser, $currentToken->id, $request);
                } else {
                    $this->sessionManager->revokeAllSessions($lockedUser, 'password_updated', $request);
                }
            }

            $this->activityLogger->log($actorId, 'admin.user_updated', $request, [
                'target_user_id' => $lockedUser->id,
                'target_email' => $lockedUser->email,
                'password_changed' => $passwordChanged,
            ]);

            return $lockedUser->fresh();
        });
    }

    /**
     * Reset a user's password and immediately revoke all of their active sessions and tokens.
     */
    public function resetPassword(User $user, string $newPassword, Request $request): void
    {
        $actor = $request->user();
        $actorId = $actor?->id;

        if (! $actor || ! $actor->isAnyAdmin()) {
            throw new AuthorizationException('Forbidden. Only administrators can reset passwords.');
        }

        DB::transaction(function () use ($user, $newPassword, $request, $actor, $actorId) {
            /** @var User $lockedUser */
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            if ($lockedUser->isProtectedSuperAdmin()) {
                throw new AuthorizationException('The protected Super Admin account password cannot be reset via ordinary admin endpoints.');
            }

            // Regular admin can only reset password of Editor accounts
            if (! $actor->isSuperAdmin()) {
                if ($lockedUser->isAnyAdmin()) {
                    throw new AuthorizationException('Forbidden. Regular administrators can only reset passwords for editor accounts.');
                }
            }

            $lockedUser->update([
                'password' => $newPassword,
            ]);

            // Revoke all existing sessions and access tokens for the target user
            $this->sessionManager->revokeAllSessions($lockedUser, 'admin_password_reset', $request);

            $this->activityLogger->log($actorId, 'admin.user_password_reset', $request, [
                'target_user_id' => $lockedUser->id,
                'target_email' => $lockedUser->email,
            ]);
        });
    }

    /**
     * Change a user's role between admin and editor.
     * MANDATORY: Super Admin ONLY. Never allows super_admin assignment.
     */
    public function changeRole(User $user, string $newRole, Request $request): User
    {
        $actor = $request->user();
        $actorId = $actor?->id;

        if (! $actor || ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Forbidden. Only the Super Admin can change user roles.');
        }

        if (! in_array($newRole, ['admin', 'editor'], true)) {
            throw new AuthorizationException('Invalid role. Roles can only be changed between admin and editor.');
        }

        return DB::transaction(function () use ($user, $newRole, $request, $actorId) {
            // Lock user row for concurrency protection
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            // Protected Super Admin cannot be demoted through ordinary endpoints
            if ($lockedUser->isProtectedSuperAdmin()) {
                throw new AuthorizationException('The designated Super Admin account is permanently protected and cannot be demoted.');
            }

            if ($lockedUser->role === $newRole) {
                return $lockedUser;
            }

            // Ensure demoting an admin will not leave zero active admins
            $this->adminSafety->ensureCanDeleteOrDemote($lockedUser, $newRole);

            $oldRole = $lockedUser->role;
            $lockedUser->update(['role' => $newRole]);

            // Revoke all existing sessions to enforce new authorization boundaries immediately
            $this->sessionManager->revokeAllSessions($lockedUser, 'role_changed', $request);

            $this->activityLogger->log($actorId, 'admin.user_role_changed', $request, [
                'target_user_id' => $lockedUser->id,
                'old_role' => $oldRole,
                'new_role' => $newRole,
            ]);

            return $lockedUser->fresh();
        });
    }

    /**
     * Delete a user with atomic protections.
     * Regular Admin cannot delete another Admin or the protected Super Admin.
     * Super Admin can delete regular Admins and Editors.
     * Protected Super Admin cannot be deleted through ordinary endpoints.
     *
     * @throws ValidationException
     */
    public function deleteUser(User $user, Request $request): void
    {
        $actor = $request->user();
        $actorId = $actor?->id;

        if (! $actor || ! $actor->isAnyAdmin()) {
            throw new AuthorizationException('Forbidden. Only administrators can delete accounts.');
        }

        DB::transaction(function () use ($user, $actor, $request, $actorId) {
            /** @var User $lockedUser */
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            // 1. Permanent guard: protected Super Admin cannot be deleted
            if ($lockedUser->isProtectedSuperAdmin() || $lockedUser->isFirstAdminEverCreated()) {
                throw new AuthorizationException('The designated Super Admin account is permanently protected and cannot be deleted.');
            }

            // 2. Regular Admin can ONLY delete Editors (and self subject to safeguards)
            if (! $actor->isSuperAdmin()) {
                if ($lockedUser->isAnyAdmin() && (int) $actor->id !== (int) $lockedUser->id) {
                    throw new AuthorizationException('Regular administrators cannot delete other administrators.');
                }
            }

            // 3. Last admin guard if deleting an admin
            if ($lockedUser->isAnyAdmin()) {
                $this->adminSafety->ensureCanDeleteOrDemote($lockedUser, null);
            }

            // Revoke all sessions and tokens prior to deletion
            $this->sessionManager->revokeAllSessions($lockedUser, 'user_deleted', $request);

            $this->activityLogger->log($actorId, 'admin.user_deleted', $request, [
                'deleted_user_id' => $lockedUser->id,
                'deleted_email' => $lockedUser->email,
                'deleted_role' => $lockedUser->role,
            ]);

            // True hard delete
            $lockedUser->forceDelete();
        });
    }
}
