<?php

namespace App\Http\Requests\Admin\Team;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamMemberRequest extends FormRequest
{
    /**
     * Both Administrator and Editor roles may update team members.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['admin', 'editor'], true);
    }

    /**
     * Partial updates: omitted fields are preserved, present fields must be valid.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'position' => ['sometimes', 'required', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The team member name cannot be empty.',
            'position.required' => 'The team member position cannot be empty.',
            'photo.image' => 'The photo must be a valid image file.',
            'photo.mimes' => 'The photo must be a file of type: jpeg, jpg, png, webp.',
            'photo.max' => 'The photo must not exceed 5 MB.',
        ];
    }
}
