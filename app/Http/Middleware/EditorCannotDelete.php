<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EditorCannotDelete
{
    /**
     * Handle an incoming request and strictly prohibit editors from DELETE requests.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? auth('sanctum')->user();

        if ($user && $user->isEditor() && $request->isMethod('DELETE')) {
            return response()->json([
                'message' => 'Forbidden. Editors are strictly prohibited from performing delete operations.',
            ], 403);
        }

        return $next($request);
    }
}
