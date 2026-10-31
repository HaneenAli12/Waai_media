<?php

namespace App\Http\Requests\Portfolio;

use App\Http\Requests\Admin\Portfolio\Concerns\ValidatesPortfolioFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IndexPublicPortfolioRequest extends FormRequest
{
    use ValidatesPortfolioFilters;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->portfolioFilterRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->portfolioFilterMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateFilterHierarchy($v));
    }
}
