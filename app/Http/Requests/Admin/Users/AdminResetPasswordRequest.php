<?php

namespace App\Http\Requests\Admin\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AdminResetPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Rules:
     * - Super Admin can reset password of regular Admins and Editors.
     * - Regular Admin can reset password of Editors only.
     * - Regular Admin cannot reset password of another Admin or Super Admin.
     * - Protected Super Admin password cannot be reset via ordinary admin endpoints.
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

        // Cannot reset password of the protected Super Admin via ordinary user management
        if ($targetUser->isProtectedSuperAdmin()) {
            return false;
        }

        // Regular admin can only reset password of Editors
        if (! $actor->isSuperAdmin()) {
            if ($targetUser->isSuperAdmin() || $targetUser->isAdmin()) {
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
        return [
            'password' => ['required', 'string', 'min:8', 'confirmed', Password::defaults()],
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
            'password.required' => 'A new password is required.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }
}
