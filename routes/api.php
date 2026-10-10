<?php

use App\Http\Controllers\Api\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Public authentication routes (with rate limiting)
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:otp');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:otp');

    // Authenticated session routes
    Route::middleware(['auth:sanctum', 'token.inactivity'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:change_password');
    });
});
