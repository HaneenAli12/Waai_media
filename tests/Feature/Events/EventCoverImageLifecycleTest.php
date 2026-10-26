<?php

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\User;
use App\Services\Events\EventCoverImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventCoverImageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_1X1_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private const FIXTURE_1X1_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const FIXTURE_1X1_WEBP_BASE64 = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=';

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->editor = User::factory()->editor()->create();

        Storage::fake('r2');
        Config::set('filesystems.disks.r2.url', 'https://pub-r2.waai.test');
    }

    private function createFakeWebpFile(string $name = 'cover.webp', ?int $sizeKb = null): UploadedFile
    {
        $binary = base64_decode(self::FIXTURE_1X1_WEBP_BASE64);
        if ($sizeKb !== null) {
            $targetBytes = $sizeKb * 1024;
            if (strlen($binary) < $targetBytes) {
                // Pad without invalidating header
                $binary .= str_repeat("\0", $targetBytes - strlen($binary));
            }
        }

        return UploadedFile::fake()->createWithContent($name, $binary);
    }

    private function createFakeJpegFile(string $name = 'cover.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_JPEG_BASE64));
    }

    private function createFakePngFile(string $name = 'cover.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_PNG_BASE64));
    }

    // =========================================================================
    // 1. Creation & Upload
    // =========================================================================

    public function test_creating_draft_without_image_still_works(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $response = $this->postJson('/api/v1/admin/events', [
            'name_en' => 'Event Without Cover Image',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name_en', 'Event Without Cover Image')
            ->assertJsonPath('data.cover_image_url', null);

        $this->assertDatabaseHas('event_calendar', [
            'name_en' => 'Event Without Cover Image',
            'cover_image_url' => null,
        ]);
    }

    public function test_creating_draft_with_valid_image_uploads_to_r2_and_stores_key(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $file = $this->createFakeWebpFile('cover.webp');

        $response = $this->postJson('/api/v1/admin/events', [
            'name_en' => 'Event With WebP Cover',
            'cover_image' => $file,
        ]);

        $response->assertStatus(201);

        $eventId = $response->json('data.id');
        $resolvedUrl = $response->json('data.cover_image_url');

        $event = Event::findOrFail($eventId);
        $storedKey = $event->cover_image_url;

        // DB stores relative R2 key, not full URL
        expect($storedKey)->not->toBeNull()
            ->and($storedKey)->toStartWith('events/covers/')
            ->and($storedKey)->toEndWith('.webp');

        // File actually exists on R2 disk
        Storage::disk('r2')->assertExists($storedKey);

        // Resource returns full resolved public URL
        expect($resolvedUrl)->toBe("https://pub-r2.waai.test/{$storedKey}");
    }

    public function test_invalid_image_formats_are_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $invalidFile = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->postJson('/api/v1/admin/events', [
            'name_en' => 'Event With Invalid File',
            'cover_image' => $invalidFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cover_image']);
    }

    public function test_images_exceeding_10_mb_are_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        // 11 MB file
        $oversizedFile = $this->createFakeJpegFile('huge.jpg');
        $oversizedFile = UploadedFile::fake()->create('huge.jpg', 11 * 1024, 'image/jpeg');

        $response = $this->postJson('/api/v1/admin/events', [
            'name_en' => 'Event With Huge Image',
            'cover_image' => $oversizedFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cover_image']);
    }

    // =========================================================================
    // 2. Update & Replace
    // =========================================================================

    public function test_updating_event_without_new_image_preserves_existing_image(): void
    {
        Sanctum::actingAs($this->editor, ['access']);

        $validUuidKey = 'events/covers/11111111-2222-4333-8444-555555555555.webp';
        $event = Event::factory()->draft()->create([
            'cover_image_url' => $validUuidKey,
        ]);
        Storage::disk('r2')->put($validUuidKey, 'image-content');

        $response = $this->patchJson("/api/v1/admin/events/{$event->id}", [
            'place_en' => 'Updated Venue',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.place_en', 'Updated Venue')
            ->assertJsonPath('data.cover_image_url', "https://pub-r2.waai.test/{$validUuidKey}");

        $this->assertDatabaseHas('event_calendar', [
            'id' => $event->id,
            'cover_image_url' => $validUuidKey,
        ]);

        Storage::disk('r2')->assertExists($validUuidKey);
    }

    public function test_updating_with_new_image_replaces_key_and_cleans_up_old_unshared_r2_object(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        // Create initial event with valid image
        $oldFile = $this->createFakeJpegFile('initial.jpg');
        $createRes = $this->postJson('/api/v1/admin/events', [
            'name_en' => 'Initial Event',
            'cover_image' => $oldFile,
        ]);
        $eventId = $createRes->json('data.id');
        $oldKey = Event::findOrFail($eventId)->cover_image_url;
        Storage::disk('r2')->assertExists($oldKey);

        // Update with new image
        $newFile = $this->createFakePngFile('replacement.png');
        $updateRes = $this->patchJson("/api/v1/admin/events/{$eventId}", [
            'cover_image' => $newFile,
        ]);

        $updateRes->assertStatus(200);

        $newKey = Event::findOrFail($eventId)->cover_image_url;
        expect($newKey)->not->toBe($oldKey);

        // New exists, old is cleaned up
        Storage::disk('r2')->assertExists($newKey);
        Storage::disk('r2')->assertMissing($oldKey);
    }

    public function test_updating_image_preserves_old_image_if_referenced_by_another_event(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $sharedKey = 'events/covers/00000000-0000-4000-8000-000000000001.jpg';
        Storage::disk('r2')->put($sharedKey, 'shared-content');

        $event1 = Event::factory()->draft()->create(['cover_image_url' => $sharedKey]);
        $event2 = Event::factory()->draft()->create(['cover_image_url' => $sharedKey]);

        $newFile = $this->createFakeWebpFile('new.webp');

        $response = $this->patchJson("/api/v1/admin/events/{$event1->id}", [
            'cover_image' => $newFile,
        ]);

        $response->assertStatus(200);

        // Shared key MUST NOT be deleted because event2 still references it
        Storage::disk('r2')->assertExists($sharedKey);
    }

    // =========================================================================
    // 3. Resources: Resolution of R2 keys, Legacy URLs, and Nulls
    // =========================================================================

    public function test_admin_and_public_resources_return_full_urls_for_r2_keys(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $event = Event::factory()->published()->create([
            'cover_image_url' => 'events/covers/00000000-0000-4000-8000-000000000002.png',
        ]);

        $adminRes = $this->getJson("/api/v1/admin/events/{$event->id}");
        $adminRes->assertStatus(200)
            ->assertJsonPath('data.cover_image_url', 'https://pub-r2.waai.test/events/covers/00000000-0000-4000-8000-000000000002.png');

        $publicRes = $this->getJson("/api/v1/events/{$event->slug}");
        $publicRes->assertStatus(200)
            ->assertJsonPath('data.cover_image_url', 'https://pub-r2.waai.test/events/covers/00000000-0000-4000-8000-000000000002.png');
    }

    public function test_legacy_absolute_image_urls_remain_unchanged(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $legacyUrl = 'https://legacy-storage.example.com/images/events/cover1.jpg';
        $event = Event::factory()->published()->create([
            'cover_image_url' => $legacyUrl,
        ]);

        $adminRes = $this->getJson("/api/v1/admin/events/{$event->id}");
        $adminRes->assertStatus(200)
            ->assertJsonPath('data.cover_image_url', $legacyUrl);

        $publicRes = $this->getJson("/api/v1/events/{$event->slug}");
        $publicRes->assertStatus(200)
            ->assertJsonPath('data.cover_image_url', $legacyUrl);
    }

    public function test_null_image_values_remain_null(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $event = Event::factory()->draft()->create([
            'cover_image_url' => null,
        ]);

        $adminRes = $this->getJson("/api/v1/admin/events/{$event->id}");
        $adminRes->assertStatus(200)
            ->assertJsonPath('data.cover_image_url', null);
    }

    // =========================================================================
    // 4. Publishing Behavior
    // =========================================================================

    public function test_publishing_with_saved_r2_image_works_without_another_upload(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $r2Key = 'events/covers/00000000-0000-4000-8000-000000000003.webp';

        $event = Event::factory()->draft()->create([
            'name_en' => 'Publishable Event',
            'name_ar' => 'فعالية قابلة للنشر',
            'category_en' => 'Media',
            'category_ar' => 'إعلام',
            'place_en' => 'Riyadh',
            'place_ar' => 'الرياض',
            'event_date' => now()->addDays(5),
            'cover_image_url' => $r2Key,
        ]);

        $response = $this->postJson("/api/v1/admin/events/{$event->id}/publish", []);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.cover_image_url', "https://pub-r2.waai.test/{$r2Key}");

        $this->assertDatabaseHas('event_calendar', [
            'id' => $event->id,
            'status' => 'published',
            'cover_image_url' => $r2Key,
        ]);
    }

    public function test_publishing_still_rejects_events_without_an_image(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $event = Event::factory()->draft()->create([
            'name_en' => 'Incomplete Event',
            'name_ar' => 'فعالية غير مكتملة',
            'category_en' => 'Media',
            'category_ar' => 'إعلام',
            'place_en' => 'Riyadh',
            'place_ar' => 'الرياض',
            'event_date' => now()->addDays(5),
            'cover_image_url' => null,
        ]);

        $response = $this->postJson("/api/v1/admin/events/{$event->id}/publish", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cover_image']);
    }

    // =========================================================================
    // 5. Deletion & Cleanup
    // =========================================================================

    public function test_deleting_event_removes_unshared_r2_image_after_database_deletion(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $r2Key = 'events/covers/00000000-0000-4000-8000-000000000004.jpg';
        Storage::disk('r2')->put($r2Key, 'to-be-deleted');

        $event = Event::factory()->draft()->create([
            'cover_image_url' => $r2Key,
        ]);

        $response = $this->deleteJson("/api/v1/admin/events/{$event->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Event permanently deleted successfully.']);

        $this->assertDatabaseMissing('event_calendar', ['id' => $event->id]);
        Storage::disk('r2')->assertMissing($r2Key);
    }

    public function test_deleting_event_does_not_delete_shared_r2_image(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $sharedKey = 'events/covers/00000000-0000-4000-8000-000000000005.jpg';
        Storage::disk('r2')->put($sharedKey, 'still-needed');

        $event1 = Event::factory()->draft()->create(['cover_image_url' => $sharedKey]);
        $event2 = Event::factory()->draft()->create(['cover_image_url' => $sharedKey]);

        $response = $this->deleteJson("/api/v1/admin/events/{$event1->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('event_calendar', ['id' => $event1->id]);
        $this->assertDatabaseHas('event_calendar', ['id' => $event2->id]);

        // Shared object must remain intact in R2
        Storage::disk('r2')->assertExists($sharedKey);
    }

    public function test_deleting_event_with_legacy_url_does_not_attempt_r2_delete(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $legacyUrl = 'https://external-cdn.example.com/covers/old.png';
        $event = Event::factory()->draft()->create([
            'cover_image_url' => $legacyUrl,
        ]);

        $response = $this->deleteJson("/api/v1/admin/events/{$event->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('event_calendar', ['id' => $event->id]);
    }

    // =========================================================================
    // 6. Cross-Module Key Isolation & Protection Tests
    // =========================================================================

    public function test_event_cover_image_service_rejects_keys_from_other_modules(): void
    {
        $service = app(EventCoverImageService::class);

        $newsKey = 'news/covers/00000000-0000-4000-8000-000000000010.webp';
        $clientLogoKey = 'clients/logos/00000000-0000-4000-8000-000000000011.png';
        $teamPhotoKey = 'team/photos/00000000-0000-4000-8000-000000000012.jpg';
        $eventKey = 'events/covers/00000000-0000-4000-8000-000000000013.webp';

        expect($service->isR2Key($newsKey))->toBeFalse()
            ->and($service->isR2Key($clientLogoKey))->toBeFalse()
            ->and($service->isR2Key($teamPhotoKey))->toBeFalse()
            ->and($service->isR2Key($eventKey))->toBeTrue();
    }

    public function test_resolve_url_does_not_convert_foreign_module_keys_to_event_urls(): void
    {
        $service = app(EventCoverImageService::class);

        $newsKey = 'news/covers/00000000-0000-4000-8000-000000000010.webp';
        $eventKey = 'events/covers/00000000-0000-4000-8000-000000000013.webp';

        // Foreign module internal key is never leaked as a public URL (returns null)
        expect($service->resolveUrl($newsKey))->toBeNull();

        // Event key is properly transformed
        expect($service->resolveUrl($eventKey))->toBe("https://pub-r2.waai.test/{$eventKey}");
    }

    public function test_deleting_event_with_foreign_news_key_does_not_delete_the_news_r2_object(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $newsKey = 'news/covers/00000000-0000-4000-8000-000000000099.webp';
        Storage::disk('r2')->put($newsKey, 'critical-news-image-content');

        // An event erroneously containing a news cover key
        $event = Event::factory()->draft()->create([
            'cover_image_url' => $newsKey,
        ]);

        $response = $this->deleteJson("/api/v1/admin/events/{$event->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('event_calendar', ['id' => $event->id]);

        // News R2 object MUST NOT be deleted
        Storage::disk('r2')->assertExists($newsKey);
    }
}
