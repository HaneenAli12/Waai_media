<?php

use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Support\Facades\Route;

test('login validation error returns top-level message and errors object', function () {
    $response = $this->postJson('/api/v1/auth/login', []);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonValidationErrors(['email', 'password']);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
    expect($response->json('errors.email.0'))->toBe('The email field is required.');
});

test('login invalid credentials returns 422 with top-level message string', function () {
    User::factory()->create([
        'email' => 'valid@example.com',
        'password' => 'CorrectPassword1!',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'valid@example.com',
        'password' => 'WrongPassword!',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid email or password.',
        ]);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
});

test('login deactivated account returns 422 with top-level message string', function () {
    $user = User::factory()->create([
        'email' => 'deact@example.com',
        'password' => 'Secret123!',
    ]);
    $user->delete();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'deact@example.com',
        'password' => 'Secret123!',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Account is deactivated.',
        ]);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
});

test('forgot password validation error returns top-level message and errors object', function () {
    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'not-an-email',
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonValidationErrors(['email']);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
    expect($response->json('errors.email.0'))->toBe('The email field must be a valid email address.');
});

test('verify otp validation error returns top-level message and errors object', function () {
    $response = $this->postJson('/api/v1/auth/verify-otp', [
        'email' => 'test@example.com',
        'otp' => '12', // Not 4 digits
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonValidationErrors(['otp']);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
    expect($response->json('errors.otp.0'))->toBe('The otp field must be 4 digits.');
});

test('verify otp with no active request returns 400 with top-level message', function () {
    $response = $this->postJson('/api/v1/auth/verify-otp', [
        'email' => 'no_otp@example.com',
        'otp' => '1234',
    ]);

    $response->assertStatus(400)
        ->assertJson([
            'message' => 'No active OTP request found for this email address.',
        ]);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
});

test('verify otp with wrong code returns 422 with attempts message', function () {
    $otpService = app(OtpService::class);
    $otpService->generateOtp('wrong_code@example.com');

    $response = $this->postJson('/api/v1/auth/verify-otp', [
        'email' => 'wrong_code@example.com',
        'otp' => '9999',
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('Invalid verification code. Attempts remaining:');
});

test('reset password validation error returns top-level message and errors object', function () {
    $response = $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'reset@example.com',
        'reset_token' => str_repeat('a', 64),
        'password' => 'short',
        'password_confirmation' => 'mismatch',
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonValidationErrors(['password']);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
});

test('reset password invalid reset token returns 422 with top-level message', function () {
    $response = $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'unknown@example.com',
        'reset_token' => str_repeat('x', 64),
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired password reset token.',
        ]);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
});

test('unauthenticated request to /me returns 401 with standard message structure', function () {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertStatus(401)
        ->assertJson([
            'message' => 'Unauthenticated.',
        ]);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
});

test('unauthenticated request to logout returns 401 with standard message structure', function () {
    $response = $this->postJson('/api/v1/auth/logout');

    $response->assertStatus(401)
        ->assertJson([
            'message' => 'Unauthenticated.',
        ]);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
});

test('unauthenticated request to change-password returns 401 with standard message structure', function () {
    $response = $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'old',
        'password' => 'new123456',
        'password_confirmation' => 'new123456',
    ]);

    $response->assertStatus(401)
        ->assertJson([
            'message' => 'Unauthenticated.',
        ]);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
});

test('change password with wrong current password returns 422 with validation error structure', function () {
    $user = User::factory()->create([
        'email' => 'cp@example.com',
        'password' => 'ActualPassword1!',
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'cp@example.com',
        'password' => 'ActualPassword1!',
    ]);
    $token = $loginResponse->json('token');

    $response = $this->withToken($token)
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => 'WrongPassword!',
            'password' => 'NewPassword2@',
            'password_confirmation' => 'NewPassword2@',
        ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonValidationErrors(['current_password']);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
    expect($response->json('errors.current_password.0'))->toBe('The password is incorrect.');
});

test('change password with same password as current returns 422 with validation error structure', function () {
    $user = User::factory()->create([
        'email' => 'same@example.com',
        'password' => 'SamePassword1!',
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'same@example.com',
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
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonValidationErrors(['password']);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
    expect($response->json('errors.password.0'))->toBe('The password field and current password must be different.');
});

test('non-existent api route returns 404 with standardized json message and no stack trace', function () {
    $response = $this->getJson('/api/v1/auth/does-not-exist');

    $response->assertStatus(404)
        ->assertJson([
            'message' => 'The requested resource was not found.',
        ]);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
    expect($response->json('trace'))->toBeNull();
    expect($response->json('exception'))->toBeNull();
});

test('unsupported http method returns 405 with standardized json message and no stack trace', function () {
    $response = $this->getJson('/api/v1/auth/login');

    $response->assertStatus(405)
        ->assertJson([
            'message' => 'Method not allowed for this route.',
        ]);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
    expect($response->json('trace'))->toBeNull();
});

test('unexpected server error returns 500 with top-level message string and no stack trace', function () {
    Route::get('/api/v1/test-server-error', function () {
        throw new RuntimeException('Something unexpected broke.');
    });

    $response = $this->getJson('/api/v1/test-server-error');

    $response->assertStatus(500)
        ->assertJsonStructure(['message']);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
    expect($response->json('trace'))->toBeNull();
    expect($response->json('file'))->toBeNull();
    expect($response->json('line'))->toBeNull();
});
