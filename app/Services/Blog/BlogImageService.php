<?php

namespace App\Services\Blog;

use App\Models\BlogImage;
use App\Models\User;
use App\Services\Storage\ImageStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BlogImageService
{
    public const COVERS_DIRECTORY = 'blogs/covers/';

    public const EDITOR_DIRECTORY = 'blogs/editor/';

    public const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    public const KEY_PATTERN = '#blogs/(?:covers|editor)/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(?:jpg|jpeg|png|webp)#i';

    public function __construct(
        protected ImageStorageService $storageService
    ) {}

    /**
     * Determine if a value is an R2 relative storage key for the Blogs module.
     */
    public function isR2Key(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        $trimmed = trim($value);

        if (Str::startsWith($trimmed, ['http://', 'https://'])) {
            return false;
        }

        if (! Str::startsWith($trimmed, [self::COVERS_DIRECTORY, self::EDITOR_DIRECTORY])) {
            return false;
        }

        try {
            return $this->storageService->validateStorageKey($trimmed);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve a BlogImage model or relative storage key to a public HTTPS delivery URL.
     */
    public function resolveUrl(BlogImage|string|null $imageOrKey): ?string
    {
        if ($imageOrKey instanceof BlogImage) {
            $key = $imageOrKey->storage_key;
        } else {
            $key = $imageOrKey;
        }

        if ($key === null || trim($key) === '') {
            return null;
        }

        $trimmed = trim($key);

        if (Str::startsWith($trimmed, ['http://', 'https://'])) {
            return $trimmed;
        }

        if (! $this->isR2Key($trimmed)) {
            return null;
        }

        try {
            return $this->storageService->getUrl($trimmed);
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                "Failed to resolve public delivery URL for blog image key '%s': %s",
                $trimmed,
                $e->getMessage()
            ));

            return null;
        }
    }

    /**
     * Extract a valid blog R2 storage key from a public delivery URL or relative key.
     */
    public function extractStorageKeyFromUrl(?string $urlOrKey): ?string
    {
        if ($urlOrKey === null || trim($urlOrKey) === '') {
            return null;
        }

        $trimmed = trim($urlOrKey);

        if ($this->isR2Key($trimmed)) {
            return $trimmed;
        }

        if (preg_match(self::KEY_PATTERN, $trimmed, $matches)) {
            $candidate = $matches[0];

            // Verify that if it is a full URL, its prefix matches the configured R2 public URL
            if (Str::startsWith($trimmed, ['http://', 'https://'])) {
                try {
                    $expectedUrl = $this->storageService->getUrl($candidate);
                    if (strcasecmp($trimmed, $expectedUrl) === 0) {
                        return $candidate;
                    }
                } catch (\Throwable) {
                    return null;
                }

                return null;
            }

            return $this->isR2Key($candidate) ? $candidate : null;
        }

        return null;
    }

    /**
     * Upload a blog image to Cloudflare R2 and persist its metadata in `blog_images`.
     *
     * If database persistence fails after the R2 upload, compensating storage deletion
     * removes the newly uploaded R2 object immediately so no orphan is left behind.
     *
     * @throws \Throwable
     */
    public function uploadAndCreate(
        UploadedFile $file,
        string $directory = self::EDITOR_DIRECTORY,
        ?int $uploadedBy = null
    ): BlogImage {
        $prefix = in_array($directory, [self::COVERS_DIRECTORY, self::EDITOR_DIRECTORY], true)
            ? $directory
            : self::EDITOR_DIRECTORY;

        $stored = $this->storageService->upload($file, $prefix, self::MAX_SIZE_BYTES);

        try {
            return DB::transaction(function () use ($stored, $uploadedBy) {
                return BlogImage::create([
                    'disk' => 'r2',
                    'storage_key' => $stored->key,
                    'mime_type' => $stored->mimeType,
                    'size_bytes' => $stored->size,
                    'sha256' => $stored->sha256,
                    'uploaded_by' => $uploadedBy,
                    'deleting_at' => null,
                ]);
            });
        } catch (\Throwable $e) {
            $this->storageService->safeDelete($stored->key);

            throw $e;
        }
    }

    /**
     * Determine if a specific `BlogImage` is currently attached to the given blog ID.
     */
    public function isImageAttachedToArticle(int $imageId, ?string $storageKey, int $blogId): bool
    {
        if (DB::table('blogs')->where('id', $blogId)->where('featured_image_id', $imageId)->exists()) {
            return true;
        }

        if (DB::table('blog_article_images')->where('blog_id', $blogId)->where('blog_image_id', $imageId)->exists()) {
            return true;
        }

        if ($storageKey !== null && trim($storageKey) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $storageKey);
            $matched = DB::table('blogs')
                ->where('id', $blogId)
                ->where(function ($q) use ($storageKey, $escaped) {
                    $q->where('featured_image_url', $storageKey)
                        ->orWhere('featured_image_url', 'LIKE', '%/'.$escaped)
                        ->orWhere('content_ar', 'LIKE', '%'.$escaped.'%')
                        ->orWhere('content_en', 'LIKE', '%'.$escaped.'%');
                })
                ->exists();

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * Explicit authorization policy for associating a `BlogImage` with an article:
     *
     * 1. An image marked `deleting_at !== null` (in-flight deletion) cannot be associated.
     * 2. An image already attached to the same article (`$currentBlogId`) is allowed for any authorized CMS user.
     * 3. An image already attached to / shared by another article is allowed for any authorized CMS user.
     * 4. An unattached image uploaded by the current user (or system-seeded with `uploaded_by === null`) is allowed.
     * 5. Super Admin and Admin users (`isAnyAdmin()`) may associate any valid unattached image.
     * 6. Editor users (`isEditor()`) are prohibited from associating an unattached image uploaded by another user.
     */
    public function canUserAssociateImage(BlogImage $image, ?User $user, ?int $currentBlogId = null): bool
    {
        if ($image->deleting_at !== null) {
            return false;
        }

        if ($user === null) {
            return $image->uploaded_by === null
                || ($currentBlogId !== null && $this->isImageAttachedToArticle($image->id, $image->storage_key, $currentBlogId))
                || $this->isImageReferenced($image->id, $image->storage_key);
        }

        if (! in_array($user->role, ['super_admin', 'admin', 'editor'], true)) {
            return false;
        }

        // 1. Already attached to the same article being edited/published
        if ($currentBlogId !== null && $this->isImageAttachedToArticle($image->id, $image->storage_key, $currentBlogId)) {
            return true;
        }

        // 2. Shared image already referenced by an existing article in the blogs library
        if ($this->isImageReferenced($image->id, $image->storage_key)) {
            return true;
        }

        // 3. Unattached image uploaded by the current user (or system-seeded without uploader)
        if ($image->uploaded_by === null || (int) $image->uploaded_by === (int) $user->id) {
            return true;
        }

        // 4. Super Admin and Admin have administrative access across all uploads
        if ($user->isAnyAdmin()) {
            return true;
        }

        // 5. Editors cannot claim/attach an unattached image uploaded by another user
        return false;
    }

    /**
     * Assert that the given user is allowed to associate the image, throwing a 422 ValidationException otherwise.
     *
     * @throws ValidationException
     */
    public function assertUserCanAssociateImage(
        BlogImage $image,
        ?User $user,
        ?int $currentBlogId = null,
        string $fieldName = 'featured_image_id'
    ): void {
        if ($image->deleting_at !== null) {
            throw ValidationException::withMessages([
                $fieldName => ["The selected image (ID {$image->id}) is currently being deleted and cannot be associated."],
            ]);
        }

        if (! $this->canUserAssociateImage($image, $user, $currentBlogId)) {
            throw ValidationException::withMessages([
                $fieldName => ['You are not authorized to associate an unattached image uploaded by another user.'],
            ]);
        }
    }

    /**
     * Lock target `BlogImage` rows in ascending ID order inside a write transaction and verify
     * that every image exists, is not marked `deleting_at`, and is authorized for the user.
     *
     * @param  list<int>  $imageIds
     *
     * @throws ValidationException
     */
    public function lockAndVerifyImagesForAssociation(
        array $imageIds,
        ?User $user,
        ?int $currentBlogId = null,
        ?int $featuredImageId = null
    ): void {
        $uniqueIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), fn (int $id) => $id > 0)));
        if ($uniqueIds === []) {
            return;
        }

        sort($uniqueIds);

        $lockedImages = BlogImage::whereIn('id', $uniqueIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($uniqueIds as $id) {
            $fieldName = ($featuredImageId !== null && $id === $featuredImageId) ? 'featured_image_id' : 'content_en';
            /** @var BlogImage|null $img */
            $img = $lockedImages->get($id);

            if (! $img) {
                throw ValidationException::withMessages([
                    $fieldName => ["The selected image (ID {$id}) does not exist."],
                ]);
            }

            $this->assertUserCanAssociateImage($img, $user, $currentBlogId, $fieldName);
        }
    }

    /**
     * Synchronize the article's attached images in `blog_article_images`.
     *
     * @param  list<int>  $editorImageIds
     */
    public function syncArticleImages(int $blogId, ?int $featuredImageId, array $editorImageIds): void
    {
        $now = now();
        $rows = [];

        if ($featuredImageId !== null) {
            $rows[] = [
                'blog_id' => $blogId,
                'blog_image_id' => $featuredImageId,
                'role' => 'featured',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_values(array_unique(array_map('intval', $editorImageIds))) as $editorId) {
            if ($editorId > 0) {
                $rows[] = [
                    'blog_id' => $blogId,
                    'blog_image_id' => $editorId,
                    'role' => 'editor',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('blog_article_images')->where('blog_id', $blogId)->delete();

        if ($rows !== []) {
            DB::table('blog_article_images')->insert($rows);
        }
    }

    /**
     * Determine whether a `BlogImage` ID or storage key is referenced by any blog article
     * (either as `featured_image_id`, `featured_image_url`, or in `blog_article_images`,
     * or inside `content_ar` / `content_en`).
     */
    public function isImageReferenced(int $imageId, ?string $storageKey = null, ?int $ignoreBlogId = null): bool
    {
        // 1. Check blogs.featured_image_id
        $featuredQuery = DB::table('blogs')->where('featured_image_id', $imageId);
        if ($ignoreBlogId !== null) {
            $featuredQuery->where('id', '!=', $ignoreBlogId);
        }
        if ($featuredQuery->exists()) {
            return true;
        }

        // 2. Check blog_article_images pivot
        $pivotQuery = DB::table('blog_article_images')->where('blog_image_id', $imageId);
        if ($ignoreBlogId !== null) {
            $pivotQuery->where('blog_id', '!=', $ignoreBlogId);
        }
        if ($pivotQuery->exists()) {
            return true;
        }

        // 3. Check storage key references if provided
        if ($storageKey !== null && trim($storageKey) !== '') {
            if ($this->isKeyReferenced($storageKey, $ignoreBlogId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether an R2 storage key is referenced anywhere in `blogs` or `blog_article_images`.
     */
    public function isKeyReferenced(string $key, ?int $ignoreBlogId = null): bool
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $key);

        $blogsQuery = DB::table('blogs')->where(function ($q) use ($key, $escaped) {
            $q->where('featured_image_url', $key)
                ->orWhere('featured_image_url', 'LIKE', '%/'.$escaped)
                ->orWhere('content_ar', 'LIKE', '%'.$escaped.'%')
                ->orWhere('content_en', 'LIKE', '%'.$escaped.'%');
        });

        if ($ignoreBlogId !== null) {
            $blogsQuery->where('id', '!=', $ignoreBlogId);
        }

        if ($blogsQuery->exists()) {
            return true;
        }

        $imageIds = DB::table('blog_images')
            ->where('storage_key', $key)
            ->pluck('id')
            ->all();

        if ($imageIds !== []) {
            $featuredById = DB::table('blogs')->whereIn('featured_image_id', $imageIds);
            if ($ignoreBlogId !== null) {
                $featuredById->where('id', '!=', $ignoreBlogId);
            }
            if ($featuredById->exists()) {
                return true;
            }

            $pivotById = DB::table('blog_article_images')->whereIn('blog_image_id', $imageIds);
            if ($ignoreBlogId !== null) {
                $pivotById->where('blog_id', '!=', $ignoreBlogId);
            }
            if ($pivotById->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Safely attempt to delete an unreferenced `BlogImage` (and its underlying R2 object)
     * using a 3-phase staged deletion strategy that never holds a database transaction open
     * during external R2 calls:
     *
     * 1. Claim Phase (Short DB transaction with row lock): Lock the `BlogImage` row, recheck
     *    all references across `blogs` and `blog_article_images`, and mark `deleting_at = now()`.
     *    While `deleting_at` is set, no concurrent request may attach this image.
     * 2. External R2 Delete Phase (Outside DB transaction): Delete the object from Cloudflare R2.
     *    If R2 deletion fails transiently, reset `deleting_at = null` and retain the row for retry.
     * 3. Finalize Phase (Short DB transaction with row lock): Re-verify references and delete
     *    the `BlogImage` database record.
     */
    public function deleteImageIfUnreferenced(int $imageId, ?int $ignoreBlogId = null): bool
    {
        // Phase 1: Claim image for deletion under row lock without holding the transaction for R2 I/O
        $claimedKey = DB::transaction(function () use ($imageId, $ignoreBlogId): ?string {
            $locked = BlogImage::where('id', $imageId)->lockForUpdate()->first();
            if (! $locked) {
                return null;
            }

            if ($this->isImageReferenced($locked->id, $locked->storage_key, $ignoreBlogId)) {
                if ($locked->deleting_at !== null) {
                    $locked->update(['deleting_at' => null]);
                }

                Log::info(sprintf(
                    "Skipping deletion of blog image ID %d ('%s') because it is still referenced.",
                    $locked->id,
                    $locked->storage_key
                ));

                return null;
            }

            $locked->update(['deleting_at' => now()]);

            return $locked->storage_key;
        });

        if ($claimedKey === null) {
            return false;
        }

        // Phase 2: Perform external R2 deletion outside the database transaction
        if ($this->isR2Key($claimedKey)) {
            $deletedFromR2 = false;
            try {
                $deletedFromR2 = $this->storageService->safeDelete($claimedKey);
            } catch (\Throwable $e) {
                Log::warning(sprintf(
                    "Exception during R2 deletion for blog image ID %d ('%s'): %s",
                    $imageId,
                    $claimedKey,
                    $e->getMessage()
                ));
            }

            if (! $deletedFromR2) {
                BlogImage::where('id', $imageId)->update(['deleting_at' => null]);

                Log::warning(sprintf(
                    "R2 deletion failed for blog image ID %d ('%s'). Retaining metadata record for retry.",
                    $imageId,
                    $claimedKey
                ));

                return false;
            }
        }

        // Phase 3: Finalize database row deletion under row lock
        return (bool) DB::transaction(function () use ($imageId, $claimedKey, $ignoreBlogId): bool {
            $locked = BlogImage::where('id', $imageId)->lockForUpdate()->first();
            if (! $locked) {
                return true;
            }

            if ($this->isImageReferenced($locked->id, $claimedKey, $ignoreBlogId)) {
                $locked->update(['deleting_at' => null]);

                return false;
            }

            $locked->delete();

            return true;
        });
    }

    /**
     * List all R2 keys under both `blogs/covers/` and `blogs/editor/`.
     *
     * @return list<string>
     */
    public function allBlogKeys(): array
    {
        $covers = Storage::disk('r2')->files(self::COVERS_DIRECTORY);
        $editor = Storage::disk('r2')->files(self::EDITOR_DIRECTORY);

        return array_values(array_unique(array_merge($covers, $editor)));
    }

    /**
     * Get the last modified timestamp of a stored blog object in R2.
     */
    public function lastModified(string $key): int
    {
        return Storage::disk('r2')->lastModified($key);
    }

    /**
     * Delete an exact blog R2 key during orphan cleanup. Logs failures instead of throwing.
     */
    public function safeDeleteKey(string $key): bool
    {
        return $this->isR2Key($key) && $this->storageService->safeDelete($key);
    }
}
