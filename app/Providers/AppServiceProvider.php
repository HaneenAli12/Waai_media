<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Coarse request-level throttle to protect server resources against DoS
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Coarse request-level throttle for token refresh
        RateLimiter::for('refresh', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('otp', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('change_password', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // Enforce token expiration on Sanctum personal access tokens
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, $isValid) {
            if (! $isValid) {
                return false;
            }

            if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
                return false;
            }

            return true;
        });
    }
}
