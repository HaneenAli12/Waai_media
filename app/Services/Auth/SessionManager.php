<?php

namespace App\Services\Auth;

use App\Models\RememberTokenRotation;
use App\Models\User;
use App\Models\UserSession;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;

class SessionManager
{
    public function __construct(
        protected ActivityLogger $activityLogger
    ) {}

    /**
     * Establish a new user session. Multi-device support: does NOT revoke other devices.
     */
    public function createSession(
        User $user,
        NewAccessToken $accessToken,
        Request $request,
        ?string $deviceName = null,
        ?NewAccessToken $rememberToken = null,
        bool $isRemember = false
    ): UserSession {
        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $device = $deviceName ?: ($request->input('device_name') ?: 'Web Browser');

        $originalLoginAt = $isRemember ? now() : null;
        $sessionDays = (int) config('auth.remember_session_lifetime_days', 30);
        $sessionExpiresAt = $isRemember ? now()->addDays($sessionDays) : null;

        $session = UserSession::create([
            'user_id' => $user->id,
            'token_id' => $accessToken->accessToken->id,
            'access_token_id' => $accessToken->accessToken->id,
            'remember_token_id' => $rememberToken?->accessToken->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'device_name' => $device,
            'is_remember' => $isRemember,
            'original_login_at' => $originalLoginAt,
            'session_expires_at' => $sessionExpiresAt,
            'last_refreshed_at' => null,
            'revoked_at' => null,
            'revocation_reason' => null,
        ]);

        $this->activityLogger->log($user->id, 'auth.login', $request, [
            'session_id' => $session->id,
            'device_name' => $device,
            'is_remember' => $isRemember,
        ]);

        return $session;
    }

    /**
     * Atomically rotate Remember Token and issue new Access Token.
     * Enforces fixed 30-day session deadline from original login.
     *
     * @return array{
     *     success: bool,
     *     access_token?: string,
     *     remember_token?: string,
     *     expires_in?: int,
     *     remember_expires_at?: DateTimeInterface,
     *     error?: string,
     *     status_code?: int
     * }
     */
    public function rotateRememberToken(UserSession $session, string $oldRememberTokenPlain, Request $request): array
    {
        return DB::transaction(function () use ($session, $oldRememberTokenPlain, $request) {
            /** @var UserSession|null $lockedSession */
            $lockedSession = UserSession::where('id', $session->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedSession || ! $lockedSession->isActive()) {
                return [
                    'success' => false,
                    'error' => 'Session is no longer active.',
                    'status_code' => 401,
                ];
            }

            if (! $lockedSession->is_remember || ! $lockedSession->session_expires_at) {
                return [
                    'success' => false,
                    'error' => 'Session does not have refresh capability.',
                    'status_code' => 401,
                ];
            }

            $remainingSeconds = $lockedSession->remainingSessionSeconds();
            if ($remainingSeconds <= 0) {
                $lockedSession->update([
                    'revoked_at' => now(),
                    'revocation_reason' => 'session_window_expired',
                ]);

                return [
                    'success' => false,
                    'error' => 'The 30-day Remember Me session window has expired. Please log in again.',
                    'status_code' => 401,
                ];
            }

            $user = $lockedSession->user;
            $deviceName = $lockedSession->device_name ?: 'Web Browser';

            // Access Token lifetime: min(1 hour, remaining session window)
            $defaultAccessLifetime = (int) config('auth.access_token_lifetime_seconds', 3600);
            $accessExpiresIn = min($defaultAccessLifetime, $remainingSeconds);
            $accessExpiresAt = now()->addSeconds($accessExpiresIn);

            // Remember Token lifetime: NEVER extended; capped to original_session_expires_at
            $rememberExpiresAt = $lockedSession->session_expires_at;

            // Revoke prior access token for this device session
            if ($lockedSession->access_token_id) {
                $user->tokens()->where('id', $lockedSession->access_token_id)->delete();
            }

            // Issue new access token with 'access' ability
            $newAccessToken = $user->createToken($deviceName, ['access'], $accessExpiresAt);

            // Issue new remember token with 'remember' ability
            $newRememberToken = $user->createToken($deviceName.' (Remember)', ['remember'], $rememberExpiresAt);

            // Record old token hash in rotations for replay detection (with grace period for concurrency)
            $graceSeconds = (int) config('auth.rotation_grace_period_seconds', 30);
            $tokenHash = hash('sha256', $oldRememberTokenPlain);

            RememberTokenRotation::create([
                'user_session_id' => $lockedSession->id,
                'token_hash' => $tokenHash,
                'rotated_at' => now(),
                'grace_period_expires_at' => now()->addSeconds($graceSeconds),
                'descendant_access_token_id' => $newAccessToken->accessToken->id,
            ]);

            // Invalidate/delete old remember token from personal_access_tokens
            if ($lockedSession->remember_token_id) {
                $user->tokens()->where('id', $lockedSession->remember_token_id)->delete();
            }

            // Update session with new token references
            $lockedSession->update([
                'token_id' => $newAccessToken->accessToken->id,
                'access_token_id' => $newAccessToken->accessToken->id,
                'remember_token_id' => $newRememberToken->accessToken->id,
                'last_refreshed_at' => now(),
            ]);

            $this->activityLogger->log($user->id, 'auth.token_refreshed', $request, [
                'session_id' => $lockedSession->id,
                'device_name' => $deviceName,
            ]);

            return [
                'success' => true,
                'access_token' => $newAccessToken->plainTextToken,
                'remember_token' => $newRememberToken->plainTextToken,
                'expires_in' => $accessExpiresIn,
                'remember_expires_at' => $rememberExpiresAt,
            ];
        });
    }

    /**
     * Handle replay attack compromise by revoking the affected device session family.
     */
    public function handleReplayCompromise(RememberTokenRotation $rotation, Request $request): void
    {
        $session = $rotation->session;
        if (! $session || ! $session->isActive()) {
            return;
        }

        $session->update([
            'revoked_at' => now(),
            'revocation_reason' => 'token_replay_detected',
        ]);

        $user = $session->user;
        if ($session->access_token_id) {
            $user->tokens()->where('id', $session->access_token_id)->delete();
        }
        if ($session->remember_token_id) {
            $user->tokens()->where('id', $session->remember_token_id)->delete();
        }

        $this->activityLogger->log($session->user_id, 'auth.token_replay_detected', $request, [
            'session_id' => $session->id,
            'rotation_id' => $rotation->id,
            'reason' => 'token_replay_detected',
        ]);
    }

    /**
     * Terminate current active session and revoke all its tokens (Access + Remember).
     */
    public function terminateCurrentSession(User $user, ?int $tokenId, ?Request $request = null, ?UserSession $session = null): void
    {
        $query = UserSession::where('user_id', $user->id)->whereNull('revoked_at');

        if ($session) {
            $targetSession = $session;
        } elseif ($tokenId) {
            $targetSession = $query->where(function ($q) use ($tokenId) {
                $q->where('token_id', $tokenId)
                    ->orWhere('access_token_id', $tokenId)
                    ->orWhere('remember_token_id', $tokenId);
            })->first();
        } else {
            $targetSession = $query->first();
        }

        if ($targetSession) {
            $targetSession->update([
                'revoked_at' => now(),
                'revocation_reason' => 'manual_logout',
            ]);

            // Revoke both access and remember tokens belonging to this session
            if ($targetSession->access_token_id) {
                $user->tokens()->where('id', $targetSession->access_token_id)->delete();
            }
            if ($targetSession->remember_token_id) {
                $user->tokens()->where('id', $targetSession->remember_token_id)->delete();
            }
            if ($targetSession->token_id && $targetSession->token_id !== $targetSession->access_token_id) {
                $user->tokens()->where('id', $targetSession->token_id)->delete();
            }
        } elseif ($tokenId) {
            $user->tokens()->where('id', $tokenId)->delete();
        }

        $this->activityLogger->log($user->id, 'auth.logout', $request, [
            'session_id' => $targetSession?->id,
        ]);

        app('auth')->forgetGuards();
    }

    /**
     * Terminate a remembered session using its Remember Token when the Access Token has expired.
     */
    public function terminateSessionByRememberToken(string $rememberTokenPlain, ?Request $request = null): ?UserSession
    {
        $token = PersonalAccessToken::findToken($rememberTokenPlain);

        if (! $token || ! $token->can('remember')) {
            return null;
        }

        $session = UserSession::where('remember_token_id', $token->id)
            ->whereNull('revoked_at')
            ->first();

        if ($session) {
            $user = $session->user;
            $this->terminateCurrentSession($user, null, $request, $session);

            return $session;
        }

        // Clean up orphaned token if session not found
        $token->delete();

        return null;
    }

    /**
     * Revoke all active device sessions and all tokens for a user.
     */
    public function revokeAllSessions(User $user, string $reason, ?Request $request = null): void
    {
        UserSession::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ]);

        $user->tokens()->delete();

        $this->activityLogger->log($user->id, 'auth.all_sessions_revoked', $request, [
            'reason' => $reason,
        ]);

        app('auth')->forgetGuards();
    }

    /**
     * Revoke all sessions and tokens upon password reset.
     */
    public function revokeAllSessionsForPasswordReset(User $user, ?Request $request = null): void
    {
        $this->revokeAllSessions($user, 'password_reset', $request);
    }

    /**
     * Revoke all other sessions when user changes password, preserving the current device.
     */
    public function revokeOtherSessionsForPasswordChange(User $user, int $currentAccessTokenId, ?Request $request = null): void
    {
        $currentSession = UserSession::where('user_id', $user->id)
            ->where(function ($q) use ($currentAccessTokenId) {
                $q->where('access_token_id', $currentAccessTokenId)
                    ->orWhere('token_id', $currentAccessTokenId);
            })
            ->whereNull('revoked_at')
            ->first();

        $otherSessionsQuery = UserSession::where('user_id', $user->id)->whereNull('revoked_at');

        if ($currentSession) {
            $otherSessionsQuery->where('id', '!=', $currentSession->id);
        } else {
            $otherSessionsQuery->where(function ($q) use ($currentAccessTokenId) {
                $q->where('access_token_id', '!=', $currentAccessTokenId)
                    ->where('token_id', '!=', $currentAccessTokenId);
            });
        }

        $otherSessions = $otherSessionsQuery->get();

        foreach ($otherSessions as $session) {
            $session->update([
                'revoked_at' => now(),
                'revocation_reason' => 'password_changed',
            ]);

            if ($session->access_token_id) {
                $user->tokens()->where('id', $session->access_token_id)->delete();
            }
            if ($session->remember_token_id) {
                $user->tokens()->where('id', $session->remember_token_id)->delete();
            }
            if ($session->token_id && $session->token_id !== $session->access_token_id) {
                $user->tokens()->where('id', $session->token_id)->delete();
            }

            $this->activityLogger->log($user->id, 'auth.session_revoked', $request, [
                'session_id' => $session->id,
                'revocation_reason' => 'password_changed',
            ]);
        }

        app('auth')->forgetGuards();
    }

    /**
     * Create an HttpOnly, secure Remember Me cookie.
     */
    public function makeRememberCookie(string $token, DateTimeInterface|int $expiresAt): Cookie
    {
        $name = config('auth.remember_cookie_name', 'waai_remember_token');
        $path = config('auth.remember_cookie_path', '/api/v1/auth');
        $domain = config('auth.remember_cookie_domain') ?: null;
        $secure = (bool) (config('auth.remember_cookie_secure') ?? (app()->isProduction() || request()->isSecure()));
        $sameSite = config('auth.remember_cookie_same_site', 'lax');

        $minutes = is_int($expiresAt)
            ? (int) ceil($expiresAt / 60)
            : (int) ceil(max(1, now()->diffInSeconds($expiresAt)) / 60);

        return cookie(
            name: $name,
            value: $token,
            minutes: $minutes,
            path: $path,
            domain: $domain,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: $sameSite
        );
    }

    /**
     * Create a cookie that clears the Remember Token in the client's browser.
     */
    public function makeForgetRememberCookie(): Cookie
    {
        $name = config('auth.remember_cookie_name', 'waai_remember_token');
        $path = config('auth.remember_cookie_path', '/api/v1/auth');
        $domain = config('auth.remember_cookie_domain') ?: null;

        return cookie()->forget($name, $path, $domain);
    }
}
