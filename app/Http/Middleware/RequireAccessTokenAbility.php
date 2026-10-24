<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RequireAccessTokenAbility
{
    /**
     * Handle an incoming request.
     * Enforce that ordinary protected API endpoints only accept access tokens.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $token = $user->currentAccessToken();

        // Reject if token is not an instance of PersonalAccessToken or does not have the 'access' ability
        if ($token instanceof PersonalAccessToken) {
            if (! $token->can('access')) {
                return response()->json([
                    'message' => 'Forbidden. This endpoint requires an access token.',
                ], 403);
            }

            // Ensure server-enforced expiration
            if ($token->expires_at && $token->expires_at->isPast()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        }

        return $next($request);
    }
}
