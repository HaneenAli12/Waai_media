<?php

namespace App\Services\News;

use App\Models\NewsImage;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class NewsHtmlSanitizerService
{
    public const MAX_INPUT_LENGTH = 500_000;

    /**
     * Elements dropped (along with their children) during sanitization.
     *
     * @var list<string>
     */
    private const DROPPED_ELEMENTS = [
        'script',
        'style',
        'iframe',
        'frame',
        'frameset',
        'object',
        'embed',
        'applet',
        'form',
        'input',
        'button',
        'select',
        'textarea',
        'meta',
        'link',
        'base',
        'svg',
        'math',
        'noscript',
        'template',
        'head',
        'title',
    ];

    private HtmlSanitizer $sanitizer;

    public function __construct(
        protected NewsImageService $imageService
    ) {
        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(self::MAX_INPUT_LENGTH)
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks(false)
            ->allowMediaSchemes(['https', 'http'])
            ->allowRelativeMedias(false)
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('frame')
            ->dropElement('frameset')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('applet')
            ->dropElement('form')
            ->dropElement('input')
            ->dropElement('button')
            ->dropElement('select')
            ->dropElement('textarea')
            ->dropElement('meta')
            ->dropElement('link')
            ->dropElement('base')
            ->dropElement('svg')
            ->dropElement('math')
            ->allowElement('p', ['dir', 'align'])
            ->allowElement('br')
            ->allowElement('hr')
            ->allowElement('div', ['dir', 'align'])
            ->allowElement('span', ['dir'])
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('del')
            ->allowElement('sub')
            ->allowElement('sup')
            ->allowElement('mark')
            ->allowElement('small')
            ->allowElement('blockquote', ['dir', 'cite'])
            ->allowElement('pre')
            ->allowElement('code')
            ->allowElement('h1', ['dir', 'align'])
            ->allowElement('h2', ['dir', 'align'])
            ->allowElement('h3', ['dir', 'align'])
            ->allowElement('h4', ['dir', 'align'])
            ->allowElement('h5', ['dir', 'align'])
            ->allowElement('h6', ['dir', 'align'])
            ->allowElement('ul', ['dir'])
            ->allowElement('ol', ['dir', 'start', 'reversed', 'type'])
            ->allowElement('li', ['dir'])
            ->allowElement('figure', ['dir'])
            ->allowElement('figcaption', ['dir'])
            ->allowElement('table', ['dir'])
            ->allowElement('thead', ['dir'])
            ->allowElement('tbody', ['dir'])
            ->allowElement('tfoot', ['dir'])
            ->allowElement('tr', ['dir'])
            ->allowElement('th', ['dir', 'colspan', 'rowspan', 'scope', 'align'])
            ->allowElement('td', ['dir', 'colspan', 'rowspan', 'align'])
            ->allowElement('a', ['href', 'title', 'target', 'rel', 'dir'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height', 'loading', 'data-image-id']);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * Sanitize rich-text HTML and validate all embedded `<img>` references against `news_images`
     * using PHP's native HTML5 DOM parser (`\Dom\HTMLDocument`) and Symfony `HtmlSanitizer`.
     *
     * Rules:
     * - HTML comments (`<!-- ... -->`) and dropped elements (`<script>`, `<svg>`, `<iframe ...>`, etc.)
     *   are parsed via the HTML5 DOM tree so commented-out `<img ...>` text never triggers false errors,
     *   and `>` characters inside `alt` or `title` attributes are parsed accurately as attribute values.
     * - Base64 / `data:` image URIs are strictly rejected with a 422 ValidationException.
     * - Every `<img>` element must resolve to a registered `NewsImage` record (by `data-image-id`,
     *   R2 delivery URL `src`, or both; mismatched `data-image-id` and `src` are rejected).
     * - Enforces image ownership/authorization via `NewsImageService::assertUserCanAssociateImage()`.
     * - Canonicalizes each valid `<img>` element's `src` to the current R2 delivery URL and sets `data-image-id`.
     * - Forces `rel="noopener noreferrer"` on `<a>` links via Symfony `HtmlSanitizerConfig`.
     *
     * @return array{html: ?string, image_ids: list<int>}
     *
     * @throws ValidationException
     */
    public function sanitizeAndExtractImages(
        ?string $html,
        string $fieldName = 'content',
        ?User $user = null,
        ?int $currentNewsId = null
    ): array {
        if ($html === null) {
            return ['html' => null, 'image_ids' => []];
        }

        $trimmed = trim($html);
        if ($trimmed === '') {
            return ['html' => '', 'image_ids' => []];
        }

        if (strlen($trimmed) > self::MAX_INPUT_LENGTH) {
            $trimmed = substr($trimmed, 0, self::MAX_INPUT_LENGTH);
        }

        // 1. Parse raw HTML using PHP's HTML5 DOM parser to validate and canonicalize <img> nodes
        $body = $this->parseHtmlBody($trimmed);
        if ($body === null) {
            return ['html' => '', 'image_ids' => []];
        }

        $this->validateAndCanonicalizeDomImages($body, $fieldName, $user, $currentNewsId);

        // 2. Run Symfony HtmlSanitizer on the DOM-canonicalized HTML
        $sanitized = $this->sanitizer->sanitize($body->innerHTML);

        if (trim($sanitized) === '') {
            return ['html' => '', 'image_ids' => []];
        }

        // 3. Extract verified image IDs from the final sanitized DOM
        $sanitizedBody = $this->parseHtmlBody($sanitized);
        $imageIds = $sanitizedBody !== null
            ? $this->extractImageIdsFromSanitizedDom($sanitizedBody, $fieldName, $user, $currentNewsId)
            : [];

        return [
            'html' => $sanitized,
            'image_ids' => array_values(array_unique($imageIds)),
        ];
    }

    /**
     * Determine if sanitized HTML has meaningful visible content (text or at least one valid image).
     */
    public function hasMeaningfulContent(?string $html): bool
    {
        if ($html === null || trim($html) === '') {
            return false;
        }

        $body = $this->parseHtmlBody($html);
        if ($body === null) {
            return false;
        }

        if ($body->getElementsByTagName('img')->length > 0) {
            return true;
        }

        $text = str_replace("\xc2\xa0", ' ', (string) ($body->textContent ?? ''));

        return trim($text) !== '';
    }

    /**
     * Parse an HTML fragment into a `\Dom\Element` body node using PHP's native HTML5 parser.
     */
    private function parseHtmlBody(string $html): ?Element
    {
        $document = @HTMLDocument::createFromString(
            sprintf('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>%s</body></html>', $html),
            \LIBXML_NOERROR
        );

        $body = $document->getElementsByTagName('body')->item(0);

        return $body instanceof Element && $body->hasChildNodes() ? $body : null;
    }

    /**
     * Walk the DOM tree (skipping comments and dropped element subtrees) to validate
     * and canonicalize all `<img>` elements in-place.
     *
     * @throws ValidationException
     */
    private function validateAndCanonicalizeDomImages(
        Node $node,
        string $fieldName,
        ?User $user,
        ?int $currentNewsId
    ): void {
        foreach ($node->childNodes ?? [] as $child) {
            if (! $child instanceof Element) {
                // Comments (\Dom\Comment), text nodes, and processing instructions are skipped
                continue;
            }

            $tagName = strtolower($child->localName ?? $child->nodeName);

            if (in_array($tagName, self::DROPPED_ELEMENTS, true)) {
                continue;
            }

            if ($tagName === 'img') {
                $attrs = $this->extractDomAttributes($child);
                $newsImage = $this->resolveNewsImageFromAttributes($attrs, $fieldName, $user, $currentNewsId);

                $canonicalUrl = $this->imageService->resolveUrl($newsImage);
                if ($canonicalUrl === null) {
                    throw ValidationException::withMessages([
                        $fieldName => ["Unable to resolve public delivery URL for image ID {$newsImage->id}."],
                    ]);
                }

                $child->setAttribute('src', $canonicalUrl);
                $child->setAttribute('data-image-id', (string) $newsImage->id);

                continue;
            }

            if ($child->hasChildNodes()) {
                $this->validateAndCanonicalizeDomImages($child, $fieldName, $user, $currentNewsId);
            }
        }
    }

    /**
     * Extract verified `NewsImage` IDs from surviving `<img>` elements in the sanitized DOM.
     *
     * @return list<int>
     *
     * @throws ValidationException
     */
    private function extractImageIdsFromSanitizedDom(
        Element $body,
        string $fieldName,
        ?User $user,
        ?int $currentNewsId
    ): array {
        $imageIds = [];

        foreach ($body->getElementsByTagName('img') as $img) {
            if (! $img instanceof Element) {
                continue;
            }

            $attrs = $this->extractDomAttributes($img);
            $newsImage = $this->resolveNewsImageFromAttributes($attrs, $fieldName, $user, $currentNewsId);
            $imageIds[] = $newsImage->id;
        }

        return $imageIds;
    }

    /**
     * Extract an associative array of lowercase attribute names and values from a DOM Element.
     *
     * @return array<string, string>
     */
    private function extractDomAttributes(Element $element): array
    {
        $attrs = [];

        foreach ($element->attributes as $attribute) {
            $attrs[strtolower($attribute->name)] = $attribute->value;
        }

        return $attrs;
    }

    /**
     * Resolve and verify the `NewsImage` record referenced by an `<img>` element's attributes,
     * including image ownership/authorization checks.
     *
     * @param  array<string, string>  $attrs
     *
     * @throws ValidationException
     */
    private function resolveNewsImageFromAttributes(
        array $attrs,
        string $fieldName,
        ?User $user = null,
        ?int $currentNewsId = null
    ): NewsImage {
        $idAttr = $attrs['data-image-id'] ?? null;
        $srcAttr = $attrs['src'] ?? null;

        if ($srcAttr !== null) {
            $normalizedSrc = preg_replace('/[\x00-\x20]+/', '', $srcAttr) ?? $srcAttr;
            if (str_starts_with(strtolower($normalizedSrc), 'data:')) {
                throw ValidationException::withMessages([
                    $fieldName => ['Base64 or data URI images are not allowed inside article HTML. Upload images to R2 first.'],
                ]);
            }
        }

        $imageById = null;
        if ($idAttr !== null && trim($idAttr) !== '') {
            if (! ctype_digit(trim($idAttr))) {
                throw ValidationException::withMessages([
                    $fieldName => ["Invalid embedded image ID '{$idAttr}' in article content."],
                ]);
            }

            $imageById = NewsImage::find((int) trim($idAttr));
            if (! $imageById) {
                throw ValidationException::withMessages([
                    $fieldName => ["Embedded image ID {$idAttr} does not exist or is not authorized."],
                ]);
            }
        }

        $imageBySrc = null;
        if ($srcAttr !== null && trim($srcAttr) !== '') {
            $storageKey = $this->imageService->extractStorageKeyFromUrl($srcAttr);
            if ($storageKey === null) {
                throw ValidationException::withMessages([
                    $fieldName => ['Embedded image URL is not a valid authorized R2 news image URL.'],
                ]);
            }

            $imageBySrc = NewsImage::where('storage_key', $storageKey)->first();
            if (! $imageBySrc) {
                throw ValidationException::withMessages([
                    $fieldName => ['Embedded image URL does not correspond to a registered news image.'],
                ]);
            }
        }

        if ($imageById !== null && $imageBySrc !== null && $imageById->id !== $imageBySrc->id) {
            throw ValidationException::withMessages([
                $fieldName => ['Embedded image ID and src URL do not match the same uploaded news image.'],
            ]);
        }

        $resolved = $imageById ?? $imageBySrc;

        if ($resolved === null) {
            throw ValidationException::withMessages([
                $fieldName => ['Every embedded <img> tag must reference a valid uploaded news image.'],
            ]);
        }

        $this->imageService->assertUserCanAssociateImage($resolved, $user, $currentNewsId, $fieldName);

        return $resolved;
    }
}
