# News Module Integration Snippets

If your production server already contains newer custom modifications in `routes/api.php`, `routes/console.php`, or `app/Models/Category.php`, you can merge the dedicated News snippets below instead of overwriting the files completely.

---

### 1. In `app/Models/Category.php`

Add the `news()` relationship inside the `Category` model class:

```php
    /**
     * News articles belonging to this category.
     */
    public function news(): HasMany
    {
        return $this->hasMany(News::class, 'category_id');
    }
```

---

### 2. In `routes/api.php`

#### A. Add Controller Imports at the top:
```php
use App\Http\Controllers\Api\Admin\NewsController as AdminNewsController;
use App\Http\Controllers\Api\NewsController;
```

#### B. Inside the Admin Route Group:
`Route::prefix('admin')->middleware(['auth:sanctum', 'require.access', 'role:super_admin,admin,editor'])->group(...)`

Append:
```php
    // News Module Admin Endpoints
    Route::post('/news/images', [AdminNewsController::class, 'uploadImage']);
    Route::get('/news', [AdminNewsController::class, 'index']);
    Route::post('/news', [AdminNewsController::class, 'store']);
    Route::get('/news/{news}', [AdminNewsController::class, 'show']);
    // POST is accepted for multipart updates (send _method=PATCH): PHP does not parse multipart bodies on real PATCH/PUT
    Route::match(['put', 'patch', 'post'], '/news/{news}', [AdminNewsController::class, 'update']);
    Route::match(['post', 'patch'], '/news/{news}/publish', [AdminNewsController::class, 'publish']);
    Route::match(['post', 'patch'], '/news/{news}/unpublish', [AdminNewsController::class, 'unpublish']);
    Route::delete('/news/{news}', [AdminNewsController::class, 'destroy'])
        ->middleware('role:super_admin,admin');
```

#### C. Add the Public Endpoints (outside the admin group):
```php
// =========================================================================
// News Module Public Endpoints
// =========================================================================
Route::get('/news/latest', [NewsController::class, 'latest']);
Route::get('/news', [NewsController::class, 'index']);
Route::get('/news/{identifier}', [NewsController::class, 'show']);
```

---

### 3. In `routes/console.php`

Append the weekly News orphaned images cleanup schedule:

```php
// Weekly orphaned news image cleanup (news/covers/ and news/editor/ in R2). Same reviewed safe mode:
// --force, 24h minimum age, capped deletions, wrong-database abort, atomic lock + withoutOverlapping().
// Review candidates first with: php artisan news:clean-orphaned-images --dry-run
Schedule::command('news:clean-orphaned-images --force --grace-hours=24 --max-deletions=50')
    ->weeklyOn(1, '05:00') // Every Monday at 05:00 AM (after the client logos cleanup)
    ->withoutOverlapping(120)
    ->runInBackground();
```
