<?php

namespace App\Http\Requests\Admin\AppLinks;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppLinksRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Both Administrator and Editor roles have full update privileges.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && in_array($user->role, ['super_admin', 'admin', 'editor'], true);
    }

    /**
     * Prepare the data for validation.
     * Safely normalizes empty/whitespace strings to null while preserving
     * non-string types for validation type checks.
     * Does not convert missing properties into present properties.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['google_app_link', 'apple_app_store_link', 'app_gallery_link'] as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                if (is_string($value)) {
                    $trimmed = trim($value);
                    $normalized[$field] = $trimmed === '' ? null : $trimmed;
                }
            }
        }

        if (! empty($normalized)) {
            $this->merge($normalized);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'google_app_link' => ['present', 'nullable', 'string', 'url:http,https', 'max:500'],
            'apple_app_store_link' => ['present', 'nullable', 'string', 'url:http,https', 'max:500'],
            'app_gallery_link' => ['present', 'nullable', 'string', 'url:http,https', 'max:500'],
        ];
    }

    /**
     * Custom validation messages for App Links fields.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'google_app_link.present' => 'The google app link field must be present.',
            'google_app_link.url' => 'The google app link must be a valid HTTP or HTTPS URL.',
            'google_app_link.max' => 'The google app link may not be greater than 500 characters.',
            'apple_app_store_link.present' => 'The apple app store link field must be present.',
            'apple_app_store_link.url' => 'The apple app store link must be a valid HTTP or HTTPS URL.',
            'apple_app_store_link.max' => 'The apple app store link may not be greater than 500 characters.',
            'app_gallery_link.present' => 'The app gallery link field must be present.',
            'app_gallery_link.url' => 'The app gallery link must be a valid HTTP or HTTPS URL.',
            'app_gallery_link.max' => 'The app gallery link may not be greater than 500 characters.',
        ];
    }
}
