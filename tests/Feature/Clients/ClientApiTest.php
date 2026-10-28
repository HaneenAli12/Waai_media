<?php

namespace Tests\Feature\Clients;

use App\Models\Client;
use App\Models\User;
use App\Services\Storage\ImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientApiTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_1X1_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private const FIXTURE_1X1_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const BASE = '/api/v1/admin/clients';

    private const PUBLIC_BASE = '/api/v1/clients';

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

    private function jpeg(string $name = 'logo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_JPEG_BASE64));
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_PNG_BASE64));
    }

    private function clientWithLogo(?string $name = null): Client
    {
        $key = 'clients/logos/'.fake()->uuid().'.jpg';
        Storage::disk('r2')->put($key, base64_decode(self::FIXTURE_1X1_JPEG_BASE64));

        return Client::factory()->create([
            'name' => $name ?? fake()->company(),
            'logo_path' => $key,
        ]);
    }

    // =========================================================================
    // 1. Authentication & authorization
    // =========================================================================

    public function test_unauthenticated_requests_are_denied(): void
    {
        $client = $this->clientWithLogo();

        $this->getJson(self::BASE)->assertStatus(401);
        $this->postJson(self::BASE, [])->assertStatus(401);
        $this->getJson(self::BASE."/{$client->id}")->assertStatus(401);
        $this->patchJson(self::BASE."/{$client->id}", [])->assertStatus(401);
        $this->deleteJson(self::BASE."/{$client->id}")->assertStatus(401);
    }

    public function test_editor_can_list_show_create_and_update_but_not_delete(): void
    {
        $client = $this->clientWithLogo('Acme Logistics');
        Sanctum::actingAs($this->editor, ['access']);

        $this->getJson(self::BASE)->assertStatus(200);
        $this->getJson(self::BASE."/{$client->id}")->assertStatus(200);

        $this->post(self::BASE, [
            'name' => 'Starlight Media',
            'logo' => $this->png(),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $this->patchJson(self::BASE."/{$client->id}", ['name' => 'Acme Global'])->assertStatus(200);

        $this->deleteJson(self::BASE."/{$client->id}")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden. Editors are strictly prohibited from performing delete operations.');

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_super_admin_has_full_crud_and_delete_access_to_clients(): void
    {
        $superAdmin = User::factory()->superAdmin()->create([
            'email' => User::DESIGNATED_SUPER_ADMIN_EMAIL,
        ]);
        Sanctum::actingAs($superAdmin, ['access']);

        // 1. Index
        $this->getJson(self::BASE)->assertStatus(200);

        // 2. Create
        $createRes = $this->post(self::BASE, [
            'name' => 'Horizon Ventures',
            'logo' => $this->png(),
        ], ['Accept' => 'application/json']);
        $createRes->assertStatus(201);
        $clientId = $createRes->json('data.id');

        // 3. Update
        $this->patchJson(self::BASE."/{$clientId}", [
            'name' => 'Horizon International',
        ])->assertStatus(200);

        // 4. Delete
        $this->deleteJson(self::BASE."/{$clientId}")->assertStatus(200);
        $this->assertDatabaseMissing('clients', ['id' => $clientId]);
    }

    // =========================================================================
    // 2. Create
    // =========================================================================

    public function test_admin_can_create_client_with_logo_uploaded_to_r2(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $response = $this->post(self::BASE, [
            'name' => 'Apex Technologies',
            'logo' => $this->jpeg(),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Apex Technologies')
            ->assertJsonStructure(['data' => ['id', 'name', 'logo_url', 'created_at', 'updated_at']]);

        $client = Client::findOrFail($response->json('data.id'));

        expect($client->logo_path)->toStartWith('clients/logos/')->toEndWith('.jpg');
        Storage::disk('r2')->assertExists($client->logo_path);
        expect($response->json('data.logo_url'))->toBe("https://pub-r2.waai.test/{$client->logo_path}");
    }

    public function test_logo_keys_are_unique_and_never_client_controlled(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $a = $this->post(self::BASE, ['name' => 'Client A', 'logo' => $this->png('../../evil.png')], ['Accept' => 'application/json']);
        $b = $this->post(self::BASE, ['name' => 'Client B', 'logo' => $this->png('../../evil.png')], ['Accept' => 'application/json']);

        $keyA = Client::find($a->json('data.id'))->logo_path;
        $keyB = Client::find($b->json('data.id'))->logo_path;

        expect($keyA)->not->toBe($keyB)->and($keyA)->not->toContain('evil');
        expect(Storage::disk('r2')->allFiles())->toHaveCount(2);
    }

    public function test_name_and_logo_are_required_on_create(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $this->postJson(self::BASE, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'logo']);

        $this->postJson(self::BASE, ['name' => '   ', 'logo' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'logo']);

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_create_missing_logo_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $this->postJson(self::BASE, ['name' => 'No Logo Co'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);

        $this->assertDatabaseCount('clients', 0);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    public function test_invalid_logo_type_is_rejected_without_side_effects(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $this->post(self::BASE, [
            'name' => 'Bad Format Inc',
            'logo' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);

        $this->assertDatabaseCount('clients', 0);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    public function test_disguised_non_image_content_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $fake = UploadedFile::fake()->createWithContent('malicious.jpg', '<?php echo "pwned"; ?>');

        $this->post(self::BASE, ['name' => 'Fake Image Corp', 'logo' => $fake], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);

        $this->assertDatabaseCount('clients', 0);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    public function test_oversized_logo_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $this->post(self::BASE, [
            'name' => 'Huge Corp',
            'logo' => UploadedFile::fake()->create('huge.jpg', 6 * 1024, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);

        $this->assertDatabaseCount('clients', 0);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    public function test_create_database_failure_removes_uploaded_logo(): void
    {
        Sanctum::actingAs($this->admin, ['access']);
        Client::creating(fn () => throw new \RuntimeException('DB down'));

        $this->post(self::BASE, ['name' => 'Fail Corp', 'logo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $this->assertDatabaseCount('clients', 0);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    public function test_storage_upload_failure_creates_no_record(): void
    {
        Sanctum::actingAs($this->admin, ['access']);
        Config::set('filesystems.disks.r2.url', null); // unresolvable public URL → upload aborts before storing

        $this->post(self::BASE, ['name' => 'NoStore Corp', 'logo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $this->assertDatabaseCount('clients', 0);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    // =========================================================================
    // 3. List & Show
    // =========================================================================

    public function test_list_is_paginated_newest_first_with_deterministic_order(): void
    {
        $same = now()->startOfSecond();
        foreach (range(1, 12) as $i) {
            Client::factory()->create([
                'name' => "Client {$i}",
                'created_at' => $same,
                'updated_at' => $same,
            ]);
        }
        Sanctum::actingAs($this->admin, ['access']);

        $page1 = $this->getJson(self::BASE)->assertStatus(200);
        $page1->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['data' => [['id', 'name', 'logo_url', 'created_at', 'updated_at']], 'links' => ['first', 'last', 'prev', 'next'], 'meta']);

        // Equal timestamps → ties broken by id desc
        expect(collect($page1->json('data'))->pluck('name')->first())->toBe('Client 12');

        $page2 = $this->getJson(self::BASE.'?page=2')->assertStatus(200);
        $page2->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2);
        expect(collect($page2->json('data'))->pluck('name')->all())->toBe(['Client 2', 'Client 1']);

        $ids = array_merge(collect($page1->json('data'))->pluck('id')->all(), collect($page2->json('data'))->pluck('id')->all());
        expect($ids)->toHaveCount(12)->and(array_unique($ids))->toHaveCount(12);
    }

    public function test_list_orders_by_created_at_desc(): void
    {
        $old = Client::factory()->create(['name' => 'Old Client', 'created_at' => now()->subDays(2)]);
        $new = Client::factory()->create(['name' => 'New Client', 'created_at' => now()->subDay()]);
        Sanctum::actingAs($this->admin, ['access']);

        $ids = collect($this->getJson(self::BASE)->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$new->id, $old->id]);
    }

    public function test_list_validates_per_page(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $this->getJson(self::BASE.'?per_page=1000')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson(self::BASE.'?per_page=5')->assertStatus(200)->assertJsonPath('meta.per_page', 5);
    }

    public function test_show_returns_client_with_logo_url_and_404_when_missing(): void
    {
        $client = $this->clientWithLogo('Prime Partners');
        Sanctum::actingAs($this->admin, ['access']);

        $this->getJson(self::BASE."/{$client->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.name', 'Prime Partners')
            ->assertJsonPath('data.logo_url', "https://pub-r2.waai.test/{$client->logo_path}");

        $this->getJson(self::BASE.'/999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'The requested resource was not found.');
    }

    // =========================================================================
    // 4. Update
    // =========================================================================

    public function test_update_name_only_preserves_logo(): void
    {
        $client = $this->clientWithLogo('Old Name');
        $key = $client->logo_path;
        Sanctum::actingAs($this->admin, ['access']);

        $this->patchJson(self::BASE."/{$client->id}", ['name' => 'Renamed Corporation'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Renamed Corporation');

        expect($client->fresh()->logo_path)->toBe($key);
        Storage::disk('r2')->assertExists($key);
    }

    public function test_update_logo_only_preserves_name(): void
    {
        $client = $this->clientWithLogo('Preserved Name');
        $oldKey = $client->logo_path;
        Sanctum::actingAs($this->admin, ['access']);

        $this->post(self::BASE."/{$client->id}", ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $fresh = $client->fresh();
        expect($fresh->name)->toBe('Preserved Name')
            ->and($fresh->logo_path)->not->toBe($oldKey);

        Storage::disk('r2')->assertExists($fresh->logo_path);
        Storage::disk('r2')->assertMissing($oldKey);
    }

    public function test_update_name_and_logo_together(): void
    {
        $client = $this->clientWithLogo('Alpha Corp');
        $oldKey = $client->logo_path;
        Sanctum::actingAs($this->admin, ['access']);

        $this->post(self::BASE."/{$client->id}", [
            'name' => 'Beta Global',
            'logo' => $this->png(),
        ], ['Accept' => 'application/json'])->assertStatus(200);

        $fresh = $client->fresh();
        expect($fresh->name)->toBe('Beta Global')
            ->and($fresh->logo_path)->not->toBe($oldKey);

        Storage::disk('r2')->assertExists($fresh->logo_path);
        Storage::disk('r2')->assertMissing($oldKey);
    }

    public function test_update_rejects_empty_name(): void
    {
        $client = $this->clientWithLogo();
        Sanctum::actingAs($this->admin, ['access']);

        $this->patchJson(self::BASE."/{$client->id}", ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_invalid_replacement_logo_preserves_existing_logo_and_data(): void
    {
        $client = $this->clientWithLogo('Original Ltd');
        $oldKey = $client->logo_path;
        Sanctum::actingAs($this->admin, ['access']);

        $this->post(self::BASE."/{$client->id}", [
            'name' => 'Should Not Apply',
            'logo' => UploadedFile::fake()->create('bad.txt', 50, 'text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);

        expect($client->fresh()->name)->toBe('Original Ltd')
            ->and($client->fresh()->logo_path)->toBe($oldKey);
        Storage::disk('r2')->assertExists($oldKey);
        expect(Storage::disk('r2')->allFiles())->toHaveCount(1);
    }

    public function test_database_failure_on_replacement_preserves_old_logo_and_removes_new_upload(): void
    {
        $client = $this->clientWithLogo();
        $oldKey = $client->logo_path;
        Sanctum::actingAs($this->admin, ['access']);
        Client::updating(fn () => throw new \RuntimeException('DB down'));

        $this->post(self::BASE."/{$client->id}", ['name' => 'Nope', 'logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertStatus(500);

        expect($client->fresh()->logo_path)->toBe($oldKey);
        Storage::disk('r2')->assertExists($oldKey);
        expect(Storage::disk('r2')->allFiles())->toHaveCount(1);
    }

    public function test_update_missing_client_returns_404(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $this->patchJson(self::BASE.'/999999', ['name' => 'Ghost'])->assertStatus(404);
    }

    // =========================================================================
    // 5. Delete
    // =========================================================================

    public function test_admin_delete_removes_record_and_logo(): void
    {
        $client = $this->clientWithLogo();
        $other = $this->clientWithLogo();
        $key = $client->logo_path;
        Sanctum::actingAs($this->admin, ['access']);

        $this->deleteJson(self::BASE."/{$client->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Client deleted successfully.');

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
        Storage::disk('r2')->assertMissing($key);
        Storage::disk('r2')->assertExists($other->logo_path);
    }

    public function test_repeated_delete_returns_standard_404(): void
    {
        $client = $this->clientWithLogo();
        Sanctum::actingAs($this->admin, ['access']);

        $this->deleteJson(self::BASE."/{$client->id}")->assertStatus(200);
        $this->deleteJson(self::BASE."/{$client->id}")->assertStatus(404);
    }

    public function test_delete_missing_client_returns_404(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $this->deleteJson(self::BASE.'/999999')->assertStatus(404);
    }

    public function test_delete_succeeds_when_logo_object_is_already_missing(): void
    {
        $client = $this->clientWithLogo();
        Storage::disk('r2')->delete($client->logo_path);
        Sanctum::actingAs($this->admin, ['access']);

        $this->deleteJson(self::BASE."/{$client->id}")->assertStatus(200);
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_delete_succeeds_even_if_storage_deletion_fails(): void
    {
        $client = $this->clientWithLogo();
        Sanctum::actingAs($this->admin, ['access']);

        $storage = \Mockery::mock(ImageStorageService::class)->makePartial();
        $storage->shouldReceive('delete')->andThrow(new \RuntimeException('R2 unavailable'));
        $this->app->instance(ImageStorageService::class, $storage);

        $this->deleteJson(self::BASE."/{$client->id}")->assertStatus(200);
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_delete_does_not_remove_logo_shared_with_another_client(): void
    {
        $client = $this->clientWithLogo();
        $twin = Client::factory()->create(['logo_path' => $client->logo_path]);
        Sanctum::actingAs($this->admin, ['access']);

        $this->deleteJson(self::BASE."/{$client->id}")->assertStatus(200);

        Storage::disk('r2')->assertExists($twin->logo_path);
    }

    public function test_delete_never_touches_keys_outside_client_logos_prefix(): void
    {
        Storage::disk('r2')->put('events/covers/'.fake()->uuid().'.jpg', 'x');
        $foreignKey = Storage::disk('r2')->allFiles()[0];
        $client = Client::factory()->create(['logo_path' => $foreignKey]);
        Sanctum::actingAs($this->admin, ['access']);

        $this->deleteJson(self::BASE."/{$client->id}")->assertStatus(200);

        Storage::disk('r2')->assertExists($foreignKey);
    }

    public function test_resource_hides_logo_url_for_foreign_or_malformed_keys(): void
    {
        $client = Client::factory()->create(['logo_path' => 'events/covers/'.fake()->uuid().'.jpg']);
        Sanctum::actingAs($this->admin, ['access']);

        $this->getJson(self::BASE."/{$client->id}")->assertJsonPath('data.logo_url', null);
    }

    // =========================================================================
    // Concurrency (route-bound model goes stale between binding and update)
    // =========================================================================

    /**
     * Run $change right after the next Client is loaded (i.e. after route-model binding), simulating another
     * request that commits between this request's binding and its own transaction.
     */
    private function afterNextClientRetrieval(callable $change): void
    {
        $fired = false;
        Client::retrieved(function (Client $client) use (&$fired, $change) {
            if ($fired) {
                return;
            }
            $fired = true;
            $change($client);
        });
    }

    public function test_concurrent_replacement_deletes_the_committed_logo_not_the_stale_one(): void
    {
        $client = $this->clientWithLogo();
        $staleKey = $client->logo_path;

        // A competing request replaced the logo (A -> B) and already deleted A before ours runs
        $competingKey = 'clients/logos/'.fake()->uuid().'.jpg';
        Storage::disk('r2')->put($competingKey, 'competing');
        $this->afterNextClientRetrieval(function () use ($client, $staleKey, $competingKey) {
            DB::table('clients')->where('id', $client->id)->update(['logo_path' => $competingKey]);
            Storage::disk('r2')->delete($staleKey);
        });

        Sanctum::actingAs($this->admin, ['access']);
        $this->post(self::BASE."/{$client->id}", ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $newKey = $client->fresh()->logo_path;
        expect($newKey)->not->toBe($competingKey)->and($newKey)->not->toBe($staleKey);
        Storage::disk('r2')->assertExists($newKey);
        // B was superseded by our replacement and must not be leaked
        Storage::disk('r2')->assertMissing($competingKey);
        expect(Storage::disk('r2')->allFiles())->toHaveCount(1);
    }

    public function test_replacement_for_a_client_deleted_concurrently_returns_404_and_removes_the_upload(): void
    {
        $client = $this->clientWithLogo();

        $this->afterNextClientRetrieval(function () use ($client) {
            DB::table('clients')->where('id', $client->id)->delete();
        });

        Sanctum::actingAs($this->admin, ['access']);
        $this->post(self::BASE."/{$client->id}", ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertStatus(404);

        // Only the original (still stored) logo remains; the new upload was removed
        expect(Storage::disk('r2')->allFiles())->toHaveCount(1);
    }

    public function test_delete_removes_the_committed_logo_after_a_concurrent_replacement(): void
    {
        $client = $this->clientWithLogo();
        $staleKey = $client->logo_path;

        $replacementKey = 'clients/logos/'.fake()->uuid().'.jpg';
        Storage::disk('r2')->put($replacementKey, 'replacement');
        $this->afterNextClientRetrieval(function () use ($client, $staleKey, $replacementKey) {
            DB::table('clients')->where('id', $client->id)->update(['logo_path' => $replacementKey]);
            Storage::disk('r2')->delete($staleKey);
        });

        Sanctum::actingAs($this->admin, ['access']);
        $this->deleteJson(self::BASE."/{$client->id}")->assertStatus(200);

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
        Storage::disk('r2')->assertMissing($replacementKey);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    public function test_replacement_never_deletes_a_logo_shared_with_another_client(): void
    {
        $client = $this->clientWithLogo();
        $shared = Client::factory()->create(['logo_path' => $client->logo_path]);
        Sanctum::actingAs($this->admin, ['access']);

        $this->post(self::BASE."/{$client->id}", ['logo' => $this->png()], ['Accept' => 'application/json'])
            ->assertStatus(200);

        Storage::disk('r2')->assertExists($shared->fresh()->logo_path);
    }

    // =========================================================================
    // 6. Public API (Home Page)
    // =========================================================================

    public function test_public_clients_list_is_accessible_without_authentication(): void
    {
        $c1 = $this->clientWithLogo('Nova Dynamics');
        $c2 = $this->clientWithLogo('Zenith Media');

        $response = $this->getJson(self::PUBLIC_BASE);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'logo_url',
                    ],
                ],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'from', 'last_page', 'path', 'per_page', 'to', 'total'],
            ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        // Verify public resource field restrictions: NO logo_path, created_at, updated_at
        $firstItem = $data[0];
        $this->assertArrayHasKey('id', $firstItem);
        $this->assertArrayHasKey('name', $firstItem);
        $this->assertArrayHasKey('logo_url', $firstItem);
        $this->assertArrayNotHasKey('logo_path', $firstItem);
        $this->assertArrayNotHasKey('created_at', $firstItem);
        $this->assertArrayNotHasKey('updated_at', $firstItem);
    }

    public function test_public_clients_list_returns_empty_paginated_array_when_no_clients_exist(): void
    {
        $response = $this->getJson(self::PUBLIC_BASE);

        $response->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_public_clients_list_supports_pagination_and_deterministic_order(): void
    {
        $same = now()->startOfSecond();
        foreach (range(1, 15) as $i) {
            Client::factory()->create([
                'name' => "Public Client {$i}",
                'created_at' => $same,
                'updated_at' => $same,
            ]);
        }

        $page1 = $this->getJson(self::PUBLIC_BASE.'?page=1&per_page=10')->assertStatus(200);
        $page1->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.last_page', 2);

        expect(collect($page1->json('data'))->pluck('name')->first())->toBe('Public Client 15');

        $page2 = $this->getJson(self::PUBLIC_BASE.'?page=2&per_page=10')->assertStatus(200);
        $page2->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_public_clients_does_not_expose_create_update_delete(): void
    {
        $client = $this->clientWithLogo();

        $this->postJson(self::PUBLIC_BASE, ['name' => 'Should Fail'])->assertStatus(405);
        $this->putJson(self::PUBLIC_BASE."/{$client->id}", ['name' => 'Should Fail'])->assertStatus(404);
        $this->deleteJson(self::PUBLIC_BASE."/{$client->id}")->assertStatus(404);
    }
}
