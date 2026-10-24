<?php

use App\Models\User;

test('cors preflight allows configured angular origins with credentials', function () {
    $response = $this->withHeaders([
        'Origin' => 'http://localhost:4200',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'Content-Type, X-XSRF-TOKEN, Authorization',
    ])->options('/api/v1/auth/refresh');

    $response->assertStatus(204);
    $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
    $response->assertHeader('Access-Control-Allow-Credentials', 'true');
});

test('cors preflight allows public website angular origin on 4201', function () {
    $response = $this->withHeaders([
        'Origin' => 'http://localhost:4201',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'Content-Type, X-XSRF-TOKEN, Authorization',
    ])->options('/api/v1/auth/refresh');

    $response->assertStatus(204);
    $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4201');
    $response->assertHeader('Access-Control-Allow-Credentials', 'true');
});

test('cors rejects untrusted origins from credentialed access', function () {
    $response = $this->withHeaders([
        'Origin' => 'http://malicious-website.com',
        'Access-Control-Request-Method' => 'POST',
    ])->options('/api/v1/auth/refresh');

    // Untrusted origin should NOT be reflected in Access-Control-Allow-Origin
    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('http://malicious-website.com');
});

test('csrf token endpoint returns token and sets readable xsrf cookie for angular', function () {
    $response = $this->getJson('/api/v1/auth/csrf-token');

    $response->assertStatus(200)
        ->assertJsonStructure(['csrf_token']);

    $cookieName = config('auth.csrf.cookie_name', 'XSRF-TOKEN');
    $response->assertCookie($cookieName);

    // Angular requires XSRF-TOKEN to be readable by javascript (httpOnly: false)
    $cookie = $response->getCookie($cookieName, false);
    expect($cookie->isHttpOnly())->toBeFalse();
    expect($cookie->getValue())->toBe($response->json('csrf_token'));
});

test('remember token cookie is httponly with narrow path and proper samesite policy', function () {
    $user = User::factory()->create(['password' => 'password']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $cookieName = config('auth.remember_cookie.name', 'waai_remember_token');
    $cookie = $response->getCookie($cookieName, false);

    expect($cookie)->not->toBeNull();
    // HttpOnly MUST be true to prevent XSS exfiltration
    expect($cookie->isHttpOnly())->toBeTrue();
    // Path should be restricted to authentication endpoints
    expect($cookie->getPath())->toBe('/api/v1/auth');
    // SameSite policy must be explicit
    expect($cookie->getSameSite())->toBe('lax');
});
