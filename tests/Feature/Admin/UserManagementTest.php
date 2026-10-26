<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('unauthenticated requests to all users endpoints are rejected with 401', function (string $method, string $uri) {
    $this->json($method, $uri)->assertStatus(401);
})->with([
    ['GET', '/api/v1/admin/users'],
    ['POST', '/api/v1/admin/users'],
    ['GET', '/api/v1/admin/users/999999'],
    ['PUT', '/api/v1/admin/users/999999'],
    ['PATCH', '/api/v1/admin/users/999999/role'],
    ['PATCH', '/api/v1/admin/users/999999/password'],
    ['DELETE', '/api/v1/admin/users/999999'],
]);

test('editors are strictly forbidden from all users management endpoints with 403', function (string $method, string $path) {
    $editor = User::factory()->editor()->create();
    $targetUser = User::factory()->editor()->create();
    Sanctum::actingAs($editor, ['access']);

    $uri = str_replace('{id}', (string) $targetUser->id, $path);

    $response = $this->json($method, $uri, [
        'name' => 'Attempted Edit',
        'email' => 'attempt@waaimedia.com',
        'role' => 'admin',
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ]);

    $response->assertStatus(403);
})->with([
    ['GET', '/api/v1/admin/users'],
    ['POST', '/api/v1/admin/users'],
    ['GET', '/api/v1/admin/users/{id}'],
    ['PUT', '/api/v1/admin/users/{id}'],
    ['PATCH', '/api/v1/admin/users/{id}/role'],
    ['PATCH', '/api/v1/admin/users/{id}/password'],
    ['DELETE', '/api/v1/admin/users/{id}'],
]);

test('admin can list users with pagination, search, and role filters', function () {
    $admin = User::factory()->admin()->create(['name' => 'Super Admin', 'email' => 'mainadmin@waaimedia.com']);
    User::factory()->editor()->create(['name' => 'Editor Alice', 'email' => 'alice@waaimedia.com']);
    User::factory()->editor()->create(['name' => 'Editor Bob', 'email' => 'bob@waaimedia.com']);
    Sanctum::actingAs($admin, ['access']);

    // 1. Basic list
    $res = $this->getJson('/api/v1/admin/users');
    $res->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'email', 'role', 'created_at', 'updated_at'],
            ],
            'meta' => ['current_page', 'total', 'per_page'],
        ]);

    // Ensure password and internal fields are never exposed
    $firstItem = $res->json('data.0');
    expect($firstItem)->not->toHaveKey('password')
        ->not->toHaveKey('remember_token');

    // 2. Filter by role
    $editorList = $this->getJson('/api/v1/admin/users?role=editor');
    $editorList->assertStatus(200);
    foreach ($editorList->json('data') as $user) {
        expect($user['role'])->toBe('editor');
    }

    // 3. Search by name
    $searchList = $this->getJson('/api/v1/admin/users?search=Alice');
    $searchList->assertStatus(200);
    expect($searchList->json('data'))->toHaveCount(1)
        ->and($searchList->json('data.0.name'))->toBe('Editor Alice');
});

test('admin can view a single user details', function () {
    $admin = User::factory()->admin()->create();
    $editor = User::factory()->editor()->create(['name' => 'Specific User']);
    Sanctum::actingAs($admin, ['access']);

    $res = $this->getJson("/api/v1/admin/users/{$editor->id}");
    $res->assertStatus(200)
        ->assertJson([
            'data' => [
                'id' => $editor->id,
                'name' => 'Specific User',
                'role' => 'editor',
            ],
        ]);
});

test('admin can create editor and admin accounts with secure password hashing', function () {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin, ['access']);

    // Create Editor
    $resEditor = $this->postJson('/api/v1/admin/users', [
        'name' => 'New Editor',
        'email' => 'neweditor@waaimedia.com',
        'password' => 'SecretPassword123!',
        'role' => 'editor',
    ]);

    $resEditor->assertStatus(201)
        ->assertJson([
            'message' => 'User created successfully.',
            'data' => [
                'name' => 'New Editor',
                'email' => 'neweditor@waaimedia.com',
                'role' => 'editor',
            ],
        ]);

    $this->assertDatabaseHas('users', ['email' => 'neweditor@waaimedia.com', 'role' => 'editor']);
    $createdEditor = User::where('email', 'neweditor@waaimedia.com')->first();
    expect(Hash::check('SecretPassword123!', $createdEditor->password))->toBeTrue();

    // Create Admin
    $resAdmin = $this->postJson('/api/v1/admin/users', [
        'name' => 'Second Admin',
        'email' => 'secondadmin@waaimedia.com',
        'password' => 'SecretAdmin123!',
        'role' => 'admin',
    ]);

    $resAdmin->assertStatus(201)
        ->assertJson([
            'data' => [
                'role' => 'admin',
            ],
        ]);
    $this->assertDatabaseHas('users', ['email' => 'secondadmin@waaimedia.com', 'role' => 'admin']);
});

test('creating user with duplicate email or invalid role is rejected with 422', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['email' => 'existing@waaimedia.com']);
    Sanctum::actingAs($admin, ['access']);

    // Duplicate email
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Test',
        'email' => 'existing@waaimedia.com',
        'password' => 'Password123!',
        'role' => 'editor',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    // Invalid role
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Test',
        'email' => 'valid@waaimedia.com',
        'password' => 'Password123!',
        'role' => 'superadmin',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['role']);
});

test('admin can update an editor profile', function () {
    $admin = User::factory()->admin()->create();
    $editor = User::factory()->editor()->create(['name' => 'Old Name', 'email' => 'old@waaimedia.com']);
    Sanctum::actingAs($admin, ['access']);

    $res = $this->putJson("/api/v1/admin/users/{$editor->id}", [
        'name' => 'New Name',
        'email' => 'new@waaimedia.com',
    ]);

    $res->assertStatus(200)
        ->assertJson([
            'data' => [
                'name' => 'New Name',
                'email' => 'new@waaimedia.com',
            ],
        ]);

    $this->assertDatabaseHas('users', ['id' => $editor->id, 'name' => 'New Name', 'email' => 'new@waaimedia.com']);
});

test('admin can update their own admin profile', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin Self', 'email' => 'self@waaimedia.com']);
    Sanctum::actingAs($admin, ['access']);

    $res = $this->putJson("/api/v1/admin/users/{$admin->id}", [
        'name' => 'Admin Self Updated',
        'email' => 'selfupdated@waaimedia.com',
    ]);

    $res->assertStatus(200)
        ->assertJson([
            'data' => [
                'name' => 'Admin Self Updated',
                'email' => 'selfupdated@waaimedia.com',
            ],
        ]);
});

test('admin cannot update another admin profile and receives 403', function () {
    $adminA = User::factory()->admin()->create(['name' => 'Admin A']);
    $adminB = User::factory()->admin()->create(['name' => 'Admin B']);
    Sanctum::actingAs($adminA, ['access']);

    $res = $this->putJson("/api/v1/admin/users/{$adminB->id}", [
        'name' => 'Tampered Admin B',
        'email' => 'tampered@waaimedia.com',
    ]);

    $res->assertStatus(403);
    $this->assertDatabaseHas('users', ['id' => $adminB->id, 'name' => 'Admin B']);
});

test('admin can change user role and target active sessions are revoked', function () {
    $admin = User::factory()->admin()->create();
    $editor = User::factory()->editor()->create();

    // Create active session and token for editor
    $token = $editor->createToken('Device A', ['access']);
    UserSession::create([
        'user_id' => $editor->id,
        'token_id' => $token->accessToken->id,
        'access_token_id' => $token->accessToken->id,
        'device_name' => 'Device A',
        'is_remember' => false,
    ]);

    Sanctum::actingAs($admin, ['access']);

    $res = $this->patchJson("/api/v1/admin/users/{$editor->id}/role", [
        'role' => 'admin',
    ]);

    $res->assertStatus(200)
        ->assertJson([
            'data' => ['role' => 'admin'],
        ]);

    $editor->refresh();
    expect($editor->role)->toBe('admin');

    // Tokens must be revoked
    expect($editor->tokens()->count())->toBe(0);
});

test('admin cannot demote the last active admin and receives 403', function () {
    $admin = User::factory()->admin()->create();
    expect(User::activeAdminsCount())->toBe(1);
    Sanctum::actingAs($admin, ['access']);

    $res = $this->patchJson("/api/v1/admin/users/{$admin->id}/role", [
        'role' => 'editor',
    ]);

    $res->assertStatus(403)
        ->assertJson(['message' => 'Cannot delete or demote the last remaining active administrator.']);

    $admin->refresh();
    expect($admin->role)->toBe('admin');
});

test('admin can reset editor password and target sessions are revoked', function () {
    $admin = User::factory()->admin()->create();
    $editor = User::factory()->editor()->create();
    $token = $editor->createToken('Device A', ['access']);
    Sanctum::actingAs($admin, ['access']);

    $res = $this->patchJson("/api/v1/admin/users/{$editor->id}/password", [
        'password' => 'BrandNewPassword123!',
        'password_confirmation' => 'BrandNewPassword123!',
    ]);

    $res->assertStatus(200)
        ->assertJson(['message' => 'User password reset successfully.']);

    $editor->refresh();
    expect(Hash::check('BrandNewPassword123!', $editor->password))->toBeTrue()
        ->and($editor->tokens()->count())->toBe(0);
});

test('admin cannot reset another admin password through users management', function () {
    $adminA = User::factory()->admin()->create();
    $adminB = User::factory()->admin()->create();
    Sanctum::actingAs($adminA, ['access']);

    $res = $this->patchJson("/api/v1/admin/users/{$adminB->id}/password", [
        'password' => 'AttackingPassword123!',
        'password_confirmation' => 'AttackingPassword123!',
    ]);

    $res->assertStatus(403);
});

test('admin can hard delete an editor', function () {
    $admin = User::factory()->admin()->create();
    $editor = User::factory()->editor()->create();
    Sanctum::actingAs($admin, ['access']);

    $res = $this->deleteJson("/api/v1/admin/users/{$editor->id}");
    $res->assertStatus(200);

    // Assert true hard delete (record removed from table, not just soft deleted)
    $this->assertDatabaseMissing('users', ['id' => $editor->id]);
});

test('the first admin account ever created is protected and can never be deleted', function () {
    // Admin 1 created first (First admin ever created)
    $firstAdmin = User::factory()->admin()->create(['created_at' => now()->subDays(10)]);
    // Admin 2 created second
    $secondAdmin = User::factory()->admin()->create(['created_at' => now()->subDays(2)]);
    // Admin 3 created third
    $thirdAdmin = User::factory()->admin()->create(['created_at' => now()]);

    expect(User::activeAdminsCount())->toBe(3);
    expect($firstAdmin->isFirstAdminEverCreated())->toBeTrue();
    expect($secondAdmin->isFirstAdminEverCreated())->toBeFalse();

    // 1. Second admin attempts to delete the first admin -> REJECTED
    Sanctum::actingAs($secondAdmin, ['access']);
    $res1 = $this->deleteJson("/api/v1/admin/users/{$firstAdmin->id}");
    $res1->assertStatus(403)
        ->assertJson(['message' => 'The first administrator account ever created in the database is protected and cannot be deleted.']);
    $this->assertDatabaseHas('users', ['id' => $firstAdmin->id]);

    // 2. First admin attempts to delete self -> REJECTED
    Sanctum::actingAs($firstAdmin, ['access']);
    $res2 = $this->deleteJson("/api/v1/admin/users/{$firstAdmin->id}");
    $res2->assertStatus(403)
        ->assertJson(['message' => 'The first administrator account ever created in the database is protected and cannot be deleted.']);
    $this->assertDatabaseHas('users', ['id' => $firstAdmin->id]);
});

test('first admin ever created remains protected even if a soft-deleted admin exists or newer admins are present', function () {
    // First admin ever created (Active)
    $firstAdmin = User::factory()->admin()->create(['created_at' => now()->subDays(20)]);
    // Later admin who was soft-deleted
    $softDeletedAdmin = User::factory()->admin()->create([
        'created_at' => now()->subDays(15),
        'deleted_at' => now()->subDays(5),
    ]);
    // Active admin created recently
    $currentAdmin = User::factory()->admin()->create(['created_at' => now()->subDays(2)]);

    expect($firstAdmin->isFirstAdminEverCreated())->toBeTrue();
    expect($currentAdmin->isFirstAdminEverCreated())->toBeFalse();

    Sanctum::actingAs($currentAdmin, ['access']);
    $res = $this->deleteJson("/api/v1/admin/users/{$firstAdmin->id}");
    $res->assertStatus(403)
        ->assertJson(['message' => 'The first administrator account ever created in the database is protected and cannot be deleted.']);
    $this->assertDatabaseHas('users', ['id' => $firstAdmin->id]);
});

test('the first admin account created is permanently registered in system_settings and identity remains static', function () {
    // Before creation, no protected admin registered
    expect(User::protectedFirstAdminId())->toBeNull();

    // 1. Create first admin ever created
    $firstAdmin = User::factory()->admin()->create(['created_at' => now()->subDays(10)]);

    // ID is recorded permanently in system_settings
    expect(User::protectedFirstAdminId())->toBe($firstAdmin->id);
    expect($firstAdmin->isFirstAdminEverCreated())->toBeTrue();

    // 2. Create subsequent admins
    $secondAdmin = User::factory()->admin()->create(['created_at' => now()->subDays(5)]);
    $thirdAdmin = User::factory()->admin()->create(['created_at' => now()]);

    // Protected ID remains locked to the first admin
    expect(User::protectedFirstAdminId())->toBe($firstAdmin->id);
    expect($secondAdmin->isFirstAdminEverCreated())->toBeFalse();
    expect($thirdAdmin->isFirstAdminEverCreated())->toBeFalse();

    // 3. An earlier editor is created and subsequently promoted to admin
    $promotedEditor = User::factory()->editor()->create(['created_at' => now()->subDays(20)]);
    $promotedEditor->update(['role' => 'admin']);

    // Even though $promotedEditor has an earlier created_at, the protected admin ID does NOT change
    expect(User::protectedFirstAdminId())->toBe($firstAdmin->id);
    expect($firstAdmin->isFirstAdminEverCreated())->toBeTrue();
    expect($promotedEditor->isFirstAdminEverCreated())->toBeFalse();

    // 4. If the first admin's role changes or account is modified, the protected identity remains permanently anchored
    $firstAdmin->update(['role' => 'editor']);
    expect(User::protectedFirstAdminId())->toBe($firstAdmin->id);
    expect($firstAdmin->isFirstAdminEverCreated())->toBeTrue();
});

test('concurrent attempts to register protected first admin safely resolve to a single winner without error', function () {
    // Both attempt to claim the protected admin slot
    User::registerProtectedFirstAdminId(101);
    User::registerProtectedFirstAdminId(102);

    expect(User::protectedFirstAdminId())->toBe(101);
});

test('an admin may delete another admin if that account is not the first admin ever created or last active admin', function () {
    $firstAdmin = User::factory()->admin()->create(['created_at' => now()->subDays(10)]);
    $secondAdmin = User::factory()->admin()->create(['created_at' => now()->subDays(5)]);
    $thirdAdmin = User::factory()->admin()->create(['created_at' => now()]);

    Sanctum::actingAs($firstAdmin, ['access']);

    // First admin deletes third admin (not first admin, not last admin) -> ALLOWED
    $res = $this->deleteJson("/api/v1/admin/users/{$thirdAdmin->id}");
    $res->assertStatus(200);

    $this->assertDatabaseMissing('users', ['id' => $thirdAdmin->id]);
});

test('an admin may delete their own account if they are not the protected first admin ever created', function () {
    $firstAdmin = User::factory()->admin()->create(['created_at' => now()->subDays(10)]);
    $secondAdmin = User::factory()->admin()->create(['created_at' => now()->subDays(2)]);

    Sanctum::actingAs($secondAdmin, ['access']);

    // Second admin deletes self -> ALLOWED because firstAdmin still remains
    $res = $this->deleteJson("/api/v1/admin/users/{$secondAdmin->id}");
    $res->assertStatus(200);

    $this->assertDatabaseMissing('users', ['id' => $secondAdmin->id]);
    expect(User::activeAdminsCount())->toBe(1);
});

test('user operations create immutable audit log records in activity_logs', function () {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin, ['access']);

    $initialLogCount = ActivityLog::count();

    $this->postJson('/api/v1/admin/users', [
        'name' => 'Audited User',
        'email' => 'audited@waaimedia.com',
        'password' => 'Password123!',
        'role' => 'editor',
    ])->assertStatus(201);

    expect(ActivityLog::count())->toBeGreaterThan($initialLogCount);
    $latestLog = ActivityLog::latest('id')->first();
    expect($latestLog->action)->toBe('admin.user_created')
        ->and($latestLog->user_id)->toBe($admin->id)
        ->and($latestLog->details['created_email'])->toBe('audited@waaimedia.com')
        ->and($latestLog->details)->not->toHaveKey('password');
});
