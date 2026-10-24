<?php

namespace App\Services\Auth;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class CsrfService
{
    /**
     * Generate an HMAC-signed CSRF token.
     */
    public function generateToken(): string
    {
        $raw = Str::random(40);
        $signature = hash_hmac('sha256', $raw, (string) config('app.key'));

        return "{$raw}.{$signature}";
    }

    /**
     * Validate an HMAC-signed CSRF token and optionally match against cookie.
     */
    public function validateToken(?string $token, ?string $cookieToken = null): bool
    {
        if (empty($token) || ! str_contains($token, '.')) {
            return false;
        }

        [$raw, $signature] = explode('.', $token, 2);
        $expectedSignature = hash_hmac('sha256', $raw, (string) config('app.key'));

        if (! hash_equals($expectedSignature, $signature)) {
            return false;
        }

        if ($cookieToken !== null && ! hash_equals($token, $cookieToken)) {
            return false;
        }

        return true;
    }

    /**
     * Verify that an Origin/Referer header matches configured allowed CORS origins.
     */
    public function isValidOrigin(?string $origin): bool
    {
        if (empty($origin)) {
            return true;
        }

        $parsed = parse_url($origin);
        if (! isset($parsed['scheme']) || ! isset($parsed['host'])) {
            return false;
        }

        $normalizedOrigin = $parsed['scheme'].'://'.$parsed['host'].(isset($parsed['port']) ? ':'.$parsed['port'] : '');
        $allowedOrigins = config('cors.allowed_origins', []);

        if (empty($allowedOrigins) || in_array('*', $allowedOrigins, true)) {
            return true;
        }

        foreach ($allowedOrigins as $allowed) {
            $allowedParsed = parse_url($allowed);
            $normalizedAllowed = ($allowedParsed['scheme'] ?? 'http').'://'.($allowedParsed['host'] ?? '').(isset($allowedParsed['port']) ? ':'.$allowedParsed['port'] : '');
            if (hash_equals($normalizedAllowed, $normalizedOrigin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create an XSRF-TOKEN cookie readable by Angular.
     */
    public function makeXsrfCookie(string $token): Cookie
    {
        $domain = config('session.domain') ?: null;
        $secure = (bool) (config('auth.remember_cookie_secure') ?? (app()->isProduction() || request()->isSecure()));
        $sameSite = config('auth.remember_cookie_same_site', 'lax');

        return cookie(
            name: 'XSRF-TOKEN',
            value: $token,
            minutes: 60 * 24 * 30, // 30 days
            path: '/',
            domain: $domain,
            secure: $secure,
            httpOnly: false, // Angular needs to read this cookie to send X-XSRF-TOKEN header
            raw: false,
            sameSite: $sameSite
        );
    }
}
