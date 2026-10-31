<?php

namespace Tests\Unit\News;

use App\Models\Category;
use App\Models\News;
use App\Models\NewsImage;
use App\Services\News\NewsHtmlSanitizerService;
use App\Services\News\NewsSlugService;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NewsUnitTest extends TestCase
{
    use RefreshDatabase;

    private NewsSlugService $slugService;

    private NewsHtmlSanitizerService $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);
        Config::set('filesystems.disks.r2.url', 'https://pub-r2.waai.test');

        $this->slugService = app(NewsSlugService::class);
        $this->sanitizer = app(NewsHtmlSanitizerService::class);
    }

    // =========================================================================
    // 1. NewsSlugService
    // =========================================================================

    public function test_slug_service_normalizes_english_titles_and_appends_id(): void
    {
        $slug = $this->slugService->generateSlug('Healthcare Innovation in 2026!', 42);

        $this->assertSame('healthcare-innovation-in-2026-42', $slug);
    }

    public function test_slug_service_returns_null_for_empty_or_non_latin_titles(): void
    {
        $this->assertNull($this->slugService->generateSlug(null, 10));
        $this->assertNull($this->slugService->generateSlug('   ', 10));
        $this->assertNull($this->slugService->generateSlug('عنوان باللغة العربية فقط', 10));
        $this->assertNull($this->slugService->generateSlug('!@#$%^&*()', 10));
    }

    public function test_slug_service_preserves_slug_once_published(): void
    {
        $health = Category::where('type', 'news')->where('slug', 'health')->firstOrFail();
        $news = News::factory()->published()->create([
            'category_id' => $health->id,
            'title_en' => 'Original Title',
            'slug' => 'original-title-99',
        ]);

        $resolved = $this->slugService->resolveSlugForUpdate($news, 'Updated Title', true);

        $this->assertSame('original-title-99', $resolved);
    }

    public function test_slug_service_enforces_maximum_length_of_255_and_preserves_id_suffix(): void
    {
        // 1. Long title (250 chars)
        $title250 = str_repeat('a', 250);
        $slug250 = $this->slugService->generateSlug($title250, 1024);
        $this->assertNotNull($slug250);
        $this->assertLessThanOrEqual(255, strlen($slug250));
        $this->assertStringEndsWith('-1024', $slug250);
        $this->assertStringNotContainsString('--1024', $slug250);

        // 2. Maximum title (255 chars) with 6-digit ID
        $title255 = str_repeat('b', 255);
        $slug255 = $this->slugService->generateSlug($title255, 999999);
        $this->assertNotNull($slug255);
        $this->assertSame(255, strlen($slug255));
        $this->assertStringEndsWith('-999999', $slug255);
        $this->assertStringNotContainsString('--999999', $slug255);

        // 3. Uniqueness across different article IDs with identical long titles
        $slugArticleA = $this->slugService->generateSlug($title255, 1);
        $slugArticleB = $this->slugService->generateSlug($title255, 2);
        $this->assertNotSame($slugArticleA, $slugArticleB);
        $this->assertStringEndsWith('-1', $slugArticleA);
        $this->assertStringEndsWith('-2', $slugArticleB);

        // 4. Fallback to 'news' base when truncation removes hyphens and leaves empty base
        $hyphenHeavyTitle = str_repeat('a-', 130); // normalizes to alternating a and hyphens
        $slugTruncated = $this->slugService->generateSlug($hyphenHeavyTitle, 55);
        $this->assertNotNull($slugTruncated);
        $this->assertLessThanOrEqual(255, strlen($slugTruncated));
        $this->assertStringEndsWith('-55', $slugTruncated);
    }

    // =========================================================================
    // 2. NewsHtmlSanitizerService
    // =========================================================================

    public function test_html_sanitizer_strips_scripts_event_handlers_iframes_and_javascript_urls(): void
    {
        $dirtyHtml = '
            <h2>Breaking News</h2>
            <p onclick="alert(1)" onmouseover="steal()">Paragraph with <strong>bold</strong> and <em>italic</em> text.</p>
            <script>window.location="https://evil.test"</script>
            <iframe src="https://evil.test/frame"></iframe>
            <svg onload="alert(2)"></svg>
            <a href="javascript:alert(3)" onclick="evil()">Malicious Link</a>
            <a href="https://waaimedia.com/about" target="_blank">Safe Link</a>
        ';

        $result = $this->sanitizer->sanitizeAndExtractImages($dirtyHtml, 'content_en');
        $clean = $result['html'];

        $this->assertStringContainsString('<h2>Breaking News</h2>', $clean);
        $this->assertStringContainsString('<strong>bold</strong>', $clean);
        $this->assertStringContainsString('<em>italic</em>', $clean);
        $this->assertStringContainsString('href="https://waaimedia.com/about"', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer"', $clean);

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onmouseover', $clean);
        $this->assertStringNotContainsString('<iframe', $clean);
        $this->assertStringNotContainsString('<svg', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertSame([], $result['image_ids']);
    }

    public function test_html_sanitizer_rejects_base64_data_uri_images(): void
    {
        $htmlWithBase64 = '<p>Text</p><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=" />';

        $this->expectException(ValidationException::class);

        $this->sanitizer->sanitizeAndExtractImages($htmlWithBase64, 'content_en');
    }

    public function test_html_sanitizer_rejects_non_existent_embedded_image_id(): void
    {
        $html = '<p>Hello</p><img data-image-id="999999" src="https://pub-r2.waai.test/news/editor/550e8400-e29b-41d4-a716-446655440000.webp" />';

        $this->expectException(ValidationException::class);

        $this->sanitizer->sanitizeAndExtractImages($html, 'content_en');
    }

    public function test_html_sanitizer_rejects_unregistered_external_image_url(): void
    {
        $html = '<p>Hello</p><img src="https://untrusted.example.com/malicious.jpg" />';

        $this->expectException(ValidationException::class);

        $this->sanitizer->sanitizeAndExtractImages($html, 'content_en');
    }

    public function test_html_sanitizer_rejects_mismatched_valid_image_id_and_url(): void
    {
        $img1 = NewsImage::factory()->editor()->create([
            'storage_key' => 'news/editor/111e8400-e29b-41d4-a716-446655440001.webp',
        ]);
        $img2 = NewsImage::factory()->editor()->create([
            'storage_key' => 'news/editor/222e8400-e29b-41d4-a716-446655440002.webp',
        ]);

        $html = sprintf(
            '<p>Mismatch</p><img data-image-id="%d" src="https://pub-r2.waai.test/%s" />',
            $img1->id,
            $img2->storage_key
        );

        $this->expectException(ValidationException::class);

        $this->sanitizer->sanitizeAndExtractImages($html, 'content_en');
    }

    public function test_html_sanitizer_allows_greater_than_sign_inside_alt_and_title_attributes(): void
    {
        $img = NewsImage::factory()->editor()->create([
            'storage_key' => 'news/editor/333e8400-e29b-41d4-a716-446655440003.webp',
        ]);

        // Place alt and title containing '>' BEFORE src and data-image-id to prove DOM parsing does not truncate at '>'
        $html = sprintf(
            '<p>Comparison</p><img alt="Revenue > Cost &amp; Q4 > Q3" title="2026 > 2025" src="https://pub-r2.waai.test/%s" data-image-id="%d" />',
            $img->storage_key,
            $img->id
        );

        $result = $this->sanitizer->sanitizeAndExtractImages($html, 'content_en');

        $this->assertSame([$img->id], $result['image_ids']);
        $this->assertStringContainsString('src="https://pub-r2.waai.test/'.$img->storage_key.'"', $result['html']);
        $this->assertStringContainsString('data-image-id="'.$img->id.'"', $result['html']);
        $this->assertStringContainsString('Revenue &gt; Cost', $result['html']);
        $this->assertStringContainsString('2026 &gt; 2025', $result['html']);
    }

    public function test_html_sanitizer_ignores_image_like_text_inside_html_comments_and_strips_comments(): void
    {
        $img = NewsImage::factory()->editor()->create([
            'storage_key' => 'news/editor/444e8400-e29b-41d4-a716-446655440004.webp',
        ]);

        $html = sprintf(
            '<p>Visible paragraph</p><!-- <img src="https://evil.example.com/unregistered.png" data-image-id="999999" /> --><img data-image-id="%d" src="https://pub-r2.waai.test/%s" alt="Valid" />',
            $img->id,
            $img->storage_key
        );

        $result = $this->sanitizer->sanitizeAndExtractImages($html, 'content_en');

        $this->assertSame([$img->id], $result['image_ids']);
        $this->assertStringNotContainsString('evil.example.com', $result['html']);
        $this->assertStringNotContainsString('<!--', $result['html']);
        $this->assertStringContainsString('data-image-id="'.$img->id.'"', $result['html']);
    }

    public function test_html_sanitizer_handles_unsupported_embeds_and_malformed_html_while_preserving_valid_content(): void
    {
        $img = NewsImage::factory()->editor()->create([
            'storage_key' => 'news/editor/777e8400-e29b-41d4-a716-446655440007.webp',
        ]);

        $malformedHtml = sprintf(
            '<div><p>Unclosed paragraph <strong>Bold text</strong><object data="https://evil.test/app.swf"></object><embed src="https://evil.test/plug.swf"><form action="/steal"><input type="text" value="secret"></form><img data-image-id="%d" /></div>',
            $img->id
        );

        $result = $this->sanitizer->sanitizeAndExtractImages($malformedHtml, 'content_en');

        $this->assertSame([$img->id], $result['image_ids']);
        $this->assertStringContainsString('Unclosed paragraph', $result['html']);
        $this->assertStringContainsString('<strong>Bold text', $result['html']);
        $this->assertStringContainsString('src="https://pub-r2.waai.test/'.$img->storage_key.'"', $result['html']);
        $this->assertStringNotContainsString('<object', $result['html']);
        $this->assertStringNotContainsString('<embed', $result['html']);
        $this->assertStringNotContainsString('<form', $result['html']);
        $this->assertStringNotContainsString('<input', $result['html']);
    }

    public function test_html_sanitizer_accepts_registered_news_image_and_canonicalizes_tag(): void
    {
        $img1 = NewsImage::factory()->editor()->create([
            'storage_key' => 'news/editor/550e8400-e29b-41d4-a716-446655440000.webp',
        ]);
        $img2 = NewsImage::factory()->editor()->create([
            'storage_key' => 'news/editor/660e8400-e29b-41d4-a716-446655440001.webp',
        ]);

        $html = sprintf(
            '<p>First paragraph</p><figure><img data-image-id="%d" src="https://pub-r2.waai.test/%s" alt="Chart 1" /><figcaption>Caption</figcaption></figure><p>Second</p><img src="https://pub-r2.waai.test/%s" alt="Chart 2" />',
            $img1->id,
            $img1->storage_key,
            $img2->storage_key
        );

        $result = $this->sanitizer->sanitizeAndExtractImages($html, 'content_en');

        $this->assertSame([$img1->id, $img2->id], $result['image_ids']);
        $this->assertStringContainsString('data-image-id="'.$img1->id.'"', $result['html']);
        $this->assertStringContainsString('data-image-id="'.$img2->id.'"', $result['html']);
        $this->assertStringContainsString('src="https://pub-r2.waai.test/'.$img1->storage_key.'"', $result['html']);
        $this->assertStringContainsString('src="https://pub-r2.waai.test/'.$img2->storage_key.'"', $result['html']);
        $this->assertStringContainsString('<figure>', $result['html']);
        $this->assertStringContainsString('<figcaption>Caption</figcaption>', $result['html']);
    }
}
