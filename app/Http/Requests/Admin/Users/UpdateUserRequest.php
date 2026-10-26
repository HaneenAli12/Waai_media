<?php

namespace App\Http\Requests\Admin\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Rules:
     * - Super Admin can edit regular Admins and Editors.
     * - Regular Admin can edit Editors only.
     * - Regular Admin cannot edit another Admin or the protected Super Admin.
     * - Protected Super Admin's email cannot be changed through ordinary endpoints.
     */
    public function authorize(): bool
    {
        $actor = $this->user();

        if (! $actor || ! $actor->isAnyAdmin()) {
            return false;
        }

        $targetUser = $this->route('user');

        if (! $targetUser instanceof User) {
            $targetUser = User::find($targetUser);
        }

        if (! $targetUser) {
            return true; // Let controller handle 404
        }

        // 1. Regular Admin: can only edit Editors.
        if (! $actor->isSuperAdmin()) {
            if ($targetUser->isProtectedSuperAdmin() || $targetUser->isSuperAdmin() || $targetUser->isAdmin()) {
                return false;
            }

            return true;
        }

        // 2. Super Admin: can edit regular Admins and Editors.
        // Protected account email cannot be changed through ordinary endpoints.
        if ($targetUser->isProtectedSuperAdmin()) {
            $newEmail = $this->input('email');
            if ($newEmail !== null && strtolower(trim((string) $newEmail)) !== strtolower(User::DESIGNATED_SUPER_ADMIN_EMAIL)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $targetUser = $this->route('user');
        $targetId = $targetUser instanceof User ? $targetUser->id : $targetUser;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email:rfc,dns',
                'max:255',
                Rule::unique('users', 'email')->ignore($targetId),
            ],
            'password' => ['nullable', 'string', 'min:8', Password::defaults()],
        ];
    }

    /**
     * Custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The user name is required.',
            'email.required' => 'A valid email address is required.',
            'email.unique' => 'This email address is already in use by another account.',
        ];
    }
}
