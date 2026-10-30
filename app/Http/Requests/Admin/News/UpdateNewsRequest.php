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

class UpdateNewsRequest extends FormRequest
{
    /**
     * Fields an update request may change.
     *
     * @var list<string>
     */
    public const UPDATABLE_FIELDS = [
        'category_id',
        'title_ar',
        'title_en',
        'author_name_ar',
        'author_name_en',
        'short_description_ar',
        'short_description_en',
        'content_ar',
        'content_en',
        'featured_image_id',
        'featured_image',
        'status',
        'action',
    ];

    /**
     * Determine if the user is authorized to make this request.
     * Super Admin, Admin, and Editor roles can update news articles.
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
     * Determine the target status after applying this update.
     *
     * An existing published article remains published unless explicitly saved as a draft.
     */
    public function targetStatus(): string
    {
        $action = strtolower(trim((string) $this->input('action', '')));
        $status = strtolower(trim((string) $this->input('status', '')));

        if ($action === 'publish' || $status === News::STATUS_PUBLISHED) {
            return News::STATUS_PUBLISHED;
        }

        if ($action === 'draft' || $status === News::STATUS_DRAFT) {
            return News::STATUS_DRAFT;
        }

        $news = $this->routeNews();

        return $news?->status ?? News::STATUS_DRAFT;
    }

    /**
     * Get the validation rules that apply to the request.
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
            'status' => ['sometimes', 'nullable', 'string', Rule::in([News::STATUS_DRAFT, News::STATUS_PUBLISHED])],
            'action' => ['sometimes', 'nullable', 'string', Rule::in(['draft', 'publish'])],
        ];
    }

    /**
     * Validate final effective state of the article according to the target status.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->exists('featured_image_url')) {
                $v->errors()->add('featured_image_url', 'Direct image URLs are not accepted. Upload an image file or provide a valid featured_image_id.');
            }

            if (! $this->hasAny(self::UPDATABLE_FIELDS) && ! $this->hasFile('featured_image')) {
                $isUnparsedMultipart = str_starts_with(strtolower((string) $this->header('Content-Type')), 'multipart/form-data')
                    && $this->getRealMethod() !== 'POST';

                $v->errors()->add('request', $isUnparsedMultipart
                    ? 'Multipart/form-data is not supported on PATCH or PUT. Send a POST request with _method=PATCH.'
                    : 'No updatable fields were provided.');

                return;
            }

            $news = $this->routeNews();
            if (! $news) {
                return;
            }

            $data = $v->getData();

            // Validate featured_image_id existence, deletion state, and ownership authorization if provided
            if (array_key_exists('featured_image_id', $data) && $data['featured_image_id'] !== null && $data['featured_image_id'] !== '' && ! $v->errors()->has('featured_image_id')) {
                $img = is_numeric($data['featured_image_id']) ? NewsImage::find((int) $data['featured_image_id']) : null;
                if (! $img) {
                    $v->errors()->add('featured_image_id', 'The selected featured image does not exist.');
                } elseif ($img->deleting_at !== null) {
                    $v->errors()->add('featured_image_id', 'The selected featured image is currently being deleted and cannot be associated.');
                } elseif (! app(NewsImageService::class)->canUserAssociateImage($img, $this->user(), $news->id)) {
                    $v->errors()->add('featured_image_id', 'You are not authorized to associate an unattached image uploaded by another user.');
                }
            }

            // Compute effective values after merging request over existing record
            $effectiveTitleAr = trim((string) (array_key_exists('title_ar', $data) ? $data['title_ar'] : $news->title_ar));
            $effectiveTitleEn = trim((string) (array_key_exists('title_en', $data) ? $data['title_en'] : $news->title_en));
            $effectiveCategoryId = array_key_exists('category_id', $data) ? $data['category_id'] : $news->category_id;

            // Validate existing category if not overridden
            if (! array_key_exists('category_id', $data)) {
                $catRule = new ValidCategoryForModule('news', disallowParentCategories: true);
                $catRule->validate('category_id', $effectiveCategoryId, function (string $msg) use ($v) {
                    $v->errors()->add('category_id', $msg);
                });
            }

            $targetStatus = $this->targetStatus();

            if ($targetStatus === News::STATUS_DRAFT) {
                if ($effectiveTitleAr === '' && $effectiveTitleEn === '') {
                    $v->errors()->add('title', 'At least one title (Arabic or English) is required for a draft.');
                }

                if (array_key_exists('title_en', $data) && $effectiveTitleEn !== '') {
                    $slugService = app(NewsSlugService::class);
                    if ($slugService->normalizeTitle($effectiveTitleEn) === null) {
                        $v->errors()->add('title_en', 'The English title must contain valid Latin alphanumeric characters to generate a slug.');
                    }
                }

                return;
            }

            // Target status is PUBLISHED: require all mandatory bilingual fields + featured image
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
                    $v->errors()->add($field, "The {$label} is required for a published news article.");
                }
            }

            if ($effectiveTitleEn !== '' && ! $v->errors()->has('title_en')) {
                $slugService = app(NewsSlugService::class);
                if ($slugService->normalizeTitle($effectiveTitleEn) === null) {
                    $v->errors()->add('title_en', 'The English title must contain valid Latin alphanumeric characters to generate a slug.');
                }
            }

            // Check effective featured image
            $hasFeaturedFile = $this->hasFile('featured_image');
            $featuredIdProvided = array_key_exists('featured_image_id', $data);
            $effectiveFeaturedId = $featuredIdProvided
                ? $data['featured_image_id']
                : $news->featured_image_id;
            $hasStoredUrl = ! $featuredIdProvided && $news->featured_image_id === null && ! empty($news->featured_image_url);

            if (! $hasFeaturedFile && (empty($effectiveFeaturedId) || $v->errors()->has('featured_image_id')) && ! $hasStoredUrl) {
                $v->errors()->add('featured_image', 'A featured image is required for a published news article.');
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
