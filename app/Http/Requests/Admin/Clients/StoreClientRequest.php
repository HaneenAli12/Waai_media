<?php

namespace App\Http\Requests\Admin\Clients;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    /**
     * Super Admin, Admin, and Editor roles may create clients.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['super_admin', 'admin', 'editor'], true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'logo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The client name is required.',
            'name.string' => 'The client name must be a string.',
            'name.max' => 'The client name must not exceed 255 characters.',
            'logo.required' => 'The client logo is required.',
            'logo.image' => 'The logo must be a valid image file.',
            'logo.mimes' => 'The logo must be a file of type: jpeg, jpg, png, webp.',
            'logo.max' => 'The logo must not exceed 5 MB.',
        ];
    }
}
