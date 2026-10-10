<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Auth\ActivityLogger;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

test('sensitive values like passwords, OTPs, and tokens are scrubbed from activity logs', function () {
    $logger = app(ActivityLogger::class);
    $user = User::factory()->create();

    $log = $logger->log($user->id, 'auth.test_action', null, [
        'password' => 'super-secret-password',
        'otp' => '1234',
        'plain_token' => 'plain-sanctum-token',
        'safe_field' => 'visible-value',
        'nested' => [
            'password_confirmation' => 'super-secret-password',
            'device' => 'iPhone',
        ],
    ]);

    expect($log->details['password'])->toBe('[REDACTED]');
    expect($log->details['otp'])->toBe('[REDACTED]');
    expect($log->details['plain_token'])->toBe('[REDACTED]');
    expect($log->details['safe_field'])->toBe('visible-value');
    expect($log->details['nested']['password_confirmation'])->toBe('[REDACTED]');
    expect($log->details['nested']['device'])->toBe('iPhone');
});

test('activity logs are append-only and prevent updates and deletions', function () {
    $user = User::factory()->create();
    $log = ActivityLog::create([
        'user_id' => $user->id,
        'action' => 'auth.immutable_test',
        'ip_address' => '127.0.0.1',
        'details' => ['test' => true],
    ]);

    // Updating must throw RuntimeException
    expect(fn () => $log->update(['action' => 'modified']))
        ->toThrow(RuntimeException::class, 'Activity logs are append-only and cannot be modified.');

    // Deleting must throw RuntimeException
    expect(fn () => $log->delete())
        ->toThrow(RuntimeException::class, 'Activity logs are append-only and cannot be deleted.');
});

test('rate limiting is triggered after excessive login attempts', function () {
    User::factory()->create([
        'email' => 'rate_test@example.com',
    ]);

    // Send 5 attempts (allowed by per-minute limit of 5)
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'rate_test@example.com',
            'password' => 'wrong',
        ]);
    }

    // 6th attempt within the same minute should be throttled
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'rate_test@example.com',
        'password' => 'wrong',
    ]);

    $response->assertStatus(429);
});

test('eloquent automatically hashes passwords on create and update', function () {
    // Test create
    $user = User::create([
        'name' => 'Hash Test',
        'email' => 'hash_create@example.com',
        'password' => 'Plaintext123!',
        'role' => 'editor',
    ]);

    expect($user->password)->not->toBe('Plaintext123!');
    expect(Hash::check('Plaintext123!', $user->password))->toBeTrue();

    // Test update
    $user->update([
        'password' => 'NewPlaintext456!',
    ]);

    // Re-fetch to ensure database state
    $user->refresh();

    expect($user->password)->not->toBe('NewPlaintext456!');
    expect(Hash::check('NewPlaintext456!', $user->password))->toBeTrue();

    // Test firstOrCreate
    $newUser = User::firstOrCreate(
        ['email' => 'hash_firstorcreate@example.com'],
        [
            'name' => 'First Or Create Hash',
            'password' => 'AnotherPlaintext789!',
            'role' => 'editor',
        ]
    );

    expect($newUser->password)->not->toBe('AnotherPlaintext789!');
    expect(Hash::check('AnotherPlaintext789!', $newUser->password))->toBeTrue();
});
