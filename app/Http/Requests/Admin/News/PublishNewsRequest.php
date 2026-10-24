<?php

namespace App\Http\Requests\Admin\News;

use App\Models\NewsArticle;
use App\Rules\ValidCategoryForModule;
use App\Services\News\HtmlSanitizerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class PublishNewsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Both Administrator and Editor roles have publishing privileges.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['admin', 'editor'], true);
    }

    /**
     * Prepare data for validation by populating omitted fields from the existing article.
     *
     * Ensures that publishing an existing draft checks the complete effective state
     * of the article, whether fields were submitted in the body or already persisted.
     */
    protected function prepareForValidation(): void
    {
        $routeParam = $this->route('news')
            ?? $this->route('news_article')
            ?? $this->route('newsArticle')
            ?? $this->route('article')
            ?? $this->route('id');
        $article = $routeParam instanceof NewsArticle
            ? $routeParam
            : (is_numeric($routeParam) ? NewsArticle::find((int) $routeParam) : null);

        if ($article) {
            $toMerge = [];
            $fields = [
                'category_id',
                'title_en',
                'title_ar',
                'summary_en',
                'summary_ar',
                'content_en',
                'content_ar',
                'cover_image_key',
            ];

            foreach ($fields as $field) {
                if (! $this->has($field) && $article->{$field} !== null) {
                    $toMerge[$field] = $article->{$field};
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                new ValidCategoryForModule('news', disallowParentCategories: true),
            ],
            'title_en' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'summary_en' => ['required', 'string', 'max:500'],
            'summary_ar' => ['required', 'string', 'max:500'],
            'content_en' => ['required', 'string'],
            'content_ar' => ['required', 'string'],
            'cover_image_key' => [
                'required',
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
            'category_id.required' => 'A valid leaf category is required to publish an article.',
            'title_en.required' => 'The English title is required for publication.',
            'title_ar.required' => 'The Arabic title is required for publication.',
            'summary_en.required' => 'The English summary is required for publication.',
            'summary_ar.required' => 'The Arabic summary is required for publication.',
            'summary_en.max' => 'The English summary must not exceed 500 characters.',
            'summary_ar.max' => 'The Arabic summary must not exceed 500 characters.',
            'content_en.required' => 'The English article content is required for publication.',
            'content_ar.required' => 'The Arabic article content is required for publication.',
            'cover_image_key.required' => 'A valid cover image is required for publication.',
            'cover_image_key.regex' => "The cover image key must follow the format 'news/covers/{uuid}.{ext}' with an approved extension (jpg, jpeg, png, webp).",
        ];
    }

    /**
     * Configure additional validator checks for Base64 inline-image constraints.
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
        });
    }

    /**
     * Handle a passed validation attempt by sanitizing effective rich-text content.
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
     * Get the validated data with effective rich-text HTML content safely sanitized.
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
