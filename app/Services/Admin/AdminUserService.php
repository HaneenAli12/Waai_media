<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\Auth\ActivityLogger;
use App\Services\Auth\AdminSafetyService;
use App\Services\Auth\SessionManager;
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

        if ($role !== null && in_array($role, ['admin', 'editor'], true)) {
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
     *
     * @param  array{name: string, email: string, password: string, role: string}  $data
     */
    public function createUser(array $data, Request $request): User
    {
        $actorId = $request->user()?->id;

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
     *
     * @param  array{name: string, email: string, password?: string|null}  $data
     */
    public function updateUser(User $user, array $data, Request $request): User
    {
        $actorId = $request->user()?->id;

        return DB::transaction(function () use ($user, $data, $request, $actorId) {
            $updatePayload = [
                'name' => $data['name'],
                'email' => $data['email'],
            ];

            $passwordChanged = false;

            if (! empty($data['password'])) {
                $updatePayload['password'] = $data['password'];
                $passwordChanged = true;
            }

            $user->update($updatePayload);

            // If an admin updated their own password here, revoke other sessions
            if ($passwordChanged) {
                if ($currentToken = $user->currentAccessToken()) {
                    $this->sessionManager->revokeOtherSessionsForPasswordChange($user, $currentToken->id, $request);
                } else {
                    $this->sessionManager->revokeAllSessions($user, 'password_updated', $request);
                }
            }

            $this->activityLogger->log($actorId, 'admin.user_updated', $request, [
                'target_user_id' => $user->id,
                'target_email' => $user->email,
                'password_changed' => $passwordChanged,
            ]);

            return $user->fresh();
        });
    }

    /**
     * Reset a user's password and immediately revoke all of their active sessions and tokens.
     */
    public function resetPassword(User $user, string $newPassword, Request $request): void
    {
        $actorId = $request->user()?->id;

        DB::transaction(function () use ($user, $newPassword, $request, $actorId) {
            $user->update([
                'password' => $newPassword,
            ]);

            // Revoke all existing sessions and access tokens for the target user
            $this->sessionManager->revokeAllSessions($user, 'admin_password_reset', $request);

            $this->activityLogger->log($actorId, 'admin.user_password_reset', $request, [
                'target_user_id' => $user->id,
                'target_email' => $user->email,
            ]);
        });
    }

    /**
     * Change a user's role with atomic last-admin safety guard and session revocation.
     */
    public function changeRole(User $user, string $newRole, Request $request): User
    {
        $actorId = $request->user()?->id;

        return DB::transaction(function () use ($user, $newRole, $request, $actorId) {
            // Lock user row for concurrency protection
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

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
     * Delete a user with atomic first-admin-ever-created and last-admin protections.
     * Hard-deletes editors. Protects the first admin ever created and the last remaining active admin.
     *
     * @throws ValidationException
     */
    public function deleteUser(User $user, Request $request): void
    {
        $actorId = $request->user()?->id;

        DB::transaction(function () use ($user, $request, $actorId) {
            /** @var User $lockedUser */
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            if ($lockedUser->isAdmin()) {
                // 1. Guard against deleting the first admin account ever created
                $this->adminSafety->ensureNotFirstAdmin($lockedUser);

                // 2. Guard against deleting the last remaining active admin
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
