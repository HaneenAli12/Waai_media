<?php

namespace App\Services\Storage;

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

class AudioStorageService
{
    /**
     * Maximum allowed file size in bytes (15 MiB).
     */
    public const MAX_SIZE_BYTES = 15 * 1024 * 1024; // 15,728,640 bytes

    /**
     * Dedicated storage directory prefix for background music audio.
     */
    public const DIRECTORY_PREFIX = 'music/audio/';

    /**
     * Map of common audio MIME types to standardized file extensions.
     * If an audio MIME type is not in this map, the extension from the original file or a safe fallback is used.
     */
    public const MIME_EXTENSIONS = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/x-pn-wav' => 'wav',
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
        'audio/vorbis' => 'ogg',
        'audio/opus' => 'opus',
        'audio/x-m4a' => 'm4a',
        'audio/mp4' => 'm4a',
        'audio/m4a' => 'm4a',
        'audio/aac' => 'aac',
        'audio/x-aac' => 'aac',
        'audio/x-hx-aac-adts' => 'aac',
        'audio/flac' => 'flac',
        'audio/x-flac' => 'flac',
        'audio/aiff' => 'aiff',
        'audio/x-aiff' => 'aiff',
        'audio/x-ms-wma' => 'wma',
        'audio/webm' => 'webm',
        'audio/midi' => 'mid',
        'audio/x-midi' => 'mid',
        'audio/basic' => 'au',
    ];

    /**
     * Regex validating UUID v4 filename with any safe lowercase alphanumeric audio extension (2 to 10 chars).
     */
    private const UUID_FILENAME_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[a-z0-9]{2,10}$/i';

    private string $diskName = 'r2';

    private int $maxSizeBytes = self::MAX_SIZE_BYTES;

    public function __construct(
        ?string $diskName = null,
        ?int $maxSizeBytes = null
    ) {
        if ($diskName !== null) {
            $this->diskName = $diskName;
        }
        if ($maxSizeBytes !== null) {
            $this->maxSizeBytes = $maxSizeBytes;
        }
    }

    /**
     * Upload and store a verified audio file in Cloudflare R2 storage.
     *
     * @throws InvalidImageException
     * @throws InvalidStorageKeyException
     * @throws StorageConfigurationException
     * @throws ImageUploadException
     */
    public function upload(UploadedFile|string $file): StoredAudio
    {
        $content = $this->extractBinaryContent($file);
        $size = strlen($content);

        if ($size === 0) {
            throw new InvalidImageException('The provided audio file is empty (0 bytes).');
        }

        if ($size > $this->maxSizeBytes) {
            throw new InvalidImageException(sprintf(
                'The audio size (%d bytes) exceeds the allowed limit of %d bytes.',
                $size,
                $this->maxSizeBytes
            ));
        }

        // Content-based inspection using finfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $verifiedMime = $finfo->buffer($content) ?: 'application/octet-stream';

        // Check if verified MIME type indicates genuine audio content
        if (! $this->isAudioMimeType($verifiedMime, $content)) {
            throw new InvalidImageException(sprintf(
                "The uploaded file is not recognized as a valid audio format (detected MIME type: '%s').",
                $verifiedMime
            ));
        }

        $extension = $this->resolveExtension($file, $verifiedMime);
        $uuid = Str::uuid()->toString();
        $key = self::DIRECTORY_PREFIX."{$uuid}.{$extension}";

        $publicUrl = $this->getUrl($key);
        $sha256 = hash('sha256', $content);

        try {
            $disk = Storage::disk($this->diskName);
            $stored = $disk->put($key, $content, [
                'ContentType' => $verifiedMime,
                'mimetype' => $verifiedMime,
            ]);

            if (! $stored) {
                throw new ImageUploadException("Storage disk returned false when storing audio at key: {$key}");
            }
        } catch (Throwable $e) {
            if ($e instanceof ImageUploadException) {
                throw $e;
            }

            throw new ImageUploadException(
                "Failed to store audio object at key '{$key}': ".$e->getMessage(),
                $e
            );
        }

        return new StoredAudio($key, $publicUrl, $verifiedMime, $size, $sha256);
    }

    /**
     * Resolve the absolute public delivery URL for a stored audio object key.
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

        if (! Str::startsWith($trimmedBaseUrl, 'https://') && ! Str::startsWith($trimmedBaseUrl, 'http://')) {
            throw new StorageConfigurationException(
                'Public delivery URL must use HTTP or HTTPS protocol.'
            );
        }

        return rtrim($trimmedBaseUrl, '/').'/'.ltrim($key, '/');
    }

    /**
     * Check if the underlying storage disk is configured.
     */
    public function diskExists(): bool
    {
        return config("filesystems.disks.{$this->diskName}") !== null;
    }

    /**
     * Get the configured disk name.
     */
    public function getDiskName(): string
    {
        return $this->diskName;
    }

    /**
     * Enumerate all files stored strictly within the dedicated audio prefix.
     *
     * @return list<string>
     */
    public function allAudioFiles(): array
    {
        return Storage::disk($this->diskName)->files(self::DIRECTORY_PREFIX);
    }

    /**
     * Get the last modified timestamp of an audio object.
     */
    public function lastModified(string $key): int
    {
        return Storage::disk($this->diskName)->lastModified($key);
    }

    /**
     * Safely attempt to delete an unreferenced object key from storage.
     * Logs any failure without throwing.
     */
    public function safeDelete(string $key): bool
    {
        try {
            $this->validateStorageKey($key);

            return Storage::disk($this->diskName)->delete($key);
        } catch (Throwable $e) {
            Log::warning(sprintf(
                "Compensating audio storage cleanup failed for object '%s': %s",
                $key,
                $e->getMessage()
            ));

            return false;
        }
    }

    /**
     * Validate an object key against the prefix allowlist, path traversal, and UUID format.
     *
     * @throws InvalidStorageKeyException
     */
    public function validateStorageKey(string $key): bool
    {
        if (trim($key) === '') {
            throw new InvalidStorageKeyException('Storage object key cannot be empty.');
        }

        if (Str::contains($key, ['..', '\\', '//', "\0"])) {
            throw new InvalidStorageKeyException("Path traversal or invalid characters detected in key: '{$key}'.");
        }

        if (! Str::startsWith($key, self::DIRECTORY_PREFIX)) {
            throw new InvalidStorageKeyException(sprintf(
                "Storage key '%s' does not start with the authorized prefix '%s'.",
                $key,
                self::DIRECTORY_PREFIX
            ));
        }

        $filename = substr($key, strlen(self::DIRECTORY_PREFIX));

        if (! preg_match(self::UUID_FILENAME_REGEX, $filename)) {
            throw new InvalidStorageKeyException(sprintf(
                "Storage key filename '%s' is not a valid UUID v4 with an approved audio extension.",
                $filename
            ));
        }

        return true;
    }

    /**
     * Determine whether the detected MIME type and binary header represent genuine audio.
     */
    public function isAudioMimeType(string $mimeType, string $content): bool
    {
        // 1. Direct standard audio MIME types
        if (Str::startsWith($mimeType, 'audio/')) {
            return true;
        }

        // 2. Known audio container types with application/ or video/ prefix that can be audio-only
        if (in_array($mimeType, ['application/ogg', 'application/x-ogg', 'application/vnd.apple.mpegurl'], true)) {
            return true;
        }

        // 3. Fallback header magic inspection for container formats that libmagic sometimes labels generic
        // OGG container ("OggS")
        if (str_starts_with($content, "OggS\x00")) {
            return true;
        }

        // ID3v2 MP3 tag ("ID3")
        if (str_starts_with($content, 'ID3')) {
            return true;
        }

        // MP3 raw sync word (0xFF 0xFB, 0xF3, 0xF2, 0xFA, 0xE0..0xFF)
        if (strlen($content) >= 2 && ord($content[0]) === 0xFF && (ord($content[1]) & 0xE0) === 0xE0) {
            return true;
        }

        // RIFF WAVE header
        if (str_starts_with($content, 'RIFF') && strlen($content) >= 12 && substr($content, 8, 4) === 'WAVE') {
            return true;
        }

        // FLAC header ("fLaC")
        if (str_starts_with($content, 'fLaC')) {
            return true;
        }

        // MIDI header ("MThd")
        if (str_starts_with($content, 'MThd')) {
            return true;
        }

        return false;
    }

    /**
     * Resolve the appropriate file extension for the audio object.
     */
    private function resolveExtension(UploadedFile|string $file, string $mimeType): string
    {
        if (isset(self::MIME_EXTENSIONS[$mimeType])) {
            return self::MIME_EXTENSIONS[$mimeType];
        }

        if ($file instanceof UploadedFile) {
            $clientExt = strtolower($file->getClientOriginalExtension());
            if ($clientExt !== '' && preg_match('/^[a-z0-9]{2,10}$/', $clientExt)) {
                return $clientExt;
            }

            $guessedExt = $file->guessExtension();
            if ($guessedExt && preg_match('/^[a-z0-9]{2,10}$/', $guessedExt)) {
                return strtolower($guessedExt);
            }
        }

        return 'audio';
    }

    /**
     * Extract binary content from UploadedFile or string input.
     *
     * @throws InvalidImageException
     */
    private function extractBinaryContent(UploadedFile|string $file): string
    {
        if ($file instanceof UploadedFile) {
            if (! $file->isValid()) {
                throw new InvalidImageException(
                    'Uploaded audio file failed HTTP upload validation with error code: '.$file->getError()
                );
            }

            $path = $file->getRealPath();
            if (! $path || ! is_readable($path)) {
                throw new InvalidImageException('Uploaded temporary audio file is unreadable.');
            }

            $content = file_get_contents($path);
            if ($content === false) {
                throw new InvalidImageException('Failed to read uploaded temporary audio file contents.');
            }

            return $content;
        }

        return $file;
    }
}
