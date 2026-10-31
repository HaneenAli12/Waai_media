<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Blog\IndexBlogRequest;
use App\Http\Requests\Admin\Blog\PublishBlogRequest;
use App\Http\Requests\Admin\Blog\StoreBlogRequest;
use App\Http\Requests\Admin\Blog\UpdateBlogRequest;
use App\Http\Requests\Admin\Blog\UploadBlogImageRequest;
use App\Http\Resources\BlogAdminResource;
use App\Jobs\DeleteUnreferencedBlogImagesJob;
use App\Models\Blog;
use App\Models\BlogImage;
use App\Models\Category;
use App\Services\Blog\BlogHtmlSanitizerService;
use App\Services\Blog\BlogImageService;
use App\Services\Blog\BlogSlugService;
use App\Services\Storage\Exceptions\InvalidImageException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BlogController extends Controller
{
    /**
     * Default page size for admin blog listing (10 records per page by default).
     */
    public const PER_PAGE = 10;

    public function __construct(
        protected BlogSlugService $slugService,
        protected BlogImageService $imageService,
        protected BlogHtmlSanitizerService $htmlSanitizer
    ) {}

    /**
     * List blog articles with server-side pagination (default 10), case-insensitive title search,
     * category filtering, and status filtering (all, published, draft).
     */
    public function index(IndexBlogRequest $request): JsonResponse
    {
        $query = Blog::query()
            ->with(['category.parent', 'featuredImage', 'editorImages'])
            ->orderedForAdmin();

        // Case-insensitive title search (Arabic and English) with literal wildcard escaping
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search, 'UTF-8'));
            $query->where(function ($q) use ($escaped) {
                $q->whereRaw("LOWER(title_en) LIKE ? ESCAPE '!'", ["%{$escaped}%"])
                    ->orWhereRaw("LOWER(title_ar) LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
            });
        }

        // Category filtering (supports category_id or category slug/name, including parent category expansion)
        $this->applyCategoryFilter($query, $request->input('category_id'), $request->input('category'));

        // Status filtering: all, published, draft
        if ($request->filled('status')) {
            $status = strtolower(trim((string) $request->input('status')));
            if (in_array($status, [Blog::STATUS_DRAFT, Blog::STATUS_PUBLISHED], true)) {
                $query->where('status', $status);
            }
        }

        $perPage = (int) $request->input('per_page', self::PER_PAGE);

        $paginated = $query->paginate($perPage);

        return BlogAdminResource::collection($paginated)->response();
    }

    /**
     * Retrieve a complete blog article for editing.
     */
    public function show(Blog $blog): JsonResponse
    {
        $blog->load(['category.parent', 'featuredImage', 'editorImages']);

        return response()->json([
            'data' => new BlogAdminResource($blog),
        ], 200);
    }

    /**
     * Upload an image to Cloudflare R2 and persist its metadata in `blog_images`.
     *
     * Returns the database image ID and accessible delivery URL so the frontend
     * can display the image immediately and associate it when saving the article.
     */
    public function uploadImage(UploadBlogImageRequest $request): JsonResponse
    {
        $type = strtolower(trim((string) $request->input('type', 'editor')));
        $directory = in_array($type, ['featured', 'cover'], true)
            ? BlogImageService::COVERS_DIRECTORY
            : BlogImageService::EDITOR_DIRECTORY;

        try {
            $blogImage = $this->imageService->uploadAndCreate(
                $request->file('image'),
                $directory,
                $request->user()?->id
            );
        } catch (InvalidImageException $e) {
            throw ValidationException::withMessages([
                'image' => [$e->getMessage()],
            ]);
        }

        $url = $this->imageService->resolveUrl($blogImage);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $blogImage->id,
                'url' => $url,
            ],
        ], 201);
    }

    /**
     * Create a new blog article (as draft or published depending on requested action/status).
     */
    public function store(StoreBlogRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $isPublishing = $request->isPublishing();
        $user = $request->user();

        // 1. Sanitize Arabic and English HTML content and extract verified editor image IDs
        $sanitizedAr = $this->htmlSanitizer->sanitizeAndExtractImages($validated['content_ar'] ?? null, 'content_ar', $user, null);
        $sanitizedEn = $this->htmlSanitizer->sanitizeAndExtractImages($validated['content_en'] ?? null, 'content_en', $user, null);

        if ($isPublishing) {
            if (! $this->htmlSanitizer->hasMeaningfulContent($sanitizedAr['html'])) {
                throw ValidationException::withMessages([
                    'content_ar' => ['The Arabic content cannot be empty after sanitization for publication.'],
                ]);
            }
            if (! $this->htmlSanitizer->hasMeaningfulContent($sanitizedEn['html'])) {
                throw ValidationException::withMessages([
                    'content_en' => ['The English content cannot be empty after sanitization for publication.'],
                ]);
            }
        }

        $validated['content_ar'] = $sanitizedAr['html'];
        $validated['content_en'] = $sanitizedEn['html'];
        $editorImageIds = array_values(array_unique(array_merge($sanitizedAr['image_ids'], $sanitizedEn['image_ids'])));

        // 2. Handle direct featured_image file upload if supplied in multipart request
        $uploadedFeaturedImage = null;
        if ($request->hasFile('featured_image')) {
            try {
                $uploadedFeaturedImage = $this->imageService->uploadAndCreate(
                    $request->file('featured_image'),
                    BlogImageService::COVERS_DIRECTORY,
                    $user?->id
                );
            } catch (InvalidImageException $e) {
                throw ValidationException::withMessages([
                    'featured_image' => [$e->getMessage()],
                ]);
            }

            $validated['featured_image_id'] = $uploadedFeaturedImage->id;
            $validated['featured_image_url'] = $uploadedFeaturedImage->storage_key;
        } elseif (! empty($validated['featured_image_id'])) {
            $img = BlogImage::find((int) $validated['featured_image_id']);
            $validated['featured_image_id'] = $img?->id;
            $validated['featured_image_url'] = $img?->storage_key;
        } else {
            $validated['featured_image_id'] = null;
            $validated['featured_image_url'] = null;
        }

        unset($validated['featured_image']);

        $validated['status'] = $isPublishing ? Blog::STATUS_PUBLISHED : Blog::STATUS_DRAFT;
        $validated['published_at'] = $isPublishing ? now() : null;
        $validated['slug'] = null;

        try {
            $blog = DB::transaction(function () use ($validated, $editorImageIds, $user) {
                $featuredId = $validated['featured_image_id'] !== null ? (int) $validated['featured_image_id'] : null;
                $allTargetImageIds = array_values(array_unique(array_filter(array_merge(
                    $editorImageIds,
                    $featuredId !== null ? [$featuredId] : []
                ))));

                $this->imageService->lockAndVerifyImagesForAssociation($allTargetImageIds, $user, null, $featuredId);

                if ($featuredId !== null) {
                    $featuredImg = BlogImage::find($featuredId);
                    $validated['featured_image_url'] = $featuredImg?->storage_key;
                } else {
                    $validated['featured_image_url'] = null;
                }

                $created = Blog::create($validated);

                $slug = $this->slugService->generateSlug($created->title_en, $created->id);
                if ($slug !== null) {
                    $created->update(['slug' => $slug]);
                }

                $this->imageService->syncArticleImages(
                    $created->id,
                    $created->featured_image_id,
                    $editorImageIds
                );

                return $created;
            });
        } catch (\Throwable $e) {
            if ($uploadedFeaturedImage !== null) {
                $this->imageService->deleteImageIfUnreferenced($uploadedFeaturedImage->id);
            }

            throw $e;
        }

        $blog->load(['category.parent', 'featuredImage', 'editorImages']);

        return response()->json([
            'data' => new BlogAdminResource($blog),
        ], 201);
    }

    /**
     * Update an existing blog article.
     *
     * Resolves omitted fields and image references from the latest row-locked database record
     * (`lockForUpdate()`), recalculates editor image IDs while holding the database locks,
     * and cleans up detached images only after the transaction commits.
     */
    public function update(UpdateBlogRequest $request, Blog $blog): JsonResponse
    {
        $validated = $request->validated();
        $targetStatus = $request->targetStatus();
        $user = $request->user();

        $contentArProvided = array_key_exists('content_ar', $validated);
        $contentEnProvided = array_key_exists('content_en', $validated);

        // 1. Pre-validate and sanitize any explicitly provided HTML fields before uploading files
        $preSanitizedAr = $contentArProvided
            ? $this->htmlSanitizer->sanitizeAndExtractImages($validated['content_ar'], 'content_ar', $user, $blog->id)
            : null;
        $preSanitizedEn = $contentEnProvided
            ? $this->htmlSanitizer->sanitizeAndExtractImages($validated['content_en'], 'content_en', $user, $blog->id)
            : null;

        if ($targetStatus === Blog::STATUS_PUBLISHED) {
            if ($preSanitizedAr !== null && ! $this->htmlSanitizer->hasMeaningfulContent($preSanitizedAr['html'])) {
                throw ValidationException::withMessages([
                    'content_ar' => ['The Arabic content cannot be empty after sanitization for a published blog article.'],
                ]);
            }
            if ($preSanitizedEn !== null && ! $this->htmlSanitizer->hasMeaningfulContent($preSanitizedEn['html'])) {
                throw ValidationException::withMessages([
                    'content_en' => ['The English content cannot be empty after sanitization for a published blog article.'],
                ]);
            }
        }

        // 2. Handle direct featured_image file upload outside the DB transaction
        $uploadedFeaturedImage = null;
        if ($request->hasFile('featured_image')) {
            try {
                $uploadedFeaturedImage = $this->imageService->uploadAndCreate(
                    $request->file('featured_image'),
                    BlogImageService::COVERS_DIRECTORY,
                    $user?->id
                );
            } catch (InvalidImageException $e) {
                throw ValidationException::withMessages([
                    'featured_image' => [$e->getMessage()],
                ]);
            }
        }

        unset($validated['featured_image']);

        $detachedImageIds = [];

        try {
            DB::transaction(function () use (
                $blog,
                $validated,
                $targetStatus,
                $user,
                $contentArProvided,
                $contentEnProvided,
                $preSanitizedAr,
                $preSanitizedEn,
                $uploadedFeaturedImage,
                &$detachedImageIds
            ) {
                $locked = Blog::where('id', $blog->id)->lockForUpdate()->first();
                if (! $locked) {
                    throw (new ModelNotFoundException)->setModel(Blog::class, [$blog->id]);
                }

                // Collect previously attached image IDs from the locked state
                $previousPivotIds = DB::table('blog_article_images')
                    ->where('blog_id', $locked->id)
                    ->pluck('blog_image_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $previousImageIds = array_values(array_unique(array_filter(array_merge(
                    $previousPivotIds,
                    $locked->featured_image_id ? [(int) $locked->featured_image_id] : []
                ))));

                // Resolve omitted content_ar and content_en from the latest locked database record
                $sanitizedAr = $preSanitizedAr
                    ?? $this->htmlSanitizer->sanitizeAndExtractImages($locked->content_ar, 'content_ar', $user, $locked->id);
                $sanitizedEn = $preSanitizedEn
                    ?? $this->htmlSanitizer->sanitizeAndExtractImages($locked->content_en, 'content_en', $user, $locked->id);

                if ($targetStatus === Blog::STATUS_PUBLISHED) {
                    if (! $this->htmlSanitizer->hasMeaningfulContent($sanitizedAr['html'])) {
                        throw ValidationException::withMessages([
                            'content_ar' => ['The Arabic content cannot be empty after sanitization for a published blog article.'],
                        ]);
                    }
                    if (! $this->htmlSanitizer->hasMeaningfulContent($sanitizedEn['html'])) {
                        throw ValidationException::withMessages([
                            'content_en' => ['The English content cannot be empty after sanitization for a published blog article.'],
                        ]);
                    }
                }

                $updates = $validated;

                if ($contentArProvided) {
                    $updates['content_ar'] = $sanitizedAr['html'];
                }
                if ($contentEnProvided) {
                    $updates['content_en'] = $sanitizedEn['html'];
                }

                // Recalculate editor image IDs from effective article content while holding the row lock
                $newEditorImageIds = array_values(array_unique(array_merge($sanitizedAr['image_ids'], $sanitizedEn['image_ids'])));

                // Resolve effective featured image ID and URL consistently under the lock
                if ($uploadedFeaturedImage !== null) {
                    $updates['featured_image_id'] = $uploadedFeaturedImage->id;
                    $updates['featured_image_url'] = $uploadedFeaturedImage->storage_key;
                } elseif (array_key_exists('featured_image_id', $updates)) {
                    if ($updates['featured_image_id'] !== null && $updates['featured_image_id'] !== '') {
                        $updates['featured_image_id'] = (int) $updates['featured_image_id'];
                    } else {
                        $updates['featured_image_id'] = null;
                        $updates['featured_image_url'] = null;
                    }
                }

                $effectiveFeaturedId = array_key_exists('featured_image_id', $updates)
                    ? $updates['featured_image_id']
                    : $locked->featured_image_id;

                $allTargetImageIds = array_values(array_unique(array_filter(array_merge(
                    $newEditorImageIds,
                    $effectiveFeaturedId !== null ? [(int) $effectiveFeaturedId] : []
                ))));

                // Lock and verify all target BlogImage records (existence, deleting_at === null, ownership)
                $this->imageService->lockAndVerifyImagesForAssociation(
                    $allTargetImageIds,
                    $user,
                    $locked->id,
                    $effectiveFeaturedId !== null ? (int) $effectiveFeaturedId : null
                );

                if ($effectiveFeaturedId !== null) {
                    $featuredImg = BlogImage::find((int) $effectiveFeaturedId);
                    $updates['featured_image_id'] = $featuredImg?->id;
                    $updates['featured_image_url'] = $featuredImg?->storage_key;
                } elseif (array_key_exists('featured_image_id', $updates)) {
                    $updates['featured_image_id'] = null;
                    $updates['featured_image_url'] = null;
                }

                // Re-verify featured image presence when targetStatus is PUBLISHED
                $hasStoredLegacyUrl = ! array_key_exists('featured_image_id', $updates)
                    && $locked->featured_image_id === null
                    && ! empty($locked->featured_image_url);

                if ($targetStatus === Blog::STATUS_PUBLISHED && empty($effectiveFeaturedId) && ! $hasStoredLegacyUrl) {
                    throw ValidationException::withMessages([
                        'featured_image' => ['A featured image is required for a published blog article.'],
                    ]);
                }

                // Resolve slug using the locked record
                $titleEnProvided = array_key_exists('title_en', $updates);
                $effectiveTitleEn = $titleEnProvided ? $updates['title_en'] : $locked->title_en;

                if ($targetStatus === Blog::STATUS_PUBLISHED && $locked->slug === null) {
                    $slug = $this->slugService->generateSlug($effectiveTitleEn, $locked->id);
                    if ($slug === null) {
                        throw ValidationException::withMessages([
                            'title_en' => ['The English title must contain valid Latin alphanumeric characters to generate a slug.'],
                        ]);
                    }
                    $updates['slug'] = $slug;
                } elseif ($titleEnProvided) {
                    $updates['slug'] = $this->slugService->resolveSlugForUpdate($locked, $effectiveTitleEn, true);
                }

                $updates['status'] = $targetStatus;

                // Preserve original published_at timestamp; set only on first publication
                if ($targetStatus === Blog::STATUS_PUBLISHED && $locked->published_at === null) {
                    $updates['published_at'] = now();
                }

                $locked->update($updates);

                $this->imageService->syncArticleImages(
                    $locked->id,
                    $locked->featured_image_id,
                    $newEditorImageIds
                );

                $currentImageIds = array_values(array_unique(array_filter(array_merge(
                    $newEditorImageIds,
                    $locked->featured_image_id ? [(int) $locked->featured_image_id] : []
                ))));

                $detachedImageIds = array_values(array_diff($previousImageIds, $currentImageIds));
            });
        } catch (\Throwable $e) {
            if ($uploadedFeaturedImage !== null) {
                $this->imageService->deleteImageIfUnreferenced($uploadedFeaturedImage->id);
            }

            throw $e;
        }

        // Clean up detached images after transaction commit without excluding this article from reference checks
        $this->cleanupDetachedImages($detachedImageIds);

        $fresh = $blog->fresh(['category.parent', 'featuredImage', 'editorImages']);

        return response()->json([
            'data' => new BlogAdminResource($fresh),
        ], 200);
    }

    /**
     * Publish or republish a blog article.
     *
     * Resolves omitted fields from the row-locked database record, sets `published_at`
     * on first successful publication only, and preserves the original `published_at`
     * timestamp on subsequent edits or republishing.
     */
    public function publish(PublishBlogRequest $request, Blog $blog): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $contentArProvided = array_key_exists('content_ar', $validated);
        $contentEnProvided = array_key_exists('content_en', $validated);

        // 1. Pre-validate and sanitize any explicitly provided HTML fields before uploading files
        $preSanitizedAr = $contentArProvided
            ? $this->htmlSanitizer->sanitizeAndExtractImages($validated['content_ar'], 'content_ar', $user, $blog->id)
            : null;
        $preSanitizedEn = $contentEnProvided
            ? $this->htmlSanitizer->sanitizeAndExtractImages($validated['content_en'], 'content_en', $user, $blog->id)
            : null;

        if ($preSanitizedAr !== null && ! $this->htmlSanitizer->hasMeaningfulContent($preSanitizedAr['html'])) {
            throw ValidationException::withMessages([
                'content_ar' => ['The Arabic content cannot be empty after sanitization for publication.'],
            ]);
        }
        if ($preSanitizedEn !== null && ! $this->htmlSanitizer->hasMeaningfulContent($preSanitizedEn['html'])) {
            throw ValidationException::withMessages([
                'content_en' => ['The English content cannot be empty after sanitization for publication.'],
            ]);
        }

        // 2. Handle direct featured_image file upload outside the DB transaction
        $uploadedFeaturedImage = null;
        if ($request->hasFile('featured_image')) {
            try {
                $uploadedFeaturedImage = $this->imageService->uploadAndCreate(
                    $request->file('featured_image'),
                    BlogImageService::COVERS_DIRECTORY,
                    $user?->id
                );
            } catch (InvalidImageException $e) {
                throw ValidationException::withMessages([
                    'featured_image' => [$e->getMessage()],
                ]);
            }
        }

        unset($validated['featured_image']);

        $detachedImageIds = [];

        try {
            DB::transaction(function () use (
                $blog,
                $validated,
                $user,
                $preSanitizedAr,
                $preSanitizedEn,
                $uploadedFeaturedImage,
                &$detachedImageIds
            ) {
                $locked = Blog::where('id', $blog->id)->lockForUpdate()->first();
                if (! $locked) {
                    throw (new ModelNotFoundException)->setModel(Blog::class, [$blog->id]);
                }

                $previousPivotIds = DB::table('blog_article_images')
                    ->where('blog_id', $locked->id)
                    ->pluck('blog_image_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $previousImageIds = array_values(array_unique(array_filter(array_merge(
                    $previousPivotIds,
                    $locked->featured_image_id ? [(int) $locked->featured_image_id] : []
                ))));

                // Resolve omitted content_ar and content_en from the latest locked database record
                $sanitizedAr = $preSanitizedAr
                    ?? $this->htmlSanitizer->sanitizeAndExtractImages($locked->content_ar, 'content_ar', $user, $locked->id);
                $sanitizedEn = $preSanitizedEn
                    ?? $this->htmlSanitizer->sanitizeAndExtractImages($locked->content_en, 'content_en', $user, $locked->id);

                if (! $this->htmlSanitizer->hasMeaningfulContent($sanitizedAr['html'])) {
                    throw ValidationException::withMessages([
                        'content_ar' => ['The Arabic content cannot be empty after sanitization for publication.'],
                    ]);
                }
                if (! $this->htmlSanitizer->hasMeaningfulContent($sanitizedEn['html'])) {
                    throw ValidationException::withMessages([
                        'content_en' => ['The English content cannot be empty after sanitization for publication.'],
                    ]);
                }

                $updates = $validated;
                $updates['content_ar'] = $sanitizedAr['html'];
                $updates['content_en'] = $sanitizedEn['html'];

                // Recalculate editor image IDs from effective article content while holding the row lock
                $newEditorImageIds = array_values(array_unique(array_merge($sanitizedAr['image_ids'], $sanitizedEn['image_ids'])));

                $featuredIdProvided = array_key_exists('featured_image_id', $updates);
                if ($uploadedFeaturedImage !== null) {
                    $updates['featured_image_id'] = $uploadedFeaturedImage->id;
                    $updates['featured_image_url'] = $uploadedFeaturedImage->storage_key;
                } elseif ($featuredIdProvided) {
                    if ($updates['featured_image_id'] !== null && $updates['featured_image_id'] !== '') {
                        $updates['featured_image_id'] = (int) $updates['featured_image_id'];
                    } else {
                        throw ValidationException::withMessages([
                            'featured_image' => ['A featured image is required for publication.'],
                        ]);
                    }
                }

                $effectiveFeaturedId = array_key_exists('featured_image_id', $updates)
                    ? $updates['featured_image_id']
                    : $locked->featured_image_id;

                $allTargetImageIds = array_values(array_unique(array_filter(array_merge(
                    $newEditorImageIds,
                    $effectiveFeaturedId !== null ? [(int) $effectiveFeaturedId] : []
                ))));

                $this->imageService->lockAndVerifyImagesForAssociation(
                    $allTargetImageIds,
                    $user,
                    $locked->id,
                    $effectiveFeaturedId !== null ? (int) $effectiveFeaturedId : null
                );

                if ($effectiveFeaturedId !== null) {
                    $featuredImg = BlogImage::find((int) $effectiveFeaturedId);
                    $updates['featured_image_id'] = $featuredImg?->id;
                    $updates['featured_image_url'] = $featuredImg?->storage_key;
                }

                $hasStoredLegacyUrl = ! $featuredIdProvided
                    && $locked->featured_image_id === null
                    && ! empty($locked->featured_image_url);

                if (empty($updates['featured_image_id'] ?? $locked->featured_image_id) && ! $hasStoredLegacyUrl) {
                    throw ValidationException::withMessages([
                        'featured_image' => ['A featured image is required for publication.'],
                    ]);
                }

                $effectiveTitleEn = array_key_exists('title_en', $updates) ? $updates['title_en'] : $locked->title_en;

                if ($locked->slug === null) {
                    $slug = $this->slugService->generateSlug($effectiveTitleEn, $locked->id);
                    if ($slug === null) {
                        throw ValidationException::withMessages([
                            'title_en' => ['The English title must contain valid Latin alphanumeric characters to generate a slug for publication.'],
                        ]);
                    }
                    $updates['slug'] = $slug;
                } elseif (array_key_exists('title_en', $updates)) {
                    $updates['slug'] = $this->slugService->resolveSlugForUpdate($locked, $effectiveTitleEn, true);
                }

                $updates['status'] = Blog::STATUS_PUBLISHED;

                if ($locked->published_at === null) {
                    $updates['published_at'] = now();
                }

                $locked->update($updates);

                $this->imageService->syncArticleImages(
                    $locked->id,
                    $locked->featured_image_id,
                    $newEditorImageIds
                );

                $currentImageIds = array_values(array_unique(array_filter(array_merge(
                    $newEditorImageIds,
                    $locked->featured_image_id ? [(int) $locked->featured_image_id] : []
                ))));

                $detachedImageIds = array_values(array_diff($previousImageIds, $currentImageIds));
            });
        } catch (\Throwable $e) {
            if ($uploadedFeaturedImage !== null) {
                $this->imageService->deleteImageIfUnreferenced($uploadedFeaturedImage->id);
            }

            throw $e;
        }

        // Clean up detached images after transaction commit without excluding this article from reference checks
        $this->cleanupDetachedImages($detachedImageIds);

        $fresh = $blog->fresh(['category.parent', 'featuredImage', 'editorImages']);

        return response()->json([
            'data' => new BlogAdminResource($fresh),
        ], 200);
    }

    /**
     * Unpublish a published blog article back to draft status.
     *
     * Preserves the original `published_at` timestamp and immutable `slug`.
     */
    public function unpublish(Blog $blog): JsonResponse
    {
        DB::transaction(function () use ($blog) {
            $locked = Blog::where('id', $blog->id)->lockForUpdate()->first();
            if (! $locked) {
                throw (new ModelNotFoundException)->setModel(Blog::class, [$blog->id]);
            }

            $locked->update([
                'status' => Blog::STATUS_DRAFT,
            ]);
        });

        $fresh = $blog->fresh(['category.parent', 'featuredImage', 'editorImages']);

        return response()->json([
            'message' => 'Blog article converted to draft successfully.',
            'data' => new BlogAdminResource($fresh),
        ], 200);
    }

    /**
     * Permanently delete a blog article (Super Admin and Admin only).
     */
    public function destroy(Request $request, Blog $blog): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isAnyAdmin()) {
            return response()->json([
                'message' => 'Forbidden. Only administrators can permanently delete blog articles.',
            ], 403);
        }

        $blogId = $blog->id;
        $attachedImageIds = [];
        $legacyFeaturedKey = null;

        DB::transaction(function () use ($blogId, &$attachedImageIds, &$legacyFeaturedKey) {
            $locked = Blog::where('id', $blogId)->lockForUpdate()->first();
            if (! $locked) {
                throw (new ModelNotFoundException)->setModel(Blog::class, [$blogId]);
            }

            $pivotIds = DB::table('blog_article_images')
                ->where('blog_id', $locked->id)
                ->pluck('blog_image_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $attachedImageIds = array_values(array_unique(array_filter(array_merge(
                $pivotIds,
                $locked->featured_image_id ? [(int) $locked->featured_image_id] : []
            ))));

            if ($locked->featured_image_id === null && $locked->featured_image_url !== null) {
                $legacyFeaturedKey = $locked->featured_image_url;
            }

            $locked->delete();
        });

        $this->cleanupDetachedImages($attachedImageIds);

        if ($legacyFeaturedKey !== null && $this->imageService->isR2Key($legacyFeaturedKey) && ! $this->imageService->isKeyReferenced($legacyFeaturedKey)) {
            $this->imageService->safeDeleteKey($legacyFeaturedKey);
        }

        return response()->json([
            'message' => 'Blog article permanently deleted successfully.',
        ], 200);
    }

    /**
     * Apply category filter by ID, slug, or name (supporting parent category expansion).
     */
    private function applyCategoryFilter($query, mixed $categoryId, mixed $categoryParam): void
    {
        if ($categoryId !== null && $categoryId !== '') {
            $cat = Category::where('type', 'blogs')->find((int) $categoryId);
            if ($cat) {
                $ids = $this->resolveCategoryFilterIds($cat);
                $query->whereIn('category_id', $ids);
            } else {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        if ($categoryParam !== null && trim((string) $categoryParam) !== '') {
            $trimmed = trim((string) $categoryParam);

            $cat = Category::where('type', 'blogs')
                ->where(function ($q) use ($trimmed) {
                    if (ctype_digit($trimmed)) {
                        $q->where('id', (int) $trimmed)->orWhere('slug', $trimmed);
                    } else {
                        $q->where('slug', $trimmed)
                            ->orWhere('name_en', $trimmed)
                            ->orWhere('name_ar', $trimmed);
                    }
                })
                ->first();

            if ($cat) {
                $ids = $this->resolveCategoryFilterIds($cat);
                $query->whereIn('category_id', $ids);
            } else {
                $query->whereRaw('1 = 0');
            }
        }
    }

    /**
     * Resolve category IDs for filtering (if a parent category is selected, include all its child subcategory IDs).
     *
     * @return list<int>
     */
    private function resolveCategoryFilterIds(Category $category): array
    {
        $childIds = $category->children()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique(array_merge([(int) $category->id], $childIds)));
    }

    /**
     * Safely clean up detached image IDs after the database transaction has committed.
     *
     * Never excludes any article from reference checks (`ignoreBlogId` is null), ensuring that
     * if any article (including the article just updated) still references the image ID or key,
     * it will not be deleted.
     *
     * @param  list<int>  $imageIds
     */
    private function cleanupDetachedImages(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }

        $failedIds = [];

        foreach ($imageIds as $imageId) {
            if ($this->imageService->isImageReferenced($imageId)) {
                continue;
            }

            $deleted = $this->imageService->deleteImageIfUnreferenced($imageId);
            if (! $deleted && BlogImage::where('id', $imageId)->exists() && ! $this->imageService->isImageReferenced($imageId)) {
                $failedIds[] = $imageId;
            }
        }

        if ($failedIds !== []) {
            try {
                DeleteUnreferencedBlogImagesJob::dispatch($failedIds);
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch retryable cleanup job for unreferenced blog images.', [
                    'image_ids' => $failedIds,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
