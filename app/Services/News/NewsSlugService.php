<?php

namespace App\Services\News;

use App\Models\NewsArticle;
use Illuminate\Support\Str;

class NewsSlugService
{
    /**
     * Normalize an English title string into a URL-friendly slug base.
     *
     * Returns null if the title is empty, contains only whitespace,
     * or normalizes to an empty string (e.g. Arabic-only or punctuation-only text).
     */
    public function normalizeTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $trimmed = trim($title);
        if ($trimmed === '') {
            return null;
        }

        // Must contain Latin alphanumeric characters. Arabic text or non-Latin scripts must never generate a slug.
        if (! preg_match('/[a-zA-Z0-9]/', $trimmed)) {
            return null;
        }

        $slug = Str::slug($trimmed, '-', 'en');

        return $slug !== '' ? $slug : null;
    }

    /**
     * Generate a definitive article slug from an English title and persisted database ID.
     *
     * Expected format: {normalized-title-en}-{article-id} (e.g. breaking-news-125).
     * Returns null if the English title is absent or normalizes to an empty string.
     * Never falls back to Arabic text or generic placeholders like "news".
     */
    public function generateSlug(?string $titleEn, int $articleId): ?string
    {
        $normalized = $this->normalizeTitle($titleEn);

        if ($normalized === null) {
            return null;
        }

        return "{$normalized}-{$articleId}";
    }

    /**
     * Resolve the appropriate slug for an article update operation.
     *
     * Rules:
     * 1. If the article has already been published at least once (published_at is not null),
     *    the slug is permanently immutable. The existing slug is returned unchanged.
     * 2. If the English title was not included in the update payload (partial update),
     *    the existing slug is preserved.
     * 3. If the English title was provided and the article has never been published,
     *    a new slug is generated with the same article ID suffix (or null if title is empty).
     */
    public function resolveSlugForUpdate(NewsArticle $article, ?string $newTitleEn, bool $titleEnProvided): ?string
    {
        // Rule 1: Permanent immutability once published
        if ($article->hasBeenPublished()) {
            return $article->slug;
        }

        // Rule 2: Partial update without title_en preserves existing slug
        if (! $titleEnProvided) {
            return $article->slug;
        }

        // Rule 3: Re-derive slug before first publication
        return $this->generateSlug($newTitleEn, $article->id);
    }

    /**
     * Determine whether the article is eligible for slug regeneration.
     */
    public function canRegenerateSlug(NewsArticle $article): bool
    {
        return ! $article->hasBeenPublished();
    }
}
