<?php

namespace App\Http\Requests\Admin\News;

use App\Models\NewsArticle;
use App\Rules\ValidCategoryForModule;
use App\Services\News\HtmlSanitizerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateNewsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Both Administrator and Editor roles have full update privileges.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['admin', 'editor'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                new ValidCategoryForModule('news', disallowParentCategories: true),
            ],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'summary_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            'summary_ar' => ['sometimes', 'nullable', 'string', 'max:500'],
            'content_en' => ['sometimes', 'nullable', 'string'],
            'content_ar' => ['sometimes', 'nullable', 'string'],
            'cover_image_key' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'regex:/^news\/covers\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|jpeg|png|webp)$/i',
            ],
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
            'category_id.required' => 'A valid leaf category is required.',
            'summary_en.max' => 'The English summary must not exceed 500 characters.',
            'summary_ar.max' => 'The Arabic summary must not exceed 500 characters.',
            'cover_image_key.regex' => "The cover image key must follow the format 'news/covers/{uuid}.{ext}' with an approved extension (jpg, jpeg, png, webp).",
        ];
    }

    /**
     * Configure additional validator checks for title preservation and inline images.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $sanitizerService = app(HtmlSanitizerService::class);

            if ($this->has('content_en') && $this->input('content_en') !== null) {
                try {
                    $sanitizerService->validateInlineImages($this->input('content_en'), 'content_en');
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $key => $messages) {
                        foreach ($messages as $msg) {
                            $v->errors()->add($key, $msg);
                        }
                    }
                }
            }

            if ($this->has('content_ar') && $this->input('content_ar') !== null) {
                try {
                    $sanitizerService->validateInlineImages($this->input('content_ar'), 'content_ar');
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $key => $messages) {
                        foreach ($messages as $msg) {
                            $v->errors()->add($key, $msg);
                        }
                    }
                }
            }

            // Ensure at least one title remains populated after the update
            $hasTitleEn = $this->has('title_en');
            $hasTitleAr = $this->has('title_ar');

            if ($hasTitleEn || $hasTitleAr) {
                $routeParam = $this->route('news')
                    ?? $this->route('news_article')
                    ?? $this->route('newsArticle')
                    ?? $this->route('article')
                    ?? $this->route('id');
                $existingArticle = $routeParam instanceof NewsArticle
                    ? $routeParam
                    : (is_numeric($routeParam) ? NewsArticle::find((int) $routeParam) : null);

                $effectiveTitleEn = $hasTitleEn ? $this->input('title_en') : $existingArticle?->title_en;
                $effectiveTitleAr = $hasTitleAr ? $this->input('title_ar') : $existingArticle?->title_ar;

                $enEmpty = $effectiveTitleEn === null || trim($effectiveTitleEn) === '';
                $arEmpty = $effectiveTitleAr === null || trim($effectiveTitleAr) === '';

                if ($enEmpty && $arEmpty) {
                    $v->errors()->add('title_en', 'At least one title language (Arabic or English) must remain populated.');
                    $v->errors()->add('title_ar', 'At least one title language (Arabic or English) must remain populated.');
                }
            }
        });
    }

    /**
     * Handle a passed validation attempt by sanitizing supplied rich-text content.
     */
    protected function passedValidation(): void
    {
        $sanitizer = app(HtmlSanitizerService::class);
        $merged = [];

        if ($this->has('content_en') && $this->input('content_en') !== null) {
            $merged['content_en'] = $sanitizer->sanitize($this->input('content_en'));
        }

        if ($this->has('content_ar') && $this->input('content_ar') !== null) {
            $merged['content_ar'] = $sanitizer->sanitize($this->input('content_ar'));
        }

        if (! empty($merged)) {
            $this->merge($merged);
        }
    }

    /**
     * Get the validated data with supplied rich-text HTML content safely sanitized.
     *
     * @param  array|int|string|null  $key
     * @param  mixed  $default
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated();
        $sanitizer = app(HtmlSanitizerService::class);

        if (array_key_exists('content_en', $validated) && $validated['content_en'] !== null) {
            $validated['content_en'] = $sanitizer->sanitize($validated['content_en']);
        }

        if (array_key_exists('content_ar', $validated) && $validated['content_ar'] !== null) {
            $validated['content_ar'] = $sanitizer->sanitize($validated['content_ar']);
        }

        return data_get($validated, $key, $default);
    }
}
