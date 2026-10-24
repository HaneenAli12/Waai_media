<?php

namespace App\Http\Requests\Admin\EventCategories;

use App\Models\EventCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
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
        /** @var EventCategory|null $eventCategory */
        $eventCategory = $this->route('eventCategory');
        $categoryId = $eventCategory?->id ?? (int) $this->route('event_category');

        return [
            'name_en' => ['sometimes', 'required', 'string', 'max:150'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                Rule::unique('event_categories', 'slug')->ignore($categoryId),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
