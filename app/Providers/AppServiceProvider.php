<?php

namespace App\Providers;

use App\Services\Auth\SessionManager;
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
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $key = (string) ($email ? strtolower(trim($email)) : $request->ip());

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('otp', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('change_password', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // Enforce 7-day inactivity token expiration in Sanctum guard
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, $isValid) {
            if (! $isValid) {
                return false;
            }

            $lastActivity = $accessToken->last_used_at ?? $accessToken->created_at;

            if ($lastActivity && $lastActivity->lt(now()->subDays(7))) {
                if ($accessToken->tokenable) {
                    app(SessionManager::class)->handleInactivityExpiration(
                        $accessToken->tokenable,
                        $accessToken->id,
                        request()
                    );
                }

                return false;
            }

            return true;
        });
    }
}
