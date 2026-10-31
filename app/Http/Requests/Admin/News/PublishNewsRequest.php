<?php

namespace App\Http\Requests\Admin\News;

use App\Models\News;
use App\Models\NewsImage;
use App\Rules\ValidCategoryForModule;
use App\Services\News\NewsImageService;
use App\Services\News\NewsSlugService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PublishNewsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Super Admin, Admin, and Editor roles can publish news articles.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['super_admin', 'admin', 'editor'], true);
    }

    /**
     * Resolve the news article targeted by the route.
     */
    public function routeNews(): ?News
    {
        $routeParam = $this->route('news') ?? $this->route('id');

        return $routeParam instanceof News
            ? $routeParam
            : (is_numeric($routeParam) ? News::find((int) $routeParam) : null);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Uses `sometimes` so omitted fields are not pre-merged from the unlocked route model;
     * effective values are validated in `withValidator()` and resolved under `lockForUpdate()`
     * in the controller.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'required', 'integer', new ValidCategoryForModule('news', disallowParentCategories: true)],
            'title_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'author_name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'author_name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'short_description_ar' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'short_description_en' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'content_ar' => ['sometimes', 'nullable', 'string'],
            'content_en' => ['sometimes', 'nullable', 'string'],
            'featured_image_id' => ['sometimes', 'nullable', 'integer'],
            'featured_image' => ['sometimes', 'nullable', 'file', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
        ];
    }

    /**
     * Validate publish completeness and featured image availability/ownership.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->exists('featured_image_url')) {
                $v->errors()->add('featured_image_url', 'Direct image URLs are not accepted. Upload an image file or provide a valid featured_image_id.');
            }

            $news = $this->routeNews();
            if (! $news) {
                return;
            }

            $data = $v->getData();

            // Validate existing category if not overridden
            if (! array_key_exists('category_id', $data)) {
                $catRule = new ValidCategoryForModule('news', disallowParentCategories: true);
                $catRule->validate('category_id', $news->category_id, function (string $msg) use ($v) {
                    $v->errors()->add('category_id', $msg);
                });
            }

            // Validate featured_image_id existence, deletion state, and ownership authorization if provided
            $featuredIdProvided = array_key_exists('featured_image_id', $data);
            $featuredImageId = $featuredIdProvided ? $data['featured_image_id'] : $news->featured_image_id;

            if ($featuredIdProvided && $featuredImageId !== null && $featuredImageId !== '' && ! $v->errors()->has('featured_image_id')) {
                $img = is_numeric($featuredImageId) ? NewsImage::find((int) $featuredImageId) : null;
                if (! $img) {
                    $v->errors()->add('featured_image_id', 'The selected featured image does not exist.');
                } elseif ($img->deleting_at !== null) {
                    $v->errors()->add('featured_image_id', 'The selected featured image is currently being deleted and cannot be associated.');
                } elseif (! app(NewsImageService::class)->canUserAssociateImage($img, $this->user(), $news->id)) {
                    $v->errors()->add('featured_image_id', 'You are not authorized to associate an unattached image uploaded by another user.');
                }
            }

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
                $val = trim((string) (array_key_exists($field, $data) ? $data[$field] : $news->{$field}));
                if ($val === '' && ! $v->errors()->has($field)) {
                    $v->errors()->add($field, "The {$label} is required for publication.");
                }
            }

            $effectiveTitleEn = trim((string) (array_key_exists('title_en', $data) ? $data['title_en'] : $news->title_en));
            if ($effectiveTitleEn !== '' && ! $v->errors()->has('title_en')) {
                $slugService = app(NewsSlugService::class);
                if ($slugService->normalizeTitle($effectiveTitleEn) === null) {
                    $v->errors()->add('title_en', 'The English title must contain valid Latin alphanumeric characters to generate a slug for publication.');
                }
            }

            $hasFeaturedFile = $this->hasFile('featured_image');
            $hasValidImageId = $featuredImageId !== null && $featuredImageId !== '' && ! $v->errors()->has('featured_image_id');
            // Explicitly sending featured_image_id: null must NEVER fall back to an old stored URL
            $hasStoredUrl = ! $featuredIdProvided && $news->featured_image_id === null && ! empty($news->featured_image_url);

            if (! $hasFeaturedFile && ! $hasValidImageId && ! $hasStoredUrl) {
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
            $validated['published_at'],
            $validated['created_at'],
            $validated['updated_at']
        );

        return data_get($validated, $key, $default);
    }
}
