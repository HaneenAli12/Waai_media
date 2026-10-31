<?php

namespace App\Services\Portfolio;

use App\Models\PortfolioItem;
use Illuminate\Support\Str;

class PortfolioSlugService
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

        // Must contain Latin alphanumeric characters. Arabic text or non-Latin scripts must never generate a slug base.
        if (! preg_match('/[a-zA-Z0-9]/', $trimmed)) {
            return null;
        }

        $slug = Str::slug($trimmed, '-', 'en');

        return $slug !== '' ? $slug : null;
    }

    /**
     * Generate a definitive portfolio slug from an English title and persisted database ID.
     *
     * Expected format: {normalized-title-en}-{portfolio-id} (e.g. creative-branding-42).
     * Truncates the base so that the full slug (including the ID suffix) never exceeds 255 characters.
     * Returns null if the English title is absent or normalizes to an empty string.
     */
    public function generateSlug(?string $titleEn, int $portfolioId): ?string
    {
        $normalized = $this->normalizeTitle($titleEn);

        if ($normalized === null) {
            return null;
        }

        $idSuffix = "-{$portfolioId}";
        $maxBaseLength = 255 - strlen($idSuffix);

        $base = substr($normalized, 0, $maxBaseLength);
        $base = rtrim($base, '-');
        if ($base === '') {
            $base = 'portfolio';
        }

        return "{$base}{$idSuffix}";
    }

    /**
     * Resolve the appropriate slug for a portfolio item update operation.
     *
     * Rules:
     * 1. If the item has already been published at least once (published_at is not null),
     *    the slug is permanently immutable. The existing slug is returned unchanged.
     * 2. If the English title was not included in the update payload (partial update),
     *    the existing slug is preserved.
     * 3. If the English title was provided and the item has never been published,
     *    a new slug is generated with the same item ID suffix.
     */
    public function resolveSlugForUpdate(PortfolioItem $item, ?string $newTitleEn, bool $titleEnProvided): ?string
    {
        if ($item->hasBeenPublished()) {
            return $item->slug;
        }

        if (! $titleEnProvided) {
            return $item->slug;
        }

        return $this->generateSlug($newTitleEn, $item->id);
    }

    /**
     * Determine whether the portfolio item is eligible for slug regeneration.
     */
    public function canRegenerateSlug(PortfolioItem $item): bool
    {
        return ! $item->hasBeenPublished();
    }
}
