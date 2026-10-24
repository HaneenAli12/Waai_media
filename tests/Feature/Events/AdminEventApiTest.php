<?php

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminEventApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->editor = User::factory()->editor()->create();
    }

    // =========================================================================
    // 1. Authentication & Role Authorization
    // =========================================================================

    public function test_unauthenticated_requests_are_denied(): void
    {
        $event = Event::factory()->draft()->create();

        $this->getJson('/api/v1/admin/events')->assertStatus(401);
        $this->postJson('/api/v1/admin/events', [])->assertStatus(401);
        $this->getJson("/api/v1/admin/events/{$event->id}")->assertStatus(401);
        $this->patchJson("/api/v1/admin/events/{$event->id}", [])->assertStatus(401);
        $this->postJson("/api/v1/admin/events/{$event->id}/publish", [])->assertStatus(401);
        $this->deleteJson("/api/v1/admin/events/{$event->id}")->assertStatus(401);
    }

    public function test_admin_and_editor_can_list_and_view_events(): void
    {
        $event = Event::factory()->draft()->create();

        // Admin
        Sanctum::actingAs($this->admin, ['access']);
        $this->getJson('/api/v1/admin/events')->assertStatus(200);
        $this->getJson("/api/v1/admin/events/{$event->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $event->id);

        // Editor
        Sanctum::actingAs($this->editor, ['access']);
        $this->getJson('/api/v1/admin/events')->assertStatus(200);
        $this->getJson("/api/v1/admin/events/{$event->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $event->id);
    }

    public function test_editor_cannot_delete_event(): void
    {
        $event = Event::factory()->draft()->create();

        Sanctum::actingAs($this->editor, ['access']);
        $response = $this->deleteJson("/api/v1/admin/events/{$event->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    public function test_admin_can_permanently_delete_event(): void
    {
        $event = Event::factory()->draft()->create();

        Sanctum::actingAs($this->admin, ['access']);
        $response = $this->deleteJson("/api/v1/admin/events/{$event->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Event permanently deleted successfully.']);

        $this->assertDatabaseMissing('events', ['id' => $event->id]);
    }

    // =========================================================================
    // 2. Draft Creation (Store)
    // =========================================================================

    public function test_can_create_draft_with_arabic_only_title(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $response = $this->postJson('/api/v1/admin/events', [
            'name_ar' => 'ملتقى ريادة الأعمال 2026',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.name_ar', 'ملتقى ريادة الأعمال 2026')
            ->assertJsonPath('data.name_en', null)
            ->assertJsonPath('data.slug', null)
            ->assertJsonPath('data.published_at', null);

        $this->assertDatabaseHas('events', [
            'name_ar' => 'ملتقى ريادة الأعمال 2026',
            'status' => 'draft',
            'slug' => null,
        ]);
    }

    public function test_can_create_draft_with_english_title_and_derives_slug_with_id(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $response = $this->postJson('/api/v1/admin/events', [
            'name_en' => 'Future Media Summit',
            'category_en' => 'Media',
            'place_en' => 'Riyadh Hub',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.name_en', 'Future Media Summit');

        $eventId = $response->json('data.id');
        $this->assertSame("future-media-summit-{$eventId}", $response->json('data.slug'));

        $this->assertDatabaseHas('events', [
            'id' => $eventId,
            'slug' => "future-media-summit-{$eventId}",
            'status' => 'draft',
        ]);
    }

    public function test_can_create_draft_with_free_text_categories(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $response = $this->postJson('/api/v1/admin/events', [
            'name_en' => 'Digital Media Expo',
            'name_ar' => 'معرض الإعلام الرقمي',
            'category_en' => 'Digital Journalism',
            'category_ar' => 'الصحافة الرقمية',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.category_en', 'Digital Journalism')
            ->assertJsonPath('data.category_ar', 'الصحافة الرقمية');

        $eventId = $response->json('data.id');
        $this->assertDatabaseHas('events', [
            'id' => $eventId,
            'category_en' => 'Digital Journalism',
            'category_ar' => 'الصحافة الرقمية',
        ]);
    }

    public function test_draft_creation_converts_local_datetime_and_iana_timezone_to_utc(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $response = $this->postJson('/api/v1/admin/events', [
            'name_en' => 'Cairo Tech Gathering',
            'event_date' => '2026-11-15 18:00:00',
            'timezone' => 'Africa/Cairo',
        ]);

        $response->assertStatus(201);

        // Cairo in winter is UTC+2 -> 16:00:00 UTC
        $this->assertDatabaseHas('events', [
            'name_en' => 'Cairo Tech Gathering',
            'event_date' => '2026-11-15 16:00:00',
        ]);

        $response->assertJsonPath('data.event_date', '2026-11-15T16:00:00.000000Z');
    }

    public function test_can_create_and_update_draft_with_bilingual_author_names(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $response = $this->postJson('/api/v1/admin/events', [
            'name_en' => 'Author Test Summit',
            'author_name_en' => 'Sarah Connor',
            'author_name_ar' => 'سارة كونور',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.author_name_en', 'Sarah Connor')
            ->assertJsonPath('data.author_name_ar', 'سارة كونور');

        $eventId = $response->json('data.id');

        $this->assertDatabaseHas('events', [
            'id' => $eventId,
            'author_name_en' => 'Sarah Connor',
            'author_name_ar' => 'سارة كونور',
        ]);

        // Update authors
        $updateResponse = $this->patchJson("/api/v1/admin/events/{$eventId}", [
            'author_name_en' => 'Sarah Connor Updated',
            'author_name_ar' => 'سارة كونور المحدثة',
        ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.author_name_en', 'Sarah Connor Updated')
            ->assertJsonPath('data.author_name_ar', 'سارة كونور المحدثة');

        $this->assertDatabaseHas('events', [
            'id' => $eventId,
            'author_name_en' => 'Sarah Connor Updated',
            'author_name_ar' => 'سارة كونور المحدثة',
        ]);
    }

    public function test_draft_creation_strips_lifecycle_attributes(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $response = $this->postJson('/api/v1/admin/events', [
            'name_en' => 'Injected Title',
            'status' => 'published',
            'published_at' => '2026-01-01 00:00:00',
            'slug' => 'custom-injected-slug',
            'id' => 8888,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', null);

        $id = $response->json('data.id');
        $this->assertNotEquals(8888, $id);
        $this->assertSame("injected-title-{$id}", $response->json('data.slug'));
    }

    // =========================================================================
    // 3. Update
    // =========================================================================

    public function test_can_update_event_partially(): void
    {
        $event = Event::factory()->draft()->create([
            'name_en' => 'Old Title',
            'category_en' => 'Original Category',
        ]);

        Sanctum::actingAs($this->admin, ['access']);

        $response = $this->patchJson("/api/v1/admin/events/{$event->id}", [
            'category_en' => 'Updated Category',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name_en', 'Old Title')
            ->assertJsonPath('data.category_en', 'Updated Category');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'category_en' => 'Updated Category',
        ]);
    }

    public function test_update_regenerates_slug_for_draft_when_english_name_changes(): void
    {
        $event = Event::factory()->draft()->create([
            'name_en' => 'Draft Initial Title',
            'slug' => null,
        ]);

        Sanctum::actingAs($this->editor, ['access']);

        $response = $this->patchJson("/api/v1/admin/events/{$event->id}", [
            'name_en' => 'New Draft Name',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.slug', "new-draft-name-{$event->id}");
    }

    public function test_update_preserves_immutable_slug_once_published(): void
    {
        $event = Event::factory()->published()->create([
            'name_en' => 'Original Published Name',
            'slug' => 'immutable-slug-999',
        ]);

        Sanctum::actingAs($this->admin, ['access']);

        $response = $this->patchJson("/api/v1/admin/events/{$event->id}", [
            'name_en' => 'Completely Different Name',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.slug', 'immutable-slug-999');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'slug' => 'immutable-slug-999',
            'name_en' => 'Completely Different Name',
        ]);
    }

    // =========================================================================
    // 4. Publishing
    // =========================================================================

    public function test_publishing_draft_validates_mandatory_completeness(): void
    {
        // An incomplete draft with only titles
        $event = Event::factory()->draft()->create([
            'name_en' => 'Incomplete Event',
            'name_ar' => 'فعالية غير مكتملة',
            'place_en' => null,
            'place_ar' => null,
            'category_en' => null,
            'category_ar' => null,
            'event_date' => null,
            'cover_image_url' => null,
        ]);

        Sanctum::actingAs($this->admin, ['access']);

        $response = $this->postJson("/api/v1/admin/events/{$event->id}/publish", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['place_en', 'place_ar', 'category_en', 'category_ar', 'event_date', 'cover_image_url']);
    }

    public function test_publishing_completes_successfully_and_sets_published_at_and_slug(): void
    {
        $event = Event::factory()->draft()->create([
            'name_en' => 'Ready to Publish Event',
            'name_ar' => 'فعالية جاهزة للنشر',
            'slug' => null,
            'published_at' => null,
        ]);

        Sanctum::actingAs($this->admin, ['access']);

        $response = $this->postJson("/api/v1/admin/events/{$event->id}/publish", [
            'category_en' => 'Technology',
            'category_ar' => 'التكنولوجيا',
            'place_en' => 'Riyadh Center',
            'place_ar' => 'مركز الرياض',
            'event_date' => '2026-11-25 10:00:00',
            'timezone' => 'Asia/Riyadh',
            'cover_image_url' => 'https://media.waaimedia.com/events/covers/sample.jpg',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.slug', "ready-to-publish-event-{$event->id}");

        $this->assertNotNull($response->json('data.published_at'));

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => 'published',
            'slug' => "ready-to-publish-event-{$event->id}",
            // 10:00 Riyadh (UTC+3) -> 07:00 UTC
            'event_date' => '2026-11-25 07:00:00',
        ]);
    }

    public function test_republishing_preserves_initial_published_at_timestamp(): void
    {
        $initialPublishedAt = now()->subDays(5)->startOfSecond();
        $event = Event::factory()->published()->create([
            'published_at' => $initialPublishedAt,
        ]);

        Sanctum::actingAs($this->editor, ['access']);

        $response = $this->postJson("/api/v1/admin/events/{$event->id}/publish", [
            'name_en' => 'Updated Published Name',
        ]);

        $response->assertStatus(200);
        $this->assertSame($initialPublishedAt->toISOString(), $response->json('data.published_at'));
    }

    // =========================================================================
    // 5. Listing & Filtering
    // =========================================================================

    public function test_admin_index_returns_paginated_events_with_default_size_10_and_supports_custom_per_page(): void
    {
        Event::factory()->count(25)->draft()->create();

        Sanctum::actingAs($this->admin, ['access']);

        // Default pagination: 10 per page
        $defaultResponse = $this->getJson('/api/v1/admin/events');
        $defaultResponse->assertStatus(200)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(10, 'data');

        // Custom per_page (e.g. per_page=5)
        $customResponse = $this->getJson('/api/v1/admin/events?per_page=5');
        $customResponse->assertStatus(200)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonCount(5, 'data');
    }

    public function test_publish_merges_persisted_fields_and_requires_only_missing_ones(): void
    {
        // Create a near-complete draft — only cover_image_url is missing
        $event = Event::factory()->draft()->create([
            'name_en' => 'Nearly Complete Event',
            'name_ar' => 'فعالية شبه مكتملة',
            'category_en' => 'Media',
            'category_ar' => 'الإعلام',
            'place_en' => 'Jeddah Convention Center',
            'place_ar' => 'مركز جدة للمؤتمرات',
            'event_date' => '2026-12-01 14:00:00', // already in UTC
            'cover_image_url' => null,
        ]);

        Sanctum::actingAs($this->admin, ['access']);

        // Supply only the missing cover_image_url at publish time
        $response = $this->postJson("/api/v1/admin/events/{$event->id}/publish", [
            'cover_image_url' => 'https://media.waaimedia.com/events/covers/final.jpg',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.name_en', 'Nearly Complete Event')
            ->assertJsonPath('data.name_ar', 'فعالية شبه مكتملة')
            ->assertJsonPath('data.cover_image_url', 'https://media.waaimedia.com/events/covers/final.jpg');

        $this->assertNotNull($response->json('data.published_at'));

        // Verify persisted fields were preserved
        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => 'published',
            'category_en' => 'Media',
            'place_en' => 'Jeddah Convention Center',
            'event_date' => '2026-12-01 14:00:00',
            'cover_image_url' => 'https://media.waaimedia.com/events/covers/final.jpg',
        ]);
    }

    public function test_admin_index_filters_by_search_category_status_and_timeline(): void
    {
        Event::factory()->draft()->create([
            'name_en' => 'Artificial Intelligence Symposium',
            'category_en' => 'Tech',
            'event_date' => now()->addDays(5),
        ]);
        Event::factory()->published()->create([
            'name_en' => 'Healthcare Forum',
            'category_en' => 'Health',
            'event_date' => now()->subDays(5),
        ]);

        Sanctum::actingAs($this->admin, ['access']);

        // Search
        $this->getJson('/api/v1/admin/events?search=Intelligence')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name_en', 'Artificial Intelligence Symposium');

        // Category
        $this->getJson('/api/v1/admin/events?category=Health')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name_en', 'Healthcare Forum');

        // Status
        $this->getJson('/api/v1/admin/events?status=draft')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Timeline
        $this->getJson('/api/v1/admin/events?timeline=upcoming')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name_en', 'Artificial Intelligence Symposium');
    }

    public function test_admin_search_is_case_insensitive(): void
    {
        $event = Event::factory()->draft()->create([
            'name_en' => 'Future Tech Summit 2026',
        ]);

        Sanctum::actingAs($this->admin, ['access']);

        // Search with lowercase 'tech summit'
        $lowerResponse = $this->getJson('/api/v1/admin/events?search=tech%20summit');
        $lowerResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $event->id);

        // Search with uppercase 'TECH SUMMIT'
        $upperResponse = $this->getJson('/api/v1/admin/events?search=TECH%20SUMMIT');
        $upperResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $event->id);
    }

    public function test_admin_search_escapes_sql_wildcards_and_does_not_return_unmatched_records(): void
    {
        // One event with a literal percent sign in title, another event without it
        $eventWithPercent = Event::factory()->draft()->create([
            'name_en' => 'Growth of 50% in Media Sector',
        ]);
        $eventWithUnderscore = Event::factory()->draft()->create([
            'name_en' => 'Event_Special_Edition',
        ]);
        $eventWithoutSpecialChars = Event::factory()->draft()->create([
            'name_en' => 'EventASpecialBEdition',
        ]);

        Sanctum::actingAs($this->admin, ['access']);

        // Searching for literal '%' must match ONLY $eventWithPercent and not return all records
        $responsePercent = $this->getJson('/api/v1/admin/events?search=%');
        $responsePercent->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eventWithPercent->id)
            ->assertJsonPath('data.0.name_en', 'Growth of 50% in Media Sector');

        $idsPercent = collect($responsePercent->json('data'))->pluck('id')->all();
        $this->assertNotContains($eventWithUnderscore->id, $idsPercent);
        $this->assertNotContains($eventWithoutSpecialChars->id, $idsPercent);

        // Searching for literal '_' must match ONLY $eventWithUnderscore and not treat '_' as any single character
        $responseUnderscore = $this->getJson('/api/v1/admin/events?search=_');
        $responseUnderscore->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eventWithUnderscore->id);

        $idsUnderscore = collect($responseUnderscore->json('data'))->pluck('id')->all();
        $this->assertNotContains($eventWithoutSpecialChars->id, $idsUnderscore);
    }

    public function test_can_publish_arabic_only_draft_by_supplying_english_title_at_publish_time(): void
    {
        // 1. Create a draft with Arabic-only title
        Sanctum::actingAs($this->admin, ['access']);

        $draftResponse = $this->postJson('/api/v1/admin/events', [
            'name_ar' => 'ملتقى الابتكار والإعلام 2026',
            'category_ar' => 'الإعلام',
            'place_ar' => 'مركز المؤتمرات بالرياض',
            'description_ar' => 'وصف تفصيلي للفعالية باللغة العربية.',
        ]);

        $draftResponse->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.name_ar', 'ملتقى الابتكار والإعلام 2026')
            ->assertJsonPath('data.name_en', null)
            ->assertJsonPath('data.slug', null)
            ->assertJsonPath('data.published_at', null);

        $eventId = $draftResponse->json('data.id');

        $this->assertDatabaseHas('events', [
            'id' => $eventId,
            'name_ar' => 'ملتقى الابتكار والإعلام 2026',
            'name_en' => null,
            'slug' => null,
            'status' => 'draft',
            'published_at' => null,
        ]);

        // 2. Publish draft by supplying English title and remaining mandatory publication fields
        $publishResponse = $this->postJson("/api/v1/admin/events/{$eventId}/publish", [
            'name_en' => 'Media and Innovation Forum 2026',
            'category_en' => 'Media',
            'place_en' => 'Riyadh Convention Center',
            'event_date' => '2026-12-10 09:00:00',
            'timezone' => 'Asia/Riyadh',
            'cover_image_url' => 'https://media.waaimedia.com/events/covers/innovation-2026.jpg',
            'author_name_en' => 'Tariq Al-Mansoor',
            'author_name_ar' => 'طارق المنصور',
        ]);

        $publishResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.name_en', 'Media and Innovation Forum 2026')
            ->assertJsonPath('data.name_ar', 'ملتقى الابتكار والإعلام 2026')
            ->assertJsonPath('data.slug', "media-and-innovation-forum-2026-{$eventId}")
            ->assertJsonPath('data.author_name_en', 'Tariq Al-Mansoor')
            ->assertJsonPath('data.author_name_ar', 'طارق المنصور')
            ->assertJsonPath('data.cover_image_url', 'https://media.waaimedia.com/events/covers/innovation-2026.jpg');

        $this->assertNotNull($publishResponse->json('data.published_at'));

        // 3. Verify database state
        $this->assertDatabaseHas('events', [
            'id' => $eventId,
            'status' => 'published',
            'slug' => "media-and-innovation-forum-2026-{$eventId}",
            'name_en' => 'Media and Innovation Forum 2026',
            'name_ar' => 'ملتقى الابتكار والإعلام 2026',
            // 09:00 Riyadh (UTC+3) -> 06:00:00 UTC
            'event_date' => '2026-12-10 06:00:00',
            'author_name_en' => 'Tariq Al-Mansoor',
            'author_name_ar' => 'طارق المنصور',
        ]);

        // 4. Verify public slug route resolves the published event
        $publicResponse = $this->getJson("/api/v1/events/media-and-innovation-forum-2026-{$eventId}");
        $publicResponse->assertStatus(200)
            ->assertJsonPath('data.id', $eventId)
            ->assertJsonPath('data.slug', "media-and-innovation-forum-2026-{$eventId}")
            ->assertJsonPath('data.name_en', 'Media and Innovation Forum 2026')
            ->assertJsonPath('data.name_ar', 'ملتقى الابتكار والإعلام 2026');
    }
}
