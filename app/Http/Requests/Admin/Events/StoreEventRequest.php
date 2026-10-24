<?php

namespace App\Http\Requests\Admin\Events;

use App\Services\Events\EventSlugService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Both Administrator and Editor roles have draft creation privileges.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['admin', 'editor'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Drafts may be incomplete but strictly require at least one non-empty title (Arabic or English).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'category_en' => ['nullable', 'string', 'max:150'],
            'category_ar' => ['nullable', 'string', 'max:150'],
            'description_en' => ['nullable', 'string'],
            'description_ar' => ['nullable', 'string'],
            'place_en' => ['nullable', 'string', 'max:255'],
            'place_ar' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:500'],
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
            'cover_image_url' => ['nullable', 'url', 'max:500'],
            'event_date' => ['nullable', 'date_format:Y-m-d'],
            'event_time' => ['nullable', 'date_format:H:i,H:i:s'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name_en.required' => 'The English event title is required.',
            'cover_image.image' => 'The cover image must be a valid image file.',
            'cover_image.mimes' => 'The cover image must be a file of type: jpeg, jpg, png, webp.',
            'cover_image.max' => 'The cover image must not exceed 10 MB.',
            'cover_image_url.url' => 'The cover image URL must be a valid URL.',
            'url.url' => 'The external link must be a valid URL.',
            'event_date.date_format' => 'The event date must be in Y-m-d format (e.g. 2026-11-15).',
            'event_time.date_format' => 'The event time must be in H:i or H:i:s format (e.g. 14:30 or 14:30:00).',
        ];
    }

    /**
     * Additional validator checks for whitespace-only and Latin slug-viable English titles.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $data = $v->getData();
            $nameEn = trim((string) ($data['name_en'] ?? $this->input('name_en')));

            if ($nameEn === '') {
                $v->errors()->add('name_en', 'The English event title cannot be empty or whitespace only.');

                return;
            }

            $slugService = app(EventSlugService::class);
            if ($slugService->normalizeTitle($nameEn) === null) {
                $v->errors()->add('name_en', 'The English event title must contain valid Latin alphanumeric characters to generate a slug.');
            }
        });
    }

    /**
     * Get the validated data with server-controlled fields stripped.
     *
     * @param  array|int|string|null  $key
     * @param  mixed  $default
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated();

        // Protect server-controlled lifecycle attributes from client injection
        unset(
            $validated['id'],
            $validated['slug'],
            $validated['status'],
            $validated['published_at'],
            $validated['created_at'],
            $validated['updated_at'],
            $validated['deleted_at']
        );

        return data_get($validated, $key, $default);
    }
}
