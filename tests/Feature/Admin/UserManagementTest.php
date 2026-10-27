<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// =========================================================================
// 1. Unauthenticated & Editor Access Boundaries
// =========================================================================

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

// =========================================================================
// 2. Listing & Details
// =========================================================================

test('super admin and admin can list users with pagination, search, and role filters', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    User::factory()->admin()->create(['name' => 'Admin Alice', 'email' => 'alice@waaimedia.com']);
    User::factory()->editor()->create(['name' => 'Editor Bob', 'email' => 'bob@waaimedia.com']);
    Sanctum::actingAs($superAdmin, ['access']);

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
        ->and($searchList->json('data.0.name'))->toBe('Admin Alice');
});

test('admin can view an editor user details', function () {
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

test('admin CANNOT view another admin or super admin details and receives 403', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    Sanctum::actingAs($admin, ['access']);

    // Admin attempts to view another admin
    $res1 = $this->getJson("/api/v1/admin/users/{$otherAdmin->id}");
    $res1->assertStatus(403);

    // Admin attempts to view the super admin
    $res2 = $this->getJson("/api/v1/admin/users/{$superAdmin->id}");
    $res2->assertStatus(403);
});

test('super admin can view details of admin, editor, and super admin accounts', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    $admin = User::factory()->admin()->create();
    $editor = User::factory()->editor()->create();
    Sanctum::actingAs($superAdmin, ['access']);

    $this->getJson("/api/v1/admin/users/{$admin->id}")->assertStatus(200);
    $this->getJson("/api/v1/admin/users/{$editor->id}")->assertStatus(200);
    $this->getJson("/api/v1/admin/users/{$superAdmin->id}")->assertStatus(200);
});

// =========================================================================
// 3. User Creation Rules
// =========================================================================

test('super admin can create admin and editor accounts', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    Sanctum::actingAs($superAdmin, ['access']);

    // Create Editor
    $resEditor = $this->postJson('/api/v1/admin/users', [
        'name' => 'New Editor',
        'email' => 'neweditor@waaimedia.com',
        'password' => 'SecretPassword123!',
        'role' => 'editor',
    ]);
    $resEditor->assertStatus(201)
        ->assertJson([
            'data' => [
                'name' => 'New Editor',
                'email' => 'neweditor@waaimedia.com',
                'role' => 'editor',
            ],
        ]);
    $this->assertDatabaseHas('users', ['email' => 'neweditor@waaimedia.com', 'role' => 'editor']);

    // Create Admin
    $resAdmin = $this->postJson('/api/v1/admin/users', [
        'name' => 'New Admin',
        'email' => 'newadmin@waaimedia.com',
        'password' => 'SecretAdmin123!',
        'role' => 'admin',
    ]);
    $resAdmin->assertStatus(201)
        ->assertJson([
            'data' => [
                'name' => 'New Admin',
                'email' => 'newadmin@waaimedia.com',
                'role' => 'admin',
            ],
        ]);
    $this->assertDatabaseHas('users', ['email' => 'newadmin@waaimedia.com', 'role' => 'admin']);
});

test('regular admin can create editors but CANNOT create admins', function () {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin, ['access']);

    // 1. Regular admin creates editor -> ALLOWED
    $resEditor = $this->postJson('/api/v1/admin/users', [
        'name' => 'Admin Created Editor',
        'email' => 'admincreated_editor@waaimedia.com',
        'password' => 'SecretPassword123!',
        'role' => 'editor',
    ]);
    $resEditor->assertStatus(201);
    $this->assertDatabaseHas('users', ['email' => 'admincreated_editor@waaimedia.com', 'role' => 'editor']);

    // 2. Regular admin attempts to create an Admin -> FORBIDDEN (403)
    $resAdmin = $this->postJson('/api/v1/admin/users', [
        'name' => 'Admin Attempted Admin',
        'email' => 'adminattempt_admin@waaimedia.com',
        'password' => 'SecretPassword123!',
        'role' => 'admin',
    ]);
    $resAdmin->assertStatus(403);
    $this->assertDatabaseMissing('users', ['email' => 'adminattempt_admin@waaimedia.com']);
});

test('creating user with super_admin role is rejected with 403 or 422', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    Sanctum::actingAs($superAdmin, ['access']);

    $res = $this->postJson('/api/v1/admin/users', [
        'name' => 'Illegal SuperAdmin',
        'email' => 'illegal_super@waaimedia.com',
        'password' => 'Password123!',
        'role' => 'super_admin',
    ]);

    expect(in_array($res->status(), [403, 422], true))->toBeTrue();
    $this->assertDatabaseMissing('users', ['email' => 'illegal_super@waaimedia.com']);
});

test('creating user with duplicate email or invalid role is rejected with 422', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    User::factory()->create(['email' => 'existing@waaimedia.com']);
    Sanctum::actingAs($superAdmin, ['access']);

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
        'role' => 'invalid_role',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['role']);
});

// =========================================================================
// 4. User Update Rules
// =========================================================================

test('regular admin can update an editor profile', function () {
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

test('regular admin cannot update another admin profile and receives 403', function () {
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

test('regular admin cannot update the protected super admin and receives 403', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin, ['access']);

    $res = $this->putJson("/api/v1/admin/users/{$superAdmin->id}", [
        'name' => 'Tampered Super Admin',
        'email' => 'tampered_super@waaimedia.com',
    ]);

    $res->assertStatus(403);
});

test('super admin can update permitted fields of regular admin and editor', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    $admin = User::factory()->admin()->create(['name' => 'Admin Target', 'email' => 'admintarget@waaimedia.com']);
    Sanctum::actingAs($superAdmin, ['access']);

    $res = $this->putJson("/api/v1/admin/users/{$admin->id}", [
        'name' => 'Admin Updated By Super',
        'email' => 'admintarget_updated@waaimedia.com',
    ]);

    $res->assertStatus(200)
        ->assertJson([
            'data' => [
                'name' => 'Admin Updated By Super',
                'email' => 'admintarget_updated@waaimedia.com',
            ],
        ]);
});

test('protected super admin email cannot be changed through ordinary endpoints', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    Sanctum::actingAs($superAdmin, ['access']);

    $res = $this->putJson("/api/v1/admin/users/{$superAdmin->id}", [
        'name' => 'Updated Super Admin Name',
        'email' => 'newemail_super@waaimedia.com',
    ]);

    $res->assertStatus(403);
    $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
});

// =========================================================================
// 5. Role Changes (Super Admin Only)
// =========================================================================

test('super admin can change role of editor to admin and admin to editor', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    $editor = User::factory()->editor()->create();
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($superAdmin, ['access']);

    // 1. Editor -> Admin
    $res1 = $this->patchJson("/api/v1/admin/users/{$editor->id}/role", [
        'role' => 'admin',
    ]);
    $res1->assertStatus(200)->assertJson(['data' => ['role' => 'admin']]);
    expect($editor->fresh()->role)->toBe('admin');

    // 2. Admin -> Editor
    $res2 = $this->patchJson("/api/v1/admin/users/{$admin->id}/role", [
        'role' => 'editor',
    ]);
    $res2->assertStatus(200)->assertJson(['data' => ['role' => 'editor']]);
    expect($admin->fresh()->role)->toBe('editor');
});

test('regular admin CANNOT change any user role and receives 403', function () {
    $admin = User::factory()->admin()->create();
    $editor = User::factory()->editor()->create();
    Sanctum::actingAs($admin, ['access']);

    // Regular admin attempts to promote editor to admin
    $res1 = $this->patchJson("/api/v1/admin/users/{$editor->id}/role", [
        'role' => 'admin',
    ]);
    $res1->assertStatus(403);
    expect($editor->fresh()->role)->toBe('editor');

    // Regular admin attempts to demote another admin
    $otherAdmin = User::factory()->admin()->create();
    $res2 = $this->patchJson("/api/v1/admin/users/{$otherAdmin->id}/role", [
        'role' => 'editor',
    ]);
    $res2->assertStatus(403);
    expect($otherAdmin->fresh()->role)->toBe('admin');
});

test('assigning super_admin via role change endpoint is strictly rejected', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    $editor = User::factory()->editor()->create();
    Sanctum::actingAs($superAdmin, ['access']);

    $res = $this->patchJson("/api/v1/admin/users/{$editor->id}/role", [
        'role' => 'super_admin',
    ]);

    expect(in_array($res->status(), [403, 422], true))->toBeTrue();
    expect($editor->fresh()->role)->toBe('editor');
});

test('protected super admin cannot be demoted through ordinary role change endpoint', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    Sanctum::actingAs($superAdmin, ['access']);

    $res = $this->patchJson("/api/v1/admin/users/{$superAdmin->id}/role", [
        'role' => 'admin',
    ]);

    $res->assertStatus(403);
    expect($superAdmin->fresh()->role)->toBe('super_admin');
});

// =========================================================================
// 6. Password Reset by Admin
// =========================================================================

test('super admin can reset password for regular admin and editor', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    $admin = User::factory()->admin()->create();
    $editor = User::factory()->editor()->create();
    Sanctum::actingAs($superAdmin, ['access']);

    // Reset editor password
    $res1 = $this->patchJson("/api/v1/admin/users/{$editor->id}/password", [
        'password' => 'BrandNewPassword123!',
        'password_confirmation' => 'BrandNewPassword123!',
    ]);
    $res1->assertStatus(200);
    expect(Hash::check('BrandNewPassword123!', $editor->fresh()->password))->toBeTrue();

    // Reset admin password
    $res2 = $this->patchJson("/api/v1/admin/users/{$admin->id}/password", [
        'password' => 'BrandNewAdminPassword123!',
        'password_confirmation' => 'BrandNewAdminPassword123!',
    ]);
    $res2->assertStatus(200);
    expect(Hash::check('BrandNewAdminPassword123!', $admin->fresh()->password))->toBeTrue();
});

test('regular admin can reset editor password but CANNOT reset another admin password', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    $editor = User::factory()->editor()->create();
    Sanctum::actingAs($admin, ['access']);

    // Reset editor password -> ALLOWED
    $res1 = $this->patchJson("/api/v1/admin/users/{$editor->id}/password", [
        'password' => 'EditorPasswordReset123!',
        'password_confirmation' => 'EditorPasswordReset123!',
    ]);
    $res1->assertStatus(200);

    // Reset another admin password -> FORBIDDEN (403)
    $res2 = $this->patchJson("/api/v1/admin/users/{$otherAdmin->id}/password", [
        'password' => 'AdminPasswordReset123!',
        'password_confirmation' => 'AdminPasswordReset123!',
    ]);
    $res2->assertStatus(403);
});

test('no one can reset password of the protected super admin via admin reset endpoint', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    $admin = User::factory()->admin()->create();

    // Regular admin tries
    Sanctum::actingAs($admin, ['access']);
    $res1 = $this->patchJson("/api/v1/admin/users/{$superAdmin->id}/password", [
        'password' => 'HackedPassword123!',
        'password_confirmation' => 'HackedPassword123!',
    ]);
    $res1->assertStatus(403);

    // Super admin tries self via admin reset endpoint
    Sanctum::actingAs($superAdmin, ['access']);
    $res2 = $this->patchJson("/api/v1/admin/users/{$superAdmin->id}/password", [
        'password' => 'HackedPassword123!',
        'password_confirmation' => 'HackedPassword123!',
    ]);
    $res2->assertStatus(403);
});

// =========================================================================
// 7. Delete User Rules & Protected Account
// =========================================================================

test('super admin and regular admin can hard delete an editor', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    $admin = User::factory()->admin()->create();
    $editor1 = User::factory()->editor()->create();
    $editor2 = User::factory()->editor()->create();

    // Regular admin deletes editor1
    Sanctum::actingAs($admin, ['access']);
    $res1 = $this->deleteJson("/api/v1/admin/users/{$editor1->id}");
    $res1->assertStatus(200);
    $this->assertDatabaseMissing('users', ['id' => $editor1->id]);

    // Super admin deletes editor2
    Sanctum::actingAs($superAdmin, ['access']);
    $res2 = $this->deleteJson("/api/v1/admin/users/{$editor2->id}");
    $res2->assertStatus(200);
    $this->assertDatabaseMissing('users', ['id' => $editor2->id]);
});

test('super admin can delete a regular admin', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($superAdmin, ['access']);

    $res = $this->deleteJson("/api/v1/admin/users/{$admin->id}");
    $res->assertStatus(200);
    $this->assertDatabaseMissing('users', ['id' => $admin->id]);
});

test('regular admin CANNOT delete another regular admin', function () {
    $adminA = User::factory()->admin()->create();
    $adminB = User::factory()->admin()->create();
    Sanctum::actingAs($adminA, ['access']);

    $res = $this->deleteJson("/api/v1/admin/users/{$adminB->id}");
    $res->assertStatus(403);
    $this->assertDatabaseHas('users', ['id' => $adminB->id]);
});

test('designated protected super admin CANNOT be deleted through ordinary endpoints by anyone', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    $admin = User::factory()->admin()->create();

    // 1. Regular admin attempts to delete super admin -> 403
    Sanctum::actingAs($admin, ['access']);
    $res1 = $this->deleteJson("/api/v1/admin/users/{$superAdmin->id}");
    $res1->assertStatus(403);
    $this->assertDatabaseHas('users', ['id' => $superAdmin->id]);

    // 2. Super admin attempts self-delete through ordinary endpoint -> 403
    Sanctum::actingAs($superAdmin, ['access']);
    $res2 = $this->deleteJson("/api/v1/admin/users/{$superAdmin->id}");
    $res2->assertStatus(403);
    $this->assertDatabaseHas('users', ['id' => $superAdmin->id]);
});

test('regular admin can delete their own account when safeguards pass', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin, ['access']);

    $res = $this->deleteJson("/api/v1/admin/users/{$admin->id}");
    $res->assertStatus(200);
    $this->assertDatabaseMissing('users', ['id' => $admin->id]);
});

test('protected status remains effective even if system_settings is missing or contains wrong id', function () {
    // No system_settings record, or wrong ID in system_settings
    $wrongAdmin = User::factory()->admin()->create();
    User::registerProtectedFirstAdminId($wrongAdmin->id);

    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    $attacker = User::factory()->admin()->create();

    Sanctum::actingAs($attacker, ['access']);

    // Attempt delete
    $res = $this->deleteJson("/api/v1/admin/users/{$superAdmin->id}");
    $res->assertStatus(403);
    $this->assertDatabaseHas('users', ['id' => $superAdmin->id]);
});

test('protected account cannot bypass deletion protection even if role is altered in memory', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL, 'role' => 'editor']);
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin, ['access']);

    $res = $this->deleteJson("/api/v1/admin/users/{$superAdmin->id}");
    $res->assertStatus(403);
    $this->assertDatabaseHas('users', ['id' => $superAdmin->id]);
});

test('user operations create immutable audit log records in activity_logs', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => User::DESIGNATED_SUPER_ADMIN_EMAIL]);
    Sanctum::actingAs($superAdmin, ['access']);

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
        ->and($latestLog->user_id)->toBe($superAdmin->id)
        ->and($latestLog->details['created_email'])->toBe('audited@waaimedia.com')
        ->and($latestLog->details)->not->toHaveKey('password');
});

// =========================================================================
// 8. Service-Level Direct Call Protection (Defense-in-Depth)
// =========================================================================

test('AdminUserService throws AuthorizationException if regular admin directly calls createUser with admin role', function () {
    $admin = User::factory()->admin()->create();
    $service = app(AdminUserService::class);

    $req = Request::create('/api/v1/admin/users', 'POST');
    $req->setUserResolver(fn () => $admin);

    expect(fn () => $service->createUser([
        'name' => 'Bypassed Admin',
        'email' => 'bypassed@waaimedia.com',
        'password' => 'Password123!',
        'role' => 'admin',
    ], $req))->toThrow(AuthorizationException::class);
});

test('AdminUserService throws AuthorizationException if regular admin directly calls updateUser on another admin', function () {
    $adminA = User::factory()->admin()->create();
    $adminB = User::factory()->admin()->create();
    $service = app(AdminUserService::class);

    $req = Request::create("/api/v1/admin/users/{$adminB->id}", 'PUT');
    $req->setUserResolver(fn () => $adminA);

    expect(fn () => $service->updateUser($adminB, [
        'name' => 'Tampered Admin',
        'email' => 'tampered@waaimedia.com',
    ], $req))->toThrow(AuthorizationException::class);
});

test('AdminUserService throws AuthorizationException if regular admin directly calls resetPassword on another admin', function () {
    $adminA = User::factory()->admin()->create();
    $adminB = User::factory()->admin()->create();
    $service = app(AdminUserService::class);

    $req = Request::create("/api/v1/admin/users/{$adminB->id}/password", 'PATCH');
    $req->setUserResolver(fn () => $adminA);

    expect(fn () => $service->resetPassword($adminB, 'HackedPassword123!', $req))
        ->toThrow(AuthorizationException::class);
});
