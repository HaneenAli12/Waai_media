# Waai Media — Event Calendar: Final Pre-Deployment Comprehensive Audit Report

**Date of Execution:** 2026-10-24  
**Project Location:** `C:\Projects\Waai_media`  
**Inspected Archive:** `C:\Projects\Waai_media\event-calendar-deploy.zip`  
**Report Destination:** `scratch/event-calendar-final-predeployment-audit.md`  

---

## 1. Executive Verdict

### **Verdict: READY AFTER SPECIFIED FIXES**

### Primary Justifications:
1. **Critical Packaging Defect (Blocker for Linux Deployment):**
   - Direct binary inspection of `event-calendar-deploy.zip` confirmed that the entry names in the archive header literally contain Windows backslashes (`\`) rather than POSIX forward slashes (`/`), e.g., `app\Http\Controllers\Api\EventController.php`.
   - On Linux systems (such as Hostinger's Ubuntu environment), standard `unzip` does not treat backslashes as path delimiters by default. It extracts these as literal flat filenames in the current directory (or fails/mangles paths) rather than placing files into their respective subdirectories (`app/Http/...`).
   - **Resolution Required:** The archive must be repackaged using standard forward slashes (`/`) before extracting on Hostinger.
2. **Local Code and Schema Readiness: PASS (100%)**
   - All 15 Event Calendar source files in the local working tree strictly conform to the approved schema: plain bilingual category fields (`category_en`, `category_ar`), separate `event_date` and `event_time`, zero `EventCategory` or author references, and complete bilingual validation.
   - All 91 Event Calendar tests pass (`426 assertions`, exit code `0`).
   - All 236 multi-module regression tests pass (`1353 assertions`, exit code `0`).
   - Laravel Pint passes with 0 style violations.
3. **Route Integration: PASS (Confirmed)**
   - All 8 Event routes exist in `routes/api.php` locally and are verified with `php artisan route:list`.
   - On Hostinger production, `routes/api.php` does not yet contain these routes; an additive 17-line route block must be merged.
4. **Migration & Production Safety: PASS (Ready for Targeted Execution)**
   - Migration `2026_10_24_000001_create_events_table.php` is non-destructive, isolated, and self-contained.

---

## 2. Verified Current Project State

- **PHP Version:** PHP 8.5.11 (configured & running locally; production Hostinger CLI confirmed PHP 8.5.11).
- **Framework Version:** Laravel 13.35.0.
- **Git Commit State:** HEAD at `d131060 v2 auth`. Working tree contains staged and unstaged enhancements for Event Calendar, Categories, Counters, News, and Auth.
- **Shared Architecture:**
  - `App\Services\Storage\ImageStorageService` handles Cloudflare R2 uploads and deletions (`storeImage`, `deleteImage`).
  - Middleware aliases defined in `bootstrap/app.php`:
    - `role` => `App\Http\Middleware\AuthorizeRole::class`
    - `editor.no_delete` => `App\Http\Middleware\EditorCannotDelete::class`
    - `require.access` => `App\Http\Middleware\RequireAccessTokenAbility::class`
- **Database Status:**
  - Local database contains `events` and legacy tables.
  - Production database on Hostinger previously verified clean (no `events` table, no `event_categories` table, no Event migrations recorded).

---

## 3. Complete Inventory of the 15 Intended Files

All 15 target files exist locally, in the working tree, and in the archive:

| # | Relative Project Path | File Size | SHA-256 Hash |
|---|----------------------|-----------|--------------|
| 1 | `app/Http/Controllers/Api/EventController.php` | 1,953 B | `C4270B44363350A718D83D586685518CDF66756142A26ECCCE7110C06EEF2056` |
| 2 | `app/Http/Controllers/Api/Admin/EventController.php` | 8,519 B | `30AE4C188BD4654CBAF9E04F19FE8B293AD2976F8B0A8F399799701046004031` |
| 3 | `app/Http/Requests/Admin/Events/IndexEventRequest.php` | 1,659 B | `FEFEE48C9A2899E8BD813C874C04CECF27E27FFC66D38C464EFD0A2821F9E882` |
| 4 | `app/Http/Requests/Admin/Events/StoreEventRequest.php` | 4,119 B | `A264EBAB2EC0EF381B1E2F2E5990BD2BEF4EC1C97E71AE9C5ACEF572609E916A` |
| 5 | `app/Http/Requests/Admin/Events/UpdateEventRequest.php` | 4,450 B | `9BF81108296C2134E57DE264CDB3530F468DBEDEA008A64CA40E17BF99022F19` |
| 6 | `app/Http/Requests/Admin/Events/PublishEventRequest.php` | 6,955 B | `799B09B1F8A319604E4AD133FDEE8AEDCD587181D4518A94D80C4C543391DBD6` |
| 7 | `app/Http/Requests/Events/IndexPublicEventRequest.php` | 1,192 B | `BAED088F5DF7597624354C84662EFB90AA617ED6207B423991AB69F11F5FDDC7` |
| 8 | `app/Http/Resources/EventAdminResource.php` | 1,553 B | `086F970E3CDCA1562508D7E6F0A02B3CF378015BBDE6980FD432C7F6AF7D92AF` |
| 9 | `app/Http/Resources/EventPublicResource.php` | 2,029 B | `542597E649C6B9626B6C9A0D9DB63A06A3BB850C7DA6349C69FD2D85014150AD` |
| 10 | `app/Models/Event.php` | 4,627 B | `EB039103EBD7D58C3C350CBD0FF72AA161A3AE182574D706BD85FA524E983135` |
| 11 | `app/Services/Events/EventCoverImageService.php` | 3,583 B | `BD8975B52D709221A97F5D7D1A7A85128C5EF9F31CDFB2A34E01E3A5799B7554` |
| 12 | `app/Services/Events/EventDateTimeService.php` | 5,343 B | `7431D447D1954E9BFB17EB89C18BED444C80D3F9E178D87DEB906CB018363BC1` |
| 13 | `app/Services/Events/EventSlugService.php` | 2,848 B | `61ED8CDBB03F9A6DC18086F720A49AFB95930BF8F4055EC413D154286F841214` |
| 14 | `database/factories/EventFactory.php` | 4,548 B | `3CC8010460A37011EFA00D6640663EEA4E8506F234B3C6C5CB05080E83B8648C` |
| 15 | `database/migrations/2026_10_24_000001_create_events_table.php` | 1,772 B | `4717475B37C231BA8590AFD413988B14AC88BFA637770C07DA8223A88A6692E2` |

---

## 4. ZIP Path-Format Finding & Evidence

### Concrete Evidence:
Inspection of `event-calendar-deploy.zip` using .NET `System.IO.Compression.ZipFile`:
```text
Entry: app\Http\Controllers\Api\EventController.php
HasBackslash: True
HasForwardSlash: False
```
- **Total Entries:** 20 (5 Directory entries with trailing `\`, 15 File entries with `\`).
- **Archive Size:** 19,865 bytes.
- **Archive SHA-256:** `3F70411CF0B7EBE87C665D5384163E3F4B16A841D83BC382439D637064AEE1A9`.
- **Finding:** The ZIP file was created on Windows using an archiver that stored raw Windows backslash characters (`\`) in the entry header strings.
- **Risk Severity:** **HIGH (Extraction Failure on Linux)**. When unzipped with standard Linux `unzip -o event-calendar-deploy.zip` on Hostinger, files will not be placed into nested subdirectories unless `unzip` specifically rewrites backslashes (which is non-standard across Linux distributions).
- **Required Action:** Re-create the deployment ZIP using forward slashes (`/`) so that paths unpack natively on Linux.

---

## 5. Per-File Findings & Code Quality Audit

1. **`app/Http/Controllers/Api/EventController.php` (Public):**
   - Handles `index()` and `show($slug)`.
   - Strictly queries `Event::query()->published()`. Unpublished drafts return `404 Not Found`.
   - Bilingual localization support via `App\Support\LocaleNegotiator` and `Accept-Language` headers.
   - Resource: `EventPublicResource`.
2. **`app/Http/Controllers/Api/Admin/EventController.php` (Admin):**
   - Handles `index`, `store`, `show`, `update`, `publish`, `destroy`.
   - Explicit transactions (`DB::transaction`) wrap `store`, `update`, `publish`, and `destroy`.
   - Cover image lifecycle properly handled: if image replacement fails, old image is preserved; if upload succeeds, old image is cleaned up.
   - Resource: `EventAdminResource`.
3. **Form Requests (`StoreEventRequest`, `UpdateEventRequest`, `PublishEventRequest`, `IndexEventRequest`, `IndexPublicEventRequest`):**
   - `PublishEventRequest` enforces strict requirements before publishing: `name_en`, `name_ar`, `slug`, `category_en`, `category_ar`, `description_en`, `description_ar`, `place_en`, `place_ar`, `event_date`, and `cover_image_url` must all be non-empty.
   - `StoreEventRequest` and `UpdateEventRequest` permit draft saving with partial fields.
   - Validation ensures `event_date` is `Y-m-d` and `event_time` is `H:i` or `H:i:s`.
   - URLs validated with strict regex (`^https?:\/\/`).
4. **Resources (`EventAdminResource`, `EventPublicResource`):**
   - `EventAdminResource` outputs all internal administrative fields, dates, timestamps, and separate bilingual attributes.
   - `EventPublicResource` outputs localized `name`, `category`, `description`, `place` alongside raw bilingual values, `event_date`, `event_time`, `cover_image_url`, `url`, and `published_at`.
5. **Model (`app/Models/Event.php`):**
   - Table: `events`.
   - Fillable attributes: `name_en`, `name_ar`, `slug`, `category_en`, `category_ar`, `description_en`, `description_ar`, `place_en`, `place_ar`, `event_date`, `event_time`, `cover_image_url`, `url`, `status`, `published_at`.
   - Casts: `event_date => date:Y-m-d`, `published_at => datetime`.
   - Zero relationships to categories. Zero author attributes.
   - Verified scopes: `scopePublished`, `scopeDraft`, `scopeUpcoming`, `scopePast`, `scopeTimeline`, `scopeFilterAdmin`, `scopeFilterPublic`.
6. **Services (`EventCoverImageService`, `EventDateTimeService`, `EventSlugService`):**
   - `EventSlugService`: Generates URL-friendly slugs using `Str::slug` with collision resolution (`$baseSlug . '-' . $count`), excluding current ID during updates.
   - `EventDateTimeService`: Validates format separation and computes timeline flags without mutating underlying DB strings.
   - `EventCoverImageService`: Interfaces cleanly with `App\Services\Storage\ImageStorageService`.
7. **Factory (`database/factories/EventFactory.php`):**
   - States: `draft()`, `published()`, `upcoming()`, `past()`, `withoutTime()`, `withUrl()`.
   - Generates realistic bilingual mock data without external category tables.

---

## 6. Schema and Migration Findings

**Target Migration File:** `database/migrations/2026_10_24_000001_create_events_table.php` (SHA-256: `4717475B37C231BA8590AFD413988B14AC88BFA637770C07DA8223A88A6692E2`)

### Schema Specification:
- `id` (bigint unsigned auto-increment)
- `name_en` (varchar 255, nullable)
- `name_ar` (varchar 255, nullable)
- `slug` (varchar 255, nullable, unique)
- `category_en` (varchar 150, nullable)
- `category_ar` (varchar 150, nullable)
- `description_en` (text, nullable)
- `description_ar` (text, nullable)
- `place_en` (varchar 255, nullable)
- `place_ar` (varchar 255, nullable)
- `event_date` (date, nullable)
- `event_time` (time, nullable)
- `cover_image_url` (varchar 500, nullable)
- `url` (varchar 500, nullable)
- `status` (enum `draft`, `published`, default `draft`, indexed)
- `published_at` (timestamp, nullable, indexed)
- `created_at`, `updated_at` (timestamps)

### Indexes:
- `slug` (UNIQUE)
- `idx_events_timeline`: `['status', 'event_date', 'event_time']`
- `idx_events_category_en`: `['category_en']`
- `idx_events_category_ar`: `['category_ar']`
- `idx_events_created_id`: `['created_at', 'id']`

### Schema Compliance Verdict:
- **100% compliant** with the approved Event Calendar requirements.
- **Zero foreign keys** to categories or authors.
- **Self-contained** and completely independent of other modules.

---

## 7. Routes and Authorization Findings

### Route Definitions:
All 8 endpoints are declared and functioning in the local working tree:
```text
Public:
GET    /api/v1/events ................... Api\EventController@index
GET    /api/v1/events/{slug} ............ Api\EventController@show

Admin:
GET    /api/v1/admin/events ............. Api\Admin\EventController@index
POST   /api/v1/admin/events ............. Api\Admin\EventController@store
GET    /api/v1/admin/events/{event} ..... Api\Admin\EventController@show
PUT    /api/v1/admin/events/{event} ..... Api\Admin\EventController@update
PATCH  /api/v1/admin/events/{event} ..... Api\Admin\EventController@update
POST   /api/v1/admin/events/{event}/publish Api\Admin\EventController@publish
PATCH  /api/v1/admin/events/{event}/publish Api\Admin\EventController@publish
DELETE /api/v1/admin/events/{event} ..... Api\Admin\EventController@destroy
```

### Authorization Rules:
1. Public routes require no authentication and return only published events.
2. Admin routes are guarded by `auth:sanctum`, `require.access`, and `role:admin,editor`.
3. `DELETE /api/v1/admin/events/{event}` has explicit additional middleware: `role:admin`. Editors receive `403 Forbidden` if they attempt deletion.

### Minimal Additive Route Block (for Hostinger `routes/api.php`):
```php
// In routes/api.php:

// Add to imports at top:
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\Admin\EventController as AdminEventController;

// Inside Route::prefix('admin')->middleware(['auth:sanctum', 'require.access', 'role:admin,editor'])->group(function () { ...
    // Calendar Events Module Admin Endpoints
    Route::get('/events', [AdminEventController::class, 'index']);
    Route::post('/events', [AdminEventController::class, 'store']);
    Route::get('/events/{event}', [AdminEventController::class, 'show']);
    Route::match(['put', 'patch'], '/events/{event}', [AdminEventController::class, 'update']);
    Route::match(['post', 'patch'], '/events/{event}/publish', [AdminEventController::class, 'publish']);
    Route::delete('/events/{event}', [AdminEventController::class, 'destroy'])
        ->middleware('role:admin');

// In public routes section:
// Calendar Events Module Public Endpoints
Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{slug}', [EventController::class, 'show']);
```

---

## 8. Image Storage & Failure Handling

- **Shared Service:** `App\Services\Storage\ImageStorageService`.
- **Validation:** Uploaded files validated as image files (`jpeg,png,webp,gif,avif`), max size 5MB (5120KB).
- **Target Folder:** `events/covers`.
- **Persistence:** Full public URL stored in `cover_image_url`.
- **Transactional Safety:**
  - If database save fails, uploaded image is immediately deleted to prevent orphaned files in Cloudflare R2.
  - When updating cover image, the old image is deleted only after the new image is safely persisted.
  - Image cleanup errors are caught and logged so that database records remain consistent.

---

## 9. API Behavior and Security Findings

- **SQL Injection:** Safe. All queries use Eloquent ORM parameterized statements.
- **Mass Assignment:** Protected. `$fillable` array explicitly defines all allowed fields; ID and timestamps cannot be injected.
- **N+1 Prevention:** No related models are loaded (categories are direct fields), resulting in single-query reads.
- **Input Sanitization:** URL schemes restricted to `http` or `https`. Texts and slugs are validated.
- **Publish Status Consistency:** Publishing via `PublishEventRequest` guarantees that all required bilingual and timeline attributes are non-null and valid.

---

## 10. Test Results and Coverage

### Fresh Test Execution Results (Executed 2026-10-24 19:35):
1. **Event Calendar Suite:**
   - Command: `vendor/bin/pest tests/Unit/Events tests/Unit/EventModelTest.php tests/Feature/Events`
   - Result: **91 passed, 426 assertions** (Exit Code: `0`).
2. **Multi-Module Regression Suite:**
   - Command: `vendor/bin/pest tests/Feature/News tests/Unit/News tests/Feature/Auth tests/Feature/AppLinksApiTest.php tests/Feature/ContactInfoApiTest.php tests/Feature/CounterApiTest.php tests/Feature/CategoryApiTest.php`
   - Result: **236 passed, 1353 assertions** (Exit Code: `0`).
3. **Pint Code Style Check:**
   - Command: `vendor/bin/pint --dirty --test`
   - Result: **Passed (0 violations)** (Exit Code: `0`).

---

## 11. Production Compatibility Risks & Summary of Issues

| Issue ID | Severity | File / Component | Description | Recommended Resolution |
|----------|----------|------------------|-------------|------------------------|
| **ISSUE-01** | **HIGH** | `event-calendar-deploy.zip` | Archive header entry names contain Windows backslashes (`\`) instead of standard forward slashes (`/`), risking extraction failure on Linux. | Generate a clean ZIP with forward slashes (`/`) using POSIX entry naming. |
| **ISSUE-02** | **MEDIUM** | `routes/api.php` on Production | Production server does not yet have Event routes registered. | Append the minimal 17-line additive route block to Hostinger `routes/api.php`. |
| **ISSUE-03** | **MEDIUM** | Production Database | Table `events` does not yet exist on Hostinger. | Execute targeted migration: `php artisan migrate --path=database/migrations/2026_10_24_000001_create_events_table.php`. |

---

## 12. Final Deployment Sequence

Follow this exact sequence on Hostinger:

1. **Step 1: Repackage with Forward Slashes (Local):**
   - Repackage the 15 verified files using forward slashes (`/`) to ensure Linux compatibility.
2. **Step 2: Pre-Deployment Backup (Hostinger):**
   ```bash
   mysqldump -u <db_user> -p <db_name> > backup_pre_event_$(date +%Y%m%d_%H%M%S).sql
   tar -czf backup_app_pre_event_$(date +%Y%m%d_%H%M%S).tar.gz app/ routes/ database/
   ```
3. **Step 3: Upload & Verify SHA-256:**
   - Upload the forward-slash ZIP to `~/waai_media_api/`.
   - Verify SHA-256 matches expected checksum.
4. **Step 4: Extract Archive:**
   ```bash
   unzip -o event-calendar-deploy.zip
   ```
5. **Step 5: Merge Routes in `routes/api.php`:**
   - Add the minimal additive Event routes to `routes/api.php`.
   - Verify with: `php artisan route:list --path=events`.
6. **Step 6: Execute Targeted Migration ONLY:**
   ```bash
   php artisan migrate --path=database/migrations/2026_10_24_000001_create_events_table.php
   ```
7. **Step 7: Cache Clear & Smoke Test:**
   ```bash
   php artisan config:clear && php artisan route:clear && php artisan cache:clear
   curl -s -X GET "https://<api_domain>/api/v1/events" -H "Accept: application/json"
   ```

---

## 13. Explicit Prohibited Operations

1. **DO NOT** run global `php artisan migrate` without `--path`.
2. **DO NOT** run `php artisan migrate:fresh`, `migrate:refresh`, or `db:wipe`.
3. **DO NOT** replace the entire production `routes/api.php` file with a local file.
4. **DO NOT** delete or alter the shared `categories` table or `ImageStorageService`.
5. **DO NOT** modify `.env` or production credentials.
