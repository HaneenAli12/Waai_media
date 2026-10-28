<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublicClientIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Public endpoint, no authorization required.
     */
    public function authorize(): bool
    {
        return true;
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
