<?php

namespace App\Services\Events;

use App\Services\Storage\ImageStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EventCoverImageService
{
    public const DIRECTORY = 'events/covers/';

    public const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    /**
     * Pattern locating an event cover key inside a stored value (a bare key or a URL that ends with one).
     */
    public const KEY_PATTERN = '#events/covers/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(?:jpg|jpeg|png|webp)#i';

    public function __construct(
        protected ImageStorageService $storageService
    ) {}

    /**
     * Determine if a value is an R2 relative storage key for events.
     *
     * Strictly requires the key to start with 'events/covers/' and satisfy the storage key format.
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
     * Resolve a stored cover_image_url value to a full public delivery URL.
     *
     * - Returns null for null/empty values.
     * - Returns full HTTP(S) URLs unchanged (legacy records only; the API no longer accepts URLs as input).
     * - Resolves relative R2 storage keys belonging to events/covers/ using ImageStorageService / filesystem config.
     * - Does not resolve relative keys belonging to other modules.
     */
    public function resolveUrl(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);

        if (Str::startsWith($trimmed, ['http://', 'https://'])) {
            return $trimmed;
        }

        if (! $this->isR2Key($trimmed)) {
            // Not an absolute HTTP URL and not a valid R2 key: do not leak raw internal keys
            return null;
        }

        try {
            return $this->storageService->getUrl($trimmed);
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                "Failed to resolve public delivery URL for event cover image key '%s': %s",
                $trimmed,
                $e->getMessage()
            ));

            return null;
        }
    }

    /**
     * Upload an uploaded file to Cloudflare R2 and return the relative object key.
     *
     * @throws \Throwable
     */
    public function upload(UploadedFile $file): string
    {
        $stored = $this->storageService->upload($file, self::DIRECTORY, self::MAX_SIZE_BYTES);

        return $stored->key;
    }

    /**
     * Determine whether any event row still references the key, either as a bare key or inside a
     * legacy URL value that ends with it. Optionally ignores one event.
     */
    public function isReferenced(string $key, ?int $ignoreEventId = null): bool
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $key);

        $query = DB::table('event_calendar')->where(function ($q) use ($key, $escaped) {
            $q->where('cover_image_url', $key)
                ->orWhere('cover_image_url', 'LIKE', '%/'.$escaped);
        });

        if ($ignoreEventId !== null) {
            $query->where('id', '!=', $ignoreEventId);
        }

        return $query->exists();
    }

    /**
     * Safely attempt to delete an unshared R2 object key.
     *
     * Will NOT delete:
     * - null or empty keys
     * - absolute external URLs
     * - keys currently referenced by other events
     */
    public function deleteIfUnshared(?string $key, ?int $ignoreEventId = null): bool
    {
        if (! $this->isR2Key($key)) {
            return false;
        }

        if ($this->isReferenced($key, $ignoreEventId)) {
            Log::info(sprintf(
                "Skipping deletion of event cover image '%s' because it is referenced by another event record.",
                $key
            ));

            return false;
        }

        return $this->storageService->safeDelete($key);
    }

    /**
     * List every object key directly under the event cover prefix (used by orphan cleanup).
     *
     * @return list<string>
     */
    public function allCoverKeys(): array
    {
        return Storage::disk('r2')->files(self::DIRECTORY);
    }

    /**
     * Get the last modified timestamp of a stored cover object.
     */
    public function lastModified(string $key): int
    {
        return Storage::disk('r2')->lastModified($key);
    }

    /**
     * Delete an exact cover key during orphan cleanup. Logs failures instead of throwing.
     * Reference checks are the caller's responsibility.
     */
    public function safeDeleteKey(string $key): bool
    {
        return $this->isR2Key($key) && $this->storageService->safeDelete($key);
    }
}
