<?php

namespace App\Http\Requests\Admin\News;

use App\Models\Category;
use App\Models\News;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexNewsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Super Admin, Admin, and Editor roles have listing privileges.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['super_admin', 'admin', 'editor'], true);
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
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'category' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['all', News::STATUS_DRAFT, News::STATUS_PUBLISHED])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Validate that any provided category_id belongs to the news module.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->filled('category_id') && ! $v->errors()->has('category_id')) {
                $cat = Category::find((int) $this->input('category_id'));
                if (! $cat || $cat->type !== 'news') {
                    $v->errors()->add('category_id', 'The selected category does not belong to the news module.');
                }
            }
        });
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'The status filter must be one of: all, draft, published.',
            'search.max' => 'The search query must not exceed 255 characters.',
        ];
    }
}
