<?php

namespace App\Http\Requests\Admin\Users;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * MANDATORY: Only Super Admin can change roles between admin and editor.
     * Regular Admins and Editors are strictly forbidden.
     * Protected Super Admin cannot be demoted through ordinary endpoints.
     */
    public function authorize(): bool
    {
        $actor = $this->user();

        if (! $actor || ! $actor->isSuperAdmin()) {
            return false;
        }

        $targetUser = $this->route('user');
        if ($targetUser && method_exists($targetUser, 'isProtectedSuperAdmin') && $targetUser->isProtectedSuperAdmin()) {
            return false;
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
            'role' => ['required', 'string', 'in:admin,editor'],
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
            'role.required' => 'The target role is required.',
            'role.in' => 'The role must be either admin or editor.',
        ];
    }
}
