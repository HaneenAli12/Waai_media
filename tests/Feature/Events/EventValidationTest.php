<?php

namespace Tests\Feature\Events;

use App\Http\Requests\Admin\Events\IndexEventRequest;
use App\Http\Requests\Admin\Events\PublishEventRequest;
use App\Http\Requests\Admin\Events\StoreEventRequest;
use App\Http\Requests\Admin\Events\UpdateEventRequest;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class EventValidationTest extends TestCase
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
    // StoreEventRequest Tests
    // =========================================================================

    public function test_store_event_request_authorizes_admin_and_editor_only(): void
    {
        $request = new StoreEventRequest;

        $this->assertFalse($request->authorize());

        $request->setUserResolver(fn () => $this->editor);
        $this->assertTrue($request->authorize());

        $request->setUserResolver(fn () => $this->admin);
        $this->assertTrue($request->authorize());
    }

    public function test_draft_with_only_english_title_passes_validation(): void
    {
        $request = new StoreEventRequest;
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make([
            'name_en' => 'Future Tech Conference 2026',
        ], $request->rules(), $request->messages());

        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
    }

    public function test_draft_with_only_arabic_title_is_rejected(): void
    {
        $request = new StoreEventRequest;
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make([
            'name_ar' => 'مؤتمر التقنية المستقبلي 2026',
        ], $request->rules(), $request->messages());

        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('name_en', $validator->errors()->toArray());
    }

    public function test_draft_with_non_latin_english_title_is_rejected(): void
    {
        $request = new StoreEventRequest;
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make([
            'name_en' => '!!! ???',
        ], $request->rules(), $request->messages());

        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('name_en', $validator->errors()->toArray());
    }

    public function test_draft_with_neither_title_is_rejected(): void
    {
        $request = new StoreEventRequest;
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make([
            'category_en' => 'Technology',
        ], $request->rules(), $request->messages());

        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('name_en', $validator->errors()->toArray());
    }

    public function test_draft_with_whitespace_only_titles_is_rejected(): void
    {
        $request = new StoreEventRequest;
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make([
            'name_en' => '   ',
            'name_ar' => '   ',
        ], $request->rules(), $request->messages());

        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('name_en', $validator->errors()->toArray());
    }

    public function test_draft_with_plain_bilingual_categories(): void
    {
        $request = new StoreEventRequest;
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make([
            'name_en' => 'AI Exhibition',
            'category_en' => 'Artificial Intelligence',
            'category_ar' => 'الذكاء الاصطناعي',
        ], $request->rules(), $request->messages());

        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
    }

    public function test_draft_rejects_category_exceeding_150_chars(): void
    {
        $request = new StoreEventRequest;
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make([
            'name_en' => 'AI Exhibition',
            'category_en' => str_repeat('A', 151),
        ], $request->rules(), $request->messages());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('category_en', $validator->errors()->toArray());
    }

    public function test_draft_with_valid_separate_event_date_and_time(): void
    {
        $request = StoreEventRequest::create('/api/v1/admin/events', 'POST', [
            'name_en' => 'Riyadh Tech Gathering',
            'event_date' => '2026-11-15',
            'event_time' => '14:30:00',
            'id' => 999,
            'status' => 'published',
            'published_at' => '2026-01-01 00:00:00',
            'slug' => 'malicious-slug',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);
        $this->assertTrue($validator->passes());

        $request->setValidator($validator);
        $validated = $request->validated();

        $this->assertSame('2026-11-15', $validated['event_date']);
        $this->assertSame('14:30:00', $validated['event_time']);

        // Server-controlled fields stripped
        $this->assertArrayNotHasKey('id', $validated);
        $this->assertArrayNotHasKey('status', $validated);
        $this->assertArrayNotHasKey('published_at', $validated);
        $this->assertArrayNotHasKey('slug', $validated);
    }

    public function test_draft_rejects_invalid_date_format(): void
    {
        $request = new StoreEventRequest;
        $validator = Validator::make([
            'name_en' => 'Tech Meetup',
            'event_date' => '15-11-2026',
        ], $request->rules(), $request->messages());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('event_date', $validator->errors()->toArray());
    }

    public function test_draft_rejects_invalid_time_format(): void
    {
        $request = new StoreEventRequest;
        $validator = Validator::make([
            'name_en' => 'Tech Meetup',
            'event_time' => '2:30 PM',
        ], $request->rules(), $request->messages());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('event_time', $validator->errors()->toArray());
    }

    // =========================================================================
    // UpdateEventRequest Tests
    // =========================================================================

    public function test_update_event_request_authorizes_admin_and_editor_only(): void
    {
        $request = new UpdateEventRequest;

        $this->assertFalse($request->authorize());

        $request->setUserResolver(fn () => $this->editor);
        $this->assertTrue($request->authorize());

        $request->setUserResolver(fn () => $this->admin);
        $this->assertTrue($request->authorize());
    }

    public function test_update_event_strips_lifecycle_fields(): void
    {
        $request = UpdateEventRequest::create('/api/v1/admin/events/1', 'PUT', [
            'name_en' => 'Updated Event Name',
            'status' => 'published',
            'id' => 123,
            'published_at' => '2026-01-01 00:00:00',
            'slug' => 'custom-slug',
        ]);
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $this->assertTrue($validator->passes());

        $request->setValidator($validator);
        $validated = $request->validated();

        $this->assertSame('Updated Event Name', $validated['name_en']);
        $this->assertArrayNotHasKey('status', $validated);
        $this->assertArrayNotHasKey('id', $validated);
        $this->assertArrayNotHasKey('published_at', $validated);
        $this->assertArrayNotHasKey('slug', $validated);
    }

    public function test_update_event_rejects_clearing_both_titles_on_draft(): void
    {
        $event = Event::factory()->draft()->create([
            'name_en' => 'Existing Name',
            'name_ar' => null,
        ]);

        $request = UpdateEventRequest::create("/api/v1/admin/events/{$event->id}", 'PATCH', [
            'name_en' => '   ',
            'name_ar' => '',
        ]);
        $request->setUserResolver(fn () => $this->editor);
        $request->setRouteResolver(fn () => (new Route('PATCH', 'api/v1/admin/events/{event}', []))->bind($request));
        $request->route()->setParameter('event', $event);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('name_en', $validator->errors()->toArray());
    }

    public function test_update_event_rejects_clearing_mandatory_fields_on_published_event(): void
    {
        $event = Event::factory()->published()->create();

        // 1. Attempting to clear English title on published event
        $request1 = UpdateEventRequest::create("/api/v1/admin/events/{$event->id}", 'PATCH', [
            'name_en' => '',
        ]);
        $request1->setUserResolver(fn () => $this->editor);
        $request1->setRouteResolver(fn () => (new Route('PATCH', 'api/v1/admin/events/{event}', []))->bind($request1));
        $request1->route()->setParameter('event', $event);

        $validator1 = Validator::make($request1->all(), $request1->rules(), $request1->messages());
        $request1->withValidator($validator1);
        $this->assertFalse($validator1->passes());
        $this->assertArrayHasKey('name_en', $validator1->errors()->toArray());

        // 2. Attempting to clear Arabic title on published event
        $request2 = UpdateEventRequest::create("/api/v1/admin/events/{$event->id}", 'PATCH', [
            'name_ar' => '',
        ]);
        $request2->setUserResolver(fn () => $this->editor);
        $request2->setRouteResolver(fn () => (new Route('PATCH', 'api/v1/admin/events/{event}', []))->bind($request2));
        $request2->route()->setParameter('event', $event);

        $validator2 = Validator::make($request2->all(), $request2->rules(), $request2->messages());
        $request2->withValidator($validator2);
        $this->assertFalse($validator2->passes());
        $this->assertArrayHasKey('name_ar', $validator2->errors()->toArray());

        // 3. Attempting to clear event_date on published event
        $request3 = UpdateEventRequest::create("/api/v1/admin/events/{$event->id}", 'PATCH', [
            'event_date' => null,
        ]);
        $request3->setUserResolver(fn () => $this->editor);
        $request3->setRouteResolver(fn () => (new Route('PATCH', 'api/v1/admin/events/{event}', []))->bind($request3));
        $request3->route()->setParameter('event', $event);

        $validator3 = Validator::make($request3->all(), $request3->rules(), $request3->messages());
        $request3->withValidator($validator3);
        $this->assertFalse($validator3->passes());
        $this->assertArrayHasKey('event_date', $validator3->errors()->toArray());
    }

    // =========================================================================
    // PublishEventRequest Tests
    // =========================================================================

    public function test_publish_event_request_authorizes_admin_and_editor_only(): void
    {
        $request = new PublishEventRequest;

        $this->assertFalse($request->authorize());

        $request->setUserResolver(fn () => $this->editor);
        $this->assertTrue($request->authorize());

        $request->setUserResolver(fn () => $this->admin);
        $this->assertTrue($request->authorize());
    }

    public function test_publish_request_requires_both_bilingual_categories(): void
    {
        $payload = [
            'name_en' => 'Summit 2026',
            'name_ar' => 'قمة 2026',
            'place_en' => 'Center',
            'place_ar' => 'مركز',
            'event_date' => '2026-11-20',
            'event_time' => '10:00:00',
            'cover_image_url' => 'https://media.waaimedia.com/events/covers/sample.jpg',
        ];

        // Missing category_ar
        $req1 = new PublishEventRequest;
        $val1 = Validator::make(array_merge($payload, ['category_en' => 'Tech']), $req1->rules(), $req1->messages());
        $this->assertFalse($val1->passes());
        $this->assertArrayHasKey('category_ar', $val1->errors()->toArray());

        // Missing category_en
        $req2 = new PublishEventRequest;
        $val2 = Validator::make(array_merge($payload, ['category_ar' => 'تكنولوجيا']), $req2->rules(), $req2->messages());
        $this->assertFalse($val2->passes());
        $this->assertArrayHasKey('category_en', $val2->errors()->toArray());
    }

    public function test_publish_request_accepts_valid_payload(): void
    {
        $payload = [
            'name_en' => 'Minimal Publish Event',
            'name_ar' => 'فعالية بحد أدنى للنشر',
            'category_en' => 'Media',
            'category_ar' => 'الإعلام',
            'place_en' => 'Media City',
            'place_ar' => 'مدينة الإعلام',
            'event_date' => '2026-12-01',
            'event_time' => '10:00:00',
            'description_en' => 'English description of minimal event',
            'description_ar' => 'وصف الفعالية باللغة العربية',
            'url' => null,
        ];

        $request = new PublishEventRequest;
        // The event already has a stored cover, which satisfies publish readiness
        $event = Event::factory()->make(['cover_image_url' => 'events/covers/11111111-2222-4333-8444-555555555555.jpg']);
        $route = \Mockery::mock(Route::class);
        $route->shouldReceive('parameter')->andReturnUsing(fn ($name) => $name === 'event' ? $event : null);
        $request->setRouteResolver(fn () => $route);
        $validator = Validator::make($payload, $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
    }

    public function test_publish_request_rejects_english_title_without_latin_alphanumerics(): void
    {
        $payload = [
            'name_en' => '!!! ??? ---',
            'name_ar' => 'فعالية بحد أدنى للنشر',
            'category_en' => 'Media',
            'category_ar' => 'الإعلام',
            'place_en' => 'Media City',
            'place_ar' => 'مدينة الإعلام',
            'event_date' => '2026-12-01',
            'event_time' => '10:00:00',
            'cover_image_url' => 'https://media.waaimedia.com/events/covers/test.jpg',
        ];

        $request = new PublishEventRequest;
        $validator = Validator::make($payload, $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('name_en', $validator->errors()->toArray());
    }

    // =========================================================================
    // IndexEventRequest Tests
    // =========================================================================

    public function test_admin_index_event_request_validates_status_and_timeline(): void
    {
        $request = new IndexEventRequest;

        // Valid filters
        $validDraft = Validator::make(['status' => 'draft', 'timeline' => 'upcoming'], $request->rules(), $request->messages());
        $this->assertTrue($validDraft->passes());

        $validPublished = Validator::make(['status' => 'published', 'timeline' => 'past'], $request->rules(), $request->messages());
        $this->assertTrue($validPublished->passes());

        // Strictly disallow 'unpublished'
        $invalidStatus = Validator::make(['status' => 'unpublished'], $request->rules(), $request->messages());
        $this->assertFalse($invalidStatus->passes());
        $this->assertArrayHasKey('status', $invalidStatus->errors()->toArray());

        // Invalid timeline
        $invalidTimeline = Validator::make(['timeline' => 'current'], $request->rules(), $request->messages());
        $this->assertFalse($invalidTimeline->passes());
        $this->assertArrayHasKey('timeline', $invalidTimeline->errors()->toArray());
    }
}
