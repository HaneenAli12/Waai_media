<?php

namespace App\Http\Requests\Admin\Events;

use App\Models\Event;
use App\Services\Events\EventCoverImageService;
use App\Services\Events\EventSlugService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class PublishEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Both Administrator and Editor roles have event publishing privileges.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['admin', 'editor'], true);
    }

    /**
     * Prepare data for validation by populating omitted fields from the existing event.
     */
    protected function prepareForValidation(): void
    {
        $routeParam = $this->route('event') ?? $this->route('id');
        $event = $routeParam instanceof Event
            ? $routeParam
            : (is_numeric($routeParam) ? Event::find((int) $routeParam) : null);

        if ($event) {
            $toMerge = [];
            $fields = [
                'name_en',
                'name_ar',
                'category_en',
                'category_ar',
                'place_en',
                'place_ar',
                'description_en',
                'description_ar',
                'cover_image_url',
                'event_date',
                'event_time',
                'url',
            ];

            foreach ($fields as $field) {
                if (! $this->has($field) && $event->{$field} !== null) {
                    if ($field === 'event_date' && $event->event_date instanceof \DateTimeInterface) {
                        $toMerge[$field] = $event->event_date->format('Y-m-d');
                    } else {
                        $toMerge[$field] = $event->{$field};
                    }
                }
            }

            if (! empty($toMerge)) {
                $this->merge($toMerge);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Publishing strictly requires all mandatory event fields:
     * - Both titles (name_en, name_ar)
     * - Both categories (category_en, category_ar)
     * - Both places (place_en, place_ar)
     * - event_date
     * - cover_image_url
     *
     * Descriptions, event_time, and external url remain optional.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
            'category_en' => ['required', 'string', 'max:150'],
            'category_ar' => ['required', 'string', 'max:150'],
            'place_en' => ['required', 'string', 'max:255'],
            'place_ar' => ['required', 'string', 'max:255'],
            'cover_image_url' => [
                'required',
                'string',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        $fail('A valid cover image is required for publication.');

                        return;
                    }

                    $trimmed = trim($value);

                    // 1. If it is an absolute URL, validate URL format
                    if (Str::startsWith($trimmed, ['http://', 'https://'])) {
                        if (! filter_var($trimmed, FILTER_VALIDATE_URL)) {
                            $fail('The cover image URL must be a valid URL.');
                        }

                        return;
                    }

                    // 2. If it is an R2 storage key, validate through EventCoverImageService
                    $imageService = app(EventCoverImageService::class);
                    if (! $imageService->isR2Key($trimmed)) {
                        $fail('The cover image must be a valid URL or a verified stored storage key.');
                    }
                },
            ],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'event_time' => ['required', 'date_format:H:i,H:i:s'],
            'description_en' => ['required', 'string'],
            'description_ar' => ['required', 'string'],
            'url' => ['nullable', 'url', 'max:500'],
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
            'name_en.required' => 'The English event title is required for publication.',
            'name_ar.required' => 'The Arabic event title is required for publication.',
            'category_en.required' => 'The English event category is required for publication.',
            'category_ar.required' => 'The Arabic event category is required for publication.',
            'place_en.required' => 'The English event place/venue is required for publication.',
            'place_ar.required' => 'The Arabic event place/venue is required for publication.',
            'cover_image_url.required' => 'A valid cover image URL is required for publication.',
            'cover_image_url.url' => 'The cover image URL must be a valid URL.',
            'event_date.required' => 'An event date is required for publication.',
            'event_date.date_format' => 'The event date must be in Y-m-d format (e.g. 2026-11-15).',
            'event_time.date_format' => 'The event time must be in H:i or H:i:s format (e.g. 14:30 or 14:30:00).',
            'url.url' => 'The external link must be a valid URL.',
        ];
    }

    /**
     * Validate whitespace on mandatory fields and ensure English title produces a valid slug.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $data = $v->getData();

            foreach (['name_en', 'name_ar', 'category_en', 'category_ar', 'place_en', 'place_ar', 'description_en', 'description_ar'] as $field) {
                if (isset($data[$field]) && trim((string) $data[$field]) === '') {
                    $v->errors()->add($field, "The {$field} field cannot be empty or whitespace only for publication.");
                }
            }

            if (! empty($data['name_en'])) {
                $slugService = app(EventSlugService::class);
                if ($slugService->normalizeTitle((string) $data['name_en']) === null) {
                    $v->errors()->add('name_en', 'The English event title must contain valid Latin alphanumeric characters to generate a slug for publication.');
                }
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

        // Protect server-controlled lifecycle attributes
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
