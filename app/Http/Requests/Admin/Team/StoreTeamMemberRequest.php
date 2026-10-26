<?php

namespace App\Http\Requests\Admin\Team;

use Illuminate\Foundation\Http\FormRequest;

class StoreTeamMemberRequest extends FormRequest
{
    /**
     * Both Administrator and Editor roles may create team members.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['admin', 'editor'], true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'position' => ['required', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The team member name is required.',
            'position.required' => 'The team member position is required.',
            'photo.image' => 'The photo must be a valid image file.',
            'photo.mimes' => 'The photo must be a file of type: jpeg, jpg, png, webp.',
            'photo.max' => 'The photo must not exceed 5 MB.',
        ];
    }
}
