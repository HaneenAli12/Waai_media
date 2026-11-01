# Waai Media — Portfolio Module Production Deployment & Integration Guide

Production deployment guide for installing the **Portfolio Module** on the Waai Media Hostinger Production Server.

---

## 1. Package Summary & Included Files

This deployment archive (`deployment/waai_media_portfolio_module_deployment.zip`) contains the complete and verified Portfolio module implementation.

### Application Code:
- `app/Console/Commands/BackfillPortfolioSlugsCommand.php`
- `app/Console/Commands/CleanOrphanedPortfolioImagesCommand.php`
- `app/Http/Controllers/Api/Admin/PortfolioController.php`
- `app/Http/Controllers/Api/Admin/ClientController.php` (Portfolio cascade deletion integration)
- `app/Http/Controllers/Api/PortfolioController.php`
- `app/Http/Requests/Admin/Portfolio/IndexPortfolioRequest.php`
- `app/Http/Requests/Admin/Portfolio/StorePortfolioRequest.php`
- `app/Http/Requests/Admin/Portfolio/UpdatePortfolioRequest.php`
- `app/Http/Requests/Admin/Portfolio/Concerns/ValidatesPortfolioFilters.php`
- `app/Http/Requests/Admin/Portfolio/Concerns/ValidatesPortfolioPayload.php`
- `app/Http/Requests/Portfolio/IndexPublicPortfolioRequest.php`
- `app/Http/Requests/Portfolio/PublicPortfolioClientNavigationRequest.php`
- `app/Http/Requests/Portfolio/PublicPortfolioClientsRequest.php`
- `app/Http/Resources/PortfolioClientCardResource.php`
- `app/Http/Resources/PortfolioItemAdminResource.php`
- `app/Http/Resources/PortfolioItemPublicResource.php`
- `app/Models/PortfolioItem.php`
- `app/Models/PortfolioMedia.php`
- `app/Services/Portfolio/PortfolioImageService.php`
- `app/Services/Portfolio/PortfolioItemService.php`
- `app/Services/Portfolio/PortfolioSlugService.php`
- `app/Services/Storage/ImageStorageService.php` (Includes `portfolio/images/` allowlist prefix)
- `app/Support/Portfolio/PortfolioCategoryPresenter.php`
- `app/Support/Portfolio/PortfolioClientPresenter.php`
- `app/Support/Portfolio/PortfolioKind.php`

### Shared Routes:
- `routes/api.php` (Includes Portfolio Admin and Public routes)
- `routes/console.php` (Includes weekly dry-run cleanup schedule for Portfolio)

### Database Migrations:
- `database/migrations/2026_10_30_000001_create_portfolio_items_table.php`
- `database/migrations/2026_10_30_000002_create_portfolio_media_table.php`
- `database/migrations/2026_10_31_000001_add_client_and_cover_to_portfolio_items.php`
- `database/migrations/2026_10_31_180313_add_slug_and_drop_slot_unique_on_portfolio_items.php`

### Seeders:
- `database/seeders/CategorySeeder.php` (Contains authoritative seeded portfolio categories)

### Documentation & Postman:
- `docs/portfolio.md`
- `docs/Waai_Media_Portfolio_API.postman_collection.json`

### Deployment Guide:
- `deployment/PORTFOLIO_INTEGRATION_GUIDE.md`

---

## 2. Pre-Deployment Requirements & Checks

### 1. Database Engine Compatibility Check
The migration `2026_10_31_000001_add_client_and_cover_to_portfolio_items` uses a **stored generated column** (`slot_subcategory_key`).
Ensure your MySQL/MariaDB database on Hostinger meets the minimum required version:
- **MySQL >= 5.7.6** or **MariaDB >= 10.2.1**
Run the check in production terminal:
```bash
php artisan tinker --execute="echo DB::selectOne('select version() as v')->v;"
```

### 2. Environment Variables & Storage Check
Portfolio uploads images (cover and work media) directly to Cloudflare R2 under `portfolio/images/`.
Verify that your production `.env` contains:
```env
CLOUDFLARE_R2_ACCESS_KEY_ID=your_key_id
CLOUDFLARE_R2_SECRET_ACCESS_KEY=your_secret_key
CLOUDFLARE_R2_BUCKET=your_bucket_name
CLOUDFLARE_R2_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
CLOUDFLARE_R2_PUBLIC_URL=https://pub-<hash>.r2.dev   # or your custom CDN domain
```

### 3. Database Backup
Before deploying or migrating, create an immediate backup of the production database:
```bash
mysqldump -u <user> -p <database_name> > backup_pre_portfolio_$(date +%Y%m%d_%H%M%S).sql
```

---

## 3. Deployment Instructions (Step-by-Step)

### Step 1: Upload and Extract Files
Upload `waai_media_portfolio_module_deployment.zip` to your project root on Hostinger:
```bash
unzip -o waai_media_portfolio_module_deployment.zip -d /path/to/waai-media
```
*(If you already have customizations in shared files like `routes/api.php` or `routes/console.php`, refer to Section 5 below to merge them instead of full replacement).*

### Step 2: Run Database Migrations
Execute the migrations in production:
```bash
php artisan migrate --force
```
This safely runs in exact sequence:
1. `2026_10_30_000001_create_portfolio_items_table` (Base table)
2. `2026_10_30_000002_create_portfolio_media_table` (Media table)
3. `2026_10_31_000001_add_client_and_cover_to_portfolio_items` (Client relation, cover image, generated slot key)
4. `2026_10_31_180313_add_slug_and_drop_slot_unique_on_portfolio_items` (Unique slug column, drops slot unique constraint)

### Step 3: Verify or Seed Portfolio Categories
Confirm the portfolio categories exist (6 main parent categories):
```bash
php artisan tinker --execute="echo App\Models\Category::where('type', 'portfolio')->whereNull('parent_id')->count();"
```
If output is `0`, run the seeder:
```bash
php artisan db:seed --class=CategorySeeder --force
```
*(Note: `CategorySeeder` is completely idempotent using `updateOrCreate`; it will not duplicate existing News or Blogs categories).*

### Step 4: Run Backfill Command for Slugs (If updating an existing database)
If you have existing portfolio records created prior to the slug update:
1. First, test safely in dry-run mode:
   ```bash
   php artisan portfolio:backfill-slugs --dry-run
   ```
2. Run the actual backfill:
   ```bash
   php artisan portfolio:backfill-slugs
   ```
*(This command is fully idempotent: if all rows already have a slug, it outputs `No portfolio items require a slug backfill.` and terminates with status 0).*

### Step 5: Clear and Rebuild Laravel Caches
```bash
php artisan config:clear
php artisan route:clear
php artisan route:cache
php artisan config:cache
```

### Step 6: Verify Hostinger System Crontab
Portfolio schedules an automated weekly orphaned images cleanup on Mondays at 05:00. Ensure Hostinger cron is active:
```bash
* * * * * cd /path/to/waai-media && php artisan schedule:run >> /dev/null 2>&1
```

---

## 4. Post-Deployment Verification

### 1. Verify Routes
```bash
php artisan route:list --path=portfolio
```
You should see 12 registered portfolio routes (both `api/v1/admin/portfolio` and `api/v1/portfolio`).

### 2. Verify Database Indexes
```bash
php artisan tinker --execute="print_r(collect(DB::select('SHOW INDEXES FROM portfolio_items'))->pluck('Key_name')->unique()->values()->all());"
```
Must include `portfolio_items_slug_unique` and no longer contain `uniq_portfolio_client_slot`.

### 3. Verify Orphan Cleanup Dry-Run
```bash
php artisan portfolio:clean-orphaned-images --dry-run
```
Must execute cleanly without throwing exceptions.

---

## 5. Integration Snippets (For Selective Merging)

If your production server already contains customized changes in shared files, merge the snippets below:

### A. In `routes/api.php`
```php
// Controllers:
use App\Http\Controllers\Api\Admin\PortfolioController as AdminPortfolioController;
use App\Http\Controllers\Api\PortfolioController;

// Inside Admin Route Group (auth:sanctum, require.access, role:super_admin,admin,editor):
Route::get('/portfolio/categories', [AdminPortfolioController::class, 'categories']);
Route::get('/portfolio', [AdminPortfolioController::class, 'index']);
Route::post('/portfolio', [AdminPortfolioController::class, 'store']);
Route::get('/portfolio/{portfolioItem}', [AdminPortfolioController::class, 'show'])->whereNumber('portfolioItem');
Route::match(['put', 'patch', 'post'], '/portfolio/{portfolioItem}', [AdminPortfolioController::class, 'update'])->whereNumber('portfolioItem');
Route::match(['post', 'patch'], '/portfolio/{portfolioItem}/publish', [AdminPortfolioController::class, 'publish'])->whereNumber('portfolioItem');
Route::match(['post', 'patch'], '/portfolio/{portfolioItem}/unpublish', [AdminPortfolioController::class, 'unpublish'])->whereNumber('portfolioItem');
Route::delete('/portfolio/{portfolioItem}', [AdminPortfolioController::class, 'destroy'])
    ->whereNumber('portfolioItem')
    ->middleware('role:super_admin,admin');

// Public Routes (outside admin group):
Route::get('/portfolio', [PortfolioController::class, 'index']);
Route::get('/portfolio/clients', [PortfolioController::class, 'clients']);
Route::get('/portfolio/clients/{clientId}', [PortfolioController::class, 'clientNavigation'])->whereNumber('clientId');
Route::get('/portfolio/{identifier}', [PortfolioController::class, 'show']);
```

### B. In `routes/console.php`
```php
// Weekly orphaned portfolio image cleanup
Schedule::command('portfolio:clean-orphaned-images --force --grace-hours=24 --max-deletions=50')
    ->weeklyOn(1, '05:00')
    ->withoutOverlapping(120)
    ->runInBackground();
```

### C. In `app/Services/Storage/ImageStorageService.php`
Ensure `'portfolio/images/'` is in `$allowedPrefixes`:
```php
protected array $allowedPrefixes = [
    'team/photos/',
    'clients/logos/',
    'events/covers/',
    'background-music/',
    'news/covers/',
    'news/editor/',
    'blogs/covers/',
    'blogs/editor/',
    'portfolio/images/',
];
```

---

## 6. Category Update & Media Reset Operational Rules

When updating a portfolio item's category (`PATCH /api/v1/admin/portfolio/{id}`):

1. **Hierarchy Integrity**: The `subcategory_id` must belong to the specified or existing `category_id`. Changing `category_id` without sending a valid matching `subcategory_id` returns **HTTP 422** (`subcategory_id: The selected subcategory does not belong to the selected category.`).
2. **Same Media Type**: Updating between subcategories with the same media type (e.g. `opening-image` to `branding`, both `images`) returns **HTTP 200 OK** without requiring `confirm_media_reset`. All existing images and R2 objects remain untouched.
3. **Different Media Type**:
   - Without confirmation or with `confirm_media_reset=false`: Returns **HTTP 422** with error key `confirm_media_reset`. No media is deleted.
   - With `confirm_media_reset=true`: Replaces old media with new media, cleans up unshared R2 files after the transaction commits, and returns **HTTP 200 OK**.
   - **Rollback Safety**: If an update on a published item fails publishing requirements (e.g. switching to `video_urls` without video entries), the entire transaction rolls back. All original categories, statuses, media rows, Web Design links, and R2 files are preserved.

---

## 7. Rollback Considerations & Limitations

1. **Code Rollback**:
   Restore the files from your pre-deployment backup.
2. **Database Rollback Caution**:
   - `2026_10_31_180313_add_slug_and_drop_slot_unique_on_portfolio_items` drops the `uniq_portfolio_client_slot` constraint. Rolling back will attempt to re-add this unique constraint. If users create multiple items for the same client and subcategory while running the new version, rolling back this migration will fail with a duplicate entry error unless duplicate records are deleted first.
   - Rolling back `create_portfolio_items_table` drops all portfolio data permanently. Always maintain SQL backups prior to running down migrations.

