<?php

namespace Tests\Feature\Team;

use App\Models\TeamMember;
use App\Models\User;
use App\Services\Storage\ImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamMemberApiTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_1X1_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private const FIXTURE_1X1_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const BASE = '/api/v1/admin/team-members';

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

    private function jpeg(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_JPEG_BASE64));
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_PNG_BASE64));
    }

    private function memberWithPhoto(): TeamMember
    {
        $key = 'team/photos/'.fake()->uuid().'.jpg';
        Storage::disk('r2')->put($key, base64_decode(self::FIXTURE_1X1_JPEG_BASE64));

        return TeamMember::factory()->create(['photo_path' => $key]);
    }

    // =========================================================================
    // 1. Authentication & authorization
    // =========================================================================

    public function test_unauthenticated_requests_are_denied(): void
    {
        $member = TeamMember::factory()->create();

        $this->getJson(self::BASE)->assertStatus(401);
        $this->postJson(self::BASE, [])->assertStatus(401);
        $this->getJson(self::BASE."/{$member->id}")->assertStatus(401);
        $this->patchJson(self::BASE."/{$member->id}", [])->assertStatus(401);
        $this->deleteJson(self::BASE."/{$member->id}")->assertStatus(401);
    }

    public function test_editor_can_list_show_create_and_update_but_not_delete(): void
    {
        $member = TeamMember::factory()->create();
        Sanctum::actingAs($this->editor, ['access']);

        $this->getJson(self::BASE)->assertStatus(200);
        $this->getJson(self::BASE."/{$member->id}")->assertStatus(200);
        $this->postJson(self::BASE, ['name' => 'Editor Made', 'position' => 'Producer'])->assertStatus(201);
        $this->patchJson(self::BASE."/{$member->id}", ['name' => 'Renamed'])->assertStatus(200);

        $this->deleteJson(self::BASE."/{$member->id}")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden. Editors are strictly prohibited from performing delete operations.');
        $this->assertDatabaseHas('team_members', ['id' => $member->id]);
    }

    // =========================================================================
    // 2. Create
    // =========================================================================

    public function test_admin_can_create_member_without_photo(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $this->postJson(self::BASE, ['name' => 'Sara Ahmed', 'position' => 'Editor in Chief'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Sara Ahmed')
            ->assertJsonPath('data.position', 'Editor in Chief')
            ->assertJsonPath('data.photo_url', null)
            ->assertJsonStructure(['data' => ['id', 'name', 'position', 'photo_url', 'created_at', 'updated_at']]);

        $this->assertDatabaseHas('team_members', ['name' => 'Sara Ahmed', 'photo_path' => null]);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    public function test_admin_can_create_member_with_photo_uploaded_to_r2(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $response = $this->post(self::BASE, [
            'name' => 'Omar Khalid',
            'position' => 'Cinematographer',
            'photo' => $this->jpeg(),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);

        $member = TeamMember::findOrFail($response->json('data.id'));

        expect($member->photo_path)->toStartWith('team/photos/')->toEndWith('.jpg');
        Storage::disk('r2')->assertExists($member->photo_path);
        expect($response->json('data.photo_url'))->toBe("https://pub-r2.waai.test/{$member->photo_path}");
    }

    public function test_photo_keys_are_unique_and_never_client_controlled(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $a = $this->post(self::BASE, ['name' => 'A', 'position' => 'P', 'photo' => $this->png('../../evil.png')], ['Accept' => 'application/json']);
        $b = $this->post(self::BASE, ['name' => 'B', 'position' => 'P', 'photo' => $this->png('../../evil.png')], ['Accept' => 'application/json']);

        $keyA = TeamMember::find($a->json('data.id'))->photo_path;
        $keyB = TeamMember::find($b->json('data.id'))->photo_path;

        expect($keyA)->not->toBe($keyB)->and($keyA)->not->toContain('evil');
        expect(Storage::disk('r2')->allFiles())->toHaveCount(2);
    }

    public function test_name_and_position_are_required(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $this->postJson(self::BASE, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'position']);

        $this->postJson(self::BASE, ['name' => '   ', 'position' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'position']);

        $this->assertDatabaseCount('team_members', 0);
    }

    public function test_invalid_photo_type_is_rejected_without_side_effects(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $this->post(self::BASE, [
            'name' => 'Bad File', 'position' => 'X',
            'photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);

        $this->assertDatabaseCount('team_members', 0);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    public function test_disguised_non_image_content_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $fake = UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo "pwned"; ?>');

        $this->post(self::BASE, ['name' => 'Disguised', 'position' => 'X', 'photo' => $fake], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);

        $this->assertDatabaseCount('team_members', 0);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    public function test_oversized_photo_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $this->post(self::BASE, [
            'name' => 'Huge', 'position' => 'X',
            'photo' => UploadedFile::fake()->create('huge.jpg', 6 * 1024, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);

        $this->assertDatabaseCount('team_members', 0);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    public function test_create_database_failure_removes_uploaded_photo(): void
    {
        Sanctum::actingAs($this->admin, ['access']);
        TeamMember::creating(fn () => throw new \RuntimeException('DB down'));

        $this->post(self::BASE, ['name' => 'Fails', 'position' => 'X', 'photo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $this->assertDatabaseCount('team_members', 0);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    public function test_storage_upload_failure_creates_no_record(): void
    {
        Sanctum::actingAs($this->admin, ['access']);
        Config::set('filesystems.disks.r2.url', null); // unresolvable public URL → upload aborts before storing

        $this->post(self::BASE, ['name' => 'NoStore', 'position' => 'X', 'photo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $this->assertDatabaseCount('team_members', 0);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    // =========================================================================
    // 3. List & show
    // =========================================================================

    public function test_list_is_paginated_newest_first_with_deterministic_order(): void
    {
        $same = now()->startOfSecond();
        foreach (range(1, 12) as $i) {
            TeamMember::factory()->create(['name' => "Member {$i}", 'created_at' => $same, 'updated_at' => $same]);
        }
        Sanctum::actingAs($this->admin, ['access']);

        $page1 = $this->getJson(self::BASE)->assertStatus(200);
        $page1->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['data' => [['id', 'name', 'position', 'photo_url']], 'links' => ['first', 'last', 'prev', 'next'], 'meta']);

        // Equal timestamps → ties broken by id desc
        expect(collect($page1->json('data'))->pluck('name')->first())->toBe('Member 12');

        $page2 = $this->getJson(self::BASE.'?page=2')->assertStatus(200);
        $page2->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2);
        expect(collect($page2->json('data'))->pluck('name')->all())->toBe(['Member 2', 'Member 1']);

        $ids = array_merge(collect($page1->json('data'))->pluck('id')->all(), collect($page2->json('data'))->pluck('id')->all());
        expect($ids)->toHaveCount(12)->and(array_unique($ids))->toHaveCount(12);
    }

    public function test_list_orders_by_created_at_desc(): void
    {
        $old = TeamMember::factory()->create(['created_at' => now()->subDays(2)]);
        $new = TeamMember::factory()->create(['created_at' => now()->subDay()]);
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

    public function test_show_returns_member_with_photo_url_and_404_when_missing(): void
    {
        $member = $this->memberWithPhoto();
        Sanctum::actingAs($this->admin, ['access']);

        $this->getJson(self::BASE."/{$member->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonPath('data.photo_url', "https://pub-r2.waai.test/{$member->photo_path}");

        $this->getJson(self::BASE.'/999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'The requested resource was not found.');
    }

    // =========================================================================
    // 4. Update
    // =========================================================================

    public function test_update_name_and_position_preserves_photo(): void
    {
        $member = $this->memberWithPhoto();
        $key = $member->photo_path;
        Sanctum::actingAs($this->admin, ['access']);

        $this->patchJson(self::BASE."/{$member->id}", ['name' => 'New Name', 'position' => 'New Position'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.position', 'New Position');

        expect($member->fresh()->photo_path)->toBe($key);
        Storage::disk('r2')->assertExists($key);
    }

    public function test_partial_update_preserves_omitted_fields(): void
    {
        $member = TeamMember::factory()->create(['name' => 'Keep', 'position' => 'Old']);
        Sanctum::actingAs($this->admin, ['access']);

        $this->putJson(self::BASE."/{$member->id}", ['position' => 'Changed'])->assertStatus(200);

        $this->assertDatabaseHas('team_members', ['id' => $member->id, 'name' => 'Keep', 'position' => 'Changed']);
    }

    public function test_update_rejects_empty_required_fields(): void
    {
        $member = TeamMember::factory()->create();
        Sanctum::actingAs($this->admin, ['access']);

        $this->patchJson(self::BASE."/{$member->id}", ['name' => '', 'position' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'position']);
    }

    public function test_replacing_photo_stores_new_and_deletes_old_object(): void
    {
        $member = $this->memberWithPhoto();
        $oldKey = $member->photo_path;
        Sanctum::actingAs($this->admin, ['access']);

        $this->post(self::BASE."/{$member->id}", ['name' => 'With New Photo', 'photo' => $this->png()], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $newKey = $member->fresh()->photo_path;

        expect($newKey)->not->toBe($oldKey)->and($newKey)->toEndWith('.png');
        Storage::disk('r2')->assertExists($newKey);
        Storage::disk('r2')->assertMissing($oldKey);
        expect(Storage::disk('r2')->allFiles())->toHaveCount(1);
    }

    public function test_adding_first_photo_to_member_without_one(): void
    {
        $member = TeamMember::factory()->create();
        Sanctum::actingAs($this->admin, ['access']);

        $this->post(self::BASE."/{$member->id}", ['photo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(200);

        Storage::disk('r2')->assertExists($member->fresh()->photo_path);
    }

    public function test_invalid_replacement_photo_preserves_existing_photo_and_data(): void
    {
        $member = $this->memberWithPhoto();
        $oldKey = $member->photo_path;
        $oldName = $member->name;
        Sanctum::actingAs($this->admin, ['access']);

        $this->post(self::BASE."/{$member->id}", [
            'name' => 'Should Not Apply',
            'photo' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);

        expect($member->fresh()->name)->toBe($oldName)->and($member->fresh()->photo_path)->toBe($oldKey);
        Storage::disk('r2')->assertExists($oldKey);
        expect(Storage::disk('r2')->allFiles())->toHaveCount(1);
    }

    public function test_database_failure_on_replacement_preserves_old_photo_and_removes_new_upload(): void
    {
        $member = $this->memberWithPhoto();
        $oldKey = $member->photo_path;
        Sanctum::actingAs($this->admin, ['access']);
        TeamMember::updating(fn () => throw new \RuntimeException('DB down'));

        $this->post(self::BASE."/{$member->id}", ['name' => 'Nope', 'photo' => $this->png()], ['Accept' => 'application/json'])
            ->assertStatus(500);

        expect($member->fresh()->photo_path)->toBe($oldKey);
        Storage::disk('r2')->assertExists($oldKey);
        expect(Storage::disk('r2')->allFiles())->toHaveCount(1);
    }

    public function test_update_missing_member_returns_404(): void
    {
        Sanctum::actingAs($this->admin, ['access']);

        $this->patchJson(self::BASE.'/999999', ['name' => 'X'])->assertStatus(404);
    }

    // =========================================================================
    // 5. Delete
    // =========================================================================

    public function test_admin_delete_removes_record_and_photo(): void
    {
        $member = $this->memberWithPhoto();
        $other = $this->memberWithPhoto();
        $key = $member->photo_path;
        Sanctum::actingAs($this->admin, ['access']);

        $this->deleteJson(self::BASE."/{$member->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Team member deleted successfully.');

        $this->assertDatabaseMissing('team_members', ['id' => $member->id]);
        Storage::disk('r2')->assertMissing($key);
        Storage::disk('r2')->assertExists($other->photo_path);
    }

    public function test_delete_member_without_photo_succeeds(): void
    {
        $member = TeamMember::factory()->create();
        Sanctum::actingAs($this->admin, ['access']);

        $this->deleteJson(self::BASE."/{$member->id}")->assertStatus(200);
        $this->assertDatabaseMissing('team_members', ['id' => $member->id]);
    }

    public function test_repeated_delete_returns_standard_404(): void
    {
        $member = TeamMember::factory()->create();
        Sanctum::actingAs($this->admin, ['access']);

        $this->deleteJson(self::BASE."/{$member->id}")->assertStatus(200);
        $this->deleteJson(self::BASE."/{$member->id}")->assertStatus(404);
    }

    public function test_delete_succeeds_when_photo_object_is_already_missing(): void
    {
        $member = $this->memberWithPhoto();
        Storage::disk('r2')->delete($member->photo_path);
        Sanctum::actingAs($this->admin, ['access']);

        $this->deleteJson(self::BASE."/{$member->id}")->assertStatus(200);
        $this->assertDatabaseMissing('team_members', ['id' => $member->id]);
    }

    public function test_delete_succeeds_even_if_storage_deletion_fails(): void
    {
        $member = $this->memberWithPhoto();
        Sanctum::actingAs($this->admin, ['access']);

        $storage = \Mockery::mock(ImageStorageService::class)->makePartial();
        $storage->shouldReceive('delete')->andThrow(new \RuntimeException('R2 unavailable'));
        $this->app->instance(ImageStorageService::class, $storage);

        $this->deleteJson(self::BASE."/{$member->id}")->assertStatus(200);
        $this->assertDatabaseMissing('team_members', ['id' => $member->id]);
    }

    public function test_delete_does_not_remove_photo_shared_with_another_member(): void
    {
        $member = $this->memberWithPhoto();
        $twin = TeamMember::factory()->create(['photo_path' => $member->photo_path]);
        Sanctum::actingAs($this->admin, ['access']);

        $this->deleteJson(self::BASE."/{$member->id}")->assertStatus(200);

        Storage::disk('r2')->assertExists($twin->photo_path);
    }

    public function test_delete_never_touches_keys_outside_team_photos_prefix(): void
    {
        Storage::disk('r2')->put('events/covers/'.fake()->uuid().'.jpg', 'x');
        $foreignKey = Storage::disk('r2')->allFiles()[0];
        $member = TeamMember::factory()->create(['photo_path' => $foreignKey]);
        Sanctum::actingAs($this->admin, ['access']);

        $this->deleteJson(self::BASE."/{$member->id}")->assertStatus(200);

        Storage::disk('r2')->assertExists($foreignKey);
    }

    public function test_resource_hides_photo_url_for_foreign_or_malformed_keys(): void
    {
        $member = TeamMember::factory()->create(['photo_path' => 'events/covers/'.fake()->uuid().'.jpg']);
        Sanctum::actingAs($this->admin, ['access']);

        $this->getJson(self::BASE."/{$member->id}")->assertJsonPath('data.photo_url', null);
    }

    // =========================================================================
    // Concurrent replacement (route-bound model goes stale between binding and update)
    // =========================================================================

    /**
     * Run $change right after the next TeamMember is loaded (i.e. after route-model binding), simulating another
     * request that commits between this request's binding and its own transaction.
     */
    private function afterNextMemberRetrieval(callable $change): void
    {
        $fired = false;
        TeamMember::retrieved(function (TeamMember $member) use (&$fired, $change) {
            if ($fired) {
                return;
            }
            $fired = true;
            $change($member);
        });
    }

    public function test_concurrent_replacement_deletes_the_committed_photo_not_the_stale_one(): void
    {
        $member = $this->memberWithPhoto();
        $staleKey = $member->photo_path;

        // A competing request replaced the photo (A -> B) and already deleted A before ours runs
        $competingKey = 'team/photos/'.fake()->uuid().'.jpg';
        Storage::disk('r2')->put($competingKey, 'competing');
        $this->afterNextMemberRetrieval(function () use ($member, $staleKey, $competingKey) {
            DB::table('team_members')->where('id', $member->id)->update(['photo_path' => $competingKey]);
            Storage::disk('r2')->delete($staleKey);
        });

        Sanctum::actingAs($this->admin, ['access']);
        $this->post(self::BASE."/{$member->id}", ['photo' => $this->png()], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $newKey = $member->fresh()->photo_path;
        expect($newKey)->not->toBe($competingKey)->and($newKey)->not->toBe($staleKey);
        Storage::disk('r2')->assertExists($newKey);
        // B was superseded by our replacement and must not be leaked
        Storage::disk('r2')->assertMissing($competingKey);
        expect(Storage::disk('r2')->allFiles())->toHaveCount(1);
    }

    public function test_replacement_for_a_member_deleted_concurrently_returns_404_and_removes_the_upload(): void
    {
        $member = $this->memberWithPhoto();

        $this->afterNextMemberRetrieval(function () use ($member) {
            DB::table('team_members')->where('id', $member->id)->delete();
        });

        Sanctum::actingAs($this->admin, ['access']);
        $this->post(self::BASE."/{$member->id}", ['photo' => $this->png()], ['Accept' => 'application/json'])
            ->assertStatus(404);

        // Only the original (still stored) photo remains; the new upload was removed
        expect(Storage::disk('r2')->allFiles())->toHaveCount(1);
    }

    public function test_delete_removes_the_committed_photo_after_a_concurrent_replacement(): void
    {
        $member = $this->memberWithPhoto();
        $staleKey = $member->photo_path;

        $replacementKey = 'team/photos/'.fake()->uuid().'.jpg';
        Storage::disk('r2')->put($replacementKey, 'replacement');
        $this->afterNextMemberRetrieval(function () use ($member, $staleKey, $replacementKey) {
            DB::table('team_members')->where('id', $member->id)->update(['photo_path' => $replacementKey]);
            Storage::disk('r2')->delete($staleKey);
        });

        Sanctum::actingAs($this->admin, ['access']);
        $this->deleteJson(self::BASE."/{$member->id}")->assertStatus(200);

        $this->assertDatabaseMissing('team_members', ['id' => $member->id]);
        Storage::disk('r2')->assertMissing($replacementKey);
        expect(Storage::disk('r2')->allFiles())->toBeEmpty();
    }

    public function test_replacement_never_deletes_a_photo_shared_with_another_member(): void
    {
        $member = $this->memberWithPhoto();
        $shared = TeamMember::factory()->create(['photo_path' => $member->photo_path]);
        Sanctum::actingAs($this->admin, ['access']);

        $this->post(self::BASE."/{$member->id}", ['photo' => $this->png()], ['Accept' => 'application/json'])
            ->assertStatus(200);

        Storage::disk('r2')->assertExists($shared->fresh()->photo_path);
    }

    public function test_super_admin_has_full_crud_and_delete_access_to_team_members(): void
    {
        $superAdmin = User::factory()->superAdmin()->create([
            'email' => User::DESIGNATED_SUPER_ADMIN_EMAIL,
        ]);
        Sanctum::actingAs($superAdmin, ['access']);

        // 1. Index
        $this->getJson(self::BASE)->assertStatus(200);

        // 2. Create
        $createRes = $this->postJson(self::BASE, [
            'name' => 'Super Admin Team Lead',
            'position' => 'Chief Operations Officer',
        ]);
        $createRes->assertStatus(201);
        $memberId = $createRes->json('data.id');

        // 3. Update
        $this->patchJson(self::BASE."/{$memberId}", [
            'name' => 'Updated Lead Name',
            'position' => 'Chief Technology Officer',
        ])->assertStatus(200);

        // 4. Delete
        $this->deleteJson(self::BASE."/{$memberId}")->assertStatus(200);
        $this->assertDatabaseMissing('team_members', ['id' => $memberId]);
    }

    // =========================================================================
    // 7. Public API (Home Page & About Us)
    // =========================================================================

    public function test_public_team_members_list_is_accessible_without_authentication(): void
    {
        $memberWithPhoto = $this->memberWithPhoto();
        $memberWithoutPhoto = TeamMember::factory()->create([
            'name' => 'Sara No Photo',
            'position' => 'Editor',
            'photo_path' => null,
        ]);

        $response = $this->getJson('/api/v1/team-members');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'position',
                        'photo_url',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        // Find the member with photo and check photo_url
        $withPhotoItem = collect($data)->firstWhere('id', $memberWithPhoto->id);
        $this->assertNotNull($withPhotoItem);
        $this->assertStringStartsWith('https://pub-r2.waai.test/team/photos/', $withPhotoItem['photo_url']);

        // Find member without photo and check photo_url is null
        $withoutPhotoItem = collect($data)->firstWhere('id', $memberWithoutPhoto->id);
        $this->assertNotNull($withoutPhotoItem);
        $this->assertNull($withoutPhotoItem['photo_url']);
    }

    public function test_public_team_members_list_returns_empty_data_array_when_no_members_exist(): void
    {
        $response = $this->getJson('/api/v1/team-members');

        $response->assertStatus(200)
            ->assertExactJson(['data' => []]);
    }

    public function test_public_team_members_list_is_ordered_consistently_newest_first(): void
    {
        $member1 = TeamMember::factory()->create(['name' => 'First Member', 'created_at' => now()->subDays(2)]);
        $member2 = TeamMember::factory()->create(['name' => 'Second Member', 'created_at' => now()->subDay()]);
        $member3 = TeamMember::factory()->create(['name' => 'Third Member', 'created_at' => now()]);

        $response = $this->getJson('/api/v1/team-members');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$member3->id, $member2->id, $member1->id], $ids);
    }
}
