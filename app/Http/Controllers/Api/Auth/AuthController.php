<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Mail\Auth\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use App\Services\Auth\ActivityLogger;
use App\Services\Auth\OtpService;
use App\Services\Auth\SessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function __construct(
        protected SessionManager $sessionManager,
        protected OtpService $otpService,
        protected ActivityLogger $activityLogger
    ) {}

    /**
     * Authenticate user, revoke previous active session, and issue a Sanctum token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->input('email')));
        $user = User::withTrashed()->where('email', $email)->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            $this->activityLogger->log(null, 'auth.failed_login', $request, [
                'email' => $email,
            ]);

            return response()->json([
                'message' => 'Invalid email or password.',
            ], 422);
        }

        if ($user->trashed()) {
            return response()->json([
                'message' => 'Account is deactivated.',
            ], 422);
        }

        $deviceName = $request->input('device_name', 'Web Browser');
        $newAccessToken = $user->createToken($deviceName);

        $session = $this->sessionManager->createSession($user, $newAccessToken, $request, $deviceName);

        return response()->json([
            'message' => 'Login successful.',
            'token' => $newAccessToken->plainTextToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'session_id' => $session->id,
        ], 200);
    }

    /**
     * Get authenticated user profile and active session info.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $tokenId = $user->currentAccessToken()?->id;
        $activeSession = $tokenId 
            ? $user->userSessions()->where('token_id', $tokenId)->whereNull('revoked_at')->first() 
            : null;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at,
            ],
            'session' => $activeSession ? [
                'id' => $activeSession->id,
                'device_name' => $activeSession->device_name,
                'ip_address' => $activeSession->ip_address,
                'created_at' => $activeSession->created_at,
            ] : null,
        ], 200);
    }

    /**
     * Terminate current session explicitly on logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $tokenId = $user->currentAccessToken()?->id;

        $this->sessionManager->terminateCurrentSession($user, $tokenId, $request);

        return response()->json([
            'message' => 'Successfully logged out.',
        ], 200);
    }

    /**
     * Change password for authenticated user.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'password' => $request->input('password'),
        ]);

        if ($currentToken = $user->currentAccessToken()) {
            $this->sessionManager->revokeOtherSessionsForPasswordChange($user, $currentToken->id, $request);
        }

        $this->activityLogger->log($user->id, 'auth.change_password', $request);

        return response()->json([
            'message' => 'Password successfully changed.',
        ], 200);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->input('email')));
        $ip = $request->ip();

        $cooldownKey = 'forgot-password-cooldown:'.$email;
        $sendsKey = 'forgot-password-sends:'.$email;
        $ipKey = 'forgot-password-ip:'.$ip;

        $cooldownSeconds = config('auth.otp_cooldown_seconds', 30);
        $maxSends = config('auth.otp_max_sends', 6);
        $windowMinutes = config('auth.otp_send_window_minutes', 60);

        if (RateLimiter::tooManyAttempts($ipKey, 20)) {
            return response()->json(['message' => 'Too many requests. Please try again later.'], 429);
        }

        if (RateLimiter::tooManyAttempts($sendsKey, $maxSends)) {
            return response()->json(['message' => 'Too many requests for this email. Please try again later.'], 429);
        }

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $seconds = RateLimiter::availableIn($cooldownKey);

            return response()->json(['message' => "Please wait {$seconds} seconds before requesting a new code."], 429);
        }

        $lock = \Illuminate\Support\Facades\Cache::lock('forgot-password-lock:'.$email, 10);
        
        if (! $lock->get()) {
            return response()->json(['message' => 'Please wait a moment before requesting again.'], 429);
        }

        try {
            $user = User::where('email', $email)->first();

        if ($user && ! $user->trashed()) {
            $otp = $this->otpService->generateOtp($email);

            $this->activityLogger->log($user->id, 'auth.forgot_password_request', $request, [
                'email' => $email,
            ]);

            try {
                Mail::to($user->email)->send(
                    new PasswordResetOtpMail($otp)
                );

                RateLimiter::hit($ipKey, 3600);
                RateLimiter::hit($sendsKey, $windowMinutes * 60);
                RateLimiter::hit($cooldownKey, $cooldownSeconds);
            } catch (\Exception $e) {
                Log::error('Failed to send OTP email.', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                // Delete unusable OTP and do not consume rate limit quota
                PasswordResetOtp::where('email', $email)->whereNull('used_at')->delete();
            }
        } else {
            RateLimiter::hit($ipKey, 3600);
            RateLimiter::hit($sendsKey, $windowMinutes * 60);
            RateLimiter::hit($cooldownKey, $cooldownSeconds);
        }

        } finally {
            $lock->release();
        }

        return response()->json([
            'message' => 'If an account exists with that email, a password reset code has been sent.',
        ], 200);
    }

    /**
     * Verify 4-digit OTP and issue a 64-char single-use reset token.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->input('email')));
        $otp = $request->input('otp');
        $ip = $request->ip();

        $ipKey = 'verify-otp-ip:'.$ip;
        if (RateLimiter::tooManyAttempts($ipKey, 30)) {
            return response()->json(['message' => 'Too many requests. Please try again later.'], 429);
        }
        RateLimiter::hit($ipKey, 3600);

        $result = $this->otpService->verifyOtp($email, $otp);

        if (! $result['success']) {
            return response()->json([
                'message' => $result['error'],
            ], $result['status_code'] ?? 422);
        }

        $this->activityLogger->log(null, 'auth.otp_verified', $request, [
            'email' => $email,
        ]);

        return response()->json([
            'message' => 'OTP verified successfully.',
            'reset_token' => $result['reset_token'],
            'expires_in' => $result['expires_in'],
        ], 200);
    }

    /**
     * Reset password using single-use reset token and revoke all existing sessions/tokens.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->input('email')));
        $resetToken = $request->input('reset_token');
        $ip = $request->ip();

        $ipKey = 'reset-password-ip:'.$ip;
        if (RateLimiter::tooManyAttempts($ipKey, 20)) {
            return response()->json(['message' => 'Too many requests. Please try again later.'], 429);
        }
        RateLimiter::hit($ipKey, 3600);

        $otpRecord = $this->otpService->validateResetToken($email, $resetToken);

        if (! $otpRecord) {
            return response()->json([
                'message' => 'Invalid or expired password reset token.',
            ], 422);
        }

        $user = User::where('email', $email)->first();

        if (! $user || $user->trashed()) {
            return response()->json([
                'message' => 'Unable to locate user for password reset.',
            ], 404);
        }

        // Wrap in a transaction to ensure password update and token consumption are atomic
        $success = \Illuminate\Support\Facades\DB::transaction(function () use ($otpRecord, $user, $request) {
            if (! $this->otpService->markResetTokenUsed($otpRecord)) {
                return false;
            }

            // Update password
            $user->update([
                'password' => \Illuminate\Support\Facades\Hash::make($request->input('password')),
            ]);

            // Revoke all existing sessions and tokens for user
            $this->sessionManager->revokeAllSessionsForPasswordReset($user, $request);

            return true;
        });

        if (! $success) {
            return response()->json([
                'message' => 'Invalid or expired password reset token.',
            ], 422);
        }

        $this->activityLogger->log($user->id, 'auth.reset_password', $request);

        return response()->json([
            'message' => 'Password has been reset successfully. Please log in with your new password.',
        ], 200);
    }
}
