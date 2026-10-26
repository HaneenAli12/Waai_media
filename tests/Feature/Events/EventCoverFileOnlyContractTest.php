<?php

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\User;
use App\Services\Storage\ImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventCoverFileOnlyContractTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const BASE = 'https://pub-r2.waai.test';

    private const URL_ERROR = 'The cover_image_url field is read-only. Upload the image as a file using the cover_image field.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
        Config::set('filesystems.disks.r2.url', self::BASE);
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin, ['access']);
    }

    private function jpeg(string $name = 'cover.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::JPEG_BASE64));
    }

    private function png(string $name = 'cover.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG_BASE64));
    }

    private function storedKey(string $ext = 'jpg'): string
    {
        $key = 'events/covers/'.fake()->uuid().'.'.$ext;
        Storage::disk('r2')->put($key, base64_decode(self::JPEG_BASE64));

        return $key;
    }

    private function eventWithCover(array $attributes = []): Event
    {
        return Event::factory()->draft()->create(array_merge(['name_en' => 'Original Title', 'cover_image_url' => $this->storedKey()], $attributes));
    }

    private function publishPayload(array $extra = []): array
    {
        return array_merge([
            'name_en' => 'Ready Event', 'name_ar' => 'فعالية', 'category_en' => 'Tech', 'category_ar' => 'تقنية',
            'place_en' => 'Riyadh', 'place_ar' => 'الرياض', 'description_en' => 'desc', 'description_ar' => 'وصف',
            'event_date' => '2027-03-01', 'event_time' => '10:00',
        ], $extra);
    }

    private function assertFileCount(int $expected): void
    {
        expect(Storage::disk('r2')->allFiles())->toHaveCount($expected);
    }

    // =========================================================================
    // cover_image_url is read-only (create / update / publish)
    // =========================================================================

    public function test_create_rejects_cover_image_url_in_every_form(): void
    {
        $values = [
            'https://example.com/a.jpg',
            self::BASE.'/events/covers/'.fake()->uuid().'.jpg',
            'events/covers/'.fake()->uuid().'.jpg',
            'news/covers/'.fake()->uuid().'.webp',
            '../../etc/passwd',
            '',
        ];

        foreach ($values as $value) {
            $this->postJson('/api/v1/admin/events', ['name_en' => 'Created', 'cover_image_url' => $value])
                ->assertStatus(422)
                ->assertJsonPath('errors.cover_image_url.0', self::URL_ERROR);
        }

        $this->assertDatabaseCount('event_calendar', 0);
    }

    public function test_create_rejects_cover_image_url_even_alongside_a_valid_file(): void
    {
        $this->post('/api/v1/admin/events', [
            'name_en' => 'Both', 'cover_image' => $this->jpeg(), 'cover_image_url' => 'https://example.com/a.jpg',
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cover_image_url']);

        $this->assertDatabaseCount('event_calendar', 0);
        $this->assertFileCount(0);
    }

    public function test_update_rejects_cover_image_url_without_touching_the_stored_image(): void
    {
        $event = $this->eventWithCover();
        $resolved = $this->getJson("/api/v1/admin/events/{$event->id}")->json('data.cover_image_url');

        foreach ([$resolved, $event->cover_image_url, 'https://example.com/other.jpg', null] as $value) {
            $this->patchJson("/api/v1/admin/events/{$event->id}", ['name_en' => 'Echoed Back', 'cover_image_url' => $value])
                ->assertStatus(422)
                ->assertJsonPath('errors.cover_image_url.0', self::URL_ERROR);
        }

        $this->assertDatabaseHas('event_calendar', ['id' => $event->id, 'name_en' => 'Original Title', 'cover_image_url' => $event->cover_image_url]);
        Storage::disk('r2')->assertExists($event->cover_image_url);
    }

    public function test_publish_rejects_cover_image_url(): void
    {
        $event = $this->eventWithCover();

        $this->postJson("/api/v1/admin/events/{$event->id}/publish", $this->publishPayload(['cover_image_url' => 'https://example.com/a.jpg']))
            ->assertStatus(422)
            ->assertJsonPath('errors.cover_image_url.0', self::URL_ERROR);

        $this->assertDatabaseHas('event_calendar', ['id' => $event->id, 'status' => 'draft']);
    }

    public function test_response_exposes_generated_read_only_public_url(): void
    {
        $key = $this->storedKey('png');
        $event = Event::factory()->draft()->create(['cover_image_url' => $key]);

        $this->getJson("/api/v1/admin/events/{$event->id}")->assertJsonPath('data.cover_image_url', self::BASE.'/'.$key);
        $this->getJson('/api/v1/admin/events')->assertJsonPath('data.0.cover_image_url', self::BASE.'/'.$key);
    }

    // =========================================================================
    // Create
    // =========================================================================

    public function test_create_with_file_stores_only_the_key_on_r2(): void
    {
        $response = $this->post('/api/v1/admin/events', ['name_en' => 'With Cover', 'cover_image' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $key = Event::findOrFail($response->json('data.id'))->cover_image_url;
        expect($key)->toStartWith('events/covers/')->toEndWith('.jpg');
        Storage::disk('r2')->assertExists($key);
        expect($response->json('data.cover_image_url'))->toBe(self::BASE.'/'.$key);
    }

    public function test_draft_without_image_remains_valid(): void
    {
        $this->postJson('/api/v1/admin/events', ['name_en' => 'No Cover'])
            ->assertStatus(201)
            ->assertJsonPath('data.cover_image_url', null);
    }

    public function test_create_rejects_invalid_file_types_and_oversized_files(): void
    {
        $this->post('/api/v1/admin/events', ['name_en' => 'Bad', 'cover_image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['cover_image']);
        $this->post('/api/v1/admin/events', ['name_en' => 'Huge', 'cover_image' => UploadedFile::fake()->create('x.jpg', 11 * 1024, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['cover_image']);

        $this->assertDatabaseCount('event_calendar', 0);
        $this->assertFileCount(0);
    }

    public function test_create_database_failure_removes_the_uploaded_object(): void
    {
        Event::creating(fn () => throw new \RuntimeException('DB down'));

        $this->post('/api/v1/admin/events', ['name_en' => 'Fails', 'cover_image' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $this->assertDatabaseCount('event_calendar', 0);
        $this->assertFileCount(0);
    }

    public function test_create_upload_failure_creates_no_record(): void
    {
        Config::set('filesystems.disks.r2.url', null); // public URL unresolvable: upload is aborted before storing

        $this->post('/api/v1/admin/events', ['name_en' => 'No Upload', 'cover_image' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $this->assertDatabaseCount('event_calendar', 0);
        $this->assertFileCount(0);
    }

    // =========================================================================
    // Update
    // =========================================================================

    public function test_update_without_file_preserves_the_image(): void
    {
        $event = $this->eventWithCover();

        $this->patchJson("/api/v1/admin/events/{$event->id}", ['place_en' => 'New Venue'])->assertStatus(200);

        $this->assertDatabaseHas('event_calendar', ['id' => $event->id, 'cover_image_url' => $event->cover_image_url]);
        Storage::disk('r2')->assertExists($event->cover_image_url);
    }

    public function test_update_with_file_replaces_the_image_and_deletes_the_previous_object(): void
    {
        $event = $this->eventWithCover();
        $oldKey = $event->cover_image_url;

        $this->post("/api/v1/admin/events/{$event->id}", ['_method' => 'PATCH', 'name_en' => 'Multipart Title', 'event_date' => '2027-01-15', 'cover_image' => $this->png()], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('data.name_en', 'Multipart Title')
            ->assertJsonPath('data.event_date', '2027-01-15');

        $newKey = $event->fresh()->cover_image_url;
        expect($newKey)->not->toBe($oldKey)->and($newKey)->toEndWith('.png');
        Storage::disk('r2')->assertExists($newKey);
        Storage::disk('r2')->assertMissing($oldKey);
        $this->assertFileCount(1);
    }

    public function test_update_does_not_delete_a_previous_object_shared_with_another_event(): void
    {
        $event = $this->eventWithCover();
        $twin = Event::factory()->draft()->create(['cover_image_url' => $event->cover_image_url]);

        $this->post("/api/v1/admin/events/{$event->id}", ['_method' => 'PATCH', 'cover_image' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(200);

        Storage::disk('r2')->assertExists($twin->fresh()->cover_image_url);
        $this->assertFileCount(2);
    }

    public function test_database_failure_keeps_the_old_image_and_removes_the_new_upload(): void
    {
        $event = $this->eventWithCover();
        $oldKey = $event->cover_image_url;
        Event::updating(fn () => throw new \RuntimeException('DB down'));

        $this->post("/api/v1/admin/events/{$event->id}", ['_method' => 'PATCH', 'name_en' => 'Nope', 'cover_image' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(500);

        expect($event->fresh()->cover_image_url)->toBe($oldKey)->and($event->fresh()->name_en)->toBe('Original Title');
        Storage::disk('r2')->assertExists($oldKey);
        $this->assertFileCount(1);
    }

    public function test_invalid_replacement_keeps_the_existing_image(): void
    {
        $event = $this->eventWithCover();

        $this->post("/api/v1/admin/events/{$event->id}", ['_method' => 'PATCH', 'cover_image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['cover_image']);

        $this->assertDatabaseHas('event_calendar', ['id' => $event->id, 'cover_image_url' => $event->cover_image_url]);
        $this->assertFileCount(1);
    }

    public function test_disguised_non_image_content_is_rejected_and_old_image_kept(): void
    {
        $event = $this->eventWithCover();
        $fake = UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo 1; ?>');

        $this->post("/api/v1/admin/events/{$event->id}", ['_method' => 'PATCH', 'cover_image' => $fake], ['Accept' => 'application/json'])
            ->assertStatus(422);

        Storage::disk('r2')->assertExists($event->cover_image_url);
        $this->assertFileCount(1);
    }

    public function test_failure_to_delete_the_superseded_object_does_not_fail_the_update(): void
    {
        $event = $this->eventWithCover();
        $oldKey = $event->cover_image_url;

        $storage = \Mockery::mock(ImageStorageService::class, ['r2'])->makePartial();
        $storage->shouldReceive('delete')->andThrow(new \RuntimeException('R2 unavailable'));
        $this->app->instance(ImageStorageService::class, $storage);

        $this->post("/api/v1/admin/events/{$event->id}", ['_method' => 'PATCH', 'cover_image' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(200);

        expect($event->fresh()->cover_image_url)->not->toBe($oldKey);
        Storage::disk('r2')->assertExists($oldKey); // retained for the weekly cleanup
    }

    // =========================================================================
    // Multipart / method handling
    // =========================================================================

    public function test_patch_and_put_json_remain_supported_and_plain_post_also_works(): void
    {
        $event = $this->eventWithCover();

        $this->patchJson("/api/v1/admin/events/{$event->id}", ['place_en' => 'PATCH'])->assertStatus(200);
        $this->putJson("/api/v1/admin/events/{$event->id}", ['place_en' => 'PUT'])->assertStatus(200);
        $this->post("/api/v1/admin/events/{$event->id}", ['place_en' => 'POST'], ['Accept' => 'application/json'])
            ->assertStatus(200)->assertJsonPath('data.place_en', 'POST');
    }

    public function test_empty_update_is_not_silently_successful(): void
    {
        $event = $this->eventWithCover();

        $this->patchJson("/api/v1/admin/events/{$event->id}", [])
            ->assertStatus(422)->assertJsonPath('message', 'No updatable fields were provided.');
    }

    public function test_unparsed_multipart_on_real_patch_returns_a_clear_error(): void
    {
        $event = $this->eventWithCover();

        $this->call('PATCH', "/api/v1/admin/events/{$event->id}", [], [], [], [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----x',
            'HTTP_ACCEPT' => 'application/json',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Multipart/form-data is not supported on PATCH or PUT. Send a POST request with _method=PATCH.');
    }

    public function test_post_update_requires_authentication_and_missing_events_return_404(): void
    {
        $event = $this->eventWithCover();

        $this->post('/api/v1/admin/events/999999', ['name_en' => 'x'], ['Accept' => 'application/json'])->assertStatus(404);

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('sanctum');
        $this->refreshApplication();
        Storage::fake('r2');

        $this->postJson("/api/v1/admin/events/{$event->id}", ['name_en' => 'x'])->assertStatus(401);
    }

    // =========================================================================
    // Publish
    // =========================================================================

    public function test_publish_is_satisfied_by_an_already_stored_cover(): void
    {
        $event = $this->eventWithCover();

        $this->postJson("/api/v1/admin/events/{$event->id}/publish", $this->publishPayload())
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.cover_image_url', self::BASE.'/'.$event->cover_image_url);

        Storage::disk('r2')->assertExists($event->cover_image_url);
    }

    public function test_publish_without_any_cover_requires_a_file(): void
    {
        $event = Event::factory()->draft()->create(['cover_image_url' => null]);

        $this->postJson("/api/v1/admin/events/{$event->id}/publish", $this->publishPayload())
            ->assertStatus(422)->assertJsonValidationErrors(['cover_image']);
    }

    public function test_publish_with_a_replacement_file_deletes_the_previous_object(): void
    {
        $event = $this->eventWithCover();
        $oldKey = $event->cover_image_url;

        $this->post("/api/v1/admin/events/{$event->id}/publish", $this->publishPayload(['cover_image' => $this->png()]), ['Accept' => 'application/json'])
            ->assertStatus(200)->assertJsonPath('data.status', 'published');

        $newKey = $event->fresh()->cover_image_url;
        expect($newKey)->not->toBe($oldKey);
        Storage::disk('r2')->assertExists($newKey);
        Storage::disk('r2')->assertMissing($oldKey);
        $this->assertFileCount(1);
    }

    public function test_publish_failure_preserves_the_existing_image_and_removes_the_new_upload(): void
    {
        $event = $this->eventWithCover();
        $oldKey = $event->cover_image_url;
        Event::updating(fn () => throw new \RuntimeException('DB down'));

        $this->post("/api/v1/admin/events/{$event->id}/publish", $this->publishPayload(['cover_image' => $this->png()]), ['Accept' => 'application/json'])
            ->assertStatus(500);

        expect($event->fresh()->cover_image_url)->toBe($oldKey)->and($event->fresh()->status)->toBe('draft');
        Storage::disk('r2')->assertExists($oldKey);
        $this->assertFileCount(1);
    }

    // =========================================================================
    // Delete
    // =========================================================================

    public function test_delete_removes_the_record_and_its_unshared_image(): void
    {
        $event = $this->eventWithCover();

        $this->deleteJson("/api/v1/admin/events/{$event->id}")->assertStatus(200);

        $this->assertDatabaseMissing('event_calendar', ['id' => $event->id]);
        $this->assertFileCount(0);
    }

    public function test_delete_keeps_an_image_referenced_by_another_event(): void
    {
        $event = $this->eventWithCover();
        $twin = Event::factory()->draft()->create(['cover_image_url' => $event->cover_image_url]);

        $this->deleteJson("/api/v1/admin/events/{$event->id}")->assertStatus(200);

        Storage::disk('r2')->assertExists($twin->cover_image_url);
    }

    public function test_delete_succeeds_even_when_r2_deletion_fails(): void
    {
        $event = $this->eventWithCover();

        $storage = \Mockery::mock(ImageStorageService::class, ['r2'])->makePartial();
        $storage->shouldReceive('delete')->andThrow(new \RuntimeException('R2 unavailable'));
        $this->app->instance(ImageStorageService::class, $storage);

        $this->deleteJson("/api/v1/admin/events/{$event->id}")->assertStatus(200);

        $this->assertDatabaseMissing('event_calendar', ['id' => $event->id]);
        Storage::disk('r2')->assertExists($event->cover_image_url);
    }

    public function test_delete_never_touches_foreign_module_keys(): void
    {
        $foreign = 'news/covers/'.fake()->uuid().'.webp';
        Storage::disk('r2')->put($foreign, 'news-image');
        $event = Event::factory()->draft()->create(['cover_image_url' => $foreign]);

        $this->deleteJson("/api/v1/admin/events/{$event->id}")->assertStatus(200);

        Storage::disk('r2')->assertExists($foreign);
    }

    // =========================================================================
    // Legacy data compatibility (never rewritten automatically)
    // =========================================================================

    public function test_legacy_external_url_is_untouched_by_updates_of_other_fields(): void
    {
        $legacy = 'https://legacy-storage.example.com/images/events/cover1.jpg';
        $event = Event::factory()->draft()->create(['cover_image_url' => $legacy]);

        $this->patchJson("/api/v1/admin/events/{$event->id}", ['place_en' => 'Changed'])
            ->assertStatus(200)
            ->assertJsonPath('data.cover_image_url', $legacy);

        $this->assertDatabaseHas('event_calendar', ['id' => $event->id, 'cover_image_url' => $legacy]);
    }

    public function test_legacy_external_url_satisfies_publish_readiness_and_is_kept(): void
    {
        $legacy = 'https://legacy-storage.example.com/images/events/cover1.jpg';
        $event = Event::factory()->draft()->create(['cover_image_url' => $legacy]);

        $this->postJson("/api/v1/admin/events/{$event->id}/publish", $this->publishPayload())
            ->assertStatus(200)->assertJsonPath('data.cover_image_url', $legacy);
    }

    public function test_replacing_a_legacy_url_with_an_upload_does_not_attempt_r2_deletion(): void
    {
        $legacy = 'https://legacy-storage.example.com/images/events/cover1.jpg';
        $event = Event::factory()->draft()->create(['cover_image_url' => $legacy]);

        $this->post("/api/v1/admin/events/{$event->id}", ['_method' => 'PATCH', 'cover_image' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(200);

        expect($event->fresh()->cover_image_url)->toStartWith('events/covers/');
        $this->assertFileCount(1);
    }

    public function test_deleting_a_legacy_url_event_does_not_touch_r2(): void
    {
        $other = $this->storedKey();
        $event = Event::factory()->draft()->create(['cover_image_url' => 'https://legacy-storage.example.com/a.jpg']);

        $this->deleteJson("/api/v1/admin/events/{$event->id}")->assertStatus(200);

        Storage::disk('r2')->assertExists($other);
    }

    public function test_object_referenced_through_a_legacy_own_public_url_is_not_deleted_as_shared(): void
    {
        $key = $this->storedKey();
        $holder = Event::factory()->draft()->create(['cover_image_url' => self::BASE.'/'.$key]); // row written by an older release
        $event = Event::factory()->draft()->create(['cover_image_url' => $key]);

        $this->deleteJson("/api/v1/admin/events/{$event->id}")->assertStatus(200);

        Storage::disk('r2')->assertExists($key);
        expect($holder->fresh()->cover_image_url)->toBe(self::BASE.'/'.$key);
    }
}
