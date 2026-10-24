<?php

use App\Http\Middleware\AuthorizeRole;
use App\Http\Middleware\EditorCannotDelete;
use App\Http\Middleware\RequireAccessTokenAbility;
use App\Http\Middleware\ValidateApiCsrf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => AuthorizeRole::class,
            'editor.no_delete' => EditorCannotDelete::class,
            'token.inactivity' => RequireAccessTokenAbility::class,
            'require.access' => RequireAccessTokenAbility::class,
            'api.csrf' => ValidateApiCsrf::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        $middleware->encryptCookies(except: [
            'XSRF-TOKEN',
            'waai_remember_token',
        ]);

        $middleware->api(append: [
            EditorCannotDelete::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof ValidationException) {
                $errors = $e->errors();
                $firstError = Arr::first(Arr::flatten($errors)) ?: $e->getMessage();

                return response()->json([
                    'message' => $firstError ?: 'Validation failed.',
                    'errors' => $errors,
                ], $e->status);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            if ($e instanceof ThrottleRequestsException) {
                return response()->json([
                    'message' => 'Too many requests. Please try again later.',
                ], 429, $e->getHeaders());
            }

            if ($e instanceof NotFoundHttpException) {
                return response()->json([
                    'message' => 'The requested resource was not found.',
                ], 404);
            }

            if ($e instanceof MethodNotAllowedHttpException) {
                return response()->json([
                    'message' => 'Method not allowed for this route.',
                ], 405, $e->getHeaders());
            }

            if ($e instanceof AuthorizationException) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'Forbidden. Insufficient permissions.',
                ], 403);
            }

            if ($e instanceof HttpExceptionInterface) {
                $statusCode = $e->getStatusCode();
                $message = ! empty($e->getMessage())
                    ? $e->getMessage()
                    : (Response::$statusTexts[$statusCode] ?? 'An error occurred.');

                return response()->json([
                    'message' => $message,
                ], $statusCode, $e->getHeaders());
            }

            // Fallback for unexpected server errors (500)
            $isProduction = app()->isProduction() || ! config('app.debug');

            return response()->json([
                'message' => $isProduction ? 'Server error. Please try again later.' : ($e->getMessage() ?: 'Server error. Please try again later.'),
            ], 500);
        });
    })->create();
