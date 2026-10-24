<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('login-email-ip:*');
    RateLimiter::clear('login-ip-minute:*');
    RateLimiter::clear('login-ip-15min:*');
});

test('email plus ip locks out after 5 consecutive failed login attempts', function () {
    $user = User::factory()->create([
        'email' => 'ratelimit@example.com',
        'password' => 'CorrectPassword1!',
    ]);

    // 4 failed attempts: return 422
    for ($i = 1; $i <= 4; $i++) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.100'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'ratelimit@example.com',
                'password' => 'WrongPassword!',
            ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Invalid email or password.']);
    }

    // 5th failed attempt: still returns 422, but hits the threshold
    $fifth = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.100'])
        ->postJson('/api/v1/auth/login', [
            'email' => 'ratelimit@example.com',
            'password' => 'WrongPassword!',
        ]);
    $fifth->assertStatus(422);

    // 6th attempt: locked out with 429 Too Many Requests and Retry-After header
    $blocked = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.100'])
        ->postJson('/api/v1/auth/login', [
            'email' => 'ratelimit@example.com',
            'password' => 'CorrectPassword1!',
        ]);

    $blocked->assertStatus(429)
        ->assertJson(['message' => 'Too many failed login attempts. Please try again later.'])
        ->assertHeader('Retry-After');

    // Different IP can still attempt login with the correct password (no account-wide lockout weaponization!)
    $differentIp = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.200'])
        ->postJson('/api/v1/auth/login', [
            'email' => 'ratelimit@example.com',
            'password' => 'CorrectPassword1!',
        ]);

    $differentIp->assertStatus(200);
});

test('successful login resets email plus ip failure counter', function () {
    $user = User::factory()->create([
        'email' => 'resetcounter@example.com',
        'password' => 'CorrectPassword1!',
    ]);

    // 3 failed attempts
    for ($i = 1; $i <= 3; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.2.100'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'resetcounter@example.com',
                'password' => 'WrongPassword!',
            ])->assertStatus(422);
    }

    // Successful login
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.2.100'])
        ->postJson('/api/v1/auth/login', [
            'email' => 'resetcounter@example.com',
            'password' => 'CorrectPassword1!',
        ])->assertStatus(200);

    // After success, client can fail again up to threshold without immediate lockout
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.2.100'])
        ->postJson('/api/v1/auth/login', [
            'email' => 'resetcounter@example.com',
            'password' => 'WrongPassword!',
        ])->assertStatus(422);
});

test('ip wide limit locks out after 10 failed attempts across different accounts', function () {
    // 10 failed attempts across 10 different fake accounts from the same IP
    for ($i = 1; $i <= 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson('/api/v1/auth/login', [
                'email' => "random{$i}@example.com",
                'password' => 'AnyPassword123!',
            ])->assertStatus(422);
    }

    // 11th attempt from this IP is blocked with 429
    $blocked = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->postJson('/api/v1/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'AnyPassword123!',
        ]);

    $blocked->assertStatus(429)
        ->assertJson(['message' => 'Too many failed login attempts. Please try again later.'])
        ->assertHeader('Retry-After');
});

test('email normalization shares the rate limit counter across casing variations', function () {
    $user = User::factory()->create([
        'email' => 'normalize_case@example.com',
        'password' => 'Secret123!',
    ]);

    // 5 failed attempts with different casing
    $variations = [
        'normalize_case@example.com',
        'Normalize_Case@example.com',
        'NORMALIZE_CASE@EXAMPLE.COM',
        '  normalize_case@example.com  ',
        'Normalize_case@EXAMPLE.com',
    ];

    foreach ($variations as $emailAttempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '172.16.0.5'])
            ->postJson('/api/v1/auth/login', [
                'email' => $emailAttempt,
                'password' => 'Wrong!',
            ])->assertStatus(422);
    }

    // Next attempt with canonical email is blocked with 429
    $this->withServerVariables(['REMOTE_ADDR' => '172.16.0.5'])
        ->postJson('/api/v1/auth/login', [
            'email' => 'normalize_case@example.com',
            'password' => 'Secret123!',
        ])->assertStatus(429);
});
