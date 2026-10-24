<?php

namespace App\Http\Requests\Events;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPublicEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Public endpoint, accessible to all users.
     */
    public function authorize(): bool
    {
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
            'category' => ['sometimes', 'nullable', 'string', 'max:150'],
            'timeline' => ['sometimes', 'nullable', 'string', Rule::in(['upcoming', 'past', 'all', 'latest'])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timeline.in' => 'The timeline filter must be one of: upcoming, past, all, latest.',
        ];
    }
}
