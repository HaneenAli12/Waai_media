<?php

namespace App\Services\Clients;

use App\Services\Storage\ImageStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClientLogoService
{
    public const DIRECTORY = 'clients/logos/';

    public const MAX_SIZE_BYTES = ImageStorageService::DEFAULT_MAX_SIZE_BYTES; // 5 MB

    public function __construct(
        protected ImageStorageService $storageService
    ) {}

    /**
     * Determine if a value is an R2 relative storage key for client logos.
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
     * Resolve a stored logo key to its public delivery URL (null when absent or unresolvable).
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
                "Failed to resolve public delivery URL for client logo key '%s': %s",
                $value,
                $e->getMessage()
            ));

            return null;
        }
    }

    /**
     * Upload a logo to Cloudflare R2 and return the relative object key.
     *
     * @throws \Throwable
     */
    public function upload(UploadedFile $file): string
    {
        return $this->storageService->upload($file, self::DIRECTORY, self::MAX_SIZE_BYTES)->key;
    }

    /**
     * Safely delete a logo key unless another client still references it.
     * Storage failures are logged, never thrown.
     */
    public function deleteIfUnshared(?string $key, ?int $ignoreClientId = null): bool
    {
        if (! $this->isR2Key($key)) {
            return false;
        }

        $query = DB::table('clients')->where('logo_path', $key);
        if ($ignoreClientId !== null) {
            $query->where('id', '!=', $ignoreClientId);
        }

        if ($query->exists()) {
            Log::info(sprintf(
                "Skipping deletion of client logo '%s' because it is referenced by another client.",
                $key
            ));

            return false;
        }

        return $this->storageService->safeDelete($key);
    }

    /**
     * List every object key directly under the client logo prefix (used by orphan cleanup).
     *
     * @return list<string>
     */
    public function allLogoKeys(): array
    {
        return Storage::disk('r2')->files(self::DIRECTORY);
    }

    /**
     * Get the last modified timestamp of a stored logo object.
     */
    public function lastModified(string $key): int
    {
        return Storage::disk('r2')->lastModified($key);
    }

    /**
     * Delete an exact client logo key during orphan cleanup. Logs failures instead of throwing.
     * Reference checks are the caller's responsibility.
     */
    public function safeDeleteKey(string $key): bool
    {
        return $this->isR2Key($key) && $this->storageService->safeDelete($key);
    }
}
