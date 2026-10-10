<?php

use App\Models\ActivityLog;
use App\Models\User;

test('authenticated user can change password with valid current password', function () {
    $user = User::factory()->create([
        'email' => 'change@example.com',
        'password' => 'OldPassword1!',
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'change@example.com',
        'password' => 'OldPassword1!',
    ]);
    $token = $loginResponse->json('token');

    $response = $this->withToken($token)
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword1!',
            'password' => 'NewSecurePassword2@',
            'password_confirmation' => 'NewSecurePassword2@',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Password successfully changed.',
        ]);

    // Verify user can now log in with the new password
    $this->postJson('/api/v1/auth/login', [
        'email' => 'change@example.com',
        'password' => 'NewSecurePassword2@',
    ])->assertStatus(200);

    // Old password no longer works
    $this->postJson('/api/v1/auth/login', [
        'email' => 'change@example.com',
        'password' => 'OldPassword1!',
    ])->assertStatus(422);

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $user->id,
        'action' => 'auth.change_password',
    ]);
});

test('change password fails if current password is incorrect', function () {
    $user = User::factory()->create([
        'email' => 'change_fail@example.com',
        'password' => 'CorrectPassword1!',
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'change_fail@example.com',
        'password' => 'CorrectPassword1!',
    ]);
    $token = $loginResponse->json('token');

    $response = $this->withToken($token)
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => 'WrongPassword!',
            'password' => 'NewPassword2@',
            'password_confirmation' => 'NewPassword2@',
        ]);

    $response->assertStatus(422);
});

test('unauthenticated user cannot change password', function () {
    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'any',
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ])->assertStatus(401);
});

test('new password cannot be the same as the current password', function () {
    $user = User::factory()->create([
        'email' => 'reuse@example.com',
        'password' => 'SamePassword1!',
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'reuse@example.com',
        'password' => 'SamePassword1!',
    ]);
    $token = $loginResponse->json('token');

    $response = $this->withToken($token)
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => 'SamePassword1!',
            'password' => 'SamePassword1!',
            'password_confirmation' => 'SamePassword1!',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('incorrect current password creates a safe activity log', function () {
    $user = User::factory()->create([
        'email' => 'log_fail@example.com',
        'password' => 'CorrectPassword1!',
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'log_fail@example.com',
        'password' => 'CorrectPassword1!',
    ]);
    $token = $loginResponse->json('token');

    $this->withToken($token)
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => 'WrongPassword!',
            'password' => 'NewPassword2@',
            'password_confirmation' => 'NewPassword2@',
        ]);

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $user->id,
        'action' => 'auth.change_password_failed',
    ]);

    // Ensure sensitive details are not logged
    $log = ActivityLog::where('user_id', $user->id)
        ->where('action', 'auth.change_password_failed')
        ->first();

    expect($log->details['reason'])->toBe('invalid_current_password');
});

test('current session remains valid but other sessions are revoked after password change', function () {
    $user = User::factory()->create([
        'email' => 'multi_session@example.com',
        'password' => 'OldPassword1!',
    ]);

    // Login device A
    $loginA = $this->postJson('/api/v1/auth/login', [
        'email' => 'multi_session@example.com',
        'password' => 'OldPassword1!',
        'device_name' => 'Device A',
    ]);
    $tokenA = $loginA->json('token');

    // Login device B
    $loginB = $this->postJson('/api/v1/auth/login', [
        'email' => 'multi_session@example.com',
        'password' => 'OldPassword1!',
        'device_name' => 'Device B',
    ]);
    $tokenB = $loginB->json('token');

    // Change password from device A
    $this->withToken($tokenA)
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword1!',
            'password' => 'NewPassword2@',
            'password_confirmation' => 'NewPassword2@',
        ])->assertStatus(200);

    // Device A should still be active
    $this->withToken($tokenA)->getJson('/api/v1/auth/me')->assertStatus(200);

    app('auth')->forgetGuards();

    // Device B should be invalidated
    $this->withToken($tokenB)->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('rate limiting is enforced for repeated change password attempts', function () {
    $user = User::factory()->create([
        'email' => 'throttle_change@example.com',
        'password' => 'OldPassword1!',
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'throttle_change@example.com',
        'password' => 'OldPassword1!',
    ]);
    $token = $loginResponse->json('token');

    // Send 5 attempts (allowed by per-minute limit of 5)
    for ($i = 0; $i < 5; $i++) {
        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'WrongPassword!',
            'password' => 'NewPassword2@',
            'password_confirmation' => 'NewPassword2@',
        ]);
    }

    // 6th attempt should be throttled
    $response = $this->withToken($token)->postJson('/api/v1/auth/change-password', [
        'current_password' => 'WrongPassword!',
        'password' => 'NewPassword2@',
        'password_confirmation' => 'NewPassword2@',
    ]);

    $response->assertStatus(429);
});
