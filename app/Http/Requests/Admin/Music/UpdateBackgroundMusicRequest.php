<?php

namespace App\Http\Requests\Admin\Music;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBackgroundMusicRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        return in_array($user->role, ['admin', 'editor'], true);
    }

    /**
     * Prepare the data for validation.
     * Safely normalize string representations of booleans (e.g. from multipart/form-data)
     * so that Laravel's boolean validation rule can validate them accurately without
     * silently defaulting invalid input to null or false.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['is_enabled', 'is_loop'] as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);

                if (is_string($value)) {
                    $trimmed = strtolower(trim($value));

                    if ($trimmed === 'true' || $trimmed === '1') {
                        $merge[$field] = true;
                    } elseif ($trimmed === 'false' || $trimmed === '0') {
                        $merge[$field] = false;
                    }
                    // Any other string (e.g. 'hello', 'abc', '', 'yesplease') is left as-is
                    // so the 'boolean' validation rule will reject it with HTTP 422.
                }
            }
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'audio_file' => [
                'nullable',
                'file',
                'max:15360', // 15 MiB in kilobytes (15 * 1024)
                'mimetypes:audio/*,application/ogg,application/x-ogg,application/vnd.apple.mpegurl',
            ],
            'is_enabled' => ['nullable', 'boolean'],
            'is_loop' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'audio_file.max' => 'The audio file must not exceed 15 megabytes.',
            'audio_file.mimetypes' => 'The uploaded file must be a valid audio file.',
        ];
    }
}
