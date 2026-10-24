<?php

namespace App\Services\Events;

use App\Models\Event;
use Illuminate\Support\Str;

class EventSlugService
{
    /**
     * Normalize an English name string into a URL-friendly slug base.
     *
     * Returns null if the name is empty, contains only whitespace,
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
     * Generate a definitive event slug from an English name and persisted database ID.
     *
     * Expected format: {normalized-name-en}-{event-id} (e.g. tech-summit-2026-125).
     * Returns null if the English name is absent or normalizes to an empty string.
     * Never falls back to Arabic text or generic placeholders like "event".
     */
    public function generateSlug(?string $titleEn, int $eventId): ?string
    {
        $normalized = $this->normalizeTitle($titleEn);

        if ($normalized === null) {
            return null;
        }

        return "{$normalized}-{$eventId}";
    }

    /**
     * Resolve the appropriate slug for an event update operation.
     *
     * Rules:
     * 1. If the event has already been published at least once (published_at is not null),
     *    the slug is permanently immutable. The existing slug is returned unchanged.
     * 2. If the English name was not included in the update payload (partial update),
     *    the existing slug is preserved.
     * 3. If the English name was provided and the event has never been published,
     *    a new slug is generated with the same event ID suffix (or null if title is empty).
     */
    public function resolveSlugForUpdate(Event $event, ?string $newTitleEn, bool $titleEnProvided): ?string
    {
        // Rule 1: Permanent immutability once published
        if ($event->hasBeenPublished()) {
            return $event->slug;
        }

        // Rule 2: Partial update without name_en preserves existing slug
        if (! $titleEnProvided) {
            return $event->slug;
        }

        // Rule 3: Re-derive slug before first publication
        return $this->generateSlug($newTitleEn, $event->id);
    }

    /**
     * Determine whether the event is eligible for slug regeneration.
     */
    public function canRegenerateSlug(Event $event): bool
    {
        return ! $event->hasBeenPublished();
    }
}
