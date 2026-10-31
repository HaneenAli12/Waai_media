<?php

namespace App\Http\Requests\Admin\Portfolio;

use App\Http\Requests\Admin\Portfolio\Concerns\ValidatesPortfolioFilters;
use App\Models\PortfolioItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexPortfolioRequest extends FormRequest
{
    use ValidatesPortfolioFilters;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['super_admin', 'admin', 'editor'], true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->portfolioFilterRules() + [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in([PortfolioItem::STATUS_DRAFT, PortfolioItem::STATUS_PUBLISHED])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->portfolioFilterMessages() + [
            'status.in' => 'The status filter must strictly be one of: draft, published.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateFilterHierarchy($v));
    }
}
