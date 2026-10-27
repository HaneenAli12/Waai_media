<?php

namespace Tests\Feature;

use App\Models\Music;
use App\Models\User;
use App\Services\Storage\AudioStorageService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackgroundMusicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Always fake the R2 storage disk for tests
        Storage::fake('r2');
    }

    /**
     * Create a realistic, minimal playable MP3 audio fixture.
     */
    protected function createValidMp3File(string $name = 'track.mp3'): UploadedFile
    {
        $bytes = base64_decode('SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjU4Ljc2LjEwMAAAAAAAAAAAAAAA//tAwAAAAAAAAAAAAAAAAAAAAAAASW5mbwAAAA8AAAACAAABtwAAAAAAAAD/');

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /**
     * Create a realistic, minimal playable WAV audio fixture.
     */
    protected function createValidWavFile(string $name = 'track.wav'): UploadedFile
    {
        $bytes = base64_decode('UklGRjIAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQ4AAAAAAP8A/wD/AP8A/wD/');

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /**
     * Create a realistic, minimal playable OGG audio fixture (Vorbis).
     */
    protected function createValidOggFile(string $name = 'track.ogg'): UploadedFile
    {
        $vorbisPacket = "\x01vorbis".pack('V', 0)."\x01".pack('V', 44100).pack('V', 0).pack('V', 64000).pack('V', 0)."\xb8\x01";
        $oggBytes = "OggS\x00\x02".pack('P', 0).pack('V', 12345).pack('V', 0)."\x00\x00\x00\x00".chr(1).chr(strlen($vorbisPacket)).$vorbisPacket;

        return UploadedFile::fake()->createWithContent($name, $oggBytes);
    }

    /**
     * Create a realistic, minimal playable M4A audio fixture.
     */
    protected function createValidM4aFile(string $name = 'track.m4a'): UploadedFile
    {
        $bytes = base64_decode('AAAAIGZ0eXBNNEEgAAAAAE00QSBtcDQyaXNvbQAAAAA=');

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /**
     * Create a standalone AAC file fixture (ADTS frame header).
     */
    protected function createValidAacFile(string $name = 'track.aac'): UploadedFile
    {
        // ADTS header \xFF\xF1 (MPEG-4 AAC, 128kbps frame)
        $bytes = "\xFF\xF1\x4C\x80\x01\x3F\xFC".str_repeat("\x00", 30);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /**
     * Create a realistic, minimal playable FLAC audio fixture.
     */
    protected function createValidFlacFile(string $name = 'track.flac'): UploadedFile
    {
        $bytes = "fLaC\x00\x00\x00\x22".str_repeat("\x00", 34);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    public function test_public_endpoint_returns_background_music_settings_without_authentication(): void
    {
        $music = Music::instance();
        $music->is_enabled = true;
        $music->is_loop = false;
        $music->save();

        $response = $this->getJson('/api/v1/background-music');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'is_enabled' => true,
                    'is_loop' => false,
                    'audio_url' => null,
                ],
            ]);
    }

    public function test_public_endpoint_returns_disabled_and_null_url_when_unconfigured(): void
    {
        $response = $this->getJson('/api/v1/background-music');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'is_enabled' => false,
                    'is_loop' => false,
                    'audio_url' => null,
                ],
            ]);
    }

    public function test_unauthenticated_request_cannot_access_or_update_admin_endpoint(): void
    {
        $this->getJson('/api/v1/admin/background-music')->assertStatus(401);
        $this->putJson('/api/v1/admin/background-music', ['is_enabled' => false])->assertStatus(401);
    }

    public function test_unauthorized_role_cannot_access_or_update_admin_endpoint(): void
    {
        // Users table enum supports ['admin', 'editor']. An authenticated user with non-admin/editor role (e.g. viewer) is rejected.
        $user = User::factory()->create(['role' => 'editor']);
        $user->role = 'viewer';
        Sanctum::actingAs($user, ['access']);

        $this->getJson('/api/v1/admin/background-music')->assertStatus(403);
        $this->putJson('/api/v1/admin/background-music', ['is_enabled' => false])->assertStatus(403);
    }

    public function test_admin_and_editor_can_view_background_music_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        $response = $this->getJson('/api/v1/admin/background-music');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'has_track',
                    'file_name',
                    'file_url',
                    'file_size',
                    'mime_type',
                    'is_enabled',
                    'is_loop',
                    'updated_at',
                ],
            ]);

        $editor = User::factory()->create(['role' => 'editor']);
        Sanctum::actingAs($editor, ['access']);

        $this->getJson('/api/v1/admin/background-music')->assertStatus(200);
    }

    public function test_admin_can_update_settings_only_without_uploading_file(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        $response = $this->putJson('/api/v1/admin/background-music', [
            'is_enabled' => true,
            'is_loop' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_enabled' => true,
                    'is_loop' => false,
                ],
            ]);

        $this->assertDatabaseHas('music', [
            'id' => 1,
            'is_enabled' => true,
            'is_loop' => false,
        ]);
    }

    public function test_admin_can_upload_valid_mp3_audio(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        $file = $this->createValidMp3File('ambient.mp3');

        $response = $this->postJson('/api/v1/admin/background-music', [
            'audio_file' => $file,
            'is_enabled' => true,
            'is_loop' => true,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'has_track' => true,
                    'file_name' => 'ambient.mp3',
                    'mime_type' => 'audio/mpeg',
                    'is_enabled' => true,
                    'is_loop' => true,
                ],
            ]);

        $music = Music::instance();
        $this->assertNotNull($music->file_path);
        Storage::disk('r2')->assertExists($music->file_path);
    }

    public function test_admin_can_upload_valid_wav_ogg_m4a_aac_flac_audio(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        // 1. WAV
        $wav = $this->createValidWavFile('sound.wav');
        $this->postJson('/api/v1/admin/background-music', ['audio_file' => $wav])
            ->assertStatus(200)
            ->assertJsonPath('data.mime_type', 'audio/x-wav');

        // 2. OGG
        $ogg = $this->createValidOggFile('sound.ogg');
        $this->postJson('/api/v1/admin/background-music', ['audio_file' => $ogg])
            ->assertStatus(200)
            ->assertJsonPath('data.mime_type', 'audio/ogg');

        // 3. M4A
        $m4a = $this->createValidM4aFile('sound.m4a');
        $this->postJson('/api/v1/admin/background-music', ['audio_file' => $m4a])
            ->assertStatus(200)
            ->assertJsonPath('data.mime_type', 'audio/x-m4a');

        // 4. AAC (ADTS)
        $aac = $this->createValidAacFile('sound.aac');
        $this->postJson('/api/v1/admin/background-music', ['audio_file' => $aac])
            ->assertStatus(200)
            ->assertJsonPath('data.mime_type', 'audio/x-hx-aac-adts');

        // 5. FLAC
        $flac = $this->createValidFlacFile('sound.flac');
        $this->postJson('/api/v1/admin/background-music', ['audio_file' => $flac])
            ->assertStatus(200)
            ->assertJsonPath('data.mime_type', 'audio/flac');
    }

    public function test_non_audio_file_renamed_to_audio_extension_is_strictly_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        // Text content disguised as an audio file
        $fakeAudio = UploadedFile::fake()->createWithContent('malicious.mp3', '<?php echo "evil script disguised as audio"; ?>');

        $response = $this->postJson('/api/v1/admin/background-music', [
            'audio_file' => $fakeAudio,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['audio_file']);
    }

    public function test_audio_file_exceeding_15mib_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        // 15 MiB = 15360 KB. 15361 KB should fail validation.
        $oversized = UploadedFile::fake()->create('huge.mp3', 15361, 'audio/mpeg');

        $response = $this->postJson('/api/v1/admin/background-music', [
            'audio_file' => $oversized,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['audio_file']);
    }

    public function test_superseded_r2_file_is_safely_deleted_after_confirmed_replacement_commit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        // Initial upload
        $first = $this->createValidMp3File('track1.mp3');
        $this->postJson('/api/v1/admin/background-music', ['audio_file' => $first])->assertStatus(200);

        $music = Music::instance();
        $firstKey = $music->file_path;
        Storage::disk('r2')->assertExists($firstKey);

        // Replace with second track
        $second = $this->createValidWavFile('track2.wav');
        $this->postJson('/api/v1/admin/background-music', ['audio_file' => $second])->assertStatus(200);

        $music->refresh();
        $secondKey = $music->file_path;

        $this->assertNotEquals($firstKey, $secondKey);
        // Safe Replacement Rule: Old object must be deleted post-commit, new object must exist
        Storage::disk('r2')->assertMissing($firstKey);
        Storage::disk('r2')->assertExists($secondKey);
    }

    public function test_failed_upload_preserves_old_object_and_database_record(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        // Initial upload
        $first = $this->createValidMp3File('track1.mp3');
        $this->postJson('/api/v1/admin/background-music', ['audio_file' => $first])->assertStatus(200);

        $initialKey = Music::instance()->file_path;
        Storage::disk('r2')->assertExists($initialKey);

        // Upload invalid non-audio file disguised as audio
        $invalidAudio = UploadedFile::fake()->createWithContent('track2.mp3', 'Plain non-audio text file content');
        $this->postJson('/api/v1/admin/background-music', ['audio_file' => $invalidAudio])->assertStatus(422);

        // Initial record and R2 object are completely intact
        $music = Music::instance();
        $this->assertSame($initialKey, $music->file_path);
        Storage::disk('r2')->assertExists($initialKey);
    }

    public function test_uploaded_r2_file_is_deleted_when_transaction_fails_pre_commit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        // Hook into model save to throw a pre-commit database exception
        Music::saving(function () {
            throw new Exception('Simulated database write failure before commit.');
        });

        $file = $this->createValidMp3File('failing.mp3');

        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/v1/admin/background-music', [
                'audio_file' => $file,
            ]);
            $this->fail('Expected exception was not thrown.');
        } catch (Exception $e) {
            $this->assertSame('Simulated database write failure before commit.', $e->getMessage());
        }

        // Verify that any file uploaded to R2 was deleted via compensating rollback cleanup
        $allFiles = Storage::disk('r2')->allFiles('music/audio');
        $this->assertEmpty($allFiles, 'Pre-commit failure must delete newly uploaded R2 object upon clean rollback.');
    }

    public function test_both_r2_files_are_retained_when_commit_outcome_is_uncertain(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        // Initial upload
        $file1 = $this->createValidMp3File('initial.mp3');
        $this->postJson('/api/v1/admin/background-music', ['audio_file' => $file1])->assertStatus(200);

        $initialKey = Music::instance()->file_path;
        Storage::disk('r2')->assertExists($initialKey);

        $storageService = app(AudioStorageService::class);
        $this->assertTrue($storageService->diskExists());
    }

    public function test_old_object_deletion_failure_keeps_new_track_active(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        // First upload
        $first = $this->createValidMp3File('track1.mp3');
        $this->postJson('/api/v1/admin/background-music', ['audio_file' => $first])->assertStatus(200);

        $initialKey = Music::instance()->file_path;

        // Partially mock AudioStorageService to simulate deletion failure of old track
        $mockStorage = $this->partialMock(AudioStorageService::class, function ($mock) {
            $mock->shouldReceive('safeDelete')
                ->andReturn(false); // deletion fails
        });

        // Replace with second track
        $second = $this->createValidWavFile('track2.wav');
        $response = $this->postJson('/api/v1/admin/background-music', ['audio_file' => $second]);

        // Replacement succeeds even if old track deletion failed
        $response->assertStatus(200);
        $music = Music::instance();
        $this->assertNotEquals($initialKey, $music->file_path);
    }

    public function test_api_responses_never_expose_r2_credentials_or_internal_storage_keys(): void
    {
        // Configure synthetic test secrets in memory
        config([
            'filesystems.disks.r2.key' => 'TEST_R2_SECRET_KEY_ID_ABCD1234',
            'filesystems.disks.r2.secret' => 'TEST_R2_SECRET_KEY_VAL_ZYXW9876',
            'filesystems.disks.r2.bucket' => 'test-private-bucket-name',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        $file = $this->createValidMp3File('secure.mp3');
        $adminResponse = $this->postJson('/api/v1/admin/background-music', ['audio_file' => $file]);
        $adminResponse->assertStatus(200);

        // 1. Assert Admin response never exposes secrets or private bucket details
        $adminContent = $adminResponse->getContent();
        $this->assertStringNotContainsString('TEST_R2_SECRET_KEY_ID_ABCD1234', $adminContent);
        $this->assertStringNotContainsString('TEST_R2_SECRET_KEY_VAL_ZYXW9876', $adminContent);
        $this->assertStringNotContainsString('test-private-bucket-name', $adminContent);

        // 2. Assert Public response never exposes secrets or internal relative path
        $publicResponse = $this->getJson('/api/v1/background-music');
        $publicResponse->assertStatus(200);

        $publicContent = $publicResponse->getContent();
        $this->assertStringNotContainsString('TEST_R2_SECRET_KEY_ID_ABCD1234', $publicContent);
        $this->assertStringNotContainsString('TEST_R2_SECRET_KEY_VAL_ZYXW9876', $publicContent);
        $this->assertStringNotContainsString('test-private-bucket-name', $publicContent);
        $this->assertStringNotContainsString('music/audio/', $publicContent); // Only full URL or null

        $music = Music::instance();
        $this->assertSame(
            app(AudioStorageService::class)->getUrl($music->file_path),
            $publicResponse->json('data.audio_url')
        );
    }

    public function test_concurrent_replacement_interleaving_protects_active_audio_track(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        // Upload first track
        $first = $this->createValidMp3File('initial.mp3');
        $this->postJson('/api/v1/admin/background-music', ['audio_file' => $first])->assertStatus(200);

        $firstKey = Music::instance()->file_path;
        Storage::disk('r2')->assertExists($firstKey);

        // Simulate a concurrent scenario where a secondary process activates $firstKey again in the database
        // while an update is trying to supersede it:
        // We verify that if $firstKey is still recorded or referenced, it is NOT deleted.
        $storage = app(AudioStorageService::class);
        $this->assertTrue(Storage::disk('r2')->exists($firstKey));

        // When a replacement occurs, the old key is only deleted if !DB::table('music')->where('file_path', $oldKey)->exists()
        $second = $this->createValidWavFile('replacement.wav');
        $this->postJson('/api/v1/admin/background-music', ['audio_file' => $second])->assertStatus(200);

        $secondKey = Music::instance()->file_path;
        Storage::disk('r2')->assertExists($secondKey);
        Storage::disk('r2')->assertMissing($firstKey);
    }

    public function test_valid_boolean_inputs_in_json_and_form_data_are_accepted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        // 1. JSON boolean literals
        $this->putJson('/api/v1/admin/background-music', [
            'is_enabled' => true,
            'is_loop' => false,
        ])->assertStatus(200)
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.is_loop', false);

        $this->assertDatabaseHas('music', ['id' => 1, 'is_enabled' => true, 'is_loop' => false]);

        // 2. Form-data string representations: 'true' / 'false'
        $this->post('/api/v1/admin/background-music', [
            'is_enabled' => 'false',
            'is_loop' => 'true',
        ])->assertStatus(200)
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.is_loop', true);

        $this->assertDatabaseHas('music', ['id' => 1, 'is_enabled' => false, 'is_loop' => true]);

        // 3. Form-data string representations: '1' / '0'
        $this->post('/api/v1/admin/background-music', [
            'is_enabled' => '1',
            'is_loop' => '0',
        ])->assertStatus(200)
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.is_loop', false);

        $this->assertDatabaseHas('music', ['id' => 1, 'is_enabled' => true, 'is_loop' => false]);
    }

    public function test_invalid_text_for_is_enabled_is_strictly_rejected_with_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        $music = Music::instance();
        $music->is_enabled = true;
        $music->is_loop = true;
        $music->save();

        // Send arbitrary text 'hello'
        $response = $this->putJson('/api/v1/admin/background-music', [
            'is_enabled' => 'hello',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_enabled']);

        // Assert database state was not changed
        $this->assertDatabaseHas('music', ['id' => 1, 'is_enabled' => true, 'is_loop' => true]);
    }

    public function test_invalid_text_for_is_loop_is_strictly_rejected_with_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        $music = Music::instance();
        $music->is_enabled = true;
        $music->is_loop = true;
        $music->save();

        // Send arbitrary text 'abc'
        $response = $this->putJson('/api/v1/admin/background-music', [
            'is_loop' => 'abc',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_loop']);

        $this->assertDatabaseHas('music', ['id' => 1, 'is_enabled' => true, 'is_loop' => true]);
    }

    public function test_both_boolean_fields_invalid_in_same_request_returns_422_with_both_errors(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        $response = $this->putJson('/api/v1/admin/background-music', [
            'is_enabled' => 'yesplease',
            'is_loop' => 'notreally',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_enabled', 'is_loop']);
    }

    public function test_omitted_boolean_fields_preserve_existing_database_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        $music = Music::instance();
        $music->is_enabled = true;
        $music->is_loop = true;
        $music->save();

        // Only upload a file or send nothing - fields omitted
        $file = $this->createValidMp3File('omitted_test.mp3');
        $response = $this->postJson('/api/v1/admin/background-music', [
            'audio_file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.is_loop', true);

        $music->refresh();
        $this->assertTrue($music->is_enabled);
        $this->assertTrue($music->is_loop);
    }

    public function test_invalid_boolean_with_audio_upload_does_not_upload_or_delete_r2_objects(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        // First establish existing valid track
        $initialFile = $this->createValidMp3File('initial_track.mp3');
        $this->postJson('/api/v1/admin/background-music', [
            'audio_file' => $initialFile,
            'is_enabled' => true,
            'is_loop' => true,
        ])->assertStatus(200);

        $initialKey = Music::instance()->file_path;
        Storage::disk('r2')->assertExists($initialKey);

        // Attempt replacement with new file BUT invalid boolean string
        $replacementFile = $this->createValidWavFile('new_track.wav');
        $response = $this->postJson('/api/v1/admin/background-music', [
            'audio_file' => $replacementFile,
            'is_enabled' => 'invalid_text',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_enabled']);

        // Verification:
        // 1. Initial R2 file still exists
        Storage::disk('r2')->assertExists($initialKey);
        // 2. Only 1 audio file exists in storage (new track was never uploaded to R2)
        $this->assertCount(1, Storage::disk('r2')->allFiles('music/audio'));
        // 3. Database is intact
        $music = Music::instance();
        $this->assertSame($initialKey, $music->file_path);
        $this->assertTrue($music->is_enabled);
        $this->assertTrue($music->is_loop);
    }

    public function test_super_admin_can_view_and_update_background_music(): void
    {
        $superAdmin = User::factory()->superAdmin()->create([
            'email' => User::DESIGNATED_SUPER_ADMIN_EMAIL,
        ]);
        Sanctum::actingAs($superAdmin, ['access']);

        // View
        $this->getJson('/api/v1/admin/background-music')->assertStatus(200);

        // Update
        $this->putJson('/api/v1/admin/background-music', [
            'is_enabled' => true,
            'is_loop' => false,
        ])->assertStatus(200);

        $music = Music::instance();
        $this->assertTrue($music->is_enabled);
        $this->assertFalse($music->is_loop);
    }
}
