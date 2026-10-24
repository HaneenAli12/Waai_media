<?php

use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\CsrfService;
use App\Services\Auth\OtpService;

test('logout current device revokes both access and remember tokens and clears cookie', function () {
    $user = User::factory()->create(['password' => 'password']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $accessToken = $loginResponse->json('token');
    $sessionId = $loginResponse->json('session_id');
    $cookieName = config('auth.remember_cookie.name', 'waai_remember_token');
    $rememberCookie = $loginResponse->getCookie($cookieName, false)->getValue();

    $session = UserSession::find($sessionId);
    $accessId = $session->access_token_id;
    $rememberId = $session->remember_token_id;

    $response = $this->withToken($accessToken)
        ->postJson('/api/v1/auth/logout');

    $response->assertStatus(200)
        ->assertJson(['message' => 'Successfully logged out.'])
        ->assertCookieExpired($cookieName);

    // Both tokens must be deleted from personal_access_tokens
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $accessId]);
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $rememberId]);

    // Session is marked revoked
    $session->refresh();
    expect($session->revoked_at)->not->toBeNull();
    expect($session->revocation_reason)->toBe('manual_logout');

    // Access token cannot be used
    $this->withToken($accessToken)->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('logout with expired access token terminates session using remember cookie', function () {
    $user = User::factory()->create(['password' => 'password']);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $accessToken = $loginResponse->json('token');
    $sessionId = $loginResponse->json('session_id');
    $cookieName = config('auth.remember_cookie.name', 'waai_remember_token');
    $rememberCookie = $loginResponse->getCookie($cookieName, false)->getValue();

    // Fast-forward 2 hours so access token is expired
    $this->travel(2)->hours();

    $csrfService = app(CsrfService::class);
    $csrfToken = $csrfService->generateToken();

    // Call logout with expired access token (or no token) but valid remember cookie + CSRF
    $response = $this->withCredentials()
        ->withHeaders([
            'Origin' => 'http://localhost:4200',
            'X-XSRF-TOKEN' => $csrfToken,
        ])
        ->withUnencryptedCookie($cookieName, $rememberCookie)
        ->withUnencryptedCookie(config('auth.csrf.cookie_name', 'XSRF-TOKEN'), $csrfToken)
        ->postJson('/api/v1/auth/logout');

    $response->assertStatus(200)
        ->assertJson(['message' => 'Successfully logged out.'])
        ->assertCookieExpired($cookieName);

    $session = UserSession::find($sessionId);
    expect($session->revoked_at)->not->toBeNull();
    expect($session->revocation_reason)->toBe('manual_logout');
});

test('logout all devices revokes all sessions and all tokens for user', function () {
    $user = User::factory()->create(['password' => 'password']);

    // Device 1
    $login1 = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Device 1',
        'remember' => true,
    ]);
    $token1 = $login1->json('token');
    $session1Id = $login1->json('session_id');

    // Device 2
    $login2 = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Device 2',
        'remember' => true,
    ]);
    $token2 = $login2->json('token');
    $session2Id = $login2->json('session_id');

    // Device 3
    $login3 = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Device 3',
        'remember' => false,
    ]);
    $token3 = $login3->json('token');
    $session3Id = $login3->json('session_id');

    // Call logout-all from Device 1
    $response = $this->withToken($token1)
        ->postJson('/api/v1/auth/logout-all');

    $response->assertStatus(200)
        ->assertJson(['message' => 'Successfully logged out from all devices.'])
        ->assertCookieExpired(config('auth.remember_cookie.name', 'waai_remember_token'));

    // All sessions must be revoked
    expect(UserSession::find($session1Id)->revoked_at)->not->toBeNull();
    expect(UserSession::find($session2Id)->revoked_at)->not->toBeNull();
    expect(UserSession::find($session3Id)->revoked_at)->not->toBeNull();

    // All tokens must be rejected
    $this->withToken($token1)->getJson('/api/v1/auth/me')->assertStatus(401);
    $this->withToken($token2)->getJson('/api/v1/auth/me')->assertStatus(401);
    $this->withToken($token3)->getJson('/api/v1/auth/me')->assertStatus(401);

    // Sanctum tokens table must have zero tokens for this user
    expect($user->tokens()->count())->toBe(0);
});

test('password change revokes other devices but preserves current device session', function () {
    $user = User::factory()->create(['password' => 'OldPassword123!']);

    // Device A (current)
    $loginA = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'OldPassword123!',
        'device_name' => 'Device A',
    ]);
    $tokenA = $loginA->json('token');
    $sessionAId = $loginA->json('session_id');

    // Device B (other)
    $loginB = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'OldPassword123!',
        'device_name' => 'Device B',
    ]);
    $tokenB = $loginB->json('token');
    $sessionBId = $loginB->json('session_id');

    // Device A changes password
    $response = $this->withToken($tokenA)->postJson('/api/v1/auth/change-password', [
        'current_password' => 'OldPassword123!',
        'password' => 'NewPassword456!',
        'password_confirmation' => 'NewPassword456!',
    ]);

    $response->assertStatus(200);

    // Device A session remains active
    expect(UserSession::find($sessionAId)->revoked_at)->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->withToken($tokenA)->getJson('/api/v1/auth/me')->assertStatus(200);

    // Device B session is revoked
    expect(UserSession::find($sessionBId)->revoked_at)->not->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->withToken($tokenB)->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('password reset revokes all devices', function () {
    $user = User::factory()->create(['password' => 'OldPassword123!']);

    // Device A
    $loginA = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'OldPassword123!',
        'device_name' => 'Device A',
    ]);
    $tokenA = $loginA->json('token');
    $sessionAId = $loginA->json('session_id');

    // Device B
    $loginB = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'OldPassword123!',
        'device_name' => 'Device B',
    ]);
    $tokenB = $loginB->json('token');
    $sessionBId = $loginB->json('session_id');

    // Generate and verify OTP to obtain genuine 64-char single-use reset token
    $otpService = app(OtpService::class);
    $plainOtp = $otpService->generateOtp($user->email);
    $verifyResponse = $this->postJson('/api/v1/auth/verify-otp', [
        'email' => $user->email,
        'otp' => $plainOtp,
    ]);
    $resetToken = $verifyResponse->json('reset_token');

    // Reset password
    $response = $this->postJson('/api/v1/auth/reset-password', [
        'email' => $user->email,
        'reset_token' => $resetToken,
        'password' => 'BrandNewPassword999!',
        'password_confirmation' => 'BrandNewPassword999!',
    ]);

    $response->assertStatus(200);

    // Both sessions must be revoked
    expect(UserSession::find($sessionAId)->revoked_at)->not->toBeNull();
    expect(UserSession::find($sessionBId)->revoked_at)->not->toBeNull();

    // Both tokens invalid
    $this->app['auth']->forgetGuards();
    $this->withToken($tokenA)->getJson('/api/v1/auth/me')->assertStatus(401);
    $this->app['auth']->forgetGuards();
    $this->withToken($tokenB)->getJson('/api/v1/auth/me')->assertStatus(401);
});
