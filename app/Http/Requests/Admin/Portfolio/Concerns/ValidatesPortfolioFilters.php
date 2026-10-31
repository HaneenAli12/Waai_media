<?php

namespace App\Http\Requests\Admin\Portfolio\Concerns;

use App\Models\Category;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Category/subcategory/pagination filter validation shared by the admin and public portfolio listings.
 */
trait ValidatesPortfolioFilters
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function portfolioFilterRules(): array
    {
        return [
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where(
                fn ($q) => $q->where('type', 'portfolio')->whereNull('parent_id')
            )],
            'subcategory_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where(
                fn ($q) => $q->where('type', 'portfolio')->whereNotNull('parent_id')
            )],
            'client_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clients', 'id')],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function portfolioFilterMessages(): array
    {
        return [
            'category_id.exists' => 'The selected portfolio category is invalid.',
            'client_id.exists' => 'The selected client does not exist.',
            'subcategory_id.exists' => 'The selected portfolio subcategory is invalid.',
        ];
    }

    /**
     * A subcategory filter must belong to the category filter when both are supplied.
     */
    protected function validateFilterHierarchy(Validator $v): void
    {
        if ($v->errors()->isNotEmpty() || ! $this->filled('category_id') || ! $this->filled('subcategory_id')) {
            return;
        }

        $matches = Category::where('id', (int) $this->input('subcategory_id'))
            ->where('parent_id', (int) $this->input('category_id'))
            ->exists();

        if (! $matches) {
            $v->errors()->add('subcategory_id', 'The selected subcategory does not belong to the selected category.');
        }
    }
}
