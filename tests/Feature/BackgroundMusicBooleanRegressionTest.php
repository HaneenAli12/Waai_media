<?php

namespace Tests\Feature;

use App\Models\Music;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackgroundMusicBooleanRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.r2.bucket' => 'test-bucket']);
        Storage::fake('r2');
    }

    /**
     * Helper to create a valid minimal MP3 file.
     */
    private function createValidMp3File(string $filename = 'test.mp3'): UploadedFile
    {
        $id3Header = "ID3\x04\x00\x00\x00\x00\x00\x00";
        $mp3Frame = "\xFF\xFB\x90\x44".str_repeat("\x00", 414);
        $content = $id3Header.str_repeat($mp3Frame, 5);

        return UploadedFile::fake()->createWithContent($filename, $content);
    }

    /**
     * Test 1: Both fields can independently and simultaneously become false via FormData text values.
     */
    public function test_fields_can_simultaneously_become_false_via_form_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        // Start with both true in the database
        $music = Music::instance();
        $music->is_enabled = true;
        $music->is_loop = true;
        $music->save();

        $this->assertDatabaseHas('music', ['id' => 1, 'is_enabled' => true, 'is_loop' => true]);

        // POST request sending FormData string values 'false'
        $response = $this->post('/api/v1/admin/background-music', [
            'is_enabled' => 'false',
            'is_loop' => 'false',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.is_loop', false);

        // Verify response types are strictly boolean
        $responseData = $response->json('data');
        $this->assertIsBool($responseData['is_enabled']);
        $this->assertFalse($responseData['is_enabled']);
        $this->assertIsBool($responseData['is_loop']);
        $this->assertFalse($responseData['is_loop']);

        // Verify database directly
        $this->assertDatabaseHas('music', ['id' => 1, 'is_enabled' => false, 'is_loop' => false]);

        $fresh = Music::find(1);
        $this->assertIsBool($fresh->is_enabled);
        $this->assertFalse($fresh->is_enabled);
        $this->assertIsBool($fresh->is_loop);
        $this->assertFalse($fresh->is_loop);
    }

    /**
     * Test 2: Each field can independently become false while the other remains true.
     */
    public function test_each_field_can_independently_become_false(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        // Sub-test A: is_enabled=false, is_loop=true
        $responseA = $this->post('/api/v1/admin/background-music', [
            'is_enabled' => 'false',
            'is_loop' => 'true',
        ]);

        $responseA->assertStatus(200)
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.is_loop', true);

        $this->assertDatabaseHas('music', ['id' => 1, 'is_enabled' => false, 'is_loop' => true]);

        // Sub-test B: is_enabled=true, is_loop=false
        $responseB = $this->post('/api/v1/admin/background-music', [
            'is_enabled' => 'true',
            'is_loop' => 'false',
        ]);

        $responseB->assertStatus(200)
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.is_loop', false);

        $this->assertDatabaseHas('music', ['id' => 1, 'is_enabled' => true, 'is_loop' => false]);
    }

    /**
     * Test 3: Updating boolean fields along with a new audio file preserves upload and toggles correctly.
     */
    public function test_updating_booleans_with_audio_file_upload(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        $file = $this->createValidMp3File('new_track.mp3');

        $response = $this->post('/api/v1/admin/background-music', [
            'audio_file' => $file,
            'is_enabled' => 'false',
            'is_loop' => 'false',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.is_loop', false)
            ->assertJsonPath('data.has_track', true);

        $this->assertDatabaseHas('music', [
            'id' => 1,
            'file_name' => 'new_track.mp3',
            'is_enabled' => false,
            'is_loop' => false,
        ]);

        $music = Music::find(1);
        $this->assertNotNull($music->file_path);
        Storage::disk('r2')->assertExists($music->file_path);
    }

    /**
     * Test 4: PUT method via _method spoofing (standard Laravel FormData PUT) works cleanly.
     */
    public function test_put_via_method_spoofing_works_with_form_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['access']);

        $music = Music::instance();
        $music->is_enabled = true;
        $music->is_loop = true;
        $music->save();

        $response = $this->post('/api/v1/admin/background-music', [
            '_method' => 'PUT',
            'is_enabled' => 'false',
            'is_loop' => 'false',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.is_loop', false);

        $this->assertDatabaseHas('music', ['id' => 1, 'is_enabled' => false, 'is_loop' => false]);
    }
}
