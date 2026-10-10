<?php

use App\Models\User;
use App\Models\UserSession;

test('authenticated user can log out, revoking active session and token', function () {
    $user = User::factory()->create([
        'email' => 'logout@example.com',
        'password' => 'Secret123!',
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'logout@example.com',
        'password' => 'Secret123!',
    ]);
    $token = $loginResponse->json('token');
    $sessionId = $loginResponse->json('session_id');

    $response = $this->withToken($token)
        ->postJson('/api/v1/auth/logout');

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Successfully logged out.',
        ]);

    $session = UserSession::find($sessionId);
    expect($session->revoked_at)->not->toBeNull();
    expect($session->revocation_reason)->toBe('manual_logout');

    // Token can no longer access protected endpoints
    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});

test('unauthenticated user cannot call logout', function () {
    $response = $this->postJson('/api/v1/auth/logout');

    $response->assertStatus(401);
});

test('logout on one device does not affect other active sessions', function () {
    $user = User::factory()->create([
        'email' => 'multilogout@example.com',
        'password' => 'Secret123!',
    ]);

    // Device A logs in
    $loginA = $this->postJson('/api/v1/auth/login', [
        'email' => 'multilogout@example.com',
        'password' => 'Secret123!',
    ]);
    $tokenA = $loginA->json('token');
    $sessionIdA = $loginA->json('session_id');

    // Device B logs in
    $loginB = $this->postJson('/api/v1/auth/login', [
        'email' => 'multilogout@example.com',
        'password' => 'Secret123!',
    ]);
    $tokenB = $loginB->json('token');
    $sessionIdB = $loginB->json('session_id');

    // Logout from Device A
    $this->withToken($tokenA)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(200);

    // Device A session should be revoked
    $sessionA = UserSession::find($sessionIdA);
    expect($sessionA->revoked_at)->not->toBeNull();
    expect($sessionA->revocation_reason)->toBe('manual_logout');

    // Device B session should remain active
    $sessionB = UserSession::find($sessionIdB);
    expect($sessionB->revoked_at)->toBeNull();

    // Device A token is invalid
    $this->withToken($tokenA)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);

    // Device B token is still valid
    $this->app['auth']->forgetGuards();
    $this->withToken($tokenB)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(200);
});
