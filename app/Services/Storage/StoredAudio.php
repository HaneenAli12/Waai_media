<?php

namespace App\Services\Storage;

class StoredAudio
{
    /**
     * @param  string  $key  The relative object key in the storage bucket (e.g. music/audio/<uuid>.mp3)
     * @param  string  $url  The public delivery URL (e.g. https://example.r2.dev/music/audio/<uuid>.mp3)
     * @param  string  $mimeType  The verified content MIME type (e.g. audio/mpeg)
     * @param  int  $size  The file size in bytes
     * @param  string  $sha256  The SHA-256 hash of the uploaded audio binary
     */
    public function __construct(
        public readonly string $key,
        public readonly string $url,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $sha256,
    ) {}

    /**
     * Convert the stored audio value object to an array.
     *
     * @return array{key: string, url: string, mime_type: string, size: int, sha256: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'url' => $this->url,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'sha256' => $this->sha256,
        ];
    }
}
