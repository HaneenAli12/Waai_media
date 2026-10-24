<?php

use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\CsrfService;

test('login with remember true issues remember cookie and does not expose it in json', function () {
    $user = User::factory()->create([
        'email' => 'remember@example.com',
        'password' => 'password',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'remember@example.com',
        'password' => 'password',
        'remember' => true,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'token',
            'token_type',
            'expires_in',
            'user',
            'session_id',
        ]);

    expect($response->json('remember_token'))->toBeNull();
    expect($response->json('expires_in'))->toBe(3600);

    // Verify cookie was sent
    $response->assertCookie(config('auth.remember_cookie.name', 'waai_remember_token'));

    $session = UserSession::find($response->json('session_id'));
    expect($session->is_remember)->toBeTrue();
    expect($session->remember_token_id)->not->toBeNull();
    expect($session->session_expires_at)->not->toBeNull();
    expect($session->session_expires_at->greaterThan(now()->addDays(29)))->toBeTrue();
    expect($session->session_expires_at->lessThanOrEqualTo(now()->addDays(30)))->toBeTrue();
});

test('login with remember false does not issue remember cookie', function () {
    $user = User::factory()->create([
        'email' => 'noremember@example.com',
        'password' => 'password',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'noremember@example.com',
        'password' => 'password',
        'remember' => false,
    ]);

    $response->assertStatus(200);
    $response->assertCookieExpired(config('auth.remember_cookie.name', 'waai_remember_token'));

    $session = UserSession::find($response->json('session_id'));
    expect($session->is_remember)->toBeFalse();
    expect($session->remember_token_id)->toBeNull();
    expect($session->session_expires_at)->toBeNull();
});

test('cookie-based refresh succeeds and rotates remember token', function () {
    $user = User::factory()->create(['password' => 'password']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $cookieName = config('auth.remember_cookie.name', 'waai_remember_token');
    $rememberCookie = $loginResponse->getCookie($cookieName, false)->getValue();
    $oldAccessToken = $loginResponse->json('token');
    $sessionId = $loginResponse->json('session_id');

    // Obtain CSRF token
    $csrfService = app(CsrfService::class);
    $csrfToken = $csrfService->generateToken();

    $refreshResponse = $this->withCredentials()
        ->withHeaders([
            'Origin' => 'http://localhost:4200',
            'X-XSRF-TOKEN' => $csrfToken,
        ])
        ->withUnencryptedCookie($cookieName, $rememberCookie)
        ->withUnencryptedCookie(config('auth.csrf.cookie_name', 'XSRF-TOKEN'), $csrfToken)
        ->postJson('/api/v1/auth/refresh');

    $refreshResponse->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'token',
            'token_type',
            'expires_in',
        ]);

    $newAccessToken = $refreshResponse->json('token');
    expect($newAccessToken)->not->toBe($oldAccessToken);

    // Verify rotated cookie was issued
    $newRememberCookie = $refreshResponse->getCookie($cookieName, false)->getValue();
    expect($newRememberCookie)->not->toBe($rememberCookie);

    // Old access token should be revoked
    $this->withToken($oldAccessToken)->getJson('/api/v1/auth/me')->assertStatus(401);

    // New access token works
    $this->withToken($newAccessToken)->getJson('/api/v1/auth/me')->assertStatus(200);

    // Verify rotation record exists
    $this->assertDatabaseHas('remember_token_rotations', [
        'user_session_id' => $sessionId,
    ]);
});

test('refresh works without a valid access token or when access token is expired', function () {
    $user = User::factory()->create(['password' => 'password']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $cookieName = config('auth.remember_cookie.name', 'waai_remember_token');
    $rememberCookie = $loginResponse->getCookie($cookieName, false)->getValue();

    // Fast-forward 2 hours so access token is completely expired
    $this->travel(2)->hours();

    $csrfService = app(CsrfService::class);
    $csrfToken = $csrfService->generateToken();

    $refreshResponse = $this->withCredentials()
        ->withHeaders([
            'Origin' => 'http://localhost:4200',
            'X-XSRF-TOKEN' => $csrfToken,
        ])
        ->withUnencryptedCookie($cookieName, $rememberCookie)
        ->withUnencryptedCookie(config('auth.csrf.cookie_name', 'XSRF-TOKEN'), $csrfToken)
        ->postJson('/api/v1/auth/refresh');

    $refreshResponse->assertStatus(200);
    $newAccessToken = $refreshResponse->json('token');

    // Works with the newly minted access token
    $this->withToken($newAccessToken)->getJson('/api/v1/auth/me')->assertStatus(200);
});

test('remember token rotation preserves the fixed 30-day session deadline', function () {
    $user = User::factory()->create(['password' => 'password']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $sessionId = $loginResponse->json('session_id');
    $session = UserSession::find($sessionId);
    $originalDeadline = $session->session_expires_at->toDateTimeString();

    $cookieName = config('auth.remember_cookie.name', 'waai_remember_token');
    $rememberCookie = $loginResponse->getCookie($cookieName, false)->getValue();

    // Travel 15 days into the 30-day window
    $this->travel(15)->days();

    $csrfService = app(CsrfService::class);
    $csrfToken = $csrfService->generateToken();

    $refreshResponse = $this->withCredentials()
        ->withHeaders([
            'Origin' => 'http://localhost:4200',
            'X-XSRF-TOKEN' => $csrfToken,
        ])
        ->withUnencryptedCookie($cookieName, $rememberCookie)
        ->withUnencryptedCookie(config('auth.csrf.cookie_name', 'XSRF-TOKEN'), $csrfToken)
        ->postJson('/api/v1/auth/refresh');

    $refreshResponse->assertStatus(200);

    $refreshedSession = UserSession::find($sessionId);
    // The session_expires_at MUST NOT be extended to 45 days; it must remain original 30 days!
    expect($refreshedSession->session_expires_at->toDateTimeString())->toBe($originalDeadline);
});

test('access token expires_in is capped near the end of the 30-day session window', function () {
    $user = User::factory()->create(['password' => 'password']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $cookieName = config('auth.remember_cookie.name', 'waai_remember_token');
    $rememberCookie = $loginResponse->getCookie($cookieName, false)->getValue();

    // Travel to 29 days, 23 hours, and 40 minutes (only 20 minutes remaining in session)
    $this->travel(29)->days();
    $this->travel(23)->hours();
    $this->travel(40)->minutes();

    $csrfService = app(CsrfService::class);
    $csrfToken = $csrfService->generateToken();

    $refreshResponse = $this->withCredentials()
        ->withHeaders([
            'Origin' => 'http://localhost:4200',
            'X-XSRF-TOKEN' => $csrfToken,
        ])
        ->withUnencryptedCookie($cookieName, $rememberCookie)
        ->withUnencryptedCookie(config('auth.csrf.cookie_name', 'XSRF-TOKEN'), $csrfToken)
        ->postJson('/api/v1/auth/refresh');

    $refreshResponse->assertStatus(200);
    // expires_in must be roughly 20 minutes (1200 seconds), not standard 3600 seconds
    expect($refreshResponse->json('expires_in'))->toBeLessThanOrEqual(1201);
    expect($refreshResponse->json('expires_in'))->toBeGreaterThanOrEqual(1190);
});

test('refresh fails after the 30-day session deadline passes', function () {
    $user = User::factory()->create(['password' => 'password']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $cookieName = config('auth.remember_cookie.name', 'waai_remember_token');
    $rememberCookie = $loginResponse->getCookie($cookieName, false)->getValue();

    // Travel beyond 30 days
    $this->travel(30)->days();
    $this->travel(5)->minutes();

    $csrfService = app(CsrfService::class);
    $csrfToken = $csrfService->generateToken();

    $refreshResponse = $this->withCredentials()
        ->withHeaders([
            'Origin' => 'http://localhost:4200',
            'X-XSRF-TOKEN' => $csrfToken,
        ])
        ->withUnencryptedCookie($cookieName, $rememberCookie)
        ->withUnencryptedCookie(config('auth.csrf.cookie_name', 'XSRF-TOKEN'), $csrfToken)
        ->postJson('/api/v1/auth/refresh');

    $refreshResponse->assertStatus(401)
        ->assertJson(['message' => 'Remember token has expired.']);
});

test('harmless duplicate refresh within grace period returns current valid token without revocation', function () {
    $user = User::factory()->create(['password' => 'password']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $cookieName = config('auth.remember_cookie.name', 'waai_remember_token');
    $oldRememberCookie = $loginResponse->getCookie($cookieName, false)->getValue();

    $csrfService = app(CsrfService::class);
    $csrfToken = $csrfService->generateToken();

    // First refresh rotates the token
    $firstRefresh = $this->withCredentials()
        ->withHeaders([
            'Origin' => 'http://localhost:4200',
            'X-XSRF-TOKEN' => $csrfToken,
        ])
        ->withUnencryptedCookie($cookieName, $oldRememberCookie)
        ->withUnencryptedCookie(config('auth.csrf.cookie_name', 'XSRF-TOKEN'), $csrfToken)
        ->postJson('/api/v1/auth/refresh');

    $firstRefresh->assertStatus(200);
    $firstAccessToken = $firstRefresh->json('token');

    // Simulate duplicate request within 5 seconds using the OLD remember token
    $this->travel(5)->seconds();

    $duplicateRefresh = $this->withCredentials()
        ->withHeaders([
            'Origin' => 'http://localhost:4200',
            'X-XSRF-TOKEN' => $csrfToken,
        ])
        ->withUnencryptedCookie($cookieName, $oldRememberCookie)
        ->withUnencryptedCookie(config('auth.csrf.cookie_name', 'XSRF-TOKEN'), $csrfToken)
        ->postJson('/api/v1/auth/refresh');

    $duplicateRefresh->assertStatus(200);
    expect($duplicateRefresh->json('token'))->not->toBeNull();

    // The access token issued works
    $this->withToken($duplicateRefresh->json('token'))->getJson('/api/v1/auth/me')->assertStatus(200);

    // Verify session remains active
    $sessionId = $loginResponse->json('session_id');
    $session = UserSession::find($sessionId);
    expect($session->revoked_at)->toBeNull();
});

test('replay detection revokes session when rotated remember token is reused outside grace period', function () {
    $user = User::factory()->create(['password' => 'password']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $cookieName = config('auth.remember_cookie.name', 'waai_remember_token');
    $stolenRememberCookie = $loginResponse->getCookie($cookieName, false)->getValue();
    $sessionId = $loginResponse->json('session_id');

    $csrfService = app(CsrfService::class);
    $csrfToken = $csrfService->generateToken();

    // Legitimate user refreshes token
    $legitRefresh = $this->withCredentials()
        ->withHeaders([
            'Origin' => 'http://localhost:4200',
            'X-XSRF-TOKEN' => $csrfToken,
        ])
        ->withUnencryptedCookie($cookieName, $stolenRememberCookie)
        ->withUnencryptedCookie(config('auth.csrf.cookie_name', 'XSRF-TOKEN'), $csrfToken)
        ->postJson('/api/v1/auth/refresh');

    $legitRefresh->assertStatus(200);

    // Attacker attempts to replay stolen token 60 seconds later (well past 30s grace period)
    $this->travel(60)->seconds();

    $attackerRefresh = $this->withCredentials()
        ->withHeaders([
            'Origin' => 'http://localhost:4200',
            'X-XSRF-TOKEN' => $csrfToken,
        ])
        ->withUnencryptedCookie($cookieName, $stolenRememberCookie)
        ->withUnencryptedCookie(config('auth.csrf.cookie_name', 'XSRF-TOKEN'), $csrfToken)
        ->postJson('/api/v1/auth/refresh');

    $attackerRefresh->assertStatus(401)
        ->assertJson(['message' => 'Token replay detected. Session revoked.']);

    // Entire session must be revoked for security
    $session = UserSession::find($sessionId);
    expect($session->revoked_at)->not->toBeNull();
    expect($session->revocation_reason)->toBe('token_replay_detected');
});

test('refresh requires valid csrf token and rejects when missing or mismatched', function () {
    $user = User::factory()->create(['password' => 'password']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $cookieName = config('auth.remember_cookie.name', 'waai_remember_token');
    $rememberCookie = $loginResponse->getCookie($cookieName, false)->getValue();

    // 1. Missing CSRF token header
    $responseNoHeader = $this->withCredentials()
        ->withHeaders(['Origin' => 'http://localhost:4200'])
        ->withUnencryptedCookie($cookieName, $rememberCookie)
        ->postJson('/api/v1/auth/refresh');

    $responseNoHeader->assertStatus(419);

    // 2. Mismatched CSRF token
    $csrfService = app(CsrfService::class);
    $validToken = $csrfService->generateToken();

    $responseMismatch = $this->withCredentials()
        ->withHeaders([
            'Origin' => 'http://localhost:4200',
            'X-XSRF-TOKEN' => 'forged-token-value',
        ])
        ->withUnencryptedCookie($cookieName, $rememberCookie)
        ->withUnencryptedCookie(config('auth.csrf.cookie_name', 'XSRF-TOKEN'), $validToken)
        ->postJson('/api/v1/auth/refresh');

    $responseMismatch->assertStatus(419)
        ->assertJson(['message' => 'CSRF token mismatch.']);
});

test('refresh rejects untrusted Origin with 403', function () {
    $user = User::factory()->create(['password' => 'password']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $cookieName = config('auth.remember_cookie.name', 'waai_remember_token');
    $rememberCookie = $loginResponse->getCookie($cookieName, false)->getValue();

    $csrfService = app(CsrfService::class);
    $csrfToken = $csrfService->generateToken();

    $response = $this->withCredentials()
        ->withHeaders([
            'Origin' => 'http://evil-attacker-site.com',
            'X-XSRF-TOKEN' => $csrfToken,
        ])
        ->withUnencryptedCookie($cookieName, $rememberCookie)
        ->withUnencryptedCookie(config('auth.csrf.cookie_name', 'XSRF-TOKEN'), $csrfToken)
        ->postJson('/api/v1/auth/refresh');

    $response->assertStatus(403)
        ->assertJson(['message' => 'Forbidden. Invalid request origin.']);
});

test('remember token cannot authorize normal protected api endpoints', function () {
    $user = User::factory()->create(['password' => 'password']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $cookieName = config('auth.remember_cookie.name', 'waai_remember_token');
    $rememberCookie = $loginResponse->getCookie($cookieName, false)->getValue();

    // If an attacker attempts to send the raw remember token in Authorization: Bearer
    $response = $this->withToken($rememberCookie)->getJson('/api/v1/auth/me');

    // Must be rejected with 403 Forbidden because it lacks the 'access' ability!
    $response->assertStatus(403)
        ->assertJson(['message' => 'Forbidden. This endpoint requires an access token.']);
});
