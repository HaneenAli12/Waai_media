<?php

use App\Models\User;
use App\Services\Auth\AdminSafetyService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;

test('editor is strictly prohibited from executing any DELETE request and receives 403', function () {
    Route::middleware(['api', 'auth:sanctum'])->delete('/api/v1/test-resource', fn () => response()->json(['message' => 'deleted']));

    $editor = User::factory()->editor()->create();
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $editor->email,
        'password' => 'password',
    ]);
    $editorToken = $loginResponse->json('token');

    $response = $this->withToken($editorToken)
        ->deleteJson('/api/v1/test-resource');

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'Forbidden. Editors are strictly prohibited from performing delete operations.',
        ]);
});

test('admin is permitted to execute DELETE requests on protected routes', function () {
    Route::middleware(['api', 'auth:sanctum'])->delete('/api/v1/test-admin-resource', fn () => response()->json(['message' => 'deleted']));

    $admin = User::factory()->admin()->create();
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);
    $adminToken = $loginResponse->json('token');

    $response = $this->withToken($adminToken)
        ->deleteJson('/api/v1/test-admin-resource');

    $response->assertStatus(200)
        ->assertJson(['message' => 'deleted']);
});

test('last active admin cannot be deleted or demoted', function () {
    // Only one active admin in database
    $soleAdmin = User::factory()->admin()->create();
    expect(User::activeAdminsCount())->toBe(1);
    expect($soleAdmin->isLastActiveAdmin())->toBeTrue();

    $safetyService = app(AdminSafetyService::class);

    // Attempting to demote or delete sole admin must throw AuthorizationException
    expect(fn () => $safetyService->ensureCanDeleteOrDemote($soleAdmin, 'editor'))
        ->toThrow(AuthorizationException::class, 'Cannot delete or demote the last remaining active administrator.');

    expect(fn () => $safetyService->ensureCanDeleteOrDemote($soleAdmin, null))
        ->toThrow(AuthorizationException::class, 'Cannot delete or demote the last remaining active administrator.');
});

test('admin can be demoted or deleted when multiple active admins exist', function () {
    $adminA = User::factory()->admin()->create();
    $adminB = User::factory()->admin()->create();
    expect(User::activeAdminsCount())->toBe(2);

    $safetyService = app(AdminSafetyService::class);

    // Should succeed without exception
    $safetyService->ensureCanDeleteOrDemote($adminA, 'editor');
    $safetyService->ensureCanDeleteOrDemote($adminA, null);

    expect(true)->toBeTrue();
});
