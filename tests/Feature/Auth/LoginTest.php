<?php

use App\Models\User;
use App\Models\UserSession;

test('user can log in successfully with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Secret123!',
        'role' => 'admin',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Secret123!',
        'device_name' => 'Chrome on Windows',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'token',
            'token_type',
            'user' => ['id', 'name', 'email', 'role'],
            'session_id',
        ])
        ->assertJson([
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'email' => 'admin@example.com',
                'role' => 'admin',
            ],
        ]);

    $this->assertDatabaseHas('user_sessions', [
        'user_id' => $user->id,
        'device_name' => 'Chrome on Windows',
        'revoked_at' => null,
    ]);
});

test('login fails with invalid credentials', function () {
    User::factory()->create([
        'email' => 'user@example.com',
        'password' => 'CorrectPassword!',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'user@example.com',
        'password' => 'WrongPassword!',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid email or password.',
        ]);
});

test('soft-deleted user cannot log in', function () {
    $user = User::factory()->create([
        'email' => 'deactivated@example.com',
        'password' => 'Secret123!',
    ]);
    $user->delete();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'deactivated@example.com',
        'password' => 'Secret123!',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Account is deactivated.',
        ]);
});

test('multiple active devices: new login does not revoke prior session and token', function () {
    $user = User::factory()->create([
        'email' => 'multi@example.com',
        'password' => 'Secret123!',
    ]);

    // Device A logs in
    $responseA = $this->postJson('/api/v1/auth/login', [
        'email' => 'multi@example.com',
        'password' => 'Secret123!',
        'device_name' => 'Device A - Laptop',
    ]);
    $tokenA = $responseA->json('token');
    $sessionIdA = $responseA->json('session_id');

    // Verify Device A can access /me
    $this->withToken($tokenA)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(200);

    // Device B logs in
    $responseB = $this->postJson('/api/v1/auth/login', [
        'email' => 'multi@example.com',
        'password' => 'Secret123!',
        'device_name' => 'Device B - Mobile',
    ]);
    $tokenB = $responseB->json('token');
    $sessionIdB = $responseB->json('session_id');

    // Prior session for Device A must NOT be revoked
    $sessionA = UserSession::find($sessionIdA);
    expect($sessionA->revoked_at)->toBeNull();

    // Session for Device B is active
    $sessionB = UserSession::find($sessionIdB);
    expect($sessionB->revoked_at)->toBeNull();

    // Device A token is still valid
    $this->app['auth']->forgetGuards();
    $this->withToken($tokenA)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(200);

    // Device B token is valid
    $this->app['auth']->forgetGuards();
    $this->withToken($tokenB)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(200);
});

test('login normalizes email with whitespace and mixed casing', function () {
    $user = User::factory()->create([
        'email' => 'mixedcase@example.com',
        'password' => 'Secret123!',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => '  MixedCASE@Example.COM  ',
        'password' => 'Secret123!',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('user.email', 'mixedcase@example.com');
});

test('logout on one device does not invalidate other active devices', function () {
    $user = User::factory()->create([
        'email' => 'logout-multi@example.com',
        'password' => 'Secret123!',
    ]);

    // Device A logs in
    $responseA = $this->postJson('/api/v1/auth/login', [
        'email' => 'logout-multi@example.com',
        'password' => 'Secret123!',
        'device_name' => 'Device A',
    ]);
    $tokenA = $responseA->json('token');

    // Device B logs in
    $responseB = $this->postJson('/api/v1/auth/login', [
        'email' => 'logout-multi@example.com',
        'password' => 'Secret123!',
        'device_name' => 'Device B',
    ]);
    $tokenB = $responseB->json('token');

    // Device A logs out
    $this->withToken($tokenA)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(200);

    $this->app['auth']->forgetGuards();

    // Device A token is now invalid
    $this->withToken($tokenA)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);

    // Device B token is still valid
    $this->app['auth']->forgetGuards();
    $this->withToken($tokenB)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(200);
});
