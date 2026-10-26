<?php

use App\Http\Controllers\Api\Admin\BackgroundMusicController as AdminBackgroundMusicController;
use App\Http\Controllers\Api\Admin\EventController as AdminEventController;
use App\Http\Controllers\Api\Admin\TeamMemberController as AdminTeamMemberController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AppLinkController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\BackgroundMusicController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ContactInfoController;
use App\Http\Controllers\Api\CounterController;
use App\Http\Controllers\Api\EventController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // CSRF token endpoint for SPA clients (Angular)
    Route::get('/csrf-token', [AuthController::class, 'csrfToken']);

    // Public authentication routes (with rate limiting)
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware(['throttle:refresh', 'api.csrf']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:otp');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:otp');

    // Logout endpoint (supports both Bearer Access Token and Cookie-based logout when access token has expired)
    Route::post('/logout', [AuthController::class, 'logout']);

    // Protected API routes requiring a valid Bearer Access Token with 'access' ability
    Route::middleware(['auth:sanctum', 'require.access'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:change_password');
    });
});

// =========================================================================
// Counters Module Endpoints
// =========================================================================

// Public read-only endpoint (no authentication required)
Route::get('/counters', [CounterController::class, 'index']);

// Admin Dashboard endpoints (requires authenticated Admin or Editor)
Route::prefix('admin')->middleware(['auth:sanctum', 'require.access', 'role:admin,editor'])->group(function () {
    Route::get('/counters', [App\Http\Controllers\Api\Admin\CounterController::class, 'index']);
    Route::put('/counters', [App\Http\Controllers\Api\Admin\CounterController::class, 'update']);
    Route::patch('/counters', [App\Http\Controllers\Api\Admin\CounterController::class, 'update']);

    // Contact Info Dashboard endpoints
    Route::get('/contact-info', [App\Http\Controllers\Api\Admin\ContactInfoController::class, 'show']);
    Route::put('/contact-info', [App\Http\Controllers\Api\Admin\ContactInfoController::class, 'update']);

    // Calendar Events Module Admin Endpoints
    Route::get('/events', [AdminEventController::class, 'index']);
    Route::post('/events', [AdminEventController::class, 'store']);
    Route::get('/events/{event}', [AdminEventController::class, 'show']);
    // POST is accepted for multipart updates (send _method=PATCH): PHP does not parse multipart bodies on real PATCH/PUT
    Route::match(['put', 'patch', 'post'], '/events/{event}', [AdminEventController::class, 'update']);
    Route::match(['post', 'patch'], '/events/{event}/publish', [AdminEventController::class, 'publish']);
    Route::match(['post', 'patch'], '/events/{event}/unpublish', [AdminEventController::class, 'unpublish']);
    Route::delete('/events/{event}', [AdminEventController::class, 'destroy'])
        ->middleware('role:admin');
    // Team Management Module Admin Endpoints (POST is accepted on update for multipart photo uploads)
    Route::get('/team-members', [AdminTeamMemberController::class, 'index']);
    Route::post('/team-members', [AdminTeamMemberController::class, 'store']);
    Route::get('/team-members/{teamMember}', [AdminTeamMemberController::class, 'show']);
    Route::match(['put', 'patch', 'post'], '/team-members/{teamMember}', [AdminTeamMemberController::class, 'update']);
    Route::delete('/team-members/{teamMember}', [AdminTeamMemberController::class, 'destroy'])
        ->middleware('role:admin');

    // Background Music Module Admin Endpoints
    Route::get('/background-music', [AdminBackgroundMusicController::class, 'show']);
    Route::match(['put', 'patch', 'post'], '/background-music', [AdminBackgroundMusicController::class, 'update']);
});

// =========================================================================
// Users Management Admin Endpoints (Strictly Administrator Only)
// =========================================================================
Route::prefix('admin/users')->middleware(['auth:sanctum', 'require.access', 'role:admin'])->group(function () {
    Route::get('/', [AdminUserController::class, 'index']);
    Route::post('/', [AdminUserController::class, 'store']);
    Route::get('/{user}', [AdminUserController::class, 'show']);
    Route::match(['put', 'patch'], '/{user}', [AdminUserController::class, 'update']);
    Route::patch('/{user}/role', [AdminUserController::class, 'updateRole']);
    Route::patch('/{user}/password', [AdminUserController::class, 'resetPassword']);
    Route::delete('/{user}', [AdminUserController::class, 'destroy']);
});

// =========================================================================
// Background Music Public Endpoint
// =========================================================================
Route::get('/background-music', [BackgroundMusicController::class, 'show']);

// =========================================================================
// Contact Info Public Endpoint
// =========================================================================
Route::get('/contact-info', [ContactInfoController::class, 'index']);

// =========================================================================
// Calendar Events Module Public Endpoints
// =========================================================================
Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{slug}', [EventController::class, 'show']);

// =========================================================================
// App Links Module Endpoints
// =========================================================================
Route::get('/app-links', [AppLinkController::class, 'index']);
Route::put('/app-links', [AppLinkController::class, 'update'])
    ->middleware(['auth:sanctum', 'require.access', 'role:admin,editor']);

// =========================================================================
// Categories Module Endpoints (Public Shared Read-Only)
// =========================================================================
Route::get('/categories', [CategoryController::class, 'index']);
