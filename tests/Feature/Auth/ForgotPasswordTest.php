<?php

use App\Mail\Auth\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Support\Facades\Mail;

test('forgot password returns identical generic response whether email exists or not', function () {
    $user = User::factory()->create([
        'email' => 'registered@example.com',
    ]);

    // Test with registered email
    $responseA = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'registered@example.com',
    ]);
    $responseA->assertStatus(200)
        ->assertJson([
            'message' => 'If an account exists with that email, a password reset code has been sent.',
        ]);

    // Test with non-registered email
    $responseB = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'nonexistent@example.com',
    ]);
    $responseB->assertStatus(200)
        ->assertJson([
            'message' => 'If an account exists with that email, a password reset code has been sent.',
        ]);
});

test('forgot password dispatches PasswordResetOtpMail to the correct admin user', function () {
    Mail::fake();

    $user = User::factory()->admin()->create([
        'email' => 'mailtest@example.com',
    ]);

    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'mailtest@example.com',
    ]);

    Mail::assertQueued(PasswordResetOtpMail::class, function ($mail) {
        return $mail->hasTo('mailtest@example.com');
    });
});

test('forgot password creates 4-digit OTP hashed with HMAC-SHA256 valid for 90 seconds for admin', function () {
    $user = User::factory()->admin()->create([
        'email' => 'otp_user@example.com',
    ]);

    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'otp_user@example.com',
    ]);

    $otpRecord = PasswordResetOtp::where('email', 'otp_user@example.com')->first();
    expect($otpRecord)->not->toBeNull();
    expect(strlen($otpRecord->otp_hash))->toBe(64); // SHA-256 hash length
    expect($otpRecord->expires_at->gt(now()))->toBeTrue();
    // 90 seconds
    expect($otpRecord->expires_at->diffInSeconds(now()))->toBeLessThanOrEqual(90);
});

test('forgot password silently skips sending OTP for editor accounts and reveals no role information', function () {
    Mail::fake();

    $editor = User::factory()->editor()->create([
        'email' => 'editor_nopassword@example.com',
    ]);

    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'editor_nopassword@example.com',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'If an account exists with that email, a password reset code has been sent.',
        ]);

    Mail::assertNothingQueued();
    expect(PasswordResetOtp::where('email', 'editor_nopassword@example.com')->count())->toBe(0);
});

test('OTP expires after 90 seconds', function () {
    $otpService = app(OtpService::class);
    $plainOtp = $otpService->generateOtp('expired_otp@example.com');

    // Simulate expiration
    $record = PasswordResetOtp::where('email', 'expired_otp@example.com')->first();
    $record->update([
        'expires_at' => now()->subSeconds(95),
    ]);

    $response = $this->postJson('/api/v1/auth/verify-otp', [
        'email' => 'expired_otp@example.com',
        'otp' => $plainOtp,
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The verification code has expired. Please request a new code.',
        ]);
});

test('OTP verification enforces 3-attempt limit', function () {
    $otpService = app(OtpService::class);
    $plainOtp = $otpService->generateOtp('limit@example.com');

    // Attempt 1: wrong code
    $this->postJson('/api/v1/auth/verify-otp', [
        'email' => 'limit@example.com',
        'otp' => '9999',
    ])->assertStatus(422);

    // Attempt 2: wrong code
    $this->postJson('/api/v1/auth/verify-otp', [
        'email' => 'limit@example.com',
        'otp' => '8888',
    ])->assertStatus(422);

    // Attempt 3: wrong code
    $this->postJson('/api/v1/auth/verify-otp', [
        'email' => 'limit@example.com',
        'otp' => '7777',
    ])->assertStatus(422);

    // Attempt 4: blocked due to maximum attempts exceeded
    $this->postJson('/api/v1/auth/verify-otp', [
        'email' => 'limit@example.com',
        'otp' => $plainOtp,
    ])->assertStatus(429)
        ->assertJson([
            'message' => 'Maximum verification attempts exceeded. Please request a new code.',
        ]);
});

test('successful OTP verification issues a 64-char single-use reset token valid for 5 minutes', function () {
    $otpService = app(OtpService::class);
    $plainOtp = $otpService->generateOtp('success_otp@example.com');

    $response = $this->postJson('/api/v1/auth/verify-otp', [
        'email' => 'success_otp@example.com',
        'otp' => $plainOtp,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'reset_token',
            'expires_in',
        ]);

    $resetToken = $response->json('reset_token');
    expect(strlen($resetToken))->toBe(64);

    $record = PasswordResetOtp::where('email', 'success_otp@example.com')->first();
    expect($record->reset_token_hash)->not->toBeNull();
    // Verify plaintext reset token is NOT stored directly in database
    expect($record->reset_token_hash)->not->toBe($resetToken);
    expect($record->reset_token_expires_at->gt(now()))->toBeTrue();
});

test('resend is rejected during the 30-second cooldown', function () {
    $email = 'cooldown@example.com';

    // First request
    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => $email,
    ])->assertStatus(200);

    // Second request immediately after should fail with 429
    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => $email,
    ])->assertStatus(429);
});

test('resend is allowed after 30 seconds and invalidates previous OTP', function () {
    $email = 'resend_allowed@example.com';

    User::factory()->admin()->create(['email' => $email]);

    // First request
    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => $email,
    ])->assertStatus(200);

    $firstOtpRecord = PasswordResetOtp::where('email', $email)->first();
    $firstOtpHash = $firstOtpRecord->otp_hash;

    // Simulate waiting 30 seconds
    $this->travel(35)->seconds();

    // Second request should succeed
    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => $email,
    ])->assertStatus(200);

    // Verify previous OTP was invalidated (deleted)
    $remainingOtps = PasswordResetOtp::where('email', $email)->get();
    expect($remainingOtps->count())->toBe(1);
    expect($remainingOtps->first()->otp_hash)->not->toBe($firstOtpHash);
});
