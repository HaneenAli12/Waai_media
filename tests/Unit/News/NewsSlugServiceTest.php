<?php

namespace Tests\Unit\News;

use App\Models\NewsArticle;
use App\Services\News\NewsSlugService;
use Tests\TestCase;

class NewsSlugServiceTest extends TestCase
{
    private NewsSlugService $slugService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slugService = new NewsSlugService;
    }

    public function test_normalizes_english_title_into_slug_base(): void
    {
        $this->assertSame('breaking-news', $this->slugService->normalizeTitle('Breaking News'));
        $this->assertSame('saudi-arabia-tech-growth', $this->slugService->normalizeTitle('Saudi Arabia: Tech Growth!'));
        $this->assertSame('ai-revolution-2026', $this->slugService->normalizeTitle('AI & Revolution (2026)'));
    }

    public function test_arabic_and_empty_titles_normalize_to_null(): void
    {
        $this->assertNull($this->slugService->normalizeTitle(null));
        $this->assertNull($this->slugService->normalizeTitle(''));
        $this->assertNull($this->slugService->normalizeTitle('   '));
        // Arabic text only
        $this->assertNull($this->slugService->normalizeTitle('أخبار التقنية اليوم'));
        // Special characters only
        $this->assertNull($this->slugService->normalizeTitle('!@#$%^&*()_+'));
    }

    public function test_generates_slug_with_article_id_suffix(): void
    {
        $slug = $this->slugService->generateSlug('Saudi Vision 2030 Update', 142);
        $this->assertSame('saudi-vision-2030-update-142', $slug);
    }

    public function test_generate_slug_returns_null_when_english_title_is_missing_or_arabic_only(): void
    {
        $this->assertNull($this->slugService->generateSlug(null, 10));
        $this->assertNull($this->slugService->generateSlug('', 10));
        $this->assertNull($this->slugService->generateSlug('تقرير اقتصادي', 10));
    }

    public function test_slug_regenerates_before_first_publication_when_title_changes(): void
    {
        $article = new NewsArticle([
            'title_en' => 'Old Title',
            'slug' => 'old-title-50',
            'published_at' => null, // Never published
        ]);
        $article->id = 50;

        $newSlug = $this->slugService->resolveSlugForUpdate($article, 'New Title', true);
        $this->assertSame('new-title-50', $newSlug);
    }

    public function test_slug_is_permanently_immutable_after_first_publication(): void
    {
        $article = new NewsArticle([
            'title_en' => 'Original Published Title',
            'slug' => 'original-published-title-77',
            'published_at' => now()->subDay(), // Has been published
        ]);
        $article->id = 77;

        // Even with a completely new English title, slug must remain original
        $newSlug = $this->slugService->resolveSlugForUpdate($article, 'Completely Changed Title', true);
        $this->assertSame('original-published-title-77', $newSlug);
    }

    public function test_slug_remains_immutable_even_if_article_is_unpublished(): void
    {
        $article = new NewsArticle([
            'status' => NewsArticle::STATUS_UNPUBLISHED,
            'title_en' => 'Previously Published Article',
            'slug' => 'previously-published-article-88',
            'published_at' => now()->subDays(5), // published_at is never reset to null
        ]);
        $article->id = 88;

        $newSlug = $this->slugService->resolveSlugForUpdate($article, 'Brand New Title While Unpublished', true);
        $this->assertSame('previously-published-article-88', $newSlug);
    }

    public function test_partial_update_without_title_en_preserves_existing_slug(): void
    {
        $draftArticle = new NewsArticle([
            'title_en' => 'Draft Title',
            'slug' => 'draft-title-99',
            'published_at' => null,
        ]);
        $draftArticle->id = 99;

        // titleEnProvided is false
        $preservedSlug = $this->slugService->resolveSlugForUpdate($draftArticle, null, false);
        $this->assertSame('draft-title-99', $preservedSlug);
    }

    public function test_can_regenerate_slug_returns_true_for_drafts_and_false_for_published(): void
    {
        $draft = new NewsArticle(['published_at' => null]);
        $this->assertTrue($this->slugService->canRegenerateSlug($draft));

        $published = new NewsArticle(['published_at' => now()]);
        $this->assertFalse($this->slugService->canRegenerateSlug($published));
    }
}
