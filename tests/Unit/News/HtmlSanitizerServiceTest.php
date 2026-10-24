<?php

namespace Tests\Unit\News;

use App\Services\News\HtmlSanitizerService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HtmlSanitizerServiceTest extends TestCase
{
    private HtmlSanitizerService $service;

    // 1x1 valid PNG in Base64 (68 bytes decoded)
    private string $validPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    // 1x1 valid JPEG in Base64
    private string $validJpegBase64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    // 1x1 valid WebP in Base64
    private string $validWebpBase64 = 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new HtmlSanitizerService;
    }

    public function test_script_tags_and_inline_event_handlers_are_removed(): void
    {
        $dirtyHtml = '<p>Safe Text</p><script>alert("XSS")</script><img src="data:image/png;base64,'.$this->validPngBase64.'" onerror="alert(\'pwned\')" onload="alert(1)" onclick="alert(2)">';

        $clean = $this->service->sanitize($dirtyHtml);

        $this->assertStringNotContainsString('<script>', $clean);
        $this->assertStringNotContainsString('alert', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('onload', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringContainsString('<p>Safe Text</p>', $clean);
    }

    public function test_dangerous_uri_schemes_are_stripped(): void
    {
        $dirtyHtml = '<a href="javascript:alert(1)">Click</a><a href="vbscript:msgbox(1)">Visual</a><a href="file:///etc/passwd">File</a><a href="https://example.com">Legit</a>';

        $clean = $this->service->sanitize($dirtyHtml);

        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('vbscript:', $clean);
        $this->assertStringNotContainsString('file:', $clean);
        $this->assertStringContainsString('href="https://example.com"', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $clean);
        $this->assertStringContainsString('target="_blank"', $clean);
    }

    public function test_legitimate_rich_text_formatting_is_preserved(): void
    {
        $html = '<h2>Article Subheading</h2><p>Paragraph with <strong>bold</strong>, <em>italic</em>, <u>underline</u>, and <strike>strikethrough</strike>.</p><blockquote>A quote</blockquote><ul><li>List item 1</li><li>List item 2</li></ul><table><thead><tr><th>Header</th></tr></thead><tbody><tr><td>Cell</td></tr></tbody></table>';

        $clean = $this->service->sanitize($html);

        $this->assertStringContainsString('<h2>Article Subheading</h2>', $clean);
        $this->assertStringContainsString('<strong>bold</strong>', $clean);
        $this->assertStringContainsString('<em>italic</em>', $clean);
        $this->assertStringContainsString('<blockquote>A quote</blockquote>', $clean);
        $this->assertStringContainsString('<ul><li>List item 1</li>', $clean);
        $this->assertStringContainsString('<table>', $clean);
        $this->assertStringContainsString('<td>Cell</td>', $clean);
    }

    public function test_valid_jpeg_png_and_webp_inline_images_are_accepted(): void
    {
        $html = '<p>Content</p>'
            .'<img src="data:image/png;base64,'.$this->validPngBase64.'">'
            .'<img src="data:image/jpeg;base64,'.$this->validJpegBase64.'">'
            .'<img src="data:image/webp;base64,'.$this->validWebpBase64.'">';

        // Should not throw
        $this->service->validateInlineImages($html);

        $sanitized = $this->service->sanitize($html);
        $this->assertStringContainsString('data:image/png;base64,', $sanitized);
        $this->assertStringContainsString('data:image/jpeg;base64,', $sanitized);
        $this->assertStringContainsString('data:image/webp;base64,', $sanitized);
    }

    public function test_svg_inline_images_are_strictly_rejected(): void
    {
        $svgBase64 = base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $html = '<p>Content</p><img src="data:image/svg+xml;base64,'.$svgBase64.'">';

        $this->expectException(ValidationException::class);
        $this->service->validateInlineImages($html);
    }

    public function test_malformed_base64_payload_is_rejected(): void
    {
        $html = '<p>Content</p><img src="data:image/png;base64,!!!NotBase64@@@">';

        $this->expectException(ValidationException::class);
        $this->service->validateInlineImages($html);
    }

    public function test_more_than_five_inline_images_is_rejected(): void
    {
        $img = '<img src="data:image/png;base64,'.$this->validPngBase64.'">';
        $htmlFive = $img.$img.$img.$img.$img;
        $htmlSix = $htmlFive.$img;

        // 5 is accepted
        $this->service->validateInlineImages($htmlFive);
        $this->assertTrue(true);

        // 6 throws
        $this->expectException(ValidationException::class);
        $this->service->validateInlineImages($htmlSix);
    }

    public function test_inline_image_exceeding_one_mib_decoded_is_rejected(): void
    {
        // 1 MiB + 10 bytes of dummy raw data
        $oversizedData = str_repeat('A', 1048576 + 10);
        $base64 = base64_encode($oversizedData);
        $html = '<img src="data:image/png;base64,'.$base64.'">';

        $this->expectException(ValidationException::class);
        $this->service->validateInlineImages($html);
    }

    public function test_inline_image_mime_content_mismatch_is_rejected(): void
    {
        // Declares PNG, but payload is actually a JPEG
        $html = '<img src="data:image/png;base64,'.$this->validJpegBase64.'">';

        $this->expectException(ValidationException::class);
        $this->service->validateInlineImages($html);
    }

    public function test_non_image_disguised_file_is_rejected(): void
    {
        // Text file disguised as a JPEG
        $textPayload = base64_encode('Plain text disguised as image');
        $html = '<img src="data:image/jpeg;base64,'.$textPayload.'">';

        $this->expectException(ValidationException::class);
        $this->service->validateInlineImages($html);
    }

    public function test_empty_content_returns_empty_string_without_error(): void
    {
        $this->assertSame('', $this->service->sanitize(null));
        $this->assertSame('', $this->service->sanitize(''));
        $this->assertSame('', $this->service->sanitize('   '));
    }

    public function test_leading_whitespace_in_data_uri_is_handled_and_validated(): void
    {
        $validHtmlWithWhitespace = '<img src=" data:image/png;base64,'.$this->validPngBase64.' ">';
        $this->service->validateInlineImages($validHtmlWithWhitespace);
        $this->assertTrue(true);

        // Leading whitespace cannot bypass SVG rejection
        $svgBase64 = base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><circle r="10"/></svg>');
        $svgHtmlWithWhitespace = '<img src=" data:image/svg+xml;base64,'.$svgBase64.' ">';

        $this->expectException(ValidationException::class);
        $this->service->validateInlineImages($svgHtmlWithWhitespace);
    }

    public function test_unquoted_src_attribute_is_parsed_and_validated(): void
    {
        // Unquoted SVG attempt is rejected
        $svgBase64 = base64_encode('<svg><rect width="10" height="10"/></svg>');
        $html = '<img src=data:image/svg+xml;base64,'.$svgBase64.'>';

        $this->expectException(ValidationException::class);
        $this->service->validateInlineImages($html);
    }

    public function test_html_entity_encoded_scheme_is_parsed_and_validated(): void
    {
        // Entity encoded scheme &#100;&#97;&#116;&#97;: (data:)
        $svgBase64 = base64_encode('<svg><g></g></svg>');
        $html = '<img src="&#100;&#97;&#116;&#97;:image/svg+xml;base64,'.$svgBase64.'">';

        $this->expectException(ValidationException::class);
        $this->service->validateInlineImages($html);
    }

    public function test_mixed_case_scheme_and_mime_are_handled(): void
    {
        // Valid PNG with uppercase DATA:IMAGE/PNG;BASE64,
        $html = '<img src="DATA:IMAGE/PNG;BASE64,'.$this->validPngBase64.'">';
        $this->service->validateInlineImages($html);
        $this->assertTrue(true);

        // Uppercase SVG is rejected
        $svgBase64 = base64_encode('<svg></svg>');
        $svgHtml = '<img src="DATA:IMAGE/SVG+XML;BASE64,'.$svgBase64.'">';

        $this->expectException(ValidationException::class);
        $this->service->validateInlineImages($svgHtml);
    }

    public function test_external_http_and_https_image_urls_are_rejected(): void
    {
        $httpsHtml = '<img src="https://example.com/photo.png">';
        try {
            $this->service->validateInlineImages($httpsHtml);
            $this->fail('Expected ValidationException for external https image URL');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('content', $e->errors());
            $this->assertStringContainsString('external URL', $e->errors()['content'][0]);
        }

        $httpHtml = '<img src="http://untrusted.com/image.jpg">';
        $this->expectException(ValidationException::class);
        $this->service->validateInlineImages($httpHtml);
    }

    public function test_missing_or_empty_image_src_is_rejected(): void
    {
        $missingSrc = '<img>';
        try {
            $this->service->validateInlineImages($missingSrc);
            $this->fail('Expected ValidationException for missing src attribute');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('content', $e->errors());
            $this->assertStringContainsString('missing or empty src', $e->errors()['content'][0]);
        }

        $emptySrc = '<img src="">';
        $this->expectException(ValidationException::class);
        $this->service->validateInlineImages($emptySrc);
    }

    public function test_jpg_synonym_for_jpeg_is_accepted_when_bytes_match(): void
    {
        $html = '<img src="data:image/jpg;base64,'.$this->validJpegBase64.'">';
        $this->service->validateInlineImages($html);
        $this->assertTrue(true);
    }

    public function test_disguised_svg_declaring_png_is_rejected_by_magic_bytes(): void
    {
        // Declares PNG, but payload is actually an SVG
        $svgPayload = base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><text>Disguised</text></svg>');
        $html = '<img src="data:image/png;base64,'.$svgPayload.'">';

        $this->expectException(ValidationException::class);
        $this->service->validateInlineImages($html);
    }
}
