<?php

namespace App\Http\Requests\Admin\Clients;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
{
    /**
     * Super Admin, Admin, and Editor roles may update clients.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['super_admin', 'admin', 'editor'], true);
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
            'logo' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The client name cannot be empty.',
            'name.string' => 'The client name must be a string.',
            'name.max' => 'The client name must not exceed 255 characters.',
            'logo.image' => 'The logo must be a valid image file.',
            'logo.mimes' => 'The logo must be a file of type: jpeg, jpg, png, webp.',
            'logo.max' => 'The logo must not exceed 5 MB.',
        ];
    }
}
