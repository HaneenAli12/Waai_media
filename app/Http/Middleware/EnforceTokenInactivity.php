<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionManager;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnforceTokenInactivity
{
    public function __construct(
        protected SessionManager $sessionManager
    ) {}

    /**
     * Handle an incoming request and enforce 7 days inactivity expiration.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $token = $user->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $lastActivity = $token->last_used_at ?? $token->created_at;

                if ($lastActivity && $lastActivity->lt(now()->subDays(7))) {
                    $this->sessionManager->handleInactivityExpiration($user, $token->id, $request);

                    return response()->json([
                        'message' => 'Session expired due to inactivity.',
                    ], 401);
                }
            }
        }

        return $next($request);
    }
}
