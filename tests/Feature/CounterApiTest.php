<?php

namespace Tests\Feature;

use App\Models\Counter;
use App\Models\User;
use Database\Seeders\CounterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CounterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed initial counters
        $this->seed(CounterSeeder::class);
    }

    public function test_public_endpoint_returns_counters_in_correct_order_without_auth(): void
    {
        $response = $this->getJson('/api/v1/counters');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'key', 'name', 'value', 'order'],
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(5, $data);

        $this->assertSame('clients_served', $data[0]['key']);
        $this->assertSame(15, $data[0]['value']);
        $this->assertSame(1, $data[0]['order']);

        $this->assertSame('campaigns_launched', $data[1]['key']);
        $this->assertSame(400, $data[1]['value']);
        $this->assertSame(2, $data[1]['order']);

        $this->assertSame('social_content_published', $data[2]['key']);
        $this->assertSame(2000, $data[2]['value']);
        $this->assertSame(3, $data[2]['order']);

        $this->assertSame('industries_covered', $data[3]['key']);
        $this->assertSame(18, $data[3]['value']);
        $this->assertSame(4, $data[3]['order']);

        $this->assertSame('marketing_experts', $data[4]['key']);
        $this->assertSame(10, $data[4]['value']);
        $this->assertSame(5, $data[4]['order']);
    }

    public function test_unauthenticated_user_cannot_access_dashboard_get_counters(): void
    {
        $response = $this->getJson('/api/v1/admin/counters');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_update_counters(): void
    {
        $response = $this->putJson('/api/v1/admin/counters', [
            'counters' => [
                ['key' => 'clients_served', 'value' => 20],
            ],
        ]);

        $response->assertStatus(401);
    }

    public function test_unauthorized_role_cannot_access_or_update_counters(): void
    {
        $user = User::factory()->create([
            'role' => 'editor',
        ]);
        $user->role = 'viewer';

        Sanctum::actingAs($user, ['access']);

        $getResponse = $this->getJson('/api/v1/admin/counters');

        $getResponse->assertStatus(403);

        $putResponse = $this->putJson('/api/v1/admin/counters', [
            'counters' => [
                ['key' => 'clients_served', 'value' => 50],
            ],
        ]);

        $putResponse->assertStatus(403);
    }

    public function test_admin_can_view_all_counters(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $token = $admin->createToken('access_token', ['access'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/counters');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data');
    }

    public function test_editor_can_view_all_counters(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
        ]);

        $token = $editor->createToken('access_token', ['access'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/counters');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data');
    }

    public function test_admin_can_update_single_counter_leaving_others_unchanged(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $token = $admin->createToken('access_token', ['access'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/admin/counters', [
                'counters' => [
                    ['key' => 'clients_served', 'value' => 25],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Counters updated successfully.',
            ]);

        $this->assertDatabaseHas('counters', [
            'key' => 'clients_served',
            'value' => 25,
        ]);

        // Verify other counters remain unchanged
        $this->assertDatabaseHas('counters', [
            'key' => 'campaigns_launched',
            'value' => 400,
        ]);
        $this->assertDatabaseHas('counters', [
            'key' => 'marketing_experts',
            'value' => 10,
        ]);
    }

    public function test_editor_can_update_multiple_counters_in_one_request(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
        ]);

        $token = $editor->createToken('access_token', ['access'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/admin/counters', [
                'counters' => [
                    ['key' => 'clients_served', 'value' => 30],
                    ['key' => 'campaigns_launched', 'value' => 450],
                    ['key' => 'marketing_experts', 'value' => 15],
                ],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('counters', [
            'key' => 'clients_served',
            'value' => 30,
        ]);
        $this->assertDatabaseHas('counters', [
            'key' => 'campaigns_launched',
            'value' => 450,
        ]);
        $this->assertDatabaseHas('counters', [
            'key' => 'marketing_experts',
            'value' => 15,
        ]);
    }

    public function test_atomic_rollback_on_invalid_counter_update(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $token = $admin->createToken('access_token', ['access'])->plainTextToken;

        // One valid update ('clients_served' => 99) and one invalid ('marketing_experts' => -5)
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/admin/counters', [
                'counters' => [
                    ['key' => 'clients_served', 'value' => 99],
                    ['key' => 'marketing_experts', 'value' => -5],
                ],
            ]);

        $response->assertStatus(422);

        // Assert atomic rollback: clients_served must NOT have been updated to 99
        $this->assertDatabaseHas('counters', [
            'key' => 'clients_served',
            'value' => 15,
        ]);
        $this->assertDatabaseHas('counters', [
            'key' => 'marketing_experts',
            'value' => 10,
        ]);
    }

    public function test_seeder_is_idempotent_and_does_not_reset_edited_values(): void
    {
        // Edit a counter value
        Counter::where('key', 'clients_served')->update(['value' => 999]);

        // Re-run the seeder
        $this->seed(CounterSeeder::class);

        // Ensure no duplicates exist (still exactly 5 rows)
        $this->assertSame(5, Counter::count());

        // Ensure edited value was preserved
        $this->assertSame(999, Counter::where('key', 'clients_served')->value('value'));
    }

    public function test_super_admin_can_view_and_update_counters(): void
    {
        $superAdmin = User::factory()->superAdmin()->create([
            'email' => User::DESIGNATED_SUPER_ADMIN_EMAIL,
        ]);
        $token = $superAdmin->createToken('access_token', ['access'])->plainTextToken;

        // View
        $getResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/counters');
        $getResponse->assertStatus(200)->assertJsonCount(5, 'data');

        // Update
        $putResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/admin/counters', [
                'counters' => [
                    ['key' => 'clients_served', 'value' => 88],
                ],
            ]);
        $putResponse->assertStatus(200);

        $this->assertDatabaseHas('counters', [
            'key' => 'clients_served',
            'value' => 88,
        ]);
    }
}
