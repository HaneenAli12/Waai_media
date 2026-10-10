<?php

use App\Models\User;

test('authenticated user can view their profile and active session via me endpoint', function () {
    $user = User::factory()->create([
        'name' => 'Sara Al-Omari',
        'email' => 'sara@example.com',
        'password' => 'Secret123!',
        'role' => 'editor',
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'sara@example.com',
        'password' => 'Secret123!',
        'device_name' => 'Safari on iPad',
    ]);
    $token = $loginResponse->json('token');

    $response = $this->withToken($token)
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(200)
        ->assertJson([
            'user' => [
                'id' => $user->id,
                'name' => 'Sara Al-Omari',
                'email' => 'sara@example.com',
                'role' => 'editor',
            ],
            'session' => [
                'device_name' => 'Safari on iPad',
            ],
        ]);
});

test('unauthenticated request to me endpoint is rejected with 401', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});

test('unauthenticated API request without Accept header returns 401 JSON and does not redirect', function () {
    // Send a standard get request (not getJson) without Accept: application/json
    $response = $this->get('/api/v1/auth/me', ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8']);

    $response->assertStatus(401)
        ->assertJson(['message' => 'Unauthenticated.']);
});
