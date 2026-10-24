# Event Calendar Pre-Deployment Audit Report

**Date of Execution:** 2026-10-24  
**Project:** `C:\Projects\Waai_media`  
**Target Package:** `C:\Projects\Waai_media\event-calendar-deploy.zip`  
**Auditor:** Automated Assistant (Pre-Deployment Safety Guard)

---

## 1. Executive Verdict

### **Verdict: PASS WITH BLOCKERS**

- **Local Readiness:** **PASS (100%)**  
  - The deployment ZIP archive `event-calendar-deploy.zip` is valid, free of malformed/traversal paths, free of forbidden keywords/fields, and 100% byte-for-byte identical (SHA-256) to the active local working tree.
  - All 8 Event Calendar API routes are defined, protected, and verified using `php artisan route:list`.
  - All 15 files and their shared service dependencies are clean, tested, and passing (91 unit/feature tests for Events, 246 regression tests for other modules, 0 Pint formatting violations).
  - The migration `database/migrations/2026_10_24_000001_create_events_table.php` is non-destructive, isolates the `events` table, and cleanly defines `event_date` (`DATE`) and `event_time` (`TIME`).
- **Production Blockers:** **BLOCKERS OUTSTANDING**  
  - By safety policy, this audit did not connect to the Hostinger production server (`~/waai_media_api`).
  - Production pre-flight verification (checking that `events` does not already exist, inspecting `migrate:status`, verifying R2 environment configuration, and validating the uploaded archive checksum) remains required before executing the migration.

---

## 2. Deployment ZIP Archive Inspection (Step 1)

### Archive Details
- **Path:** `C:\Projects\Waai_media\event-calendar-deploy.zip`
- **File Size:** `19,865` bytes (19.40 KB)
- **SHA-256 Hash:** `3F70411CF0B7EBE87C665D5384163E3F4B16A841D83BC382439D637064AEE1A9`
- **Total Entries:** 20 (5 Directory entries, 15 File entries)

### Entry Manifest
| # | Entry Relative Path | Type | Compressed Size | Uncompressed Size |
|---|---------------------|------|-----------------|-------------------|
| 1 | `app/Http/Controllers/Api/` | Directory | 0 B | 0 B |
| 2 | `app/Http/Controllers/Api/Admin/` | Directory | 0 B | 0 B |
| 3 | `app/Http/Requests/Admin/Events/` | Directory | 0 B | 0 B |
| 4 | `app/Http/Requests/Events/` | Directory | 0 B | 0 B |
| 5 | `app/Services/Events/` | Directory | 0 B | 0 B |
| 6 | `app/Http/Controllers/Api/EventController.php` | File | 1,189 B | 3,111 B |
| 7 | `app/Http/Controllers/Api/Admin/EventController.php` | File | 1,940 B | 6,942 B |
| 8 | `app/Http/Requests/Admin/Events/IndexEventRequest.php` | File | 946 B | 2,752 B |
| 9 | `app/Http/Requests/Admin/Events/StoreEventRequest.php` | File | 1,328 B | 4,213 B |
| 10 | `app/Http/Requests/Admin/Events/UpdateEventRequest.php` | File | 1,440 B | 4,775 B |
| 11 | `app/Http/Requests/Admin/Events/PublishEventRequest.php` | File | 902 B | 2,492 B |
| 12 | `app/Http/Requests/Events/IndexPublicEventRequest.php` | File | 913 B | 2,563 B |
| 13 | `app/Http/Resources/EventAdminResource.php` | File | 884 B | 2,423 B |
| 14 | `app/Http/Resources/EventPublicResource.php` | File | 863 B | 2,298 B |
| 15 | `app/Models/Event.php` | File | 1,749 B | 5,876 B |
| 16 | `app/Services/Events/EventCoverImageService.php` | File | 1,514 B | 4,499 B |
| 17 | `app/Services/Events/EventDateTimeService.php` | File | 967 B | 2,593 B |
| 18 | `app/Services/Events/EventSlugService.php` | File | 818 B | 1,939 B |
| 19 | `database/factories/EventFactory.php` | File | 1,146 B | 3,169 B |
| 20 | `database/migrations/2026_10_24_000001_create_events_table.php` | File | 1,029 B | 3,356 B |

### Archive Structure & Safety Checks
- **Path Traversal (`..`):** None detected (0 instances).
- **Absolute Paths (`C:\`, `/`):** None detected. All paths are strictly relative to project root.
- **Unwanted Files:**
  - `.env` / credentials / secrets: **NONE**
  - `vendor/`: **NONE**
  - `.git/`: **NONE**
  - `scratch/`: **NONE**
  - `event-module-staging/`: **NONE**
  - Old ZIP files / backup copies: **NONE**
  - Obsolete category/author migrations: **NONE**
- **Extraction Behavior:** Extracting this archive directly in the Laravel project root (`/`) will cleanly deposit files into their proper respective directories without root-level nesting artifacts.

### Comparison with Local Working Tree (SHA-256)
Every single file from the ZIP archive was extracted and hashed, then compared against the actual file in `C:\Projects\Waai_media`:

| File Path | ZIP Content SHA-256 | Local Working Tree SHA-256 | Match |
|-----------|---------------------|----------------------------|-------|
| `app/Http/Controllers/Api/EventController.php` | `903E7016259C3946F19A2132C7CD4A8BAAE869B9AC959E7B35FA30182C0E9BD8` | `903E7016259C3946F19A2132C7CD4A8BAAE869B9AC959E7B35FA30182C0E9BD8` | **MATCH** |
| `app/Http/Controllers/Api/Admin/EventController.php` | `4859EB01F29D453673B8A61159C663FFB3DF3B5E0DE5645B25FE74665BAA1916` | `4859EB01F29D453673B8A61159C663FFB3DF3B5E0DE5645B25FE74665BAA1916` | **MATCH** |
| `app/Http/Requests/Admin/Events/IndexEventRequest.php` | `406E9804EE1F58EFD27F5987171EBD2CDA3A5B89953BD8693A3BF09A67CBE8DA` | `406E9804EE1F58EFD27F5987171EBD2CDA3A5B89953BD8693A3BF09A67CBE8DA` | **MATCH** |
| `app/Http/Requests/Admin/Events/StoreEventRequest.php` | `40D4A098FA95400D2DDA8CD312A34A6A875D4919FD52A183759C48721FD9A7D0` | `40D4A098FA95400D2DDA8CD312A34A6A875D4919FD52A183759C48721FD9A7D0` | **MATCH** |
| `app/Http/Requests/Admin/Events/UpdateEventRequest.php` | `672C1517CC896645398B8ACD2F0AF5EBC909F1B2521BA9935E18671BF37ACF41` | `672C1517CC896645398B8ACD2F0AF5EBC909F1B2521BA9935E18671BF37ACF41` | **MATCH** |
| `app/Http/Requests/Admin/Events/PublishEventRequest.php` | `D185449A588B9AC258C5D30F8747FF0AC492A0CD99BF217BA1B0434460CF7B6A` | `D185449A588B9AC258C5D30F8747FF0AC492A0CD99BF217BA1B0434460CF7B6A` | **MATCH** |
| `app/Http/Requests/Events/IndexPublicEventRequest.php` | `0C508A6DE37315E397F37FF6BE4DCC60787A9A16E87DBC6EE27E01A4A0BBEFE2` | `0C508A6DE37315E397F37FF6BE4DCC60787A9A16E87DBC6EE27E01A4A0BBEFE2` | **MATCH** |
| `app/Http/Resources/EventAdminResource.php` | `388F110EFBFFBC1492576F0E0D10D09F172283A4EB4251BFA45C890069EC289E` | `388F110EFBFFBC1492576F0E0D10D09F172283A4EB4251BFA45C890069EC289E` | **MATCH** |
| `app/Http/Resources/EventPublicResource.php` | `090C5AC57E2F13C4D32B818BA2493392EFBD7C2ED89D910CC61A3416F6D9BFBF` | `090C5AC57E2F13C4D32B818BA2493392EFBD7C2ED89D910CC61A3416F6D9BFBF` | **MATCH** |
| `app/Models/Event.php` | `4A9F21532057F7B01DE5F53075F38EBC57E56F14CE14DAEC3B0904CD12E07CE0` | `4A9F21532057F7B01DE5F53075F38EBC57E56F14CE14DAEC3B0904CD12E07CE0` | **MATCH** |
| `app/Services/Events/EventCoverImageService.php` | `CE510CE5EFA4F469CF7F6DE68BF32609A0A8CF5635848ACDF2AFEB3BA64A4901` | `CE510CE5EFA4F469CF7F6DE68BF32609A0A8CF5635848ACDF2AFEB3BA64A4901` | **MATCH** |
| `app/Services/Events/EventDateTimeService.php` | `378F7DA164D88EB48777937402F0B9C6FF7B519C6F5BFBAF36CD8B3BD540EFB9` | `378F7DA164D88EB48777937402F0B9C6FF7B519C6F5BFBAF36CD8B3BD540EFB9` | **MATCH** |
| `app/Services/Events/EventSlugService.php` | `88A8F243E1EDBA5FA9D507AE36C0A985172C9B76A0356EE9431D9B0A3561A975` | `88A8F243E1EDBA5FA9D507AE36C0A985172C9B76A0356EE9431D9B0A3561A975` | **MATCH** |
| `database/factories/EventFactory.php` | `6256976A08D80F16C97F37F99602C823DFCE87483BA078A1ED967A615822394A` | `6256976A08D80F16C97F37F99602C823DFCE87483BA078A1ED967A615822394A` | **MATCH** |
| `database/migrations/2026_10_24_000001_create_events_table.php` | `DDCB8ECB3B59D8CF7A4BD24016559C99A4181B5780D3BFB77B70BEB4B5A5237C` | `DDCB8ECB3B59D8CF7A4BD24016559C99A4181B5780D3BFB77B70BEB4B5A5237C` | **MATCH** |

### Forbidden Keywords Inspection
A regular expression search for `author_name`, `author_name_en`, `author_name_ar`, `journalist_name`, `EventCategory`, `event_category_id`, and `event_categories` was executed across all 15 extracted files.
- **Result:** **0 matches detected.** All deprecated categories, relationships, and author attributes have been purged.

---

## 3. Routes, Dependencies & Shared Services (Step 2)

### Route Definitions & Inspection
All 8 Event Calendar routes are declared in `routes/api.php` under the `v1` prefix. Verification via `php artisan route:list` confirmed:

```text
GET|HEAD   api/v1/events ............................... events.index › Api\EventController@index
GET|HEAD   api/v1/events/{slug} ........................ events.show › Api\EventController@show
GET|HEAD   api/v1/admin/events ................... admin.events.index › Api\Admin\EventController@index
POST       api/v1/admin/events ................... admin.events.store › Api\Admin\EventController@store
GET|HEAD   api/v1/admin/events/{event} ............ admin.events.show › Api\Admin\EventController@show
PUT|PATCH  api/v1/admin/events/{event} ......... admin.events.update › Api\Admin\EventController@update
POST|PATCH api/v1/admin/events/{event}/publish . admin.events.publish › Api\Admin\EventController@publish
DELETE     api/v1/admin/events/{event} ........ admin.events.destroy › Api\Admin\EventController@destroy
```

#### Middleware & Authorization Rules
1. **Public Endpoints (`events.index`, `events.show`):**
   - Middleware: `api`
   - Scope: Strictly returns published events (`is_published = true`). Model binding on `show` resolves via `$slug` matching `is_published = 1`.
2. **Admin Read/Write Endpoints (`index`, `store`, `show`, `update`, `publish`):**
   - Middleware: `auth:sanctum`, `active.user`, `role:admin,editor`
   - Scope: Allows full administrative access to both drafts and published events.
3. **Admin Delete Endpoint (`destroy`):**
   - Middleware: `auth:sanctum`, `active.user`, `role:admin`
   - Scope: Editors receive `403 Forbidden`. Only users with the `admin` role can delete events.

#### Minimal Route Block for Production (if not already present)
```php
// Public Event Routes
Route::prefix('events')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\EventController::class, 'index'])->name('events.index');
    Route::get('/{slug}', [\App\Http\Controllers\Api\EventController::class, 'show'])->name('events.show');
});

// Admin Event Routes
Route::prefix('admin/events')->middleware(['auth:sanctum', 'active.user'])->group(function () {
    Route::middleware('role:admin,editor')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Admin\EventController::class, 'index'])->name('admin.events.index');
        Route::post('/', [\App\Http\Controllers\Api\Admin\EventController::class, 'store'])->name('admin.events.store');
        Route::get('/{event}', [\App\Http\Controllers\Api\Admin\EventController::class, 'show'])->name('admin.events.show');
        Route::match(['put', 'patch'], '/{event}', [\App\Http\Controllers\Api\Admin\EventController::class, 'update'])->name('admin.events.update');
        Route::match(['post', 'patch'], '/{event}/publish', [\App\Http\Controllers\Api\Admin\EventController::class, 'publish'])->name('admin.events.publish');
    });

    Route::delete('/{event}', [\App\Http\Controllers\Api\Admin\EventController::class, 'destroy'])
        ->middleware('role:admin')
        ->name('admin.events.destroy');
});
```

### Dependencies & Shared Services
- **Storage Subsystem:**
  - `EventCoverImageService` delegates to `App\Services\Storage\ImageStorageService`.
  - Methods utilized: `storeImage($file, 'events/covers')` and `deleteImage($path)`.
  - Isolated file deletion: `deleteImage` only accepts non-empty string paths belonging to the event record. It does not touch News images or global buckets.
- **Model Binding & Scopes:**
  - Admin controller methods use explicit Route Model Binding (`Event $event`), resolving via primary key `id`.
  - Public `show($slug)` receives the string slug directly and performs `Event::published()->where('slug', $slug)->firstOrFail()`.
  - Scopes verified in `App\Models\Event`: `scopePublished`, `scopeUpcoming`, `scopePast`, `scopeTimeline`, `scopeFilterAdmin`, `scopeFilterPublic`.
- **Date & Time Independence:**
  - Date and time are stored in separate DB columns: `event_date` (`DATE`) and `event_time` (`TIME`, nullable).
  - Validation ensures `event_date` is `Y-m-d` and `event_time` is `H:i` or `H:i:s`.

---

## 4. Test Execution & Code Quality Results

Fresh test executions were performed during this audit:

### A. Event Calendar Specific Suite
```bash
vendor/bin/pest tests/Unit/Events tests/Unit/EventModelTest.php tests/Feature/Events
```
- **Exit Code:** `0`
- **Result:** **91 tests passed**, **426 assertions** (Duration: 5.61s)
- **Coverage:** Unit tests for Slug/DateTime/Image services, EventModel cast & scope tests, Admin CRUD/Publishing/Deletion tests, Public listing/filtering/localization tests.

### B. Core Regression Suite (Unrelated Modules)
```bash
vendor/bin/pest tests/Feature/News tests/Unit/News tests/Feature/Auth tests/Feature/AppLinks tests/Feature/ContactInfo tests/Feature/Counters tests/Feature/Categories
```
- **Exit Code:** `0`
- **Result:** **246 tests passed**, **1465 assertions** (Duration: 13.92s)
- **Outcome:** Zero regressions in News, Categories, Authentication, App Links, Contact Info, or Counters.

### C. Code Formatting (Laravel Pint)
```bash
vendor/bin/pint --dirty --test
```
- **Exit Code:** `0`
- **Result:** **PASS**. All modified and active Event Calendar files adhere to project coding standards.

---

## 5. Migration & Production Safety Review (Step 3)

### Migration Analysis: `2026_10_24_000001_create_events_table.php`
- **Type:** Table creation only (`Schema::create('events', ...)`).
- **Target Table:** `events`
- **Foreign Keys / Cascading Operations:**
  - `created_by` -> references `id` on `users` (`onDelete('set null')`, nullable).
  - `updated_by` -> references `id` on `users` (`onDelete('set null')`, nullable).
- **Schema Columns:**
  - `id` (BigIncrements)
  - `title_en`, `title_ar` (String)
  - `slug` (String, Unique)
  - `description_en`, `description_ar` (Text, Nullable)
  - `event_date` (`date`)
  - `event_time` (`time`, Nullable)
  - `location_en`, `location_ar` (String, Nullable)
  - `organizer_en`, `organizer_ar` (String, Nullable)
  - `category_en`, `category_ar` (String, Nullable)
  - `cover_image` (String, Nullable)
  - `is_published` (Boolean, Default `false`)
  - `published_at` (Timestamp, Nullable)
  - `created_by`, `updated_by` (UnsignedBigInteger, Nullable)
  - `created_at`, `updated_at` (Timestamps)
- **Indexes:**
  - Unique: `slug`
  - Composite Indexes:
    - `idx_events_timeline`: `['is_published', 'event_date', 'event_time']`
    - `idx_events_category_en`: `['category_en']`
    - `idx_events_category_ar`: `['category_ar']`
    - `idx_events_created_id`: `['created_at', 'id']`
- **Safety Assessment:**
  - Non-destructive: Does not alter or drop any existing tables.
  - Independent: Does not depend on obsolete `event_categories` table.

### Local Migration Status
Inspection of the local database:
- Table `events` exists locally.
- In `migrations` table, legacy batch records for previous development attempts exist (`2026_10_24_000001_create_events_table`, `...000002_add_author_to_events_table`, `...000003_create_event_categories_table`, `...000004_add_category_id_to_events_table`).
- **Production Status:** Production database was previously cleaned; neither `events` nor `event_categories` exists on Hostinger.

---

## 6. Outstanding Production Blockers & Staged Deployment Plan

Because production was not accessed during this local audit, the following pre-flight verifications must be performed on the server prior to running any Artisan commands.

### Priority Blockers Checklist
1. **[CRITICAL] Server Schema Inspection:**
   - Confirm via MySQL on production that `events` does **not** already exist.
   - Confirm that no legacy `event_categories` table exists.
2. **[CRITICAL] Migration Status Inspection:**
   - Execute `php artisan migrate:status` on Hostinger.
   - Confirm that `2026_10_24_000001_create_events_table` is listed as `Pending` (`Ran? No`).
   - If old migration records (`...000002`, `...000003`, `...000004`) linger in production's `migrations` table, delete those specific rows before migrating.
3. **[CRITICAL] ZIP Checksum Verification:**
   - Upload `event-calendar-deploy.zip` to `~/waai_media_api/`.
   - Verify SHA-256 matches: `3F70411CF0B7EBE87C665D5384163E3F4B16A841D83BC382439D637064AEE1A9`.
4. **[CRITICAL] Cloudflare R2 Credentials:**
   - Verify `.env` on production has valid `CLOUDFLARE_R2_*` keys so event cover image uploads function immediately.

---

## 7. Recommended Safe Deployment Sequence

Execute the following steps sequentially in `~/waai_media_api` on Hostinger:

### Step 1: Pre-Deployment Backup
```bash
# 1. Database backup
mysqldump -u <db_user> -p <db_name> > backup_pre_event_calendar_$(date +%Y%m%d_%H%M%S).sql

# 2. Application files backup
tar -czf backup_app_pre_event_$(date +%Y%m%d_%H%M%S).tar.gz app/ routes/ database/
```

### Step 2: Upload and Checksum Verification
```bash
# Upload event-calendar-deploy.zip to ~/waai_media_api/
sha256sum event-calendar-deploy.zip
# Must output: 3f70411cf0b7ebe87c665d5384163e3f4b16a841d83bc382439d637064aee1a9
```

### Step 3: Extract Package
```bash
unzip -o event-calendar-deploy.zip
```

### Step 4: Verify Routes in `routes/api.php`
- Check if the 8 Event Calendar routes are present in production `routes/api.php`.
- If missing, append the minimal route block provided in Section 3 of this report.
- Verify with: `php artisan route:list --path=events`

### Step 5: Execute Targeted Migration ONLY
Do NOT run global `php artisan migrate`. Execute only the specific migration file:
```bash
php artisan migrate --path=database/migrations/2026_10_24_000001_create_events_table.php
```

### Step 6: Post-Migration Verification & Smoke Test
```bash
# Verify table creation
php artisan migrate:status | grep events

# Clear caches
php artisan config:clear
php artisan route:clear
php artisan cache:clear

# Non-destructive smoke test
curl -s -X GET "https://<api_domain>/api/v1/events" -H "Accept: application/json"
```

### Step 7: Rollback / Disaster Recovery Plan
If unexpected issues arise during deployment:
1. **If table has no data:**
   ```bash
   php artisan migrate:rollback --path=database/migrations/2026_10_24_000001_create_events_table.php
   ```
2. **If table was created but code fails:**
   - Revert application files from `backup_app_pre_event_*.tar.gz`.
   - Restore database from `backup_pre_event_calendar_*.sql` if any schema corruption occurred.
