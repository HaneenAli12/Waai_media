<?php

namespace Tests\Feature\Events;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicEventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_index_returns_published_events_only_and_excludes_drafts(): void
    {
        $published = Event::factory()->published()->create([
            'name_en' => 'Public Tech Event',
            'name_ar' => 'فعالية تقنية عامة',
        ]);

        $draft = Event::factory()->draft()->create([
            'name_en' => 'Secret Draft Event',
            'name_ar' => 'فعالية سرية مسودة',
        ]);

        $response = $this->getJson('/api/v1/events');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id)
            ->assertJsonPath('data.0.name', 'Public Tech Event');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($published->id, $ids);
        $this->assertNotContains($draft->id, $ids);
    }

    public function test_public_index_returns_paginated_events_with_default_size_3(): void
    {
        Event::factory()->count(10)->published()->create();

        $response = $this->getJson('/api/v1/events?timeline=all');

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonCount(3, 'data');
    }

    public function test_public_index_supports_custom_per_page_3_for_calendar_page(): void
    {
        Event::factory()->count(7)->published()->create();

        $response = $this->getJson('/api/v1/events?timeline=all&per_page=3&page=1');

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 7)
            ->assertJsonCount(3, 'data');
    }

    public function test_public_index_pagination_navigation_prev_and_next(): void
    {
        Event::factory()->count(7)->published()->create();

        // Page 1: First page, previous should be null, next should be present
        $page1 = $this->getJson('/api/v1/events?timeline=all&per_page=3&page=1');
        $page1->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('links.prev', null);
        $this->assertNotNull($page1->json('links.next'));

        $page1Ids = collect($page1->json('data'))->pluck('id')->all();

        // Page 2: Middle page, both prev and next should be present
        $page2 = $this->getJson('/api/v1/events?timeline=all&per_page=3&page=2');
        $page2->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2);
        $this->assertNotNull($page2->json('links.prev'));
        $this->assertNotNull($page2->json('links.next'));

        $page2Ids = collect($page2->json('data'))->pluck('id')->all();

        // Page 3: Last page (7 total / 3 per page = 3 pages, page 3 has 1 item), next should be null
        $page3 = $this->getJson('/api/v1/events?timeline=all&per_page=3&page=3');
        $page3->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('links.next', null);
        $this->assertNotNull($page3->json('links.prev'));

        $page3Ids = collect($page3->json('data'))->pluck('id')->all();

        // Verify distinct items across pages (no duplicates or skipped items)
        $allRetrievedIds = array_merge($page1Ids, $page2Ids, $page3Ids);
        $this->assertCount(7, array_unique($allRetrievedIds));
    }

    public function test_homepage_shows_latest_three_published_events_ordered_by_created_at_descending(): void
    {
        // Create 5 events with distinct created_at times and contrasting event_dates
        // to ensure ordering is strictly by created_at DESC, not by event_date
        $event1 = Event::factory()->published()->create([
            'name_en' => 'Event 1 (Oldest Created)',
            'created_at' => now()->subHours(5),
            'event_date' => now()->addDays(30),
        ]);

        $event2 = Event::factory()->published()->create([
            'name_en' => 'Event 2',
            'created_at' => now()->subHours(4),
            'event_date' => now()->addDays(20),
        ]);

        $event3 = Event::factory()->published()->create([
            'name_en' => 'Event 3',
            'created_at' => now()->subHours(3),
            'event_date' => now()->addDays(10),
        ]);

        $event4 = Event::factory()->published()->create([
            'name_en' => 'Event 4',
            'created_at' => now()->subHours(2),
            'event_date' => now()->addDays(50),
        ]);

        $event5 = Event::factory()->published()->create([
            'name_en' => 'Event 5 (Newest Created)',
            'created_at' => now()->subHours(1),
            'event_date' => now()->addDays(5),
        ]);

        // Draft event should be strictly excluded
        Event::factory()->draft()->create([
            'name_en' => 'Draft Event (Newest But Unapproved)',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/events?timeline=latest&per_page=3');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');

        $data = $response->json('data');

        // Must be exactly the 3 newest created events: Event 5, Event 4, Event 3
        $this->assertSame($event5->id, $data[0]['id']);
        $this->assertSame($event4->id, $data[1]['id']);
        $this->assertSame($event3->id, $data[2]['id']);

        // Must contain data required by homepage UI: title, date, cover_image_url, slug
        $this->assertNotEmpty($data[0]['name']);
        $this->assertNotEmpty($data[0]['event_date']);
        $this->assertNotEmpty($data[0]['slug']);
        $this->assertArrayHasKey('cover_image_url', $data[0]);
    }

    public function test_public_index_filters_by_category(): void
    {
        $techEvent = Event::factory()->published()->create([
            'name_en' => 'Tech Meetup',
            'category_en' => 'Technology',
            'category_ar' => 'التكنولوجيا',
        ]);

        $healthEvent = Event::factory()->published()->create([
            'name_en' => 'Health Expo',
            'category_en' => 'Health',
            'category_ar' => 'الصحة',
        ]);

        $response = $this->getJson('/api/v1/events?category=Technology');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $techEvent->id);
    }

    public function test_public_index_filters_by_upcoming_and_past_timeline(): void
    {
        $upcoming = Event::factory()->published()->upcoming(10)->create([
            'name_en' => 'Future Event',
        ]);

        $past = Event::factory()->published()->past(10)->create([
            'name_en' => 'Past Event',
        ]);

        // Default / upcoming
        $upcomingResponse = $this->getJson('/api/v1/events?timeline=upcoming');
        $upcomingResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $upcoming->id);

        // Past
        $pastResponse = $this->getJson('/api/v1/events?timeline=past');
        $pastResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $past->id);

        // All
        $allResponse = $this->getJson('/api/v1/events?timeline=all');
        $allResponse->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_public_show_retrieves_published_event_by_slug(): void
    {
        $event = Event::factory()->published()->create([
            'name_en' => 'Annual Design Conference',
            'slug' => 'annual-design-conference-55',
            'place_en' => 'Design Museum',
            'description_en' => 'World class design discussions.',
        ]);

        $response = $this->getJson("/api/v1/events/{$event->slug}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.slug', 'annual-design-conference-55')
            ->assertJsonPath('data.name', 'Annual Design Conference')
            ->assertJsonPath('data.place', 'Design Museum')
            ->assertJsonPath('data.description', 'World class design discussions.');
    }

    public function test_public_show_returns_404_for_draft_event_even_with_slug(): void
    {
        $draft = Event::factory()->draft()->create([
            'slug' => 'draft-event-with-slug-99',
        ]);

        $response = $this->getJson("/api/v1/events/{$draft->slug}");

        $response->assertStatus(404);
    }

    public function test_public_show_returns_404_for_non_existent_slug(): void
    {
        $this->getJson('/api/v1/events/non-existent-event-slug')->assertStatus(404);
    }

    public function test_public_endpoints_localize_content_according_to_accept_language_header(): void
    {
        $event = Event::factory()->published()->create([
            'name_en' => 'English Name',
            'name_ar' => 'الاسم بالعربية',
            'category_en' => 'Technology',
            'category_ar' => 'التكنولوجيا',
            'place_en' => 'Riyadh Hall',
            'place_ar' => 'قاعة الرياض',
            'description_en' => 'English Description',
            'description_ar' => 'الوصف بالعربية',
            'event_date' => '2026-11-20',
            'event_time' => '10:00:00',
            'slug' => 'bilingual-event-101',
        ]);

        // Request with Accept-Language: ar
        $arResponse = $this->getJson("/api/v1/events/{$event->slug}", [
            'Accept-Language' => 'ar,en;q=0.8',
        ]);

        $arResponse->assertStatus(200)
            ->assertJsonPath('data.name', 'الاسم بالعربية')
            ->assertJsonPath('data.name_en', 'English Name')
            ->assertJsonPath('data.name_ar', 'الاسم بالعربية')
            ->assertJsonPath('data.category', 'التكنولوجيا')
            ->assertJsonPath('data.category_en', 'Technology')
            ->assertJsonPath('data.category_ar', 'التكنولوجيا')
            ->assertJsonPath('data.place', 'قاعة الرياض')
            ->assertJsonPath('data.place_en', 'Riyadh Hall')
            ->assertJsonPath('data.place_ar', 'قاعة الرياض')
            ->assertJsonPath('data.description', 'الوصف بالعربية')
            ->assertJsonPath('data.event_date', '2026-11-20')
            ->assertJsonPath('data.event_time', '10:00:00');

        // Request with Accept-Language: en
        $enResponse = $this->getJson("/api/v1/events/{$event->slug}", [
            'Accept-Language' => 'en-US,en;q=0.9',
        ]);

        $enResponse->assertStatus(200)
            ->assertJsonPath('data.name', 'English Name')
            ->assertJsonPath('data.name_en', 'English Name')
            ->assertJsonPath('data.name_ar', 'الاسم بالعربية')
            ->assertJsonPath('data.category', 'Technology')
            ->assertJsonPath('data.category_en', 'Technology')
            ->assertJsonPath('data.category_ar', 'التكنولوجيا')
            ->assertJsonPath('data.place', 'Riyadh Hall')
            ->assertJsonPath('data.place_en', 'Riyadh Hall')
            ->assertJsonPath('data.place_ar', 'قاعة الرياض')
            ->assertJsonPath('data.description', 'English Description')
            ->assertJsonPath('data.event_date', '2026-11-20')
            ->assertJsonPath('data.event_time', '10:00:00');
    }
}
