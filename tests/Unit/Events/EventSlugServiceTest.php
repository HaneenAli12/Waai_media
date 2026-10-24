<?php

namespace Tests\Unit\Events;

use App\Models\Event;
use App\Services\Events\EventSlugService;
use Tests\TestCase;

class EventSlugServiceTest extends TestCase
{
    private EventSlugService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EventSlugService;
    }

    public function test_normalize_title_returns_null_for_empty_or_whitespace(): void
    {
        $this->assertNull($this->service->normalizeTitle(null));
        $this->assertNull($this->service->normalizeTitle(''));
        $this->assertNull($this->service->normalizeTitle('   '));
    }

    public function test_normalize_title_returns_null_for_arabic_or_non_latin_text(): void
    {
        $this->assertNull($this->service->normalizeTitle('مؤتمر التقنية السنوي'));
        $this->assertNull($this->service->normalizeTitle('!!! ??? ---'));
    }

    public function test_normalize_title_slugifies_latin_text(): void
    {
        $this->assertSame('annual-tech-summit-2026', $this->service->normalizeTitle('Annual Tech Summit 2026!'));
        $this->assertSame('ai-revolution', $this->service->normalizeTitle('   AI Revolution   '));
    }

    public function test_generate_slug_appends_event_id_deterministically(): void
    {
        $this->assertSame('tech-expo-42', $this->service->generateSlug('Tech Expo', 42));
        $this->assertNull($this->service->generateSlug(null, 42));
        $this->assertNull($this->service->generateSlug('معرض التقنية', 42));
    }

    public function test_resolve_slug_for_update_preserves_slug_once_published(): void
    {
        $event = new Event;
        $event->setRawAttributes([
            'id' => 15,
            'slug' => 'original-slug-15',
            'status' => Event::STATUS_PUBLISHED,
            'published_at' => '2026-10-23 00:00:00',
        ], true);

        $resolved = $this->service->resolveSlugForUpdate($event, 'Brand New Title', true);
        $this->assertSame('original-slug-15', $resolved);
        $this->assertFalse($this->service->canRegenerateSlug($event));
    }

    public function test_resolve_slug_for_update_preserves_slug_if_title_en_not_provided(): void
    {
        $event = new Event;
        $event->id = 18;
        $event->slug = 'draft-slug-18';
        $event->status = Event::STATUS_DRAFT;
        $event->published_at = null;

        $resolved = $this->service->resolveSlugForUpdate($event, null, false);
        $this->assertSame('draft-slug-18', $resolved);
        $this->assertTrue($this->service->canRegenerateSlug($event));
    }

    public function test_resolve_slug_for_update_regenerates_slug_for_draft_when_title_en_provided(): void
    {
        $event = new Event;
        $event->id = 22;
        $event->slug = 'old-draft-slug-22';
        $event->status = Event::STATUS_DRAFT;
        $event->published_at = null;

        $resolved = $this->service->resolveSlugForUpdate($event, 'Updated Event Name', true);
        $this->assertSame('updated-event-name-22', $resolved);
    }
}
