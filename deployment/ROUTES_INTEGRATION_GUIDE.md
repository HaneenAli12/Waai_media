# Background Music Routes & Console Integration Snippets

If your production server already has custom modifications or newer/divergent routes in `routes/api.php` or `routes/console.php`, **DO NOT overwrite** those files entirely. Instead, copy and paste the dedicated blocks below into the server's existing files.

---

### 1. In `routes/api.php`:

#### A. Add Controller Imports at the top:
```php
use App\Http\Controllers\Api\Admin\BackgroundMusicController as AdminBackgroundMusicController;
use App\Http\Controllers\Api\BackgroundMusicController;
```

#### B. Inside the `Route::prefix('admin')->middleware(['auth:sanctum', 'require.access', 'role:super_admin,admin,editor'])->group(...)` block, add:
```php
    // Background Music Module Admin Endpoints
    Route::get('/background-music', [AdminBackgroundMusicController::class, 'show']);
    Route::match(['put', 'patch', 'post'], '/background-music', [AdminBackgroundMusicController::class, 'update']);
```

#### C. Add the Public Endpoint (outside the admin group):
```php
// =========================================================================
// Background Music Public Endpoint
// =========================================================================
Route::get('/background-music', [BackgroundMusicController::class, 'show']);
```

---

### 2. In `routes/console.php`:

#### A. Add Schedule Import at top:
```php
use Illuminate\Support\Facades\Schedule;
```

#### B. Append the Weekly Cleanup Job:
```php
// Schedule weekly orphaned audio cleanup for background music in R2
Schedule::command('music:clean-orphaned')
    ->weeklyOn(1, '03:00') // Every Monday at 03:00 AM (runs in dry-run mode by default for safety)
    ->withoutOverlapping()
    ->runInBackground();
```
