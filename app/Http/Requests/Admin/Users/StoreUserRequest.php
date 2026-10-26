<?php

namespace App\Http\Requests\Admin\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Super Admin can create Admin or Editor.
     * Regular Admin can create Editor only.
     * Editor cannot create any user.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user || ! $user->isAnyAdmin()) {
            return false;
        }

        $targetRole = (string) $this->input('role');

        // If role is admin, only Super Admin can create it
        if ($targetRole === 'admin') {
            return $user->isSuperAdmin();
        }

        // For all other roles (including invalid roles like 'foo' or 'super_admin'),
        // let authorize pass for admins so validation rules can trigger 422 with proper errors
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     * Never expose super_admin as an assignable option.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();
        $allowedRoles = $user && $user->isSuperAdmin() ? 'admin,editor' : 'editor';

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', Password::defaults()],
            'role' => ['required', 'string', "in:{$allowedRoles}"],
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
            'email.unique' => 'This email address is already in use.',
            'password.required' => 'A secure password is required.',
            'role.required' => 'A user role (admin or editor) is required.',
            'role.in' => 'The selected role must be either admin or editor.',
        ];
    }
}
