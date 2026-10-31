<?php

namespace App\Http\Requests\Admin\Portfolio;

use App\Http\Requests\Admin\Portfolio\Concerns\ValidatesPortfolioPayload;
use App\Models\PortfolioItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePortfolioRequest extends FormRequest
{
    use ValidatesPortfolioPayload;

    /**
     * Admin and Editor roles can update portfolio items.
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
        return ['category_id' => ['sometimes', 'integer']] + $this->portfolioRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->portfolioMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Never report success for an empty update. PHP does not parse multipart/form-data bodies for real
            // PATCH/PUT requests, so such requests arrive here with no fields at all.
            if (! $this->hasAny(self::UPDATABLE_FIELDS) && ! $this->hasFile('featured_image') && ! $this->hasFile('cover_image') && ! $this->has('confirm_media_reset')) {
                $isUnparsedMultipart = str_starts_with(strtolower((string) $this->header('Content-Type')), 'multipart/form-data')
                    && $this->getRealMethod() !== 'POST';

                $v->errors()->add('request', $isUnparsedMultipart
                    ? 'Multipart/form-data is not supported on PATCH or PUT. Send a POST request with _method=PATCH.'
                    : 'No updatable fields were provided.');

                return;
            }

            $item = $this->route('portfolioItem');
            $this->validatePortfolioPayload($v, $item instanceof PortfolioItem ? $item : null);
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
