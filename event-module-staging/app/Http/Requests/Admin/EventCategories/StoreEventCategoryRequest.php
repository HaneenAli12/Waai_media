<?php

namespace App\Http\Requests\Admin\EventCategories;

use Illuminate\Foundation\Http\FormRequest;

class StoreEventCategoryRequest extends FormRequest
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
        return [
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:150', 'unique:event_categories,slug'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Prepare the data for validation with sensible defaults.
     */
    protected function prepareForValidation(): void
    {
        $toMerge = [];

        if (! $this->has('is_active')) {
            $toMerge['is_active'] = true;
        }

        if (! $this->has('sort_order')) {
            $toMerge['sort_order'] = 0;
        }

        if (! empty($toMerge)) {
            $this->merge($toMerge);
        }
    }
}
