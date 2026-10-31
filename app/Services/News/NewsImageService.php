<?php

namespace App\Services\News;

use App\Models\NewsImage;
use App\Models\User;
use App\Services\Storage\ImageStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NewsImageService
{
    public const COVERS_DIRECTORY = 'news/covers/';

    public const EDITOR_DIRECTORY = 'news/editor/';

    public const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    public const KEY_PATTERN = '#news/(?:covers|editor)/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(?:jpg|jpeg|png|webp)#i';

    public function __construct(
        protected ImageStorageService $storageService
    ) {}

    /**
     * Determine if a value is an R2 relative storage key for the News module.
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
     * Resolve a NewsImage model or relative storage key to a public HTTPS delivery URL.
     */
    public function resolveUrl(NewsImage|string|null $imageOrKey): ?string
    {
        if ($imageOrKey instanceof NewsImage) {
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
                "Failed to resolve public delivery URL for news image key '%s': %s",
                $trimmed,
                $e->getMessage()
            ));

            return null;
        }
    }

    /**
     * Extract a valid news R2 storage key from a public delivery URL or relative key.
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
     * Upload a news image to Cloudflare R2 and persist its metadata in `news_images`.
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
    ): NewsImage {
        $prefix = in_array($directory, [self::COVERS_DIRECTORY, self::EDITOR_DIRECTORY], true)
            ? $directory
            : self::EDITOR_DIRECTORY;

        $stored = $this->storageService->upload($file, $prefix, self::MAX_SIZE_BYTES);

        try {
            return DB::transaction(function () use ($stored, $uploadedBy) {
                return NewsImage::create([
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
     * Determine if a specific `NewsImage` is currently attached to the given article ID.
     */
    public function isImageAttachedToArticle(int $imageId, ?string $storageKey, int $newsId): bool
    {
        if (DB::table('news')->where('id', $newsId)->where('featured_image_id', $imageId)->exists()) {
            return true;
        }

        if (DB::table('news_article_images')->where('news_id', $newsId)->where('news_image_id', $imageId)->exists()) {
            return true;
        }

        if ($storageKey !== null && trim($storageKey) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $storageKey);
            $matched = DB::table('news')
                ->where('id', $newsId)
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
     * Explicit authorization policy for associating a `NewsImage` with an article:
     *
     * 1. An image marked `deleting_at !== null` (in-flight deletion) cannot be associated.
     * 2. An image already attached to the same article (`$currentNewsId`) is allowed for any authorized CMS user.
     * 3. An image already attached to / shared by another article is allowed for any authorized CMS user.
     * 4. An unattached image uploaded by the current user (or system-seeded with `uploaded_by === null`) is allowed.
     * 5. Super Admin and Admin users (`isAnyAdmin()`) may associate any valid unattached image.
     * 6. Editor users (`isEditor()`) are prohibited from associating an unattached image uploaded by another user.
     */
    public function canUserAssociateImage(NewsImage $image, ?User $user, ?int $currentNewsId = null): bool
    {
        if ($image->deleting_at !== null) {
            return false;
        }

        if ($user === null) {
            return $image->uploaded_by === null
                || ($currentNewsId !== null && $this->isImageAttachedToArticle($image->id, $image->storage_key, $currentNewsId))
                || $this->isImageReferenced($image->id, $image->storage_key);
        }

        if (! in_array($user->role, ['super_admin', 'admin', 'editor'], true)) {
            return false;
        }

        // 1. Already attached to the same article being edited/published
        if ($currentNewsId !== null && $this->isImageAttachedToArticle($image->id, $image->storage_key, $currentNewsId)) {
            return true;
        }

        // 2. Shared image already referenced by an existing article in the news library
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
        NewsImage $image,
        ?User $user,
        ?int $currentNewsId = null,
        string $fieldName = 'featured_image_id'
    ): void {
        if ($image->deleting_at !== null) {
            throw ValidationException::withMessages([
                $fieldName => ["The selected image (ID {$image->id}) is currently being deleted and cannot be associated."],
            ]);
        }

        if (! $this->canUserAssociateImage($image, $user, $currentNewsId)) {
            throw ValidationException::withMessages([
                $fieldName => ['You are not authorized to associate an unattached image uploaded by another user.'],
            ]);
        }
    }

    /**
     * Lock target `NewsImage` rows in ascending ID order inside a write transaction and verify
     * that every image exists, is not marked `deleting_at`, and is authorized for the user.
     *
     * @param  list<int>  $imageIds
     *
     * @throws ValidationException
     */
    public function lockAndVerifyImagesForAssociation(
        array $imageIds,
        ?User $user,
        ?int $currentNewsId = null,
        ?int $featuredImageId = null
    ): void {
        $uniqueIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), fn (int $id) => $id > 0)));
        if ($uniqueIds === []) {
            return;
        }

        sort($uniqueIds);

        $lockedImages = NewsImage::whereIn('id', $uniqueIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($uniqueIds as $id) {
            $fieldName = ($featuredImageId !== null && $id === $featuredImageId) ? 'featured_image_id' : 'content_en';
            /** @var NewsImage|null $img */
            $img = $lockedImages->get($id);

            if (! $img) {
                throw ValidationException::withMessages([
                    $fieldName => ["The selected image (ID {$id}) does not exist."],
                ]);
            }

            $this->assertUserCanAssociateImage($img, $user, $currentNewsId, $fieldName);
        }
    }

    /**
     * Synchronize the article's attached images in `news_article_images`.
     *
     * @param  list<int>  $editorImageIds
     */
    public function syncArticleImages(int $newsId, ?int $featuredImageId, array $editorImageIds): void
    {
        $now = now();
        $rows = [];

        if ($featuredImageId !== null) {
            $rows[] = [
                'news_id' => $newsId,
                'news_image_id' => $featuredImageId,
                'role' => 'featured',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_values(array_unique(array_map('intval', $editorImageIds))) as $editorId) {
            if ($editorId > 0) {
                $rows[] = [
                    'news_id' => $newsId,
                    'news_image_id' => $editorId,
                    'role' => 'editor',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('news_article_images')->where('news_id', $newsId)->delete();

        if ($rows !== []) {
            DB::table('news_article_images')->insert($rows);
        }
    }

    /**
     * Determine whether a `NewsImage` ID or storage key is referenced by any news article
     * (either as `featured_image_id`, `featured_image_url`, or in `news_article_images`,
     * or inside `content_ar` / `content_en`).
     */
    public function isImageReferenced(int $imageId, ?string $storageKey = null, ?int $ignoreNewsId = null): bool
    {
        // 1. Check news.featured_image_id
        $featuredQuery = DB::table('news')->where('featured_image_id', $imageId);
        if ($ignoreNewsId !== null) {
            $featuredQuery->where('id', '!=', $ignoreNewsId);
        }
        if ($featuredQuery->exists()) {
            return true;
        }

        // 2. Check news_article_images pivot
        $pivotQuery = DB::table('news_article_images')->where('news_image_id', $imageId);
        if ($ignoreNewsId !== null) {
            $pivotQuery->where('news_id', '!=', $ignoreNewsId);
        }
        if ($pivotQuery->exists()) {
            return true;
        }

        // 3. Check storage key references if provided
        if ($storageKey !== null && trim($storageKey) !== '') {
            if ($this->isKeyReferenced($storageKey, $ignoreNewsId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether an R2 storage key is referenced anywhere in `news` or `news_article_images`.
     */
    public function isKeyReferenced(string $key, ?int $ignoreNewsId = null): bool
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $key);

        $newsQuery = DB::table('news')->where(function ($q) use ($key, $escaped) {
            $q->where('featured_image_url', $key)
                ->orWhere('featured_image_url', 'LIKE', '%/'.$escaped)
                ->orWhere('content_ar', 'LIKE', '%'.$escaped.'%')
                ->orWhere('content_en', 'LIKE', '%'.$escaped.'%');
        });

        if ($ignoreNewsId !== null) {
            $newsQuery->where('id', '!=', $ignoreNewsId);
        }

        if ($newsQuery->exists()) {
            return true;
        }

        $imageIds = DB::table('news_images')
            ->where('storage_key', $key)
            ->pluck('id')
            ->all();

        if ($imageIds !== []) {
            $featuredById = DB::table('news')->whereIn('featured_image_id', $imageIds);
            if ($ignoreNewsId !== null) {
                $featuredById->where('id', '!=', $ignoreNewsId);
            }
            if ($featuredById->exists()) {
                return true;
            }

            $pivotById = DB::table('news_article_images')->whereIn('news_image_id', $imageIds);
            if ($ignoreNewsId !== null) {
                $pivotById->where('news_id', '!=', $ignoreNewsId);
            }
            if ($pivotById->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Safely attempt to delete an unreferenced `NewsImage` (and its underlying R2 object)
     * using a 3-phase staged deletion strategy that never holds a database transaction open
     * during external R2 calls:
     *
     * 1. Claim Phase (Short DB transaction with row lock): Lock the `NewsImage` row, recheck
     *    all references across `news` and `news_article_images`, and mark `deleting_at = now()`.
     *    While `deleting_at` is set, no concurrent request may attach this image.
     * 2. External R2 Delete Phase (Outside DB transaction): Delete the object from Cloudflare R2.
     *    If R2 deletion fails transiently, reset `deleting_at = null` and retain the row for retry.
     * 3. Finalize Phase (Short DB transaction with row lock): Re-verify references and delete
     *    the `NewsImage` database record.
     */
    public function deleteImageIfUnreferenced(int $imageId, ?int $ignoreNewsId = null): bool
    {
        // Phase 1: Claim image for deletion under row lock without holding the transaction for R2 I/O
        $claimedKey = DB::transaction(function () use ($imageId, $ignoreNewsId): ?string {
            $locked = NewsImage::where('id', $imageId)->lockForUpdate()->first();
            if (! $locked) {
                return null;
            }

            if ($this->isImageReferenced($locked->id, $locked->storage_key, $ignoreNewsId)) {
                if ($locked->deleting_at !== null) {
                    $locked->update(['deleting_at' => null]);
                }

                Log::info(sprintf(
                    "Skipping deletion of news image ID %d ('%s') because it is still referenced.",
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
                    "Exception during R2 deletion for news image ID %d ('%s'): %s",
                    $imageId,
                    $claimedKey,
                    $e->getMessage()
                ));
            }

            if (! $deletedFromR2) {
                NewsImage::where('id', $imageId)->update(['deleting_at' => null]);

                Log::warning(sprintf(
                    "R2 deletion failed for news image ID %d ('%s'). Retaining metadata record for retry.",
                    $imageId,
                    $claimedKey
                ));

                return false;
            }
        }

        // Phase 3: Finalize database row deletion under row lock
        return (bool) DB::transaction(function () use ($imageId, $claimedKey, $ignoreNewsId): bool {
            $locked = NewsImage::where('id', $imageId)->lockForUpdate()->first();
            if (! $locked) {
                return true;
            }

            if ($this->isImageReferenced($locked->id, $claimedKey, $ignoreNewsId)) {
                $locked->update(['deleting_at' => null]);

                return false;
            }

            $locked->delete();

            return true;
        });
    }

    /**
     * List all R2 keys under both `news/covers/` and `news/editor/`.
     *
     * @return list<string>
     */
    public function allNewsKeys(): array
    {
        $covers = Storage::disk('r2')->files(self::COVERS_DIRECTORY);
        $editor = Storage::disk('r2')->files(self::EDITOR_DIRECTORY);

        return array_values(array_unique(array_merge($covers, $editor)));
    }

    /**
     * Get the last modified timestamp of a stored news object in R2.
     */
    public function lastModified(string $key): int
    {
        return Storage::disk('r2')->lastModified($key);
    }

    /**
     * Delete an exact news R2 key during orphan cleanup. Logs failures instead of throwing.
     */
    public function safeDeleteKey(string $key): bool
    {
        return $this->isR2Key($key) && $this->storageService->safeDelete($key);
    }
}
