<?php

namespace App\Services\Storage;

use App\Services\Storage\Exceptions\ImageDeleteException;
use App\Services\Storage\Exceptions\ImageUploadException;
use App\Services\Storage\Exceptions\InvalidImageException;
use App\Services\Storage\Exceptions\InvalidStorageKeyException;
use App\Services\Storage\Exceptions\StorageConfigurationException;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImageStorageService
{
    /**
     * Default maximum permitted file size in bytes (5 MB).
     */
    public const DEFAULT_MAX_SIZE_BYTES = 5 * 1024 * 1024;

    /**
     * Allowlist of permitted content MIME types.
     */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Map of verified MIME types to standardized file extensions.
     */
    public const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Allowlist of approved folder prefixes for application images.
     */
    public const ALLOWED_PREFIXES = [
        'news/covers/',
        'news/editor/',
        'clients/logos/',
        'team/photos/',
        'events/covers/',
        'portfolio/images/',
    ];

    /**
     * Regex pattern validating UUID v4 filename with allowed extension.
     */
    private const UUID_FILENAME_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|jpeg|png|webp)$/i';

    /**
     * Create a new ImageStorageService instance.
     */
    public function __construct(
        private readonly string $diskName = 'r2',
        private readonly int $defaultMaxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES
    ) {}

    /**
     * Upload and store a verified image in the cloud storage bucket.
     *
     * @param  UploadedFile|string  $file  An UploadedFile instance or raw binary content
     * @param  string  $prefix  An allowed directory prefix (e.g. 'news/covers/')
     * @param  int|null  $maxSizeBytes  Optional custom maximum byte limit
     *
     * @throws InvalidImageException
     * @throws InvalidStorageKeyException
     * @throws StorageConfigurationException
     * @throws ImageUploadException
     */
    public function upload(UploadedFile|string $file, string $prefix, ?int $maxSizeBytes = null): StoredImage
    {
        $normalizedPrefix = $this->normalizePrefix($prefix);
        $maxSize = $maxSizeBytes ?? $this->defaultMaxSizeBytes;

        // 1. Extract content and validate size
        $content = $this->extractBinaryContent($file);
        $size = strlen($content);

        if ($size === 0) {
            throw new InvalidImageException('The provided image file is empty (0 bytes).');
        }

        if ($size > $maxSize) {
            throw new InvalidImageException(sprintf(
                'The image size (%d bytes) exceeds the allowed limit of %d bytes.',
                $size,
                $maxSize
            ));
        }

        // 2. Perform content-based MIME inspection (GD-independent)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $verifiedMime = $finfo->buffer($content);

        if (! in_array($verifiedMime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidImageException(sprintf(
                "Unsupported or disguised image format with MIME type '%s'. Allowed formats: JPEG, PNG, WebP.",
                $verifiedMime
            ));
        }

        // 3. Generate deterministic UUID-based object key
        $extension = self::MIME_EXTENSIONS[$verifiedMime];
        $uuid = Str::uuid()->toString();
        $key = "{$normalizedPrefix}{$uuid}.{$extension}";

        // 4. Validate public URL configuration before storing
        $publicUrl = $this->getUrl($key);
        $sha256 = hash('sha256', $content);

        // 5. Upload to dedicated storage disk (standard private upload, no public-read ACL)
        try {
            $disk = Storage::disk($this->diskName);
            $stored = $disk->put($key, $content, [
                'ContentType' => $verifiedMime,
                'mimetype' => $verifiedMime,
            ]);

            if (! $stored) {
                throw new ImageUploadException("Storage disk returned false when storing object at key: {$key}");
            }
        } catch (Throwable $e) {
            if ($e instanceof ImageUploadException) {
                throw $e;
            }

            throw new ImageUploadException(
                "Failed to store image object at key '{$key}': ".$e->getMessage(),
                $e
            );
        }

        return new StoredImage($key, $publicUrl, $verifiedMime, $size, $sha256);
    }

    /**
     * Resolve the public HTTPS delivery URL for a stored object key.
     *
     * @param  string  $key  The relative object key
     *
     * @throws InvalidStorageKeyException
     * @throws StorageConfigurationException
     */
    public function getUrl(string $key): string
    {
        $this->validateStorageKey($key);

        $baseUrl = config("filesystems.disks.{$this->diskName}.url");

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new StorageConfigurationException(
                "Public delivery URL is not configured for storage disk '{$this->diskName}'."
            );
        }

        $trimmedBaseUrl = trim($baseUrl);

        if (! Str::startsWith($trimmedBaseUrl, 'https://')) {
            throw new StorageConfigurationException(
                'Public delivery URL must strictly use HTTPS protocol.'
            );
        }

        if (Str::contains($trimmedBaseUrl, 'r2.cloudflarestorage.com')) {
            throw new StorageConfigurationException(
                'Public delivery URL cannot point to the private S3 API endpoint.'
            );
        }

        return rtrim($trimmedBaseUrl, '/').'/'.ltrim($key, '/');
    }

    /**
     * Delete an exact object key from cloud storage.
     *
     * @param  string  $key  The relative object key to delete
     *
     * @throws InvalidStorageKeyException
     * @throws ImageDeleteException
     */
    public function delete(string $key): bool
    {
        $this->validateStorageKey($key);

        try {
            return Storage::disk($this->diskName)->delete($key);
        } catch (Throwable $e) {
            throw new ImageDeleteException(
                "Failed to delete object at key '{$key}': ".$e->getMessage(),
                $e
            );
        }
    }

    /**
     * Safely attempt to delete an object during compensating failure cleanup.
     *
     * Logs any deletion error rather than throwing, returning false on failure.
     *
     * @param  string  $key  The relative object key to clean up
     */
    public function safeDelete(string $key): bool
    {
        try {
            return $this->delete($key);
        } catch (Throwable $e) {
            Log::warning(sprintf(
                "Compensating storage cleanup failed for object '%s': %s",
                $key,
                $e->getMessage()
            ));

            return false;
        }
    }

    /**
     * Determine whether an exact object exists in storage.
     *
     * @param  string  $key  The relative object key to check
     *
     * @throws InvalidStorageKeyException
     */
    public function exists(string $key): bool
    {
        $this->validateStorageKey($key);

        return Storage::disk($this->diskName)->exists($key);
    }

    /**
     * Validate an object key against the prefix allowlist, traversal, and UUID structure.
     *
     * @param  string  $key  The object key to validate
     *
     * @throws InvalidStorageKeyException
     */
    public function validateStorageKey(string $key): bool
    {
        if (trim($key) === '') {
            throw new InvalidStorageKeyException('Storage object key cannot be empty.');
        }

        // Prevent path traversal, directory stepping, and backslashes
        if (Str::contains($key, ['..', '\\', '//', "\0"])) {
            throw new InvalidStorageKeyException("Path traversal or invalid characters detected in key: '{$key}'.");
        }

        $matchedPrefix = null;
        foreach (self::ALLOWED_PREFIXES as $allowedPrefix) {
            if (Str::startsWith($key, $allowedPrefix)) {
                $matchedPrefix = $allowedPrefix;
                break;
            }
        }

        if ($matchedPrefix === null) {
            throw new InvalidStorageKeyException(sprintf(
                "Storage key '%s' does not start with an authorized prefix. Allowed prefixes: %s",
                $key,
                implode(', ', self::ALLOWED_PREFIXES)
            ));
        }

        $filename = substr($key, strlen($matchedPrefix));

        if (! preg_match(self::UUID_FILENAME_REGEX, $filename)) {
            throw new InvalidStorageKeyException(sprintf(
                "Storage key filename '%s' is not a valid UUID v4 with an approved extension (jpg, jpeg, png, webp).",
                $filename
            ));
        }

        return true;
    }

    /**
     * Normalize and validate a directory prefix against the internal allowlist.
     *
     * @throws InvalidStorageKeyException
     */
    private function normalizePrefix(string $prefix): string
    {
        $normalized = rtrim(trim($prefix), '/').'/';

        if (! in_array($normalized, self::ALLOWED_PREFIXES, true)) {
            throw new InvalidStorageKeyException(sprintf(
                "Directory prefix '%s' is not in the approved storage prefix allowlist. Approved prefixes: %s",
                $prefix,
                implode(', ', self::ALLOWED_PREFIXES)
            ));
        }

        return $normalized;
    }

    /**
     * Extract raw binary content from an UploadedFile or string input.
     *
     * @throws InvalidImageException
     */
    private function extractBinaryContent(UploadedFile|string $file): string
    {
        if ($file instanceof UploadedFile) {
            if (! $file->isValid()) {
                throw new InvalidImageException(
                    'Uploaded file failed HTTP upload validation with error code: '.$file->getError()
                );
            }

            $path = $file->getRealPath();
            if (! $path || ! is_readable($path)) {
                throw new InvalidImageException('Uploaded temporary file is unreadable.');
            }

            $content = file_get_contents($path);
            if ($content === false) {
                throw new InvalidImageException('Failed to read uploaded temporary file contents.');
            }

            return $content;
        }

        return $file;
    }
}
