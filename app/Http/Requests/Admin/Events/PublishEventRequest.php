<?php

namespace App\Http\Requests\Admin\Events;

use App\Models\Event;
use App\Services\Events\EventCoverImageService;
use App\Services\Events\EventDateTimeService;
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
     *
     * Enables publishing an existing draft by evaluating the complete effective state,
     * whether fields are submitted in the publish request body or already persisted.
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
                'author_name',
                'author_name_en',
                'author_name_ar',
                'url',
            ];

            foreach ($fields as $field) {
                if (! $this->has($field) && $event->{$field} !== null) {
                    $toMerge[$field] = $event->{$field};
                }
            }

            // If event_date was not provided in the request, merge the persisted UTC value
            if (! $this->has('event_date') && $event->event_date !== null) {
                $toMerge['event_date'] = $event->event_date->format('Y-m-d H:i:s');
            }

            // Backward compatibility alias handling
            if (! $this->has('author_name_en') && $this->has('author_name')) {
                $toMerge['author_name_en'] = $this->input('author_name');
            } elseif (! $this->has('author_name_en') && $this->has('journalist_name')) {
                $toMerge['author_name_en'] = $this->input('journalist_name');
            }

            if (! $this->has('author_name') && $this->has('author_name_en')) {
                $toMerge['author_name'] = $this->input('author_name_en');
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
     * Descriptions and external URL remain optional.
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
            'event_date' => ['required'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'description_en' => ['nullable', 'string'],
            'description_ar' => ['nullable', 'string'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'author_name_en' => ['nullable', 'string', 'max:255'],
            'author_name_ar' => ['nullable', 'string', 'max:255'],
            'journalist_name' => ['nullable', 'string', 'max:255'],
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
            'event_date.required' => 'An event date and time is required for publication.',
            'timezone.timezone' => 'The selected timezone is not a valid IANA timezone identifier.',
            'url.url' => 'The external link must be a valid URL.',
        ];
    }

    /**
     * Validate whitespace and verify timezone when a new event_date is submitted in the request body.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $data = $v->getData();

            foreach (['name_en', 'name_ar', 'category_en', 'category_ar', 'place_en', 'place_ar'] as $field) {
                if (isset($data[$field]) && trim((string) $data[$field]) === '') {
                    $v->errors()->add($field, "The {$field} field cannot be empty or whitespace only for publication.");
                }
            }

            // If event_date was passed in the request body (not just merged from persisted draft), require timezone
            $rawInputHasEventDate = array_key_exists('event_date', $this->all()) || array_key_exists('event_date', $data);
            $eventDate = $data['event_date'] ?? $this->input('event_date');
            $timezone = $data['timezone'] ?? $this->input('timezone');

            // If a timezone is present or a new date was explicitly provided in input, validate conversion
            if ($rawInputHasEventDate && $eventDate !== null && trim((string) $eventDate) !== '') {
                // If timezone was provided, or if the incoming date differs from persisted UTC date
                if ($timezone === null || trim((string) $timezone) === '') {
                    // Check if event already has a persisted event_date and no timezone was sent
                    $routeParam = $this->route('event') ?? $this->route('id');
                    $event = $routeParam instanceof Event
                        ? $routeParam
                        : (is_numeric($routeParam) ? Event::find((int) $routeParam) : null);

                    $persistedDate = $event?->event_date?->format('Y-m-d H:i:s');
                    if ($persistedDate === null || $persistedDate !== $eventDate) {
                        $v->errors()->add('timezone', 'An IANA timezone identifier is required when providing an event date.');
                    }
                } else {
                    $dateTimeService = app(EventDateTimeService::class);
                    try {
                        $dateTimeService->parseLocalToUtc((string) $eventDate, (string) $timezone);
                    } catch (\Throwable $e) {
                        $v->errors()->add('event_date', 'The provided event date could not be parsed with the given timezone.');
                    }
                }
            }
        });
    }

    /**
     * Get the validated data with server-controlled fields stripped and local datetime converted to UTC.
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

        // Alias journalist_name to author_name
        if (isset($validated['journalist_name']) && ! isset($validated['author_name'])) {
            $validated['author_name'] = $validated['journalist_name'];
        }
        unset($validated['journalist_name']);

        // Convert local date/time + IANA timezone to UTC if newly submitted with timezone
        $rawInputHasEventDate = array_key_exists('event_date', $this->all());
        if ($rawInputHasEventDate && ! empty($validated['event_date']) && ! empty($validated['timezone'])) {
            $dateTimeService = app(EventDateTimeService::class);
            $utc = $dateTimeService->parseLocalToUtc($validated['event_date'], $validated['timezone']);
            $validated['event_date'] = $utc->format('Y-m-d H:i:s');
        }
        unset($validated['timezone']);

        return data_get($validated, $key, $default);
    }
}
