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
use App\Models\RememberTokenRotation;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\ActivityLogger;
use App\Services\Auth\CsrfService;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\OtpService;
use App\Services\Auth\SessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        protected SessionManager $sessionManager,
        protected OtpService $otpService,
        protected ActivityLogger $activityLogger,
        protected LoginRateLimiter $loginRateLimiter,
        protected CsrfService $csrfService
    ) {}

    /**
     * Provide an HMAC-signed CSRF token and set the XSRF-TOKEN cookie for Angular SPAs.
     */
    public function csrfToken(Request $request): JsonResponse
    {
        $token = $this->csrfService->generateToken();
        $cookie = $this->csrfService->makeXsrfCookie($token);

        return response()->json([
            'csrf_token' => $token,
        ], 200)->withCookie($cookie);
    }

    /**
     * Authenticate user with layered failure rate limiting, multi-device support,
     * and optional 30-day Remember Me HttpOnly Cookie.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = $this->loginRateLimiter->normalizeEmail($request->input('email'));

        // Layered failed-login rate limiting check
        if ($retryAfter = $this->loginRateLimiter->getRetryAfter($request, $email)) {
            return response()->json([
                'message' => 'Too many failed login attempts. Please try again later.',
            ], 429, [
                'Retry-After' => (string) $retryAfter,
            ]);
        }

        $user = User::withTrashed()->where('email', $email)->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            $this->loginRateLimiter->recordFailedAttempt($request, $email);

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

        // Clear email+IP failure counter on successful credentials
        $this->loginRateLimiter->clearSuccessfulLogin($request, $email);

        $deviceName = $request->input('device_name') ?: 'Web Browser';
        $wantsRemember = $request->wantsRemember();

        // Access Token: 1-hour absolute lifetime, ability 'access'
        $accessExpiresIn = (int) config('auth.access_token_lifetime_seconds', 3600);
        $accessExpiresAt = now()->addSeconds($accessExpiresIn);
        $accessToken = $user->createToken($deviceName, ['access'], $accessExpiresAt);

        $rememberToken = null;
        $rememberCookie = null;

        if ($wantsRemember) {
            // Remember Token: fixed 30 days from original login, ability 'remember'
            $rememberDays = (int) config('auth.remember_session_lifetime_days', 30);
            $rememberExpiresAt = now()->addDays($rememberDays);
            $rememberToken = $user->createToken($deviceName.' (Remember)', ['remember'], $rememberExpiresAt);

            $session = $this->sessionManager->createSession($user, $accessToken, $request, $deviceName, $rememberToken, true);

            // Set Remember Token in HttpOnly, secure cookie
            $rememberCookie = $this->sessionManager->makeRememberCookie(
                $rememberToken->plainTextToken,
                $rememberExpiresAt
            );
        } else {
            $session = $this->sessionManager->createSession($user, $accessToken, $request, $deviceName, null, false);

            // Ensure any stale remember cookie is forgotten
            $rememberCookie = $this->sessionManager->makeForgetRememberCookie();
        }

        // Issue fresh XSRF-TOKEN cookie for subsequent requests
        $csrfToken = $this->csrfService->generateToken();
        $xsrfCookie = $this->csrfService->makeXsrfCookie($csrfToken);

        $response = response()->json([
            'message' => 'Login successful.',
            'token' => $accessToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessExpiresIn,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'session_id' => $session->id,
        ], 200);

        if ($rememberCookie) {
            $response->withCookie($rememberCookie);
        }

        return $response->withCookie($xsrfCookie);
    }

    /**
     * Refresh Access Token using HttpOnly Remember Cookie with atomic rotation
     * and replay attack detection within a fixed 30-day window.
     */
    public function refresh(Request $request): JsonResponse
    {
        $cookieName = config('auth.remember_cookie_name', 'waai_remember_token');
        $rememberTokenPlain = $request->cookie($cookieName);

        if (empty($rememberTokenPlain)) {
            return response()->json([
                'message' => 'No remember token provided.',
            ], 401);
        }

        $tokenRecord = PersonalAccessToken::findToken($rememberTokenPlain);

        // Case 1: Active remember token presented
        if ($tokenRecord) {
            if (! $tokenRecord->can('remember')) {
                return response()->json([
                    'message' => 'Invalid token ability. Remember token required for refresh.',
                ], 403);
            }

            if ($tokenRecord->expires_at && $tokenRecord->expires_at->isPast()) {
                $forgetCookie = $this->sessionManager->makeForgetRememberCookie();

                return response()->json([
                    'message' => 'Remember token has expired.',
                ], 401)->withCookie($forgetCookie);
            }

            $session = UserSession::where('remember_token_id', $tokenRecord->id)
                ->whereNull('revoked_at')
                ->first();

            if (! $session || ! $session->isActive()) {
                $forgetCookie = $this->sessionManager->makeForgetRememberCookie();

                return response()->json([
                    'message' => 'Session is no longer active.',
                ], 401)->withCookie($forgetCookie);
            }

            $rotationResult = $this->sessionManager->rotateRememberToken($session, $rememberTokenPlain, $request);

            if (! $rotationResult['success']) {
                $forgetCookie = $this->sessionManager->makeForgetRememberCookie();

                return response()->json([
                    'message' => $rotationResult['error'],
                ], $rotationResult['status_code'] ?? 401)->withCookie($forgetCookie);
            }

            $newRememberCookie = $this->sessionManager->makeRememberCookie(
                $rotationResult['remember_token'],
                $rotationResult['remember_expires_at']
            );

            return response()->json([
                'message' => 'Token refreshed successfully.',
                'token' => $rotationResult['access_token'],
                'token_type' => 'Bearer',
                'expires_in' => $rotationResult['expires_in'],
            ], 200)->withCookie($newRememberCookie);
        }

        // Case 2: Token not found in personal_access_tokens — evaluate replay detection
        $tokenHash = hash('sha256', $rememberTokenPlain);
        $rotation = RememberTokenRotation::where('token_hash', $tokenHash)->latest('id')->first();

        if ($rotation) {
            // Harmless concurrency / duplicate request within grace period
            if ($rotation->isWithinGracePeriod()) {
                $session = $rotation->session;
                if ($session && $session->isActive()) {
                    $descendantToken = $rotation->descendantAccessToken;
                    $remaining = $session->remainingSessionSeconds();
                    $expiresIn = min((int) config('auth.access_token_lifetime_seconds', 3600), $remaining);

                    // Re-issue a temporary access token for this concurrent request
                    $duplicateAccess = $session->user->createToken(
                        $session->device_name,
                        ['access'],
                        now()->addSeconds($expiresIn)
                    );

                    return response()->json([
                        'message' => 'Token refreshed successfully.',
                        'token' => $duplicateAccess->plainTextToken,
                        'token_type' => 'Bearer',
                        'expires_in' => $expiresIn,
                    ], 200);
                }
            }

            // Confirmed replay attack outside grace period: revoke compromised session family
            $this->sessionManager->handleReplayCompromise($rotation, $request);
            $forgetCookie = $this->sessionManager->makeForgetRememberCookie();

            return response()->json([
                'message' => 'Token replay detected. Session revoked.',
            ], 401)->withCookie($forgetCookie);
        }

        // Unknown or completely invalid token
        $forgetCookie = $this->sessionManager->makeForgetRememberCookie();

        return response()->json([
            'message' => 'Invalid or expired remember token.',
        ], 401)->withCookie($forgetCookie);
    }

    /**
     * Get authenticated user profile and active session info.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $tokenId = $user->currentAccessToken()?->id;
        $activeSession = $tokenId
            ? $user->userSessions()
                ->where(function ($q) use ($tokenId) {
                    $q->where('token_id', $tokenId)
                        ->orWhere('access_token_id', $tokenId);
                })
                ->whereNull('revoked_at')
                ->first()
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
     * Terminate current device session. Supports both Bearer Access Token and
     * Cookie-based logout when the Access Token has expired.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user() ?: auth('sanctum')->user();
        $forgetCookie = $this->sessionManager->makeForgetRememberCookie();

        // 1. Authenticated via Access Token
        if ($user) {
            $token = $user->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                if (! $token->can('access')) {
                    return response()->json([
                        'message' => 'Forbidden. This endpoint requires an access token.',
                    ], 403);
                }

                if ($token->expires_at && $token->expires_at->isPast()) {
                    return response()->json([
                        'message' => 'Unauthenticated.',
                    ], 401);
                }
            }

            $tokenId = $token?->id;
            $this->sessionManager->terminateCurrentSession($user, $tokenId, $request);

            return response()->json([
                'message' => 'Successfully logged out.',
            ], 200)->withCookie($forgetCookie);
        }

        // 2. Fallback: Expired Access Token but valid Remember Cookie present
        $cookieName = config('auth.remember_cookie_name', 'waai_remember_token');
        $rememberCookie = $request->cookie($cookieName);

        if (! empty($rememberCookie)) {
            // Origin check for cookie-authenticated request
            $origin = $request->header('Origin') ?: $request->header('Referer');
            if ($origin && ! $this->csrfService->isValidOrigin($origin)) {
                return response()->json([
                    'message' => 'Forbidden. Invalid request origin.',
                ], 403);
            }

            $session = $this->sessionManager->terminateSessionByRememberToken($rememberCookie, $request);

            if ($session) {
                return response()->json([
                    'message' => 'Successfully logged out.',
                ], 200)->withCookie($forgetCookie);
            }
        }

        return response()->json([
            'message' => 'Unauthenticated.',
        ], 401);
    }

    /**
     * Revoke all active sessions and tokens for the user across all devices.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->sessionManager->revokeAllSessions($user, 'logout_all', $request);
        $forgetCookie = $this->sessionManager->makeForgetRememberCookie();

        return response()->json([
            'message' => 'Successfully logged out from all devices.',
        ], 200)->withCookie($forgetCookie);
    }

    /**
     * Change password for authenticated user and revoke other devices.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $request) {
            $user->update([
                'password' => $request->input('password'),
            ]);

            if ($currentToken = $user->currentAccessToken()) {
                $this->sessionManager->revokeOtherSessionsForPasswordChange($user, $currentToken->id, $request);
            }
        });

        $this->activityLogger->log($user->id, 'auth.change_password', $request);

        return response()->json([
            'message' => 'Password successfully changed.',
        ], 200);
    }

    /**
     * Initiate password reset with timing-attack mitigation and asynchronous mail queuing.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $this->loginRateLimiter->normalizeEmail($request->input('email'));
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

        $lock = Cache::lock('forgot-password-lock:'.$email, 10);

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
                    // Queued mail delivery mitigates timing differences and prevents SMTP blocking
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
                    PasswordResetOtp::where('email', $email)->whereNull('used_at')->delete();
                }
            } else {
                // Constant-time mitigation: perform comparable hashing work when user does not exist
                hash('sha256', Str::random(40));

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
        $email = $this->loginRateLimiter->normalizeEmail($request->input('email'));
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
        $email = $this->loginRateLimiter->normalizeEmail($request->input('email'));
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

        $success = DB::transaction(function () use ($otpRecord, $user, $request) {
            if (! $this->otpService->markResetTokenUsed($otpRecord)) {
                return false;
            }

            $user->update([
                'password' => Hash::make($request->input('password')),
            ]);

            $this->sessionManager->revokeAllSessionsForPasswordReset($user, $request);

            return true;
        });

        if (! $success) {
            return response()->json([
                'message' => 'Invalid or expired password reset token.',
            ], 422);
        }

        $this->activityLogger->log($user->id, 'auth.reset_password', $request);
        $forgetCookie = $this->sessionManager->makeForgetRememberCookie();

        return response()->json([
            'message' => 'Password has been reset successfully. Please log in with your new password.',
        ], 200)->withCookie($forgetCookie);
    }
}
