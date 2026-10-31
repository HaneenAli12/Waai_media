<?php

namespace App\Http\Requests\Admin\Portfolio;

use App\Http\Requests\Admin\Portfolio\Concerns\ValidatesPortfolioPayload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePortfolioRequest extends FormRequest
{
    use ValidatesPortfolioPayload;

    /**
     * Admin and Editor roles can create portfolio items.
     */
    public function authorize(): bool
    {
        return $this->isPortfolioAuthor();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['category_id' => ['required', 'integer'], 'client_id' => ['required', 'integer', Rule::exists('clients', 'id')]] + $this->portfolioRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->portfolioMessages() + [
            'category_id.required' => 'The portfolio category is required.',
            'client_id.required' => 'The client is required.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->has('media.0.id') || collect((array) ($this->all()['media'] ?? []))->contains(fn ($e) => is_array($e) && isset($e['id']))) {
                $v->errors()->add('media', 'Media ids can only be used when updating an existing portfolio item.');

                return;
            }

            $this->validatePortfolioPayload($v, null);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function serviceInput(): array
    {
        return $this->portfolioServiceInput();
    }
}
