<?php

use App\Models\User;

test('admin access token expires after 1 hour absolute lifetime', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $token = $loginResponse->json('token');

    // 1. Valid immediately
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

    // 2. Valid at 59 minutes
    $this->travel(59)->minutes();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

    // 3. Expired after 1 hour + 1 second
    $this->travel(2)->minutes();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('editor access token expires after 1 hour absolute lifetime', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $editor->email,
        'password' => 'password',
    ]);

    $token = $loginResponse->json('token');

    // 1. Valid immediately
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

    // 2. Valid at 59 minutes
    $this->travel(59)->minutes();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

    // 3. Expired after 1 hour + 1 second
    $this->travel(2)->minutes();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('admin access token is not extended by intermediate activity', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);
    $token = $loginResponse->json('token');

    // Activity at 30 minutes
    $this->travel(30)->minutes();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

    // Travel to 65 minutes from original login (35 minutes after last request)
    $this->travel(35)->minutes();

    // Must be expired because 1 hour is an absolute lifetime, not a sliding window
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('editor access token is not extended by intermediate activity', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $editor->email,
        'password' => 'password',
    ]);
    $token = $loginResponse->json('token');

    // Activity at 30 minutes
    $this->travel(30)->minutes();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

    // Travel to 65 minutes from original login
    $this->travel(35)->minutes();

    // Must be expired
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});
