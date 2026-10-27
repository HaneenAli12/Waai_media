<?php

namespace App\Http\Requests\Admin\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ShowUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Rules:
     * - Super Admin can view details of any account (Super Admin, Admin, Editor).
     * - Regular Admin can view details of Editor accounts ONLY.
     * - Regular Admin cannot view details of other Admins or the Super Admin.
     * - Editor cannot access user management endpoints.
     */
    public function authorize(): bool
    {
        $actor = $this->user();

        if (! $actor || ! $actor->isAnyAdmin()) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        $targetUser = $this->route('user');

        if (! $targetUser instanceof User) {
            $targetUser = User::find($targetUser);
        }

        if (! $targetUser) {
            return true; // Let controller handle 404
        }

        // Regular admin can only view Editor accounts
        return $targetUser->isEditor();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
