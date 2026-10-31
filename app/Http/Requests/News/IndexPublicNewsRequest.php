<?php

namespace App\Http\Requests\News;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IndexPublicNewsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Public endpoint, accessible without authentication.
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
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'category' => ['sometimes', 'nullable', 'string', 'max:150'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
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
}
