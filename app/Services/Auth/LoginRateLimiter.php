<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class LoginRateLimiter
{
    /**
     * Get remaining lockout seconds if any rate limit threshold is exceeded.
     */
    public function getRetryAfter(Request $request, string $normalizedEmail): ?int
    {
        $emailIpKey = $this->getEmailIpKey($request, $normalizedEmail);
        $ipMinuteKey = $this->getIpMinuteKey($request);
        $ip15MinKey = $this->getIp15MinKey($request);

        $maxEmailIp = (int) config('auth.failed_login_email_ip_limit', 5);
        $maxIpMinute = (int) config('auth.failed_login_ip_limit', 10);
        $maxIp15Min = (int) config('auth.failed_login_ip_15m_limit', 50);

        $isLimited = false;
        $maxAvailableIn = 0;

        if (RateLimiter::tooManyAttempts($emailIpKey, $maxEmailIp)) {
            $isLimited = true;
            $maxAvailableIn = max($maxAvailableIn, RateLimiter::availableIn($emailIpKey));
        }

        if (RateLimiter::tooManyAttempts($ipMinuteKey, $maxIpMinute)) {
            $isLimited = true;
            $maxAvailableIn = max($maxAvailableIn, RateLimiter::availableIn($ipMinuteKey));
        }

        if (RateLimiter::tooManyAttempts($ip15MinKey, $maxIp15Min)) {
            $isLimited = true;
            $maxAvailableIn = max($maxAvailableIn, RateLimiter::availableIn($ip15MinKey));
        }

        return $isLimited ? max(1, $maxAvailableIn) : null;
    }

    /**
     * Record a failed login attempt across all layered limiters.
     */
    public function recordFailedAttempt(Request $request, string $normalizedEmail): void
    {
        $emailIpKey = $this->getEmailIpKey($request, $normalizedEmail);
        $ipMinuteKey = $this->getIpMinuteKey($request);
        $ip15MinKey = $this->getIp15MinKey($request);

        RateLimiter::hit($emailIpKey, 60);
        RateLimiter::hit($ipMinuteKey, 60);
        RateLimiter::hit($ip15MinKey, 900);
    }

    /**
     * Clear the email+IP failure counter upon successful login.
     */
    public function clearSuccessfulLogin(Request $request, string $normalizedEmail): void
    {
        RateLimiter::clear($this->getEmailIpKey($request, $normalizedEmail));
    }

    /**
     * Normalize email for consistent rate-limiting and comparison.
     */
    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    protected function getEmailIpKey(Request $request, string $normalizedEmail): string
    {
        return 'failed_login:email_ip:'.sha1($normalizedEmail).':'.$request->ip();
    }

    protected function getIpMinuteKey(Request $request): string
    {
        return 'failed_login:ip_min:'.$request->ip();
    }

    protected function getIp15MinKey(Request $request): string
    {
        return 'failed_login:ip_15m:'.$request->ip();
    }
}
