<?php

namespace App\Http\Requests\Portfolio;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /portfolio/clients/{client}?category_id= - the subcategory tabs of one client inside one main category.
 */
class PublicPortfolioClientNavigationRequest extends FormRequest
{
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
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where(
                fn ($q) => $q->where('type', 'portfolio')->whereNull('parent_id')
            )],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.required' => 'The main portfolio category is required.',
            'category_id.exists' => 'The selected portfolio category is invalid.',
        ];
    }
}
