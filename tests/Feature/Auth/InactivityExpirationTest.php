<?php

use App\Models\User;
use App\Models\UserSession;
use Laravel\Sanctum\PersonalAccessToken;

test('access token expires after 1 hour absolute lifetime', function () {
    $user = User::factory()->create();

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $token = $loginResponse->json('token');

    // Token is valid initially
    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(200);

    // Fast-forward past 1 hour (60 minutes + 1 second)
    $this->travel(60)->minutes();
    $this->travel(1)->seconds();

    // Request must be rejected with 401 because 1-hour access lifetime expired
    $response = $this->withToken($token)
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(401);
});

test('access token is valid within 1 hour window', function () {
    $user = User::factory()->create();

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $token = $loginResponse->json('token');

    // Fast-forward 30 minutes (within 1 hour window)
    $this->travel(30)->minutes();

    $response = $this->withToken($token)
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(200);
});

test('ordinary protected endpoints reject remember token with 403 forbidden', function () {
    $user = User::factory()->create();

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    // Retrieve the remember token created in database
    $session = UserSession::where('user_id', $user->id)->first();
    expect($session->remember_token_id)->not->toBeNull();

    $rememberTokenRecord = PersonalAccessToken::find($session->remember_token_id);
    expect($rememberTokenRecord->can('remember'))->toBeTrue();
    expect($rememberTokenRecord->can('access'))->toBeFalse();

    // Attempt to access ordinary protected endpoint /me using Remember Token as Bearer token
    // We construct the token format ID|plaintext (or use token instance)
    $fakeBearer = $rememberTokenRecord->id.'|testsecret';
    // Let's test with a real remember token
    $plainRemember = $user->createToken('Test Remember Only', ['remember'])->plainTextToken;

    $response = $this->withToken($plainRemember)
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'Forbidden. This endpoint requires an access token.',
        ]);
});

test('activity within 1 hour does not extend absolute access token expiration', function () {
    $user = User::factory()->create();

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $token = $loginResponse->json('token');

    // Activity at 40 minutes
    $this->travel(40)->minutes();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);

    // Travel another 25 minutes (total 65 minutes since login)
    $this->travel(25)->minutes();

    // Must be expired even though activity occurred at 40 minutes (absolute lifetime, not sliding)
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});
