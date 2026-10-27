<?php

use App\Models\PasswordResetOtp;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\OtpService;

test('successful password reset with valid token changes password and revokes all active sessions/tokens', function () {
    $user = User::factory()->admin()->create([
        'email' => 'reset_user@example.com',
        'password' => 'OldPassword1!',
    ]);

    // Active session prior to reset
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'reset_user@example.com',
        'password' => 'OldPassword1!',
        'device_name' => 'Existing Active Device',
    ]);
    $oldToken = $loginResponse->json('token');
    $oldSessionId = $loginResponse->json('session_id');

    // Generate and verify OTP to obtain reset token
    $otpService = app(OtpService::class);
    $plainOtp = $otpService->generateOtp('reset_user@example.com');
    $verifyResponse = $this->postJson('/api/v1/auth/verify-otp', [
        'email' => 'reset_user@example.com',
        'otp' => $plainOtp,
    ]);
    $resetToken = $verifyResponse->json('reset_token');

    // Execute password reset
    $resetResponse = $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'reset_user@example.com',
        'reset_token' => $resetToken,
        'password' => 'BrandNewPassword123!',
        'password_confirmation' => 'BrandNewPassword123!',
    ]);

    $resetResponse->assertStatus(200)
        ->assertJson([
            'message' => 'Password has been reset successfully. Please log in with your new password.',
        ]);

    // Verify all prior sessions were revoked with reason 'password_reset'
    $oldSession = UserSession::find($oldSessionId);
    expect($oldSession->revoked_at)->not->toBeNull();
    expect($oldSession->revocation_reason)->toBe('password_reset');

    // Prior token can no longer access /me
    $this->withToken($oldToken)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);

    // Verify user can now log in with the new password
    $this->postJson('/api/v1/auth/login', [
        'email' => 'reset_user@example.com',
        'password' => 'BrandNewPassword123!',
    ])->assertStatus(200);

    // Old password fails
    $this->postJson('/api/v1/auth/login', [
        'email' => 'reset_user@example.com',
        'password' => 'OldPassword1!',
    ])->assertStatus(422);
});

test('reset token is single-use and cannot be used twice', function () {
    $user = User::factory()->admin()->create([
        'email' => 'single_use@example.com',
    ]);

    $otpService = app(OtpService::class);
    $plainOtp = $otpService->generateOtp('single_use@example.com');
    $verifyResponse = $this->postJson('/api/v1/auth/verify-otp', [
        'email' => 'single_use@example.com',
        'otp' => $plainOtp,
    ]);
    $resetToken = $verifyResponse->json('reset_token');

    // First use: success
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'single_use@example.com',
        'reset_token' => $resetToken,
        'password' => 'FirstReset123!',
        'password_confirmation' => 'FirstReset123!',
    ])->assertStatus(200);

    // Second use with the same token: rejected
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'single_use@example.com',
        'reset_token' => $resetToken,
        'password' => 'SecondReset123!',
        'password_confirmation' => 'SecondReset123!',
    ])->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired password reset token.',
        ]);
});

test('reset token expires after 5 minutes', function () {
    $user = User::factory()->admin()->create([
        'email' => 'expired_token@example.com',
    ]);

    $otpService = app(OtpService::class);
    $plainOtp = $otpService->generateOtp('expired_token@example.com');
    $verifyResponse = $this->postJson('/api/v1/auth/verify-otp', [
        'email' => 'expired_token@example.com',
        'otp' => $plainOtp,
    ]);
    $resetToken = $verifyResponse->json('reset_token');

    // Simulate 6 minutes passing
    $record = PasswordResetOtp::where('email', 'expired_token@example.com')->first();
    $record->update([
        'reset_token_expires_at' => now()->subMinutes(6),
    ]);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'expired_token@example.com',
        'reset_token' => $resetToken,
        'password' => 'ExpiredAttempt123!',
        'password_confirmation' => 'ExpiredAttempt123!',
    ])->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired password reset token.',
        ]);
});

test('verify-otp and reset-password strictly reject editor accounts', function () {
    $editor = User::factory()->editor()->create([
        'email' => 'editor_bypass@example.com',
    ]);

    $otpService = app(OtpService::class);
    $plainOtp = $otpService->generateOtp('editor_bypass@example.com');

    // verifyOtp rejection
    $verifyRes = $this->postJson('/api/v1/auth/verify-otp', [
        'email' => 'editor_bypass@example.com',
        'otp' => $plainOtp,
    ]);
    $verifyRes->assertStatus(422)
        ->assertJson(['message' => 'Invalid or expired OTP code.']);

    // Manually inject valid reset_token into database to simulate forged token
    $fakeResetToken = str_repeat('a', 64);
    PasswordResetOtp::where('email', 'editor_bypass@example.com')->update([
        'reset_token_hash' => hash('sha256', $fakeResetToken),
        'reset_token_expires_at' => now()->addMinutes(10),
    ]);

    // resetPassword rejection
    $resetRes = $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'editor_bypass@example.com',
        'reset_token' => $fakeResetToken,
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ]);
    $resetRes->assertStatus(422)
        ->assertJson(['message' => 'Invalid or expired password reset token.']);
});
