<?php

namespace App\Services\News;

use finfo;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class HtmlSanitizerService
{
    /**
     * Maximum allowed inline Base64 images per language content.
     */
    public const MAX_INLINE_IMAGES = 5;

    /**
     * Maximum decoded size in bytes per inline image (1 MiB = 1,048,576 bytes).
     */
    public const MAX_IMAGE_DECODED_SIZE_BYTES = 1048576;

    /**
     * Maximum total content input size in bytes to prevent memory exhaustion (10 MB).
     */
    public const MAX_CONTENT_LENGTH_BYTES = 10 * 1024 * 1024;

    /**
     * Allowed MIME types for inline raster images.
     *
     * @var list<string>
     */
    public const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(self::MAX_CONTENT_LENGTH_BYTES)
            ->allowSafeElements()
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank')
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowMediaSchemes(['data'])
            ->dropElement('script')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('style')
            ->dropElement('form');

        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * Validate all inline Base64 images within the provided HTML content.
     *
     * @throws ValidationException
     */
    public function validateInlineImages(?string $html, string $attributeName = 'content'): void
    {
        if ($html === null || trim($html) === '') {
            return;
        }

        if (strlen($html) > self::MAX_CONTENT_LENGTH_BYTES) {
            throw ValidationException::withMessages([
                $attributeName => 'Content payload exceeds maximum allowable limit of 10 MB.',
            ]);
        }

        // Parse HTML robustly using ext-dom to extract every <img> element
        $dom = new \DOMDocument;
        $prevErrors = libxml_use_internal_errors(true);
        // Using UTF-8 XML encoding declaration ensures multi-byte Arabic and Unicode are parsed without corruption
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        $imgNodes = $dom->getElementsByTagName('img');
        $totalImages = $imgNodes->length;

        if ($totalImages > self::MAX_INLINE_IMAGES) {
            throw ValidationException::withMessages([
                $attributeName => sprintf(
                    'Content contains %d inline images. The maximum permitted is %d inline images.',
                    $totalImages,
                    self::MAX_INLINE_IMAGES
                ),
            ]);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        for ($i = 0; $i < $totalImages; $i++) {
            $img = $imgNodes->item($i);
            $imageNum = $i + 1;

            if (! $img->hasAttribute('src') || trim($img->getAttribute('src')) === '') {
                throw ValidationException::withMessages([
                    $attributeName => sprintf('Inline image #%d has a missing or empty src attribute.', $imageNum),
                ]);
            }

            $src = trim($img->getAttribute('src'));

            // Reject external HTTP/HTTPS URLs explicitly (SEC-03)
            if (preg_match('/^https?:\/\//i', $src)) {
                throw ValidationException::withMessages([
                    $attributeName => sprintf(
                        'Inline image #%d uses an external URL. External image URLs are strictly prohibited; only Base64-encoded JPEG, PNG, and WebP raster images are permitted.',
                        $imageNum
                    ),
                ]);
            }

            // Must match format: data:image/(jpeg|jpg|png|webp);base64,...
            if (! preg_match('/^data:(image\/[a-zA-Z0-9\-\+\.]+);base64,(.+)$/is', $src, $parts)) {
                throw ValidationException::withMessages([
                    $attributeName => sprintf(
                        'Inline image #%d is invalid or uses an unsupported scheme. Only Base64-encoded JPEG, PNG, and WebP raster images are allowed. SVG and external URLs are strictly rejected.',
                        $imageNum
                    ),
                ]);
            }

            $declaredMime = strtolower(trim($parts[1]));
            if ($declaredMime === 'image/jpg') {
                $declaredMime = 'image/jpeg';
            }
            $base64Data = trim($parts[2]);

            // Reject SVG and non-raster formats immediately
            if (! in_array($declaredMime, self::ALLOWED_IMAGE_MIMES, true)) {
                throw ValidationException::withMessages([
                    $attributeName => sprintf(
                        "Inline image #%d declares unsupported MIME type '%s'. Allowed types: JPEG, PNG, WebP.",
                        $imageNum,
                        $declaredMime
                    ),
                ]);
            }

            // Fast pre-check: Base64 encodes 3 bytes to 4 chars.
            // If the Base64 string length exceeds ~1.4 MB, decoded size definitely exceeds 1 MiB.
            $maxBase64Length = (int) ceil(self::MAX_IMAGE_DECODED_SIZE_BYTES * 4 / 3) + 8;
            if (strlen($base64Data) > $maxBase64Length) {
                throw ValidationException::withMessages([
                    $attributeName => sprintf(
                        'Inline image #%d exceeds the maximum permitted decoded size limit of 1 MiB.',
                        $imageNum
                    ),
                ]);
            }

            $decoded = base64_decode($base64Data, true);

            if ($decoded === false) {
                throw ValidationException::withMessages([
                    $attributeName => sprintf(
                        'Inline image #%d contains malformed or corrupted Base64 data.',
                        $imageNum
                    ),
                ]);
            }

            $decodedBytes = strlen($decoded);
            if ($decodedBytes > self::MAX_IMAGE_DECODED_SIZE_BYTES) {
                throw ValidationException::withMessages([
                    $attributeName => sprintf(
                        'Inline image #%d decoded size (%d bytes) exceeds the maximum allowed limit of 1 MiB (%d bytes).',
                        $imageNum,
                        $decodedBytes,
                        self::MAX_IMAGE_DECODED_SIZE_BYTES
                    ),
                ]);
            }

            if ($decodedBytes === 0) {
                throw ValidationException::withMessages([
                    $attributeName => sprintf('Inline image #%d is empty (0 bytes).', $imageNum),
                ]);
            }

            // Inspect magic bytes to verify actual image content
            $actualMime = $finfo->buffer($decoded);
            if (! in_array($actualMime, self::ALLOWED_IMAGE_MIMES, true)) {
                throw ValidationException::withMessages([
                    $attributeName => sprintf(
                        "Inline image #%d content is disguised or corrupted (detected MIME: '%s'). Expected JPEG, PNG, or WebP.",
                        $imageNum,
                        $actualMime
                    ),
                ]);
            }

            // Ensure declared MIME matches verified content MIME
            if ($actualMime !== $declaredMime) {
                throw ValidationException::withMessages([
                    $attributeName => sprintf(
                        "Inline image #%d declared MIME '%s' does not match verified content '%s'.",
                        $imageNum,
                        $declaredMime,
                        $actualMime
                    ),
                ]);
            }
        }
    }

    /**
     * Sanitize HTML content using the configured Symfony HtmlSanitizer.
     */
    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return $this->sanitizer->sanitize($html);
    }

    /**
     * Validate inline images and sanitize HTML content in one unified operation.
     *
     * @throws ValidationException
     */
    public function validateAndSanitize(?string $html, string $attributeName = 'content'): string
    {
        $this->validateInlineImages($html, $attributeName);

        return $this->sanitize($html);
    }
}
