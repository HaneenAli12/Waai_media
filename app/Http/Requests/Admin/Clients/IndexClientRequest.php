<?php

namespace App\Http\Requests\Admin\Clients;

use Illuminate\Foundation\Http\FormRequest;

class IndexClientRequest extends FormRequest
{
    /**
     * Super Admin, Admin, and Editor roles have listing privileges.
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
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
