<?php

namespace App\Http\Requests\Admin\Events;

use App\Models\Event;
use App\Models\EventCategory;
use App\Services\Events\EventDateTimeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Both Administrator and Editor roles have update privileges.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['admin', 'editor'], true);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $toMerge = [];
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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name_en' => ['nullable', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'event_category_id' => ['nullable', 'integer', 'exists:event_categories,id'],
            'category_en' => ['nullable', 'string', 'max:150'],
            'category_ar' => ['nullable', 'string', 'max:150'],
            'description_en' => ['nullable', 'string'],
            'description_ar' => ['nullable', 'string'],
            'place_en' => ['nullable', 'string', 'max:255'],
            'place_ar' => ['nullable', 'string', 'max:255'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'author_name_en' => ['nullable', 'string', 'max:255'],
            'author_name_ar' => ['nullable', 'string', 'max:255'],
            'journalist_name' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:500'],
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
            'cover_image_url' => ['nullable', 'url', 'max:500'],
            'event_date' => ['nullable', 'string'],
            'timezone' => ['required_with:event_date', 'nullable', 'string', 'timezone:all'],
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
            'timezone.required_with' => 'An IANA timezone identifier (e.g. Africa/Cairo, Asia/Riyadh) is required when updating the event date.',
            'timezone.timezone' => 'The selected timezone is not a valid IANA timezone identifier.',
            'cover_image.image' => 'The cover image must be a valid image file.',
            'cover_image.mimes' => 'The cover image must be a file of type: jpeg, jpg, png, webp.',
            'cover_image.max' => 'The cover image must not exceed 10 MB.',
            'cover_image_url.url' => 'The cover image URL must be a valid URL.',
            'url.url' => 'The external link must be a valid URL.',
        ];
    }

    /**
     * Additional validation for effective title preservation and timezone parsing.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $data = $v->getData();
            $routeParam = $this->route('event') ?? $this->route('id');
            $event = $routeParam instanceof Event
                ? $routeParam
                : (is_numeric($routeParam) ? Event::find((int) $routeParam) : null);

            if ($event && $event->isDraft()) {
                $hasNameEn = array_key_exists('name_en', $data) || $this->has('name_en');
                $hasNameAr = array_key_exists('name_ar', $data) || $this->has('name_ar');

                $effectiveNameEn = $hasNameEn
                    ? trim((string) ($data['name_en'] ?? $this->input('name_en')))
                    : trim((string) $event->name_en);

                $effectiveNameAr = $hasNameAr
                    ? trim((string) ($data['name_ar'] ?? $this->input('name_ar')))
                    : trim((string) $event->name_ar);

                if ($effectiveNameEn === '' && $effectiveNameAr === '') {
                    $v->errors()->add('name_en', 'At least one event title (Arabic or English) must remain non-empty.');
                    $v->errors()->add('name_ar', 'At least one event title (Arabic or English) must remain non-empty.');
                }
            }

            $eventDate = $data['event_date'] ?? $this->input('event_date');
            $timezone = $data['timezone'] ?? $this->input('timezone');

            if ($eventDate !== null && trim((string) $eventDate) !== '') {
                if ($timezone === null || trim((string) $timezone) === '') {
                    $v->errors()->add('timezone', 'An IANA timezone identifier is required when providing an event date.');
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

        // Convert local date/time + IANA timezone to UTC
        if (! empty($validated['event_date']) && ! empty($validated['timezone'])) {
            $dateTimeService = app(EventDateTimeService::class);
            $utc = $dateTimeService->parseLocalToUtc($validated['event_date'], $validated['timezone']);
            $validated['event_date'] = $utc->format('Y-m-d H:i:s');
        }
        // Populate category text fields from event_category_id if provided and text fields are omitted
        if (! empty($validated['event_category_id'])) {
            $cat = EventCategory::query()->find($validated['event_category_id']);
            if ($cat) {
                if (empty($validated['category_en'])) {
                    $validated['category_en'] = $cat->name_en;
                }
                if (empty($validated['category_ar'])) {
                    $validated['category_ar'] = $cat->name_ar;
                }
            }
        }

        return data_get($validated, $key, $default);
    }
}
