<?php

namespace App\Http\Requests\Admin\Blog;

use App\Models\Blog;
use App\Models\BlogImage;
use App\Rules\ValidCategoryForModule;
use App\Services\Blog\BlogImageService;
use App\Services\Blog\BlogSlugService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PublishBlogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Super Admin, Admin, and Editor roles can publish blog articles.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['super_admin', 'admin', 'editor'], true);
    }

    /**
     * Resolve the blog article targeted by the route.
     */
    public function routeBlog(): ?Blog
    {
        $routeParam = $this->route('blog') ?? $this->route('id');

        return $routeParam instanceof Blog
            ? $routeParam
            : (is_numeric($routeParam) ? Blog::find((int) $routeParam) : null);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'required', 'integer', new ValidCategoryForModule('blogs', disallowParentCategories: true)],
            'title_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'title_en' => ['sometimes', 'required', 'string', 'max:255'],
            'author_name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'author_name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'short_description_ar' => ['sometimes', 'required', 'string', 'max:2000'],
            'short_description_en' => ['sometimes', 'required', 'string', 'max:2000'],
            'content_ar' => ['sometimes', 'required', 'string'],
            'content_en' => ['sometimes', 'required', 'string'],
            'featured_image_id' => ['sometimes', 'nullable'],
            'featured_image' => ['nullable', 'file', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
        ];
    }

    /**
     * Additional validation to ensure all mandatory publication fields exist effectively.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->exists('featured_image_url')) {
                $v->errors()->add('featured_image_url', 'Direct image URLs are not accepted. Upload an image file or provide a valid featured_image_id.');
            }

            $blog = $this->routeBlog();
            $data = $v->getData();

            $effectiveTitleAr = array_key_exists('title_ar', $data) ? trim((string) $data['title_ar']) : trim((string) ($blog?->title_ar ?? ''));
            $effectiveTitleEn = array_key_exists('title_en', $data) ? trim((string) $data['title_en']) : trim((string) ($blog?->title_en ?? ''));
            $effectiveAuthorAr = array_key_exists('author_name_ar', $data) ? trim((string) $data['author_name_ar']) : trim((string) ($blog?->author_name_ar ?? ''));
            $effectiveAuthorEn = array_key_exists('author_name_en', $data) ? trim((string) $data['author_name_en']) : trim((string) ($blog?->author_name_en ?? ''));
            $effectiveShortDescAr = array_key_exists('short_description_ar', $data) ? trim((string) $data['short_description_ar']) : trim((string) ($blog?->short_description_ar ?? ''));
            $effectiveShortDescEn = array_key_exists('short_description_en', $data) ? trim((string) $data['short_description_en']) : trim((string) ($blog?->short_description_en ?? ''));

            if ($effectiveTitleAr === '') {
                $v->errors()->add('title_ar', 'The Arabic title is required for publication.');
            }
            if ($effectiveTitleEn === '') {
                $v->errors()->add('title_en', 'The English title is required for publication.');
            }
            if ($effectiveAuthorAr === '') {
                $v->errors()->add('author_name_ar', 'The Arabic author name is required for publication.');
            }
            if ($effectiveAuthorEn === '') {
                $v->errors()->add('author_name_en', 'The English author name is required for publication.');
            }
            if ($effectiveShortDescAr === '') {
                $v->errors()->add('short_description_ar', 'The Arabic short description is required for publication.');
            }
            if ($effectiveShortDescEn === '') {
                $v->errors()->add('short_description_en', 'The English short description is required for publication.');
            }

            // Featured image validation
            if (array_key_exists('featured_image_id', $data) && ! $v->errors()->has('featured_image_id')) {
                $featuredImageId = $data['featured_image_id'];
                if ($featuredImageId !== null && $featuredImageId !== '') {
                    $img = is_numeric($featuredImageId) ? BlogImage::find((int) $featuredImageId) : null;
                    if (! $img) {
                        $v->errors()->add('featured_image_id', 'The selected featured image does not exist.');
                    } elseif ($img->deleting_at !== null) {
                        $v->errors()->add('featured_image_id', 'The selected featured image is currently being deleted and cannot be associated.');
                    } elseif (! app(BlogImageService::class)->canUserAssociateImage($img, $this->user(), $blog?->id)) {
                        $v->errors()->add('featured_image_id', 'You are not authorized to associate an unattached image uploaded by another user.');
                    }
                }
            }

            $hasNewFile = $this->hasFile('featured_image');
            $hasExplicitId = array_key_exists('featured_image_id', $data)
                && $data['featured_image_id'] !== null
                && $data['featured_image_id'] !== ''
                && ! $v->errors()->has('featured_image_id');
            $hasExistingCover = ! array_key_exists('featured_image_id', $data)
                && $blog
                && ($blog->featured_image_id !== null || ! empty($blog->featured_image_url));

            if (! $hasNewFile && ! $hasExplicitId && ! $hasExistingCover) {
                $v->errors()->add('featured_image', 'A featured image is required for publication.');
            }

            // Validate that title_en generates a valid slug
            $slugService = app(BlogSlugService::class);
            if ($slugService->normalizeTitle($effectiveTitleEn) === null) {
                $v->errors()->add('title_en', 'The English title must contain valid Latin alphanumeric characters to generate a slug for publication.');
            }
        });
    }
}
