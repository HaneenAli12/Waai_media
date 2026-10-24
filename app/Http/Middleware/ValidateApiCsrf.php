<?php

namespace App\Http\Middleware;

use App\Services\Auth\CsrfService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiCsrf
{
    public function __construct(
        protected CsrfService $csrfService
    ) {}

    /**
     * Handle an incoming request for cookie-authenticated endpoints.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Strict Origin / Referer validation against allowed CORS origins
        $origin = $request->header('Origin') ?: $request->header('Referer');
        if ($origin && ! $this->csrfService->isValidOrigin($origin)) {
            return response()->json([
                'message' => 'Forbidden. Invalid request origin.',
            ], 403);
        }

        // 2. CSRF Token verification for cookie-authenticated state changes
        $submittedToken = $request->header('X-XSRF-TOKEN')
            ?: ($request->header('X-CSRF-TOKEN') ?: $request->input('_token'));

        $cookieToken = $request->cookie('XSRF-TOKEN');

        if (! $this->csrfService->validateToken($submittedToken, $cookieToken)) {
            return response()->json([
                'message' => 'CSRF token mismatch.',
            ], 419);
        }

        return $next($request);
    }
}
