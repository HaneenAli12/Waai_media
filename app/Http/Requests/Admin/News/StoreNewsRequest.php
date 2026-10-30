<?php

namespace App\Http\Requests\Admin\News;

use App\Models\News;
use App\Models\NewsImage;
use App\Rules\ValidCategoryForModule;
use App\Services\News\NewsImageService;
use App\Services\News\NewsSlugService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreNewsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Super Admin, Admin, and Editor roles can create news articles.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['super_admin', 'admin', 'editor'], true);
    }

    /**
     * Determine if the requested action is to publish immediately.
     */
    public function isPublishing(): bool
    {
        $action = strtolower(trim((string) $this->input('action', '')));
        $status = strtolower(trim((string) $this->input('status', '')));

        return $action === 'publish' || $status === News::STATUS_PUBLISHED;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $isPublishing = $this->isPublishing();

        return [
            'category_id' => ['required', 'integer', new ValidCategoryForModule('news', disallowParentCategories: true)],
            'title_ar' => [$isPublishing ? 'required' : 'nullable', 'string', 'max:255'],
            'title_en' => [$isPublishing ? 'required' : 'nullable', 'string', 'max:255'],
            'author_name_ar' => [$isPublishing ? 'required' : 'nullable', 'string', 'max:255'],
            'author_name_en' => [$isPublishing ? 'required' : 'nullable', 'string', 'max:255'],
            'short_description_ar' => [$isPublishing ? 'required' : 'nullable', 'string', 'max:2000'],
            'short_description_en' => [$isPublishing ? 'required' : 'nullable', 'string', 'max:2000'],
            'content_ar' => [$isPublishing ? 'required' : 'nullable', 'string'],
            'content_en' => [$isPublishing ? 'required' : 'nullable', 'string'],
            'featured_image_id' => ['nullable', 'integer'],
            'featured_image' => ['nullable', 'file', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in([News::STATUS_DRAFT, News::STATUS_PUBLISHED])],
            'action' => ['sometimes', 'nullable', 'string', Rule::in(['draft', 'publish'])],
        ];
    }

    /**
     * Additional validation rules for draft vs publish requirements and image ownership/safety.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->exists('featured_image_url')) {
                $v->errors()->add('featured_image_url', 'Direct image URLs are not accepted. Upload an image file or provide a valid featured_image_id.');
            }

            $data = $v->getData();
            $titleAr = trim((string) ($data['title_ar'] ?? $this->input('title_ar', '')));
            $titleEn = trim((string) ($data['title_en'] ?? $this->input('title_en', '')));

            // Validate featured_image_id existence, deletion state, and ownership authorization if provided
            $featuredImageId = $data['featured_image_id'] ?? $this->input('featured_image_id');
            if ($featuredImageId !== null && $featuredImageId !== '' && ! $v->errors()->has('featured_image_id')) {
                $img = is_numeric($featuredImageId) ? NewsImage::find((int) $featuredImageId) : null;
                if (! $img) {
                    $v->errors()->add('featured_image_id', 'The selected featured image does not exist.');
                } elseif ($img->deleting_at !== null) {
                    $v->errors()->add('featured_image_id', 'The selected featured image is currently being deleted and cannot be associated.');
                } elseif (! app(NewsImageService::class)->canUserAssociateImage($img, $this->user(), null)) {
                    $v->errors()->add('featured_image_id', 'You are not authorized to associate an unattached image uploaded by another user.');
                }
            }

            if (! $this->isPublishing()) {
                // Draft rule: At least one Arabic or English title is required
                if ($titleAr === '' && $titleEn === '') {
                    $v->errors()->add('title', 'At least one title (Arabic or English) is required to save a draft.');
                }

                if ($titleEn !== '') {
                    $slugService = app(NewsSlugService::class);
                    if ($slugService->normalizeTitle($titleEn) === null) {
                        $v->errors()->add('title_en', 'The English title must contain valid Latin alphanumeric characters to generate a slug.');
                    }
                }

                return;
            }

            // Publish validation: require all bilingual fields non-empty + valid featured image
            $mandatoryFields = [
                'title_ar' => 'Arabic title',
                'title_en' => 'English title',
                'author_name_ar' => 'Arabic author name',
                'author_name_en' => 'English author name',
                'short_description_ar' => 'Arabic short description',
                'short_description_en' => 'English short description',
                'content_ar' => 'Arabic content',
                'content_en' => 'English content',
            ];

            foreach ($mandatoryFields as $field => $label) {
                $val = trim((string) ($data[$field] ?? $this->input($field, '')));
                if ($val === '' && ! $v->errors()->has($field)) {
                    $v->errors()->add($field, "The {$label} is required for publication.");
                }
            }

            if ($titleEn !== '' && ! $v->errors()->has('title_en')) {
                $slugService = app(NewsSlugService::class);
                if ($slugService->normalizeTitle($titleEn) === null) {
                    $v->errors()->add('title_en', 'The English title must contain valid Latin alphanumeric characters to generate a slug for publication.');
                }
            }

            $hasFeaturedFile = $this->hasFile('featured_image');
            $hasValidImageId = $featuredImageId !== null && $featuredImageId !== '' && ! $v->errors()->has('featured_image_id');

            if (! $hasFeaturedFile && ! $hasValidImageId) {
                $v->errors()->add('featured_image', 'A featured image is required for publication.');
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

        unset(
            $validated['id'],
            $validated['slug'],
            $validated['status'],
            $validated['action'],
            $validated['published_at'],
            $validated['created_at'],
            $validated['updated_at']
        );

        return data_get($validated, $key, $default);
    }
}
