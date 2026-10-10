<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

test('admin session expires after 7 days of inactivity', function () {
    // 1. Authenticate using Admin
    $admin = User::factory()->create(['role' => 'admin']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $token = $loginResponse->json('token');

    // 3. Verify /me succeeds before inactivity
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

    // 4. Simulate seven days without activity (7 days + 1 second to be strictly greater)
    $this->travel(7)->days();
    $this->travel(1)->seconds();

    // 5. Verify old token is rejected
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('editor session expires after 7 days of inactivity', function () {
    // 1. Authenticate using Editor
    $editor = User::factory()->create(['role' => 'editor']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $editor->email,
        'password' => 'password',
    ]);

    $token = $loginResponse->json('token');

    // 3. Verify /me succeeds before inactivity
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

    // 4. Simulate seven days without activity
    $this->travel(7)->days();
    $this->travel(1)->seconds();

    // 5. Verify old token is rejected
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('admin session window is extended if activity occurs within 7 days', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);
    $token = $loginResponse->json('token');

    // Simulate 5 days of inactivity
    $this->travel(5)->days();

    // Activity occurs! This should update last_used_at (if > 1 min elapsed)
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

    $dbToken = PersonalAccessToken::findToken($token);
    expect($dbToken->last_used_at->diffInSeconds(now()))->toBeLessThanOrEqual(2);

    // Simulate another 5 days of inactivity (total 10 days since login, but only 5 since last activity)
    $this->travel(5)->days();

    // Should still be valid because the window was extended
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);
});

test('editor session window is extended if activity occurs within 7 days', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $editor->email,
        'password' => 'password',
    ]);
    $token = $loginResponse->json('token');

    // Simulate 5 days of inactivity
    $this->travel(5)->days();

    // Activity occurs! This should update last_used_at (if > 1 min elapsed)
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

    $dbToken = PersonalAccessToken::findToken($token);
    expect($dbToken->last_used_at->diffInSeconds(now()))->toBeLessThanOrEqual(2);

    // Simulate another 5 days of inactivity (total 10 days since login, but only 5 since last activity)
    $this->travel(5)->days();

    // Should still be valid because the window was extended
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);
});
