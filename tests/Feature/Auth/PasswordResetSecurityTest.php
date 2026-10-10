<?php

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

test('send limit rejects 7th request in 60 minutes', function () {
    Mail::fake();
    $email = 'limit6@example.com';
    User::factory()->create(['email' => $email]);

    // Clear limiters just in case
    RateLimiter::clear('forgot-password-sends:'.$email);
    RateLimiter::clear('forgot-password-cooldown:'.$email);
    RateLimiter::clear('forgot-password-ip:127.0.0.1');

    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(200);
        // Bypass cooldown
        $this->travel(35)->seconds();
    }

    // 7th request should fail with 429
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(429);
});

test('sending becomes available again when earlier request falls outside window', function () {
    Mail::fake();
    $email = 'rolling@example.com';
    User::factory()->create(['email' => $email]);

    RateLimiter::clear('forgot-password-sends:'.$email);
    RateLimiter::clear('forgot-password-cooldown:'.$email);
    RateLimiter::clear('forgot-password-ip:127.0.0.1');

    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(200);
        $this->travel(35)->seconds();
    }

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(429);

    // Travel forward 61 minutes
    $this->travel(61)->minutes();

    // Need to clear IP limit since it resets to 1 hour, although 61 mins passed so it clears automatically.

    // Should now succeed
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(200);
});

test('unregistered email still consumes rate limit to prevent enumeration', function () {
    Mail::fake();
    $email = 'unregistered@example.com';

    RateLimiter::clear('forgot-password-sends:'.$email);
    RateLimiter::clear('forgot-password-cooldown:'.$email);
    RateLimiter::clear('forgot-password-ip:127.0.0.1');

    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(200);
        $this->travel(35)->seconds();
    }

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(429);
});
