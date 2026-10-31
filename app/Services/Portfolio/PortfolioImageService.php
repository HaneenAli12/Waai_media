<?php

namespace App\Services\Portfolio;

use App\Services\Storage\Exceptions\InvalidImageException;
use App\Services\Storage\ImageStorageService;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PortfolioImageService
{
    public const DIRECTORY = 'portfolio/images/';

    public const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    /**
     * Portfolio images are PNG or JPEG only (the shared storage service additionally knows WebP).
     */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];

    public function __construct(
        protected ImageStorageService $storageService
    ) {}

    /**
     * Determine if a value is an R2 relative storage key for portfolio images.
     */
    public function isR2Key(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        $trimmed = trim($value);

        if (! Str::startsWith($trimmed, self::DIRECTORY)) {
            return false;
        }

        try {
            return $this->storageService->validateStorageKey($trimmed);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve a stored image key to its public delivery URL (null when absent or unresolvable).
     */
    public function resolveUrl(?string $value): ?string
    {
        if (! $this->isR2Key($value)) {
            return null;
        }

        try {
            return $this->storageService->getUrl(trim($value));
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                "Failed to resolve public delivery URL for portfolio image key '%s': %s",
                $value,
                $e->getMessage()
            ));

            return null;
        }
    }

    /**
     * Upload a PNG/JPEG image to Cloudflare R2 and return the generated relative object key.
     * The client filename is never used; the key is a server-side UUID.
     *
     * @throws InvalidImageException when the content is not a real PNG/JPEG
     * @throws \Throwable on storage failures
     */
    public function upload(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $mime = $path !== false ? (new finfo(FILEINFO_MIME_TYPE))->file($path) : false;

        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidImageException(sprintf(
                "Unsupported or disguised image format with MIME type '%s'. Allowed formats: PNG, JPG.",
                $mime ?: 'unknown'
            ));
        }

        return $this->storageService->upload($file, self::DIRECTORY, self::MAX_SIZE_BYTES)->key;
    }

    /**
     * Safely delete an image key unless another media row (outside $ignoreMediaIds) still references it.
     * Storage failures are logged, never thrown.
     *
     * @param  list<int>  $ignoreMediaIds
     */
    public function deleteIfUnshared(?string $key, array $ignoreMediaIds = []): bool
    {
        if (! $this->isR2Key($key)) {
            return false;
        }

        $query = DB::table('portfolio_media')->where('image_path', $key);
        if ($ignoreMediaIds !== []) {
            $query->whereNotIn('id', $ignoreMediaIds);
        }

        // A key is also referenced when it is some item's dedicated cover
        if ($query->exists() || DB::table('portfolio_items')->where('cover_image_path', $key)->exists()) {
            Log::info(sprintf(
                "Skipping deletion of portfolio image '%s' because another media entry references it.",
                $key
            ));

            return false;
        }

        return $this->storageService->safeDelete($key);
    }

    /**
     * List every object key directly under the portfolio image prefix (used by orphan cleanup).
     *
     * @return list<string>
     */
    public function allImageKeys(): array
    {
        return Storage::disk('r2')->files(self::DIRECTORY);
    }

    /**
     * Get the last modified timestamp of a stored image object.
     */
    public function lastModified(string $key): int
    {
        return Storage::disk('r2')->lastModified($key);
    }

    /**
     * Delete an exact portfolio image key during orphan cleanup. Logs failures instead of throwing.
     * Reference checks are the caller's responsibility.
     */
    public function safeDeleteKey(string $key): bool
    {
        return $this->isR2Key($key) && $this->storageService->safeDelete($key);
    }
}
