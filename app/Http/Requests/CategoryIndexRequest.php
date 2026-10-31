<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CategoryIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Public endpoint, no authentication required.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['news', 'blogs', 'portfolio'])],
            // Optional hierarchy modes (all backward compatible; without them the response is unchanged)
            'level' => ['sometimes', 'string', Rule::in(['parent', 'all'])],
            'include_children' => ['sometimes', 'boolean'],
            'parent_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')->where(
                fn ($q) => $q->where('type', $this->input('type'))->whereNull('parent_id')
            )],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            if ($this->has('parent_id') && ($this->has('level') || $this->has('include_children'))) {
                $v->errors()->add('parent_id', 'parent_id cannot be combined with level or include_children.');
            }

            if ($this->input('level') === 'parent' && $this->boolean('include_children')) {
                $v->errors()->add('include_children', 'include_children cannot be true when level=parent.');
            }
        });
    }

    /**
     * Custom messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'The category type is required.',
            'level.in' => 'The level must be either parent or all.',
            'parent_id.exists' => 'The selected parent category is invalid for this category type.',
            'type.in' => 'The selected category type is invalid. Supported types are news, blogs, and portfolio.',
        ];
    }
}
