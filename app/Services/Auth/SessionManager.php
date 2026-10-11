<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;

class SessionManager
{
    public function __construct(
        protected ActivityLogger $activityLogger
    ) {}

    /**
     * Establish a new user session and enforce single active session constraint.
     */
    public function createSession(
        User $user,
        NewAccessToken $newAccessToken,
        Request $request,
        ?string $deviceName = null
    ): UserSession {
        $tokenId = $newAccessToken->accessToken->id;
        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $device = $deviceName ?: ($request->input('device_name') ?: 'Web Browser');

        $session = UserSession::create([
            'user_id' => $user->id,
            'token_id' => $tokenId,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'device_name' => $device,
            'revoked_at' => null,
            'revocation_reason' => null,
        ]);

        $this->activityLogger->log($user->id, 'auth.login', $request, [
            'session_id' => $session->id,
            'device_name' => $device,
        ]);

        return $session;
    }

    /**
     * Revoke previous active sessions for a user with the specified reason.
     */
    public function revokePreviousActiveSessions(User $user, string $reason, ?Request $request = null): void
    {
        $activeSessions = UserSession::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->get();

        foreach ($activeSessions as $session) {
            $session->update([
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ]);

            // Revoke the corresponding Sanctum personal access token
            if ($session->token_id) {
                $user->tokens()->where('id', $session->token_id)->delete();
            }

            $this->activityLogger->log($user->id, 'auth.session_revoked', $request, [
                'session_id' => $session->id,
                'revocation_reason' => $reason,
            ]);
        }

        app('auth')->forgetGuards();
    }

    /**
     * Terminate the current active session explicitly on logout.
     */
    public function terminateCurrentSession(User $user, ?int $tokenId, ?Request $request = null): void
    {
        $query = UserSession::where('user_id', $user->id)->whereNull('revoked_at');

        if ($tokenId) {
            $query->where('token_id', $tokenId);
        }

        $session = $query->first();

        if ($session) {
            $session->update([
                'revoked_at' => now(),
                'revocation_reason' => 'manual_logout',
            ]);
        }

        if ($tokenId) {
            $user->tokens()->where('id', $tokenId)->delete();
        } else {
            $user->currentAccessToken()?->delete();
        }

        $this->activityLogger->log($user->id, 'auth.logout', $request, [
            'session_id' => $session?->id,
        ]);

        app('auth')->forgetGuards();
    }

    /**
     * Revoke all sessions and tokens upon password reset.
     */
    public function revokeAllSessionsForPasswordReset(User $user, ?Request $request = null): void
    {
        UserSession::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revocation_reason' => 'password_reset',
            ]);

        $user->tokens()->delete();

        $this->activityLogger->log($user->id, 'auth.password_reset_revocation', $request, [
            'reason' => 'password_reset',
        ]);

        app('auth')->forgetGuards();
    }

    /**
     * Revoke all other sessions when user changes password.
     */
    public function revokeOtherSessionsForPasswordChange(User $user, int $currentTokenId, ?Request $request = null): void
    {
        $otherSessions = UserSession::where('user_id', $user->id)
            ->where(function ($query) use ($currentTokenId) {
                $query->where('token_id', '!=', $currentTokenId)
                    ->orWhereNull('token_id');
            })
            ->whereNull('revoked_at')
            ->get();

        foreach ($otherSessions as $session) {
            $session->update([
                'revoked_at' => now(),
                'revocation_reason' => 'password_changed',
            ]);

            if ($session->token_id) {
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
     * Revoke session due to inactivity expiration (7 days).
     */
    public function handleInactivityExpiration(User $user, int $tokenId, ?Request $request = null): void
    {
        $session = UserSession::where('user_id', $user->id)
            ->where('token_id', $tokenId)
            ->whereNull('revoked_at')
            ->first();

        if ($session) {
            $session->update([
                'revoked_at' => now(),
                'revocation_reason' => 'inactivity_expired',
            ]);
        }

        $user->tokens()->where('id', $tokenId)->delete();

        $this->activityLogger->log($user->id, 'auth.inactivity_expired', $request, [
            'session_id' => $session?->id,
            'token_id' => $tokenId,
            'reason' => 'inactivity_expired',
        ]);

        app('auth')->forgetGuards();
    }
}
