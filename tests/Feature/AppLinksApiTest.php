<?php

namespace Tests\Feature;

use App\Models\AppLink;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppLinksApiTest extends TestCase
{
    use RefreshDatabase;

    private function getValidPayload(array $overrides = []): array
    {
        return array_merge([
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.app',
            'apple_app_store_link' => 'https://apps.apple.com/app/waai-media/id123456789',
            'app_gallery_link' => 'https://appgallery.huawei.com/app/C123456789',
        ], $overrides);
    }

    private function createAuthHeader(string $role = 'admin', array $abilities = ['access']): array
    {
        $user = User::factory()->create([
            'role' => $role,
        ]);

        $token = $user->createToken('test_token', $abilities)->plainTextToken;

        return [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
    }

    // =========================================================================
    // 1. Public Endpoint Tests
    // =========================================================================

    public function test_public_endpoint_returns_null_data_when_unconfigured(): void
    {
        $response = $this->getJson('/api/v1/app-links');

        $response->assertStatus(200)
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_public_endpoint_returns_app_links_without_internal_metadata_when_configured(): void
    {
        AppLink::create($this->getValidPayload());

        $response = $this->getJson('/api/v1/app-links');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'google_app_link',
                    'apple_app_store_link',
                    'app_gallery_link',
                ],
            ]);

        $data = $response->json('data');
        $this->assertSame('https://play.google.com/store/apps/details?id=com.waaimedia.app', $data['google_app_link']);
        $this->assertSame('https://apps.apple.com/app/waai-media/id123456789', $data['apple_app_store_link']);
        $this->assertSame('https://appgallery.huawei.com/app/C123456789', $data['app_gallery_link']);
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('singleton_lock', $data);
        $this->assertArrayNotHasKey('created_at', $data);
        $this->assertArrayNotHasKey('updated_at', $data);
    }

    // =========================================================================
    // 2. Authentication and Authorization Tests
    // =========================================================================

    public function test_unauthenticated_user_cannot_access_put_endpoint(): void
    {
        $response = $this->putJson('/api/v1/app-links', $this->getValidPayload());

        $response->assertStatus(401);
    }

    public function test_token_without_access_ability_is_rejected(): void
    {
        $headers = $this->createAuthHeader('admin', ['remember']);

        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', $this->getValidPayload());

        $response->assertStatus(403);
    }

    public function test_admin_can_update_app_links(): void
    {
        $headers = $this->createAuthHeader('admin');

        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', $this->getValidPayload());

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'App download links updated successfully.',
            ])
            ->assertJsonPath('data.google_app_link', 'https://play.google.com/store/apps/details?id=com.waaimedia.app');
    }

    public function test_editor_can_update_app_links(): void
    {
        $headers = $this->createAuthHeader('editor');

        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', $this->getValidPayload());

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'App download links updated successfully.',
            ]);
    }

    public function test_viewer_is_forbidden_from_updating_app_links(): void
    {
        $user = User::factory()->create([
            'role' => 'editor',
        ]);
        $user->role = 'viewer';

        Sanctum::actingAs($user, ['access']);

        $response = $this->putJson('/api/v1/app-links', $this->getValidPayload());

        $response->assertStatus(403);
    }

    public function test_unapproved_custom_role_is_forbidden(): void
    {
        $user = User::factory()->create([
            'role' => 'editor',
        ]);
        $user->role = 'guest_contributor';

        Sanctum::actingAs($user, ['access']);

        $response = $this->putJson('/api/v1/app-links', $this->getValidPayload());

        $response->assertStatus(403);
    }

    // =========================================================================
    // 3. Validation and Normalization Tests
    // =========================================================================

    public function test_missing_required_payload_properties_are_rejected(): void
    {
        $headers = $this->createAuthHeader('admin');

        // Missing app_gallery_link entirely
        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', [
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.app',
            'apple_app_store_link' => 'https://apps.apple.com/app/waai-media/id123456789',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['app_gallery_link']);
    }

    public function test_null_is_accepted_for_each_nullable_field(): void
    {
        $headers = $this->createAuthHeader('admin');

        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', [
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.app',
            'apple_app_store_link' => null,
            'app_gallery_link' => null,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.google_app_link', 'https://play.google.com/store/apps/details?id=com.waaimedia.app')
            ->assertJsonPath('data.apple_app_store_link', null)
            ->assertJsonPath('data.app_gallery_link', null);

        $this->assertDatabaseHas('app_links', [
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.app',
            'apple_app_store_link' => null,
            'app_gallery_link' => null,
            'singleton_lock' => 1,
        ]);
    }

    public function test_empty_strings_and_whitespace_are_normalized_to_null(): void
    {
        $headers = $this->createAuthHeader('admin');

        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', [
            'google_app_link' => '   ',
            'apple_app_store_link' => '',
            'app_gallery_link' => "  \t\n  ",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.google_app_link', null)
            ->assertJsonPath('data.apple_app_store_link', null)
            ->assertJsonPath('data.app_gallery_link', null);

        $this->assertDatabaseHas('app_links', [
            'google_app_link' => null,
            'apple_app_store_link' => null,
            'app_gallery_link' => null,
            'singleton_lock' => 1,
        ]);
    }

    public function test_malformed_urls_are_rejected(): void
    {
        $headers = $this->createAuthHeader('admin');

        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', $this->getValidPayload([
            'google_app_link' => 'not-a-valid-url',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['google_app_link']);
    }

    public function test_unsupported_schemes_are_rejected(): void
    {
        $headers = $this->createAuthHeader('admin');

        // javascript scheme
        $response1 = $this->withHeaders($headers)->putJson('/api/v1/app-links', $this->getValidPayload([
            'apple_app_store_link' => 'javascript:alert(1)',
        ]));
        $response1->assertStatus(422)->assertJsonValidationErrors(['apple_app_store_link']);

        // ftp scheme
        $response2 = $this->withHeaders($headers)->putJson('/api/v1/app-links', $this->getValidPayload([
            'apple_app_store_link' => 'ftp://files.example.com/app',
        ]));
        $response2->assertStatus(422)->assertJsonValidationErrors(['apple_app_store_link']);
    }

    public function test_non_string_values_are_rejected(): void
    {
        $headers = $this->createAuthHeader('admin');

        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', [
            'google_app_link' => 12345,
            'apple_app_store_link' => ['https://apps.apple.com/app/waai-media/id123456789'],
            'app_gallery_link' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['google_app_link', 'apple_app_store_link', 'app_gallery_link']);
    }

    public function test_values_over_500_characters_are_rejected(): void
    {
        $headers = $this->createAuthHeader('admin');

        $longUrl = 'https://play.google.com/store/apps/details?id='.str_repeat('a', 500);

        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', $this->getValidPayload([
            'google_app_link' => $longUrl,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['google_app_link']);
    }

    public function test_unexpected_fields_are_not_persisted(): void
    {
        $headers = $this->createAuthHeader('admin');

        $payload = $this->getValidPayload([
            'malicious_key' => 'arbitrary_data',
            'is_admin' => true,
        ]);

        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', $payload);

        $response->assertStatus(200);
        $record = AppLink::instance();
        $this->assertNotNull($record);
        $this->assertArrayNotHasKey('malicious_key', $record->getAttributes());
        $this->assertArrayNotHasKey('is_admin', $record->getAttributes());
    }

    public function test_failed_validation_does_not_modify_existing_singleton_record(): void
    {
        // Seed initial record
        AppLink::create($this->getValidPayload([
            'google_app_link' => 'https://play.google.com/store/apps/details?id=initial.app',
        ]));

        $headers = $this->createAuthHeader('admin');

        // Send invalid payload
        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', [
            'google_app_link' => 'invalid-url',
            'apple_app_store_link' => null,
            'app_gallery_link' => null,
        ]);

        $response->assertStatus(422);

        // Verify stored data is completely unchanged
        $record = AppLink::instance();
        $this->assertSame('https://play.google.com/store/apps/details?id=initial.app', $record->google_app_link);
    }

    // =========================================================================
    // 4. Persistence and Singleton Integrity Tests
    // =========================================================================

    public function test_first_valid_put_creates_singleton_record(): void
    {
        $this->assertSame(0, AppLink::count());

        $headers = $this->createAuthHeader('admin');

        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', $this->getValidPayload());

        $response->assertStatus(200);
        $this->assertSame(1, AppLink::count());
        $this->assertDatabaseHas('app_links', [
            'singleton_lock' => 1,
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.app',
        ]);
    }

    public function test_subsequent_put_updates_existing_singleton_record(): void
    {
        $headers = $this->createAuthHeader('admin');

        // First PUT
        $this->withHeaders($headers)->putJson('/api/v1/app-links', $this->getValidPayload());
        $this->assertSame(1, AppLink::count());

        // Second PUT with updated values
        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', $this->getValidPayload([
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.updated',
            'apple_app_store_link' => 'https://apps.apple.com/app/waai-media/id987654321',
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('data.google_app_link', 'https://play.google.com/store/apps/details?id=com.waaimedia.updated')
            ->assertJsonPath('data.apple_app_store_link', 'https://apps.apple.com/app/waai-media/id987654321');

        $this->assertSame(1, AppLink::count());
    }

    public function test_table_has_exactly_one_record_after_repeated_successful_put_requests(): void
    {
        $headers = $this->createAuthHeader('admin');

        for ($i = 1; $i <= 3; $i++) {
            $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', $this->getValidPayload([
                'google_app_link' => "https://play.google.com/store/apps/details?id=com.waaimedia.app{$i}",
            ]));
            $response->assertStatus(200);
            $this->assertSame(1, AppLink::count());
        }
    }

    public function test_all_null_values_are_supported(): void
    {
        $headers = $this->createAuthHeader('admin');

        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', [
            'google_app_link' => null,
            'apple_app_store_link' => null,
            'app_gallery_link' => null,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.google_app_link', null)
            ->assertJsonPath('data.apple_app_store_link', null)
            ->assertJsonPath('data.app_gallery_link', null);

        $this->assertSame(1, AppLink::count());
    }

    public function test_database_uniqueness_prevents_duplicate_singleton_insert(): void
    {
        AppLink::create($this->getValidPayload());

        $this->expectException(QueryException::class);

        // Attempt direct insertion of a second row with singleton_lock = 1
        DB::table('app_links')->insert([
            'singleton_lock' => 1,
            'google_app_link' => 'https://play.google.com/store/apps/details?id=duplicate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =========================================================================
    // 5. Multi-Process Concurrency Test
    // =========================================================================

    public function test_concurrent_first_time_put_requests_safely_serialize_into_single_record(): void
    {
        $this->assertSame(0, AppLink::count());

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $workerScript = base_path('scratch/app_links_concurrency_worker.php');
        $cmd1 = 'php '.$workerScript.' 1 https://play.google.com/store/apps/details?id=com.waaimedia.worker1 https://apps.apple.com/app/waai-media/id100000001';
        $cmd2 = 'php '.$workerScript.' 2 https://play.google.com/store/apps/details?id=com.waaimedia.worker2 https://apps.apple.com/app/waai-media/id100000002';

        $p1 = proc_open($cmd1, $descriptors, $pipes1, base_path());
        $p2 = proc_open($cmd2, $descriptors, $pipes2, base_path());

        $this->assertIsResource($p1);
        $this->assertIsResource($p2);

        fclose($pipes1[0]);
        fclose($pipes2[0]);

        $out1 = stream_get_contents($pipes1[1]);
        $err1 = stream_get_contents($pipes1[2]);
        fclose($pipes1[1]);
        fclose($pipes1[2]);

        $out2 = stream_get_contents($pipes2[1]);
        $err2 = stream_get_contents($pipes2[2]);
        fclose($pipes2[1]);
        fclose($pipes2[2]);

        $exit1 = proc_close($p1);
        $exit2 = proc_close($p2);

        $this->assertSame(0, $exit1, "Worker 1 failed: {$err1}");
        $this->assertSame(0, $exit2, "Worker 2 failed: {$err2}");

        $res1 = json_decode($out1, true);
        $res2 = json_decode($out2, true);

        $this->assertSame(200, $res1['status'] ?? 0);
        $this->assertSame(200, $res2['status'] ?? 0);

        // Reconnect database so the test runner reads external child process commits (bypassing RefreshDatabase transaction snapshot)
        DB::purge();

        // Verify singleton guarantees
        $this->assertSame(1, AppLink::count());
        $record = AppLink::instance();
        $this->assertNotNull($record);
        $this->assertSame(1, (int) $record->singleton_lock);
        $this->assertNotEmpty($record->google_app_link);
        $this->assertNotEmpty($record->apple_app_store_link);
    }

    public function test_super_admin_can_update_app_links(): void
    {
        $superAdmin = User::factory()->superAdmin()->create([
            'email' => User::DESIGNATED_SUPER_ADMIN_EMAIL,
        ]);
        $headers = [
            'Authorization' => 'Bearer '.$superAdmin->createToken('access_token', ['access'])->plainTextToken,
        ];

        $response = $this->withHeaders($headers)->putJson('/api/v1/app-links', [
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.super',
            'apple_app_store_link' => 'https://apps.apple.com/app/waaimedia/id987654321',
            'app_gallery_link' => 'https://appgallery.huawei.com/app/C12345678',
        ]);
        $response->assertStatus(200);

        $this->assertDatabaseHas('app_links', [
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.super',
        ]);
    }
}
