<?php

namespace Tests\Feature;

use App\Models\ContactInfo;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactInfoApiTest extends TestCase
{
    use RefreshDatabase;

    private function getValidPayload(array $overrides = []): array
    {
        return array_merge([
            'phone_1' => '+201000000000',
            'phone_2' => '+201100000000',
            'phone_3' => null,
            'official_email' => 'info@example.com',
            'facebook' => 'https://facebook.com/example',
            'instagram' => 'https://instagram.com/example',
            'youtube' => 'https://youtube.com/@example',
            'linkedin' => 'https://linkedin.com/company/example',
            'tiktok' => 'https://tiktok.com/@example',
            'whatsapp' => 'https://wa.me/201000000000',
            'complete_address' => '123 Main Street, Damietta, Egypt',
            'google_maps_url' => 'https://maps.app.goo.gl/example123',
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

    public function test_public_endpoint_returns_null_data_when_unconfigured(): void
    {
        $response = $this->getJson('/api/v1/contact-info');

        $response->assertStatus(200)
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_public_endpoint_returns_contact_info_without_internal_metadata_when_configured(): void
    {
        ContactInfo::create($this->getValidPayload());

        $response = $this->getJson('/api/v1/contact-info');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'phone_1',
                    'phone_2',
                    'phone_3',
                    'official_email',
                    'facebook',
                    'instagram',
                    'youtube',
                    'linkedin',
                    'tiktok',
                    'whatsapp',
                    'complete_address',
                    'google_maps_url',
                ],
            ]);

        $data = $response->json('data');
        $this->assertSame('+201000000000', $data['phone_1']);
        $this->assertSame('info@example.com', $data['official_email']);
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('singleton_lock', $data);
        $this->assertArrayNotHasKey('created_at', $data);
        $this->assertArrayNotHasKey('updated_at', $data);
    }

    public function test_unauthenticated_user_cannot_access_admin_get_contact_info(): void
    {
        $response = $this->getJson('/api/v1/admin/contact-info');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_access_admin_put_contact_info(): void
    {
        $response = $this->putJson('/api/v1/admin/contact-info', $this->getValidPayload());

        $response->assertStatus(401);
    }

    public function test_unauthorized_role_cannot_access_admin_endpoints(): void
    {
        $user = User::factory()->create([
            'role' => 'editor',
        ]);
        $user->role = 'viewer';

        Sanctum::actingAs($user, ['access']);

        $getResponse = $this->getJson('/api/v1/admin/contact-info');
        $getResponse->assertStatus(403);

        $putResponse = $this->putJson('/api/v1/admin/contact-info', $this->getValidPayload());
        $putResponse->assertStatus(403);
    }

    public function test_remember_or_refresh_token_rejected_by_require_access_middleware(): void
    {
        $headers = $this->createAuthHeader('admin', ['remember']);

        $getResponse = $this->withHeaders($headers)->getJson('/api/v1/admin/contact-info');
        $getResponse->assertStatus(403);

        $putResponse = $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $this->getValidPayload());
        $putResponse->assertStatus(403);
    }

    public function test_admin_and_editor_can_view_contact_info_via_admin_get(): void
    {
        // When unconfigured
        $adminHeaders = $this->createAuthHeader('admin');
        $this->withHeaders($adminHeaders)
            ->getJson('/api/v1/admin/contact-info')
            ->assertStatus(200)
            ->assertExactJson(['data' => null]);

        // Seed record
        ContactInfo::create($this->getValidPayload());

        // Admin can view
        $this->withHeaders($adminHeaders)
            ->getJson('/api/v1/admin/contact-info')
            ->assertStatus(200)
            ->assertJsonPath('data.official_email', 'info@example.com');

        // Editor can view
        $editorHeaders = $this->createAuthHeader('editor');
        $this->withHeaders($editorHeaders)
            ->getJson('/api/v1/admin/contact-info')
            ->assertStatus(200)
            ->assertJsonPath('data.official_email', 'info@example.com');
    }

    public function test_admin_can_initialize_singleton_contact_info_on_first_put(): void
    {
        $this->assertSame(0, ContactInfo::count());

        $headers = $this->createAuthHeader('admin');
        $payload = $this->getValidPayload();

        $response = $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Contact information updated successfully.',
            ])
            ->assertJsonPath('data.official_email', 'info@example.com')
            ->assertJsonPath('data.phone_1', '+201000000000');

        $this->assertSame(1, ContactInfo::count());
        $this->assertDatabaseHas('contact_info', [
            'official_email' => 'info@example.com',
            'phone_1' => '+201000000000',
            'singleton_lock' => 1,
        ]);
    }

    public function test_subsequent_put_updates_existing_singleton_record(): void
    {
        $headers = $this->createAuthHeader('admin');

        // First PUT
        $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $this->getValidPayload());
        $this->assertSame(1, ContactInfo::count());

        // Second PUT with updated values
        $updatedPayload = $this->getValidPayload([
            'phone_1' => '+201299999999',
            'official_email' => 'updated@example.com',
            'complete_address' => 'Updated Address in Cairo',
        ]);

        $response = $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $updatedPayload);

        $response->assertStatus(200)
            ->assertJsonPath('data.phone_1', '+201299999999')
            ->assertJsonPath('data.official_email', 'updated@example.com')
            ->assertJsonPath('data.complete_address', 'Updated Address in Cairo');

        $this->assertSame(1, ContactInfo::count());
        $this->assertDatabaseHas('contact_info', [
            'phone_1' => '+201299999999',
            'official_email' => 'updated@example.com',
            'complete_address' => 'Updated Address in Cairo',
        ]);
        $this->assertDatabaseMissing('contact_info', [
            'official_email' => 'info@example.com',
        ]);
    }

    public function test_editor_can_update_contact_info(): void
    {
        $headers = $this->createAuthHeader('editor');
        $payload = $this->getValidPayload(['official_email' => 'editor_saved@example.com']);

        $response = $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.official_email', 'editor_saved@example.com');

        $this->assertDatabaseHas('contact_info', [
            'official_email' => 'editor_saved@example.com',
        ]);
    }

    public function test_activity_log_is_recorded_on_successful_put(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('test_token', ['access'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson('/api/v1/admin/contact-info', $this->getValidPayload());

        $response->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'contact_info_updated',
        ]);
    }

    public function test_put_validates_all_required_fields(): void
    {
        $headers = $this->createAuthHeader('admin');
        $requiredFields = [
            'phone_1',
            'official_email',
            'facebook',
            'instagram',
            'youtube',
            'linkedin',
            'tiktok',
            'whatsapp',
            'complete_address',
            'google_maps_url',
        ];

        foreach ($requiredFields as $field) {
            $payload = $this->getValidPayload();
            unset($payload[$field]);

            $response = $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $payload);

            $response->assertStatus(422)
                ->assertJsonValidationErrors([$field]);
        }
    }

    public function test_put_validates_optional_phone_normalization(): void
    {
        $headers = $this->createAuthHeader('admin');

        // Whitespace & empty string normalized to null
        $payload = $this->getValidPayload([
            'phone_2' => '   ',
            'phone_3' => '',
        ]);

        $response = $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.phone_2', null)
            ->assertJsonPath('data.phone_3', null);

        $this->assertDatabaseHas('contact_info', [
            'phone_2' => null,
            'phone_3' => null,
        ]);

        // Array for phone must return 422, never 500
        $invalidPayload = $this->getValidPayload([
            'phone_2' => ['invalid', 'array'],
        ]);

        $invalidResponse = $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $invalidPayload);
        $invalidResponse->assertStatus(422)
            ->assertJsonValidationErrors(['phone_2']);
    }

    public function test_put_validates_flexible_urls(): void
    {
        $headers = $this->createAuthHeader('admin');

        // Valid URLs including short links, query params, fragments
        $validPayload = $this->getValidPayload([
            'facebook' => 'http://facebook.com/company',
            'instagram' => 'https://instagr.am/p/12345',
            'youtube' => 'https://youtu.be/xyz987',
            'google_maps_url' => 'https://maps.app.goo.gl/short456?g_st=ic#details',
            'whatsapp' => 'https://wa.me/201000000000?text=Hello%20World',
        ]);

        $response = $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $validPayload);
        $response->assertStatus(200);

        // Invalid URL schemes must fail with 422
        $invalidPayload = $this->getValidPayload([
            'facebook' => 'javascript:alert(1)',
        ]);

        $invalidResponse = $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $invalidPayload);
        $invalidResponse->assertStatus(422)
            ->assertJsonValidationErrors(['facebook']);
    }

    public function test_put_validates_email_format(): void
    {
        $headers = $this->createAuthHeader('admin');
        $payload = $this->getValidPayload(['official_email' => 'invalid-email-address']);

        $response = $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['official_email']);
    }

    public function test_atomic_update_preserves_existing_data_on_validation_failure(): void
    {
        $headers = $this->createAuthHeader('admin');

        // Initialize record
        $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $this->getValidPayload());

        // Send invalid payload
        $invalidPayload = $this->getValidPayload([
            'phone_1' => '+201599999999',
            'official_email' => 'not-an-email',
        ]);

        $response = $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $invalidPayload);
        $response->assertStatus(422);

        // Verify original data was preserved
        $this->assertDatabaseHas('contact_info', [
            'phone_1' => '+201000000000',
            'official_email' => 'info@example.com',
        ]);
        $this->assertDatabaseMissing('contact_info', [
            'phone_1' => '+201599999999',
        ]);
    }

    public function test_database_enforces_singleton_constraint_against_duplicate_rows(): void
    {
        ContactInfo::create($this->getValidPayload());
        $this->assertSame(1, ContactInfo::count());

        // Attempt direct insertion of second row with singleton_lock = 1
        $this->expectException(QueryException::class);

        DB::table('contact_info')->insert(array_merge($this->getValidPayload(), [
            'singleton_lock' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_repeated_identical_put_requests_remain_safe(): void
    {
        $headers = $this->createAuthHeader('admin');
        $payload = $this->getValidPayload();

        for ($i = 0; $i < 3; $i++) {
            $response = $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $payload);
            $response->assertStatus(200);
            $this->assertSame(1, ContactInfo::count());
        }
    }

    public function test_unsupported_http_methods_are_rejected(): void
    {
        $adminHeaders = $this->createAuthHeader('admin');

        // Public POST / DELETE
        $this->postJson('/api/v1/contact-info', [])->assertStatus(405);
        $this->deleteJson('/api/v1/contact-info')->assertStatus(405);

        // Admin POST / DELETE
        $this->withHeaders($adminHeaders)->postJson('/api/v1/admin/contact-info', [])->assertStatus(405);
        $this->withHeaders($adminHeaders)->deleteJson('/api/v1/admin/contact-info')->assertStatus(405);
    }

    public function test_public_get_reflects_changes_saved_by_admin(): void
    {
        $headers = $this->createAuthHeader('admin');

        $this->withHeaders($headers)->putJson('/api/v1/admin/contact-info', $this->getValidPayload([
            'phone_1' => '+201111222333',
            'official_email' => 'public_test@waaimedia.com',
        ]));

        $publicResponse = $this->getJson('/api/v1/contact-info');

        $publicResponse->assertStatus(200)
            ->assertJsonPath('data.phone_1', '+201111222333')
            ->assertJsonPath('data.official_email', 'public_test@waaimedia.com');
    }

    public function test_concurrent_first_time_put_requests_safely_serialize_into_single_record(): void
    {
        $this->assertSame(0, ContactInfo::count());

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $workerScript = base_path('scratch/concurrency_worker.php');
        $cmd1 = 'php '.$workerScript.' 1 worker_1@waaimedia.com +201000000001';
        $cmd2 = 'php '.$workerScript.' 2 worker_2@waaimedia.com +201000000002';

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
        $this->assertSame(1, ContactInfo::count());
        $record = ContactInfo::instance();
        $this->assertNotNull($record);
        $this->assertSame(1, (int) $record->singleton_lock);
        $this->assertNotEmpty($record->official_email);
        $this->assertNotEmpty($record->complete_address);
    }
}
