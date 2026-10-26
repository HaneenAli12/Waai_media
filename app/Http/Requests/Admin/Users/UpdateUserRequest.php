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
     * Rule:
     * - Admin can update an Editor's profile.
     * - Admin can update their OWN Admin profile.
     * - Admin CANNOT update another Admin's profile.
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

        // If target is an admin, actor must be the target user themselves
        if ($targetUser->isAdmin() && (int) $actor->id !== (int) $targetUser->id) {
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
