# Hostinger Pre-Deployment Readiness Check — Event Calendar

**Document Purpose:** Staged read-only inspection protocol to be executed manually on the Hostinger production server via SSH prior to uploading or migrating the Event Calendar package.  
**Target Package:** `event-calendar-deploy.zip`  
**Expected Package SHA-256:** `3F70411CF0B7EBE87C665D5384163E3F4B16A841D83BC382439D637064AEE1A9`  
**Target Migration:** `database/migrations/2026_10_24_000001_create_events_table.php`  
**Target Migration SHA-256:** `4717475B37C231BA8590AFD413988B14AC88BFA637770C07DA8223A88A6692E2`  

---

## 1. Safety Rules for SSH Execution

- All commands provided in this script are strictly **READ-ONLY**.
- **DO NOT** execute `php artisan migrate`, `db:wipe`, `migrate:fresh`, `migrate:refresh`, `rollback`, or any data-altering commands.
- **DO NOT** print or expose passwords, database credentials, or the raw `.env` file.
- Copy each command block into your existing Hostinger SSH terminal and paste back the raw output for evaluation.

---

## 2. Command Sequence (Copy-Paste Blocks)

### BLOCK 1: Confirm Project Root & Environment Integrity
Verifies the current working directory, Laravel artisan entry point, PHP version, and Laravel framework version without exposing any configuration secrets.

```bash
pwd && ls -la artisan bootstrap/app.php
php -v | head -n 1
php artisan --version
```

- **Expected Output:**
  - `pwd` prints project root (typically `/home/u.../waai_media_api` or `~/waai_media_api`).
  - `artisan` and `bootstrap/app.php` exist and are accessible.
  - PHP version should be PHP 8.2+ (or 8.3 / 8.5).
  - Laravel framework version is displayed (e.g. `Laravel Framework 11.x.x` or `12.x.x`).
- **What to paste back:** The complete console output of this block.

---

### BLOCK 2: Inspect Database Schema for Events & Categories (No Credentials Exposed)
Uses Laravel Tinker in a read-only query to check whether the `events` or `event_categories` tables exist in the production database, without revealing username or password.

```bash
php artisan tinker --execute "echo 'Table [events] exists: ' . (Illuminate\Support\Facades\Schema::hasTable('events') ? 'YES' : 'NO') . PHP_EOL; echo 'Table [event_categories] exists: ' . (Illuminate\Support\Facades\Schema::hasTable('event_categories') ? 'YES' : 'NO') . PHP_EOL;"
```

- **Expected Output:**
  - `Table [events] exists: NO`
  - `Table [event_categories] exists: NO`
  *(If either outputs `YES`, deployment must halt to avoid collision or schema conflict).*
- **What to paste back:** The lines showing `Table [events] exists:` and `Table [event_categories] exists:`.

---

### BLOCK 3: Production Migration Status Check
Checks if Laravel's `migrations` table contains any records for the Event Calendar, and prints the overall migration status for event-related files.

```bash
php artisan migrate:status | grep -E "events|category|Migration table" || php artisan migrate:status | tail -n 15
```

- **Expected Output:**
  - If `2026_10_24_000001_create_events_table` is present in the filesystem, it should show `Ran? No` (Pending).
  - If it is not yet uploaded, it will not appear in the table.
  - **Crucial:** Confirm there are no legacy migrations recorded as `Ran? Yes` for deleted category files (`...create_event_categories_table` or `...add_category_id_to_events_table`).
- **What to paste back:** The matched lines from `migrate:status`.

---

### BLOCK 4: Verify Shared Storage Service & Middleware Classes
Ensures the server already has the underlying classes and middleware that the Event Calendar module depends on.

```bash
php artisan tinker --execute "echo 'ImageStorageService: ' . (class_exists('App\Services\Storage\ImageStorageService') ? 'YES' : 'NO') . PHP_EOL; echo 'AuthorizeRole (role alias): ' . (class_exists('App\Http\Middleware\AuthorizeRole') ? 'YES' : 'NO') . PHP_EOL; echo 'EditorCannotDelete: ' . (class_exists('App\Http\Middleware\EditorCannotDelete') ? 'YES' : 'NO') . PHP_EOL; echo 'RequireAccessTokenAbility: ' . (class_exists('App\Http\Middleware\RequireAccessTokenAbility') ? 'YES' : 'NO') . PHP_EOL;"
```

- **Expected Output:**
  - `ImageStorageService: YES`
  - `AuthorizeRole (role alias): YES`
  - `EditorCannotDelete: YES`
  - `RequireAccessTokenAbility: YES`
- **What to paste back:** The four `YES/NO` status lines.

---

### BLOCK 5: Verify Routes File for Event Endpoints
Checks if `routes/api.php` on the server already contains Event routes or if they need to be appended.

```bash
grep -n -E "events|EventController" routes/api.php || echo "No Event routes found in routes/api.php"
```

- **Expected Output:**
  - Either the route definitions for `/events` and `/admin/events` with line numbers,
  - Or `No Event routes found in routes/api.php` (which indicates the minimal route block must be added).
- **What to paste back:** The grep output or message.

---

### BLOCK 6: Inspect Target Migration File (if present)
Checks if `database/migrations/2026_10_24_000001_create_events_table.php` or any other event migration files already exist on the server, and calculates their SHA-256 checksums.

```bash
ls -la database/migrations/*event*.php 2>/dev/null || echo "No event migrations found in database/migrations/"
if [ -f database/migrations/2026_10_24_000001_create_events_table.php ]; then sha256sum database/migrations/2026_10_24_000001_create_events_table.php; fi
```

- **Expected Output:**
  - Lists any existing event migration files.
  - If `2026_10_24_000001_create_events_table.php` is present, prints its SHA-256 hash. If it matches `4717475b37c231ba8590afd413988b14ac88bfa637770c07da8223a88a6692e2`, it is identical to the verified local file.
  - If absent, it confirms the file will be cleanly introduced when the ZIP is extracted.
- **What to paste back:** The list of files and any SHA-256 output.

---

## 3. Checklist of Outstanding Pre-Deployment Verification

Before any upload or migration execution, the following verification items remain outstanding:

1. [ ] **Confirmation of Project Root:** Verified active path on Hostinger.
2. [ ] **Clean Database State:** Verified neither `events` nor `event_categories` tables exist in production database.
3. [ ] **Migration History Audit:** Verified no dangling historical records exist for obsolete category migrations.
4. [ ] **Shared Services & Middleware Verification:** Confirmed `ImageStorageService` and `AuthorizeRole` exist on production.
5. [ ] **Routes File Status:** Confirmed whether `routes/api.php` already contains the 8 endpoints or requires appending the minimal route block.
6. [ ] **Package Upload Checksum:** When `event-calendar-deploy.zip` is uploaded, its SHA-256 must match `3f70411cf0b7ebe87c665d5384163e3f4b16a841d83bc382439d637064aee1a9`.
