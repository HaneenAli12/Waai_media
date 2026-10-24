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

    public function test_draft_with_only_arabic_title_passes_validation(): void
    {
        $request = new StoreEventRequest;
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make([
            'name_ar' => 'مؤتمر التقنية المستقبلي 2026',
        ], $request->rules(), $request->messages());

        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
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
        $this->assertArrayHasKey('name_ar', $validator->errors()->toArray());
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
        $this->assertArrayHasKey('name_ar', $validator->errors()->toArray());
    }

    public function test_draft_with_event_date_but_missing_timezone_is_rejected(): void
    {
        $request = new StoreEventRequest;
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make([
            'name_en' => 'AI Expo',
            'event_date' => '2026-11-15 18:00:00',
        ], $request->rules(), $request->messages());

        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('timezone', $validator->errors()->toArray());
    }

    public function test_draft_with_invalid_timezone_is_rejected(): void
    {
        $request = new StoreEventRequest;
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make([
            'name_en' => 'AI Expo',
            'event_date' => '2026-11-15 18:00:00',
            'timezone' => 'Invalid/Zone',
        ], $request->rules(), $request->messages());

        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('timezone', $validator->errors()->toArray());
    }

    public function test_draft_with_valid_event_date_and_cairo_timezone_converts_to_utc_in_validated_data(): void
    {
        $request = StoreEventRequest::create('/api/v1/admin/events', 'POST', [
            'name_en' => 'Cairo Tech Gathering',
            'event_date' => '2026-11-15 18:00:00',
            'timezone' => 'Africa/Cairo',
            'journalist_name' => 'Sara Al-Ahmad',
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

        // 18:00 in Africa/Cairo (winter UTC+2) -> 16:00 UTC
        $this->assertSame('2026-11-15 16:00:00', $validated['event_date']);
        $this->assertArrayNotHasKey('timezone', $validated);

        // journalist_name alias mapped to author_name
        $this->assertSame('Sara Al-Ahmad', $validated['author_name']);
        $this->assertArrayNotHasKey('journalist_name', $validated);

        // Server-controlled fields stripped
        $this->assertArrayNotHasKey('id', $validated);
        $this->assertArrayNotHasKey('status', $validated);
        $this->assertArrayNotHasKey('published_at', $validated);
        $this->assertArrayNotHasKey('slug', $validated);
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
        $this->assertArrayHasKey('name_ar', $validator->errors()->toArray());
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

    public function test_publish_request_passes_when_all_mandatory_fields_are_provided(): void
    {
        $request = PublishEventRequest::create('/api/v1/admin/events/1/publish', 'POST', [
            'name_en' => 'Global AI Summit 2026',
            'name_ar' => 'القمة العالمية للذكاء الاصطناعي 2026',
            'category_en' => 'Technology',
            'category_ar' => 'التكنولوجيا',
            'place_en' => 'King Abdulaziz International Conference Center',
            'place_ar' => 'مركز الملك عبد العزيز الدولي للمؤتمرات',
            'event_date' => '2026-11-20 09:00:00',
            'timezone' => 'Asia/Riyadh',
            'cover_image_url' => 'https://media.waaimedia.com/events/covers/9b8b7e22-8d76-4d22-bf29-37651c682055.jpg',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertTrue($validator->passes());

        $request->setValidator($validator);
        $validated = $request->validated();

        // 09:00 Riyadh (UTC+3) -> 06:00 UTC
        $this->assertSame('2026-11-20 06:00:00', $validated['event_date']);
        $this->assertArrayNotHasKey('timezone', $validated);
        $this->assertArrayNotHasKey('status', $validated);
        $this->assertArrayNotHasKey('published_at', $validated);
    }

    public function test_publish_request_fails_if_any_mandatory_field_is_missing(): void
    {
        $base = [
            'name_en' => 'Event Name',
            'name_ar' => 'اسم الفعالية',
            'category_en' => 'Business',
            'category_ar' => 'الأعمال',
            'place_en' => 'Riyadh Hub',
            'place_ar' => 'واحة الرياض',
            'event_date' => '2026-11-20 09:00:00',
            'timezone' => 'Asia/Riyadh',
            'cover_image_url' => 'https://media.waaimedia.com/events/covers/example.jpg',
        ];

        $mandatoryFields = [
            'name_en',
            'name_ar',
            'category_en',
            'category_ar',
            'place_en',
            'place_ar',
            'event_date',
            'cover_image_url',
        ];

        foreach ($mandatoryFields as $field) {
            $payload = $base;
            unset($payload[$field]);

            $request = new PublishEventRequest;
            $validator = Validator::make($payload, $request->rules(), $request->messages());
            $request->withValidator($validator);

            $this->assertFalse($validator->passes(), "Expected validation to fail when mandatory field '{$field}' is missing.");
            $this->assertArrayHasKey($field, $validator->errors()->toArray());
        }
    }

    public function test_publish_request_fails_if_cover_image_url_is_not_a_valid_url(): void
    {
        $payload = [
            'name_en' => 'Event Name',
            'name_ar' => 'اسم الفعالية',
            'category_en' => 'Business',
            'category_ar' => 'الأعمال',
            'place_en' => 'Riyadh Hub',
            'place_ar' => 'واحة الرياض',
            'event_date' => '2026-11-20 09:00:00',
            'timezone' => 'Asia/Riyadh',
            'cover_image_url' => 'not-a-valid-url',
        ];

        $request = new PublishEventRequest;
        $validator = Validator::make($payload, $request->rules(), $request->messages());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('cover_image_url', $validator->errors()->toArray());
    }

    public function test_publish_request_allows_optional_fields_to_be_null(): void
    {
        $payload = [
            'name_en' => 'Minimal Publish Event',
            'name_ar' => 'فعالية بحد أدنى للنشر',
            'category_en' => 'Media',
            'category_ar' => 'الإعلام',
            'place_en' => 'Media City',
            'place_ar' => 'مدينة الإعلام',
            'event_date' => '2026-12-01 10:00:00',
            'timezone' => 'Asia/Riyadh',
            'cover_image_url' => 'https://media.waaimedia.com/events/covers/test.jpg',
            'description_en' => null,
            'description_ar' => null,
            'author_name' => null,
            'author_name_en' => null,
            'author_name_ar' => null,
            'url' => null,
        ];

        $request = new PublishEventRequest;
        $validator = Validator::make($payload, $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
    }

    public function test_publish_request_accepts_bilingual_author_names(): void
    {
        $payload = [
            'name_en' => 'Complete Event',
            'name_ar' => 'فعالية كاملة',
            'category_en' => 'Technology',
            'category_ar' => 'تكنولوجيا',
            'place_en' => 'Venue EN',
            'place_ar' => 'المكان عربي',
            'event_date' => '2026-11-20 09:00:00',
            'timezone' => 'UTC',
            'cover_image_url' => 'https://media.waaimedia.com/events/covers/test.jpg',
            'author_name_en' => 'Jane Doe',
            'author_name_ar' => 'جين دو',
        ];

        $request = new PublishEventRequest;
        $validator = Validator::make($payload, $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
        $request->setValidator($validator);
        $validated = $request->validated();

        $this->assertSame('Jane Doe', $validated['author_name_en']);
        $this->assertSame('جين دو', $validated['author_name_ar']);
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
