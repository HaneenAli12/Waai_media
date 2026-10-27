<?php

namespace App\Http\Requests\Admin\ContactInfo;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContactInfoRequest extends FormRequest
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
     * Safely normalizes optional phone inputs without mutating non-string types.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('phone_2')) {
            $phone2 = $this->input('phone_2');
            if (is_string($phone2)) {
                $trimmed = trim($phone2);
                $normalized['phone_2'] = $trimmed === '' ? null : $trimmed;
            }
        }

        if ($this->has('phone_3')) {
            $phone3 = $this->input('phone_3');
            if (is_string($phone3)) {
                $trimmed = trim($phone3);
                $normalized['phone_3'] = $trimmed === '' ? null : $trimmed;
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
        $phoneRegex = 'regex:/^(\+?[0-9\s\-\(\)]+)$/';

        return [
            'phone_1' => ['required', 'string', 'min:5', 'max:50', $phoneRegex],
            'phone_2' => ['nullable', 'string', 'min:5', 'max:50', $phoneRegex],
            'phone_3' => ['nullable', 'string', 'min:5', 'max:50', $phoneRegex],
            'official_email' => ['required', 'string', 'email:rfc,filter', 'max:255'],
            'facebook' => ['required', 'string', 'url:http,https', 'max:500'],
            'instagram' => ['required', 'string', 'url:http,https', 'max:500'],
            'youtube' => ['required', 'string', 'url:http,https', 'max:500'],
            'linkedin' => ['required', 'string', 'url:http,https', 'max:500'],
            'tiktok' => ['required', 'string', 'url:http,https', 'max:500'],
            'whatsapp' => ['required', 'string', 'url:http,https', 'max:500'],
            'complete_address' => ['required', 'string', 'max:2000'],
            'google_maps_url' => ['required', 'string', 'url:http,https', 'max:2000'],
        ];
    }

    /**
     * Custom validation messages for contact info fields.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_1.required' => 'The primary phone number is required.',
            'phone_1.regex' => 'The primary phone number format is invalid.',
            'phone_2.regex' => 'The secondary phone number format is invalid.',
            'phone_3.regex' => 'The third phone number format is invalid.',
            'official_email.required' => 'The official email address is required.',
            'official_email.email' => 'The official email must be a valid email address.',
            'facebook.url' => 'The Facebook link must be a valid HTTP or HTTPS URL.',
            'instagram.url' => 'The Instagram link must be a valid HTTP or HTTPS URL.',
            'youtube.url' => 'The YouTube link must be a valid HTTP or HTTPS URL.',
            'linkedin.url' => 'The LinkedIn link must be a valid HTTP or HTTPS URL.',
            'tiktok.url' => 'The TikTok link must be a valid HTTP or HTTPS URL.',
            'whatsapp.url' => 'The WhatsApp link must be a valid HTTP or HTTPS URL.',
            'google_maps_url.url' => 'The Google Maps link must be a valid HTTP or HTTPS URL.',
        ];
    }
}
