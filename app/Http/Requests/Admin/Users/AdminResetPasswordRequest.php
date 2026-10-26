<?php

namespace App\Http\Requests\Admin\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AdminResetPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Rule:
     * - Admin can reset an Editor's password.
     * - Admin CANNOT reset another Admin's password through Users Management.
     * - Admin resetting their OWN password must use self-service /auth/change-password.
     */
    public function authorize(): bool
    {
        $actor = $this->user();

        if (! $actor || ! $actor->isAdmin()) {
            return false;
        }

        $targetUser = $this->route('user');

        if (! $targetUser instanceof User) {
            $targetUser = User::find($targetUser);
        }

        if (! $targetUser) {
            return true; // Let controller handle 404
        }

        // An Admin cannot reset another Admin's password through Users Management
        if ($targetUser->isAdmin()) {
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
