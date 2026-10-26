<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\AdminResetPasswordRequest;
use App\Http\Requests\Admin\Users\IndexUserRequest;
use App\Http\Requests\Admin\Users\StoreUserRequest;
use App\Http\Requests\Admin\Users\UpdateUserRequest;
use App\Http\Requests\Admin\Users\UpdateUserRoleRequest;
use App\Http\Resources\UserAdminResource;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        protected AdminUserService $userService
    ) {}

    /**
     * Display a paginated listing of dashboard users.
     */
    public function index(IndexUserRequest $request): JsonResponse
    {
        $perPage = (int) ($request->input('per_page') ?: 15);
        $search = $request->input('search');
        $role = $request->input('role');

        $users = $this->userService->listUsers($search, $role, $perPage);

        return UserAdminResource::collection($users)->response();
    }

    /**
     * Display the specified user.
     */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'data' => new UserAdminResource($user),
        ], 200);
    }

    /**
     * Create a new user account (Admin or Editor).
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->createUser($request->validated(), $request);

        return response()->json([
            'message' => 'User created successfully.',
            'data' => new UserAdminResource($user),
        ], 201);
    }

    /**
     * Update permitted user profile details.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $updatedUser = $this->userService->updateUser($user, $request->validated(), $request);

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => new UserAdminResource($updatedUser),
        ], 200);
    }

    /**
     * Change a user's role.
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        $updatedUser = $this->userService->changeRole($user, $request->validated('role'), $request);

        return response()->json([
            'message' => 'User role updated successfully.',
            'data' => new UserAdminResource($updatedUser),
        ], 200);
    }

    /**
     * Admin-managed password reset for an editor.
     */
    public function resetPassword(AdminResetPasswordRequest $request, User $user): JsonResponse
    {
        $this->userService->resetPassword($user, $request->validated('password'), $request);

        return response()->json([
            'message' => 'User password reset successfully.',
        ], 200);
    }

    /**
     * Permanently delete a user account.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if (! $actor || ! $actor->isAnyAdmin()) {
            return response()->json([
                'message' => 'Forbidden. Only administrators can delete accounts.',
            ], 403);
        }

        $this->userService->deleteUser($user, $request);

        return response()->json([
            'message' => 'User permanently deleted successfully.',
        ], 200);
    }
}
