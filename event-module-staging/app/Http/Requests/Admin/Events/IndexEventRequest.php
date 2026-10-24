<?php

namespace App\Http\Requests\Admin\Events;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Both Administrator and Editor roles have listing privileges.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['admin', 'editor'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in([Event::STATUS_DRAFT, Event::STATUS_PUBLISHED])],
            'timeline' => ['sometimes', 'nullable', 'string', Rule::in(['upcoming', 'past'])],
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
            'status.in' => 'The status filter must strictly be one of: draft, published.',
            'timeline.in' => 'The timeline filter must be one of: upcoming, past.',
            'search.max' => 'The search query must not exceed 255 characters.',
        ];
    }
}
