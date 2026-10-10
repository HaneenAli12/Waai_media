<?php

use App\Models\User;
use App\Models\UserSession;
use Laravel\Sanctum\PersonalAccessToken;

test('token inactive for more than 7 days is revoked with inactivity_expired', function () {
    $user = User::factory()->create();

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $token = $loginResponse->json('token');
    $sessionId = $loginResponse->json('session_id');

    // Simulate inactivity of 8 days (exceeding 7-day limit)
    $dbToken = PersonalAccessToken::findToken($token);
    $dbToken->forceFill([
        'last_used_at' => now()->subDays(8),
    ])->save();

    // Request should be rejected with 401
    $response = $this->withToken($token)
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(401);

    // Token should be deleted
    expect(PersonalAccessToken::findToken($token))->toBeNull();

    // Session should be revoked with reason 'inactivity_expired'
    $session = UserSession::find($sessionId);
    expect($session->revoked_at)->not->toBeNull();
    expect($session->revocation_reason)->toBe('inactivity_expired');

    // Activity log should record the inactivity expiration
    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $user->id,
        'action' => 'auth.inactivity_expired',
    ]);
});

test('token active within 7 days is permitted and updates last_used_at', function () {
    $user = User::factory()->create();

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $token = $loginResponse->json('token');

    // Simulate activity 2 days ago
    $dbToken = PersonalAccessToken::findToken($token);
    $dbToken->forceFill([
        'last_used_at' => now()->subDays(2),
    ])->save();

    $response = $this->withToken($token)
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(200);
});
