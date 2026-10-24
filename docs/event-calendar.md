# Waai Media — Event Calendar Documentation

A comprehensive developer reference and technical specification for the Calendar Events module in Waai Media.

---

## 1. Overview

The Event Calendar module provides bilingual event management, scheduling, and publication for the Waai Media platform. It offers:
- An **Admin API** for authenticated content managers (Admins and Editors) to create incomplete drafts, update event details, publish validated events, and for Administrators to permanently delete events.
- A **Public API** for unauthenticated clients to list upcoming, past, or all published events with filtering, configurable pagination, and localized attribute resolution based on HTTP `Accept-Language` headers.

All implementation code resides within the Laravel backend (`app/Models/Event.php`, `app/Http/Controllers/Api/...`, `app/Services/Events/...`, `app/Http/Requests/...`, `app/Http/Resources/...`).

---

## 2. Final Business Rules

1. **Statuses & Lifecycle**:
   - Exactly two valid statuses exist: `draft` and `published`.
   - There is **no `unpublished` status** and no unpublish endpoint.
2. **Draft Creation & Updates**:
   - Drafts can be partial or incomplete to facilitate iterative editing.
   - A draft must contain at least one non-empty, non-whitespace title: either `name_ar` or `name_en`.
3. **Publishing Invariants**:
   - Publishing an event transitions its status from `draft` to `published`.
   - Complete bilingual data is strictly enforced at publishing time: `name_en`, `name_ar`, `category_en`, `category_ar`, `place_en`, `place_ar`, `event_date`, and `cover_image_url` are mandatory.
   - `description_en`, `description_ar`, `author_name`, `author_name_en`, `author_name_ar`, and `url` remain optional.
   - `published_at` is set to the current UTC timestamp (`now()`) on initial publication and is preserved across subsequent updates or republishes.
4. **Permanent Deletion**:
   - Events are permanently removed from the database (`DELETE FROM events WHERE id = ?`).
   - There is **no soft delete mechanism** (no `deleted_at` column, no `SoftDeletes` trait).
   - Deletion is strictly reserved for users with the `admin` role. Users with the `editor` role receive an HTTP `403 Forbidden`.
5. **Authorship & Attribution**:
   - Events do not link to user accounts via a foreign key (`no author_id`).
   - Author names are stored in three fields:
     - `author_name` — legacy single-language field, kept for backward compatibility.
     - `author_name_en` — English author name (bilingual support).
     - `author_name_ar` — Arabic author name (bilingual support).
   - The alias `journalist_name` is accepted during ingestion and mapped to `author_name`/`author_name_en`. Both `author_name` and `journalist_name` appear in API responses.
   - Public API responses localize author name based on the negotiated locale with fallback.
6. **Cover Image Storage Contract**:
   - The database stores only the complete public URL in `cover_image_url`.
   - There is **no `cover_image_key` column** on the `events` table.
   - Direct Cloudflare R2 uploads and S3 driver integrations are currently paused. The Events module only accepts and exposes valid HTTP/HTTPS URLs.
7. **Public Visibility**:
   - Public listing and detail endpoints exclusively return published events where `published_at <= now()`.
   - Draft events return `404 Not Found` on public detail routes and are filtered out of public listings.

---

## 3. Database Schema

The module's database design is managed by:
1. `database/migrations/2026_10_24_000001_create_events_table.php` — Core `events` table structure, free-text categories, separate `event_date` (`date`) and `event_time` (`time`), indexes, and constraints.

### Table: `events`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | Auto-increment | Primary key |
| `name_en` | `VARCHAR(255)` | Yes | `NULL` | Event title in English |
| `name_ar` | `VARCHAR(255)` | Yes | `NULL` | Event title in Arabic |
| `slug` | `VARCHAR(255)` | Yes | `NULL` | URL-safe slug (`{slug}-{id}`). Unique index. |
| `category_en` | `VARCHAR(150)` | Yes | `NULL` | Free-text English category |
| `category_ar` | `VARCHAR(150)` | Yes | `NULL` | Free-text Arabic category |
| `description_en` | `TEXT` | Yes | `NULL` | Event description in English (plain text) |
| `description_ar` | `TEXT` | Yes | `NULL` | Event description in Arabic (plain text) |
| `place_en` | `VARCHAR(255)` | Yes | `NULL` | Venue / location name in English |
| `place_ar` | `VARCHAR(255)` | Yes | `NULL` | Venue / location name in Arabic |
| `event_date` | `DATE` | Yes | `NULL` | Scheduled event calendar date (`YYYY-MM-DD`) |
| `event_time` | `TIME` | Yes | `NULL` | Scheduled event start time (`HH:MM:SS`) |
| `cover_image_url`| `VARCHAR(500)` | Yes | `NULL` | Full public HTTP/HTTPS URL or verified R2 key |
| `url` | `VARCHAR(500)` | Yes | `NULL` | External website or registration URL |
| `status` | `ENUM('draft', 'published')` | No | `'draft'` | Current publication status |
| `published_at` | `TIMESTAMP` | Yes | `NULL` | Timestamp when event was first published |
| `created_at` | `TIMESTAMP` | Yes | `NULL` | Standard Laravel creation timestamp |
| `updated_at` | `TIMESTAMP` | Yes | `NULL` | Standard Laravel update timestamp |

### Indexes on `events`

- **Primary Key**: `id`
- **Unique**: `slug` (`unique('slug')`)
- **Single-Column Indexes**:
  - `status`
  - `published_at`
  - `category_en` (`idx_events_category_en`)
  - `category_ar` (`idx_events_category_ar`)
- **Composite Indexes**:
  - `[status, event_date]` — Optimizes public timeline filtering (`upcoming` / `past`)
  - `[created_at, id]` — Optimizes administrative chronologically ordered listings

---

## 4. Lifecycle: Draft, Update, Publish, and Delete

```
               [ POST /admin/events ]
                         │
                         ▼
                  ┌──────────────┐
                  │    Draft     │◄────────────┐
                  │ (status:     │             │
                  │  'draft')    │             │
                  └──────┬───────┘             │
                         │                     │
      [ PUT/PATCH /admin/events/{id} ]         │
                         │                     │
                         ▼                     │
                  ┌──────────────┐             │
                  │ Update Draft │─────────────┘
                  └──────┬───────┘
                         │
       [ POST/PATCH /admin/events/{id}/publish ]
       (Validates all mandatory bilingual fields)
                         │
                         ▼
                  ┌──────────────┐
                  │  Published   │◄────────────┐
                  │ (status:     │             │
                  │ 'published') │             │
                  └──────┬───────┘             │
                         │                     │
      [ PUT/PATCH /admin/events/{id} ]         │
    (Cannot mutate slug or published_at)       │
                         │                     │
                         ▼                     │
                  ┌──────────────┐             │
                  │Update Pub'd  │─────────────┘
                  │    Event     │
                  └──────┬───────┘
                         │
            [ DELETE /admin/events/{id} ]
                 (Admin role only)
                         │
                         ▼
                  ┌──────────────┐
                  │  Permanently │
                  │   Deleted    │
                  └──────────────┘
```

### 1. Draft Creation (`POST /api/v1/admin/events`)
- Status is explicitly forced to `draft`.
- Requires at least one non-whitespace title: `name_en` or `name_ar`.
- All other fields (dates, categories, descriptions, cover image, author names) are optional.
- If `name_en` contains Latin characters, a slug is generated (`{slug}-{id}`). If `name_en` is omitted or contains only non-Latin characters, `slug` is initialized to `null`.

### 2. Updating Events (`PUT|PATCH /api/v1/admin/events/{id}`)
- Supports partial updates.
- Protected attributes (`id`, `status`, `slug`, `published_at`) submitted by the client are stripped and ignored.
- If the event is in `draft` status and `name_en` changes, the slug is recalculated.
- If the event is in `published` status, the slug is **immutable** and will not change even if `name_en` is updated.

### 3. Publishing (`POST|PATCH /api/v1/admin/events/{id}/publish`)
- Can be invoked directly on an existing draft, optionally submitting updated fields in the same request payload.
- Merges submitted payload with existing database attributes (`prepareForValidation()`).
- Validates the complete merged data against strict publishing requirements.
- Sets `status = published`.
- Sets `published_at = now()` if previously `null` (re-publishing preserves the original `published_at`).
- If `slug` was `null` (e.g. created Arabic-only) and `name_en` has Latin characters, generates and assigns the slug.

### 4. Permanent Deletion (`DELETE /api/v1/admin/events/{id}`)
- Dual-layer authorization:
  1. Route middleware: `role:admin`.
  2. Controller guard: `$request->user()->isAdmin()`.
- Issues `$event->delete()`, executing a direct SQL `DELETE`.
- The database record is permanently purged.

---

## 5. Slug Rules

The slug generation logic is encapsulated in `App\Services\Events\EventSlugService` following the News module conventions.

### Format
```
slug = Str::slug(name_en) + "-" + id
```

Example:
- Title: `"Web3 & AI Summit 2026"`
- ID: `42`
- Resulting Slug: `web3-ai-summit-2026-42`

### Invariants & Rules
1. **Latin-Only Basis**: Slugs are generated strictly from the English title (`name_en`).
2. **Arabic-Only Titles**: If `name_en` is empty, null, or contains no Latin characters (e.g., Arabic-only draft), `generateSlug()` returns `null`.
3. **Immutability Once Published**:
   - A draft event's slug is updated whenever `name_en` changes.
   - Once an event has been published (`published_at !== null`), its slug is **strictly immutable**. Subsequent updates to `name_en` do not alter the slug, preventing broken URLs and SEO degradation.
4. **Idempotence**: Calling `resolveSlugForUpdate()` with identical parameters or on an immutable published event will not perform redundant database writes.

---

## 6. Datetime and Timezone Handling

Event datetime conversion and validation are handled by `App\Services\Events\EventDateTimeService`.

### Input Format & Conversion
- The frontend client sends:
  1. A local date/time string (`event_date`), such as `"2026-11-15 19:30:00"`.
  2. A valid IANA timezone identifier (`timezone`), such as `"Africa/Cairo"`, `"Asia/Riyadh"`, or `"Europe/London"`.
- The service parses the local date/time in the context of the supplied timezone:
  ```php
  Carbon::parse($dateString, $timezone)->setTimezone('UTC');
  ```
- The converted timestamp is stored in the database as UTC.

### Accepted Formats
- `Y-m-d H:i:s` (e.g., `2026-11-15 19:30:00`)
- `Y-m-d\TH:i:s` (e.g., `2026-11-15T19:30:00`)
- ISO 8601 strings (e.g., `2026-11-15T19:30:00Z` or `2026-11-15T21:30:00+02:00`)

### Contradiction Rejection
If a datetime string containing an embedded offset or UTC Zulu indicator (e.g. `2026-11-15T19:30:00+02:00`) is submitted alongside an incompatible IANA timezone (e.g. `"America/New_York"`), the request is rejected with a validation error.

### API Output
All date/time attributes returned in API resources (`event_date`, `published_at`, `created_at`, `updated_at`) are formatted as standard UTC ISO 8601 strings:
```json
"event_date": "2026-11-15T17:30:00.000000Z"
```

---

## 7. Author Name Handling

### Fields

| Field | Database Column | Description |
|---|---|---|
| `author_name` | Yes (`varchar(255)`) | Legacy single-language author name |
| `author_name_en` | Model fillable | English author name (bilingual) |
| `author_name_ar` | Model fillable | Arabic author name (bilingual) |
| `journalist_name` | Alias only | Accepted as input, mapped to `author_name`/`author_name_en` |

### Input Backward Compatibility
The `prepareForValidation()` method on all three form requests handles alias mapping:
- If `author_name` is submitted without `author_name_en`, it is copied to `author_name_en`.
- If `journalist_name` is submitted without `author_name_en`, it is mapped to `author_name_en`.
- If `author_name_en` is submitted without `author_name`, `author_name` is populated from `author_name_en`.

### Admin API Response
The admin resource returns all author fields:
```json
{
  "author_name": "Sarah Connor",
  "author_name_en": "Sarah Connor",
  "author_name_ar": "سارة كونور",
  "journalist_name": "Sarah Connor"
}
```
- `author_name` resolves to `author_name ?? author_name_en`.
- `journalist_name` resolves to `author_name ?? author_name_en`.

### Public API Response
The public resource localizes the author name based on `Accept-Language`:
- **Arabic** (`ar`): `author_name_ar → author_name_en → author_name` (fallback chain)
- **English** (`en`): `author_name_en → author_name_ar → author_name` (fallback chain)

Both `author_name` and `journalist_name` in the response resolve to the same localized value:
```json
{
  "author_name": "سارة كونور",
  "author_name_en": "Sarah Connor",
  "author_name_ar": "سارة كونور",
  "journalist_name": "سارة كونور"
}
```

---

## 8. Admin API Reference

All Admin endpoints require Sanctum authentication, active account access, and `admin` or `editor` role (except `DELETE`, which requires `admin` only).

Base URL path prefix: `/api/v1/admin/events`

### Summary of Endpoints

| Method | URI | Action | Allowed Roles |
|---|---|---|---|
| `GET` | `/api/v1/admin/events` | List events with filters & pagination | `admin`, `editor` |
| `POST` | `/api/v1/admin/events` | Create a new event draft | `admin`, `editor` |
| `GET` | `/api/v1/admin/events/{event}` | Get complete bilingual event details | `admin`, `editor` |
| `PUT` / `PATCH` | `/api/v1/admin/events/{event}` | Partially update an event | `admin`, `editor` |
| `POST` / `PATCH` | `/api/v1/admin/events/{event}/publish` | Validate and publish an event | `admin`, `editor` |
| `DELETE` | `/api/v1/admin/events/{event}` | Permanently delete an event | `admin` only |

---

### 1. List Admin Events
- **Route**: `GET /api/v1/admin/events`
- **Headers**:
  - `Authorization: Bearer <token>`
  - `Accept: application/json`
- **Query Parameters**:
  - `search` (string, max:255, optional) — Case-insensitive search matching against `name_en` and `name_ar` (LIKE with SQL-safe escaping).
  - `category` (string, max:150, optional) — Matches `category_en` or `category_ar`.
  - `status` (string, optional) — Filter by `draft` or `published`.
  - `timeline` (string, optional) — Filter by `upcoming` (`event_date >= now()`) or `past` (`event_date < now()`).
  - `per_page` (integer, min:1, max:100, optional, default: 10) — Items per page (defaults to 10 for Admin Management).
  - `page` (integer, min:1, optional) — Current page number.
- **Pagination**: Default 10 items per page (configurable via `per_page` up to 100). Ordered by `created_at` DESC, `id` DESC.
- **Response**: HTTP 200 OK
  ```json
  {
    "data": [
      {
        "id": 1,
        "slug": "digital-media-conference-2026-1",
        "status": "published",
        "name_en": "Digital Media Conference 2026",
        "name_ar": "مؤتمر الإعلام الرقمي 2026",
        "category_en": "Technology",
        "category_ar": "تكنولوجيا",
        "description_en": "Annual digital media gathering.",
        "description_ar": "التجمع السنوي للإعلام الرقمي.",
        "place_en": "Cairo Exhibition Center",
        "place_ar": "مركز القاهرة للمعارض",
        "event_date": "2026-11-20T08:00:00.000000Z",
        "cover_image_url": "https://example.com/images/dmc2026.jpg",
        "author_name": "Sarah Connor",
        "author_name_en": "Sarah Connor",
        "author_name_ar": "سارة كونور",
        "journalist_name": "Sarah Connor",
        "url": "https://dmc2026.example.com",
        "published_at": "2026-10-23T02:00:00.000000Z",
        "created_at": "2026-10-23T01:30:00.000000Z",
        "updated_at": "2026-10-23T02:00:00.000000Z"
      }
    ],
    "links": {
      "first": "http://example.com/api/v1/admin/events?page=1",
      "last": "http://example.com/api/v1/admin/events?page=1",
      "prev": null,
      "next": null
    },
    "meta": {
      "current_page": 1,
      "from": 1,
      "last_page": 1,
      "path": "http://example.com/api/v1/admin/events",
      "per_page": 10,
      "to": 1,
      "total": 1
    }
  }
  ```

---

### 2. Create Event Draft
- **Route**: `POST /api/v1/admin/events`
- **Headers**:
  - `Authorization: Bearer <token>`
  - `Content-Type: application/json`
  - `Accept: application/json`
- **Request Body Fields**:
  - `name_en` (string, max:255, nullable) — Required if `name_ar` is missing.
  - `name_ar` (string, max:255, nullable) — Required if `name_en` is missing.
  - `category_en` (string, max:150, nullable, optional)
  - `category_ar` (string, max:150, nullable, optional)
  - `description_en` (string, nullable, optional)
  - `description_ar` (string, nullable, optional)
  - `place_en` (string, max:255, nullable, optional)
  - `place_ar` (string, max:255, nullable, optional)
  - `event_date` (string, datetime format, nullable, optional)
  - `timezone` (string, IANA timezone name, required if `event_date` is provided)
  - `cover_image_url` (string, valid URL, max:500, nullable, optional)
  - `author_name` (string, max:255, nullable, optional)
  - `author_name_en` (string, max:255, nullable, optional)
  - `author_name_ar` (string, max:255, nullable, optional)
  - `journalist_name` (string, max:255, nullable, optional, alias for `author_name`/`author_name_en`)
  - `url` (string, valid URL, max:500, nullable, optional)
- **Response**: HTTP 201 Created
  ```json
  {
    "data": {
      "id": 2,
      "slug": "ai-ethics-forum-2",
      "status": "draft",
      "name_en": "AI Ethics Forum",
      "name_ar": "منتدى أخلاقيات الذكاء الاصطناعي",
      "category_en": null,
      "category_ar": null,
      "description_en": null,
      "description_ar": null,
      "place_en": null,
      "place_ar": null,
      "event_date": null,
      "cover_image_url": null,
      "author_name": "Tariq Ali",
      "author_name_en": "Tariq Ali",
      "author_name_ar": "طارق علي",
      "journalist_name": "Tariq Ali",
      "url": null,
      "published_at": null,
      "created_at": "2026-10-23T02:20:00.000000Z",
      "updated_at": "2026-10-23T02:20:00.000000Z"
    }
  }
  ```

---

### 3. Get Single Admin Event
- **Route**: `GET /api/v1/admin/events/{event}`
- **Headers**:
  - `Authorization: Bearer <token>`
  - `Accept: application/json`
- **Response**: HTTP 200 OK with `EventAdminResource` wrapped in `{"data": ...}`.

---

### 4. Update Event
- **Route**: `PUT|PATCH /api/v1/admin/events/{event}`
- **Headers**:
  - `Authorization: Bearer <token>`
  - `Content-Type: application/json`
  - `Accept: application/json`
- **Request Body**: Same field options as draft creation. Only supplied fields are updated.
- **Response**: HTTP 200 OK with updated `EventAdminResource`.

---

### 5. Publish Event
- **Route**: `POST|PATCH /api/v1/admin/events/{event}/publish`
- **Headers**:
  - `Authorization: Bearer <token>`
  - `Content-Type: application/json`
  - `Accept: application/json`
- **Request Body**: Optional. Any fields provided in the payload will update the event prior to publishing.
- **Validation Rules**:
  - `name_en`: required string, max:255
  - `name_ar`: required string, max:255
  - `category_en`: required string, max:150
  - `category_ar`: required string, max:150
  - `place_en`: required string, max:255
  - `place_ar`: required string, max:255
  - `event_date`: required datetime
  - `timezone`: nullable IANA timezone (required when submitting a new `event_date`)
  - `cover_image_url`: required valid URL, max:500
  - `description_en`: optional string
  - `description_ar`: optional string
  - `author_name` / `author_name_en` / `author_name_ar` / `journalist_name`: optional string, max:255
  - `url`: optional valid URL, max:500
- **Response**: HTTP 200 OK with updated `EventAdminResource` (`status: "published"`, `published_at: "<ISO timestamp>"`).

---

### 6. Delete Event
- **Route**: `DELETE /api/v1/admin/events/{event}`
- **Headers**:
  - `Authorization: Bearer <token>`
  - `Accept: application/json`
- **Permissions**: `admin` role required. `editor` receives HTTP 403 Forbidden.
- **Response**: HTTP 200 OK
  ```json
  {
    "message": "Event permanently deleted successfully."
  }
  ```

---

## 9. Public API Reference

Public endpoints are unauthenticated and expose published events only (`status = published`, `published_at <= now()`).

Base URL path prefix: `/api/v1/events`

### Summary of Endpoints

| Method | URI | Action | Visibility |
|---|---|---|---|
| `GET` | `/api/v1/events` | List published events with timeline filters & pagination | Public |
| `GET` | `/api/v1/events/{slug}` | Get single published event by slug | Public |

---

### 1. Public Event List
- **Route**: `GET /api/v1/events`
- **Headers**:
  - `Accept: application/json`
  - `Accept-Language: ar` (or `en`)
- **Query Parameters**:
  - `timeline` (string, optional, in: `upcoming`, `past`, `all`, `latest`) — **Default: `upcoming`**.
    - `upcoming`: `event_date >= now()`, sorted by `event_date ASC`, `id ASC`.
    - `past`: `event_date < now()`, sorted by `event_date DESC`.
    - `all`: All published dates, sorted by `event_date ASC`, `id ASC`.
    - `latest`: Most recently created, sorted by `created_at DESC`, `id DESC` (homepage use).
  - `category` (string, max:150, optional) — Matches `category_en` or `category_ar`.
  - `per_page` (integer, min:1, max:100, optional) — Number of items per page. **Default: 20**.
  - `page` (integer, min:1, optional) — Current page number.
- **Pagination**: Default 20 items per page. Clients can override with `per_page` (1–100).
- **Response**: HTTP 200 OK
  ```json
  {
    "data": [
      {
        "id": 1,
        "slug": "digital-media-conference-2026-1",
        "name": "مؤتمر الإعلام الرقمي 2026",
        "name_en": "Digital Media Conference 2026",
        "name_ar": "مؤتمر الإعلام الرقمي 2026",
        "description": "التجمع السنوي للإعلام الرقمي.",
        "category": "تكنولوجيا",
        "place": "مركز القاهرة للمعارض",
        "event_date": "2026-11-20T08:00:00.000000Z",
        "cover_image_url": "https://example.com/images/dmc2026.jpg",
        "author_name": "سارة كونور",
        "author_name_en": "Sarah Connor",
        "author_name_ar": "سارة كونور",
        "journalist_name": "سارة كونور",
        "url": "https://dmc2026.example.com"
      }
    ],
    "links": {
      "first": "http://example.com/api/v1/events?page=1",
      "last": "http://example.com/api/v1/events?page=1",
      "prev": null,
      "next": null
    },
    "meta": {
      "current_page": 1,
      "from": 1,
      "last_page": 1,
      "path": "http://example.com/api/v1/events",
      "per_page": 20,
      "to": 1,
      "total": 1
    }
  }
  ```

---

### 2. Public Event Detail
- **Route**: `GET /api/v1/events/{slug}`
- **Headers**:
  - `Accept: application/json`
  - `Accept-Language: en` (or `ar`)
- **URL Parameter**:
  - `slug` (string) — The unique event slug.
- **Behavior**:
  - If the slug matches a published event (`published_at <= now()`), returns HTTP 200 OK.
  - If the event is in `draft` status or `published_at` is null / in the future, returns HTTP 404 Not Found.
- **Response**: HTTP 200 OK
  ```json
  {
    "data": {
      "id": 1,
      "slug": "digital-media-conference-2026-1",
      "name": "Digital Media Conference 2026",
      "name_en": "Digital Media Conference 2026",
      "name_ar": "مؤتمر الإعلام الرقمي 2026",
      "description": "Annual digital media gathering.",
      "category": "Technology",
      "place": "Cairo Exhibition Center",
      "event_date": "2026-11-20T08:00:00.000000Z",
      "cover_image_url": "https://example.com/images/dmc2026.jpg",
      "author_name": "Sarah Connor",
      "author_name_en": "Sarah Connor",
      "author_name_ar": "سارة كونور",
      "journalist_name": "Sarah Connor",
      "url": "https://dmc2026.example.com"
    }
  }
  ```

---

## 10. Localization and Response Format

Localization is negotiated via `App\Support\LocaleNegotiator::negotiate($request->header('Accept-Language'))`.

### Locale Resolution
- Supported locales: `ar`, `en`.
- Default fallback locale: `en`.
- Matches quality factors (`q-values`) in standard RFC 7231 `Accept-Language` headers (e.g. `ar,en;q=0.8`).

### Resource Field Structures

#### Public API (`EventPublicResource`)
Bilingual pairs are collapsed into a single localized string with automatic fallback to the alternative language if the primary language is null or whitespace:

| Public Field | Arabic (`ar`) Resolution | English (`en`) Resolution |
|---|---|---|
| `name` | `name_ar` → fallback to `name_en` | `name_en` → fallback to `name_ar` |
| `description` | `description_ar` → fallback to `description_en` | `description_en` → fallback to `description_ar` |
| `category` | `category_ar` → fallback to `category_en` | `category_en` → fallback to `category_ar` |
| `place` | `place_ar` → fallback to `place_en` | `place_en` → fallback to `place_ar` |
| `author_name` | `author_name_ar` → `author_name_en` → `author_name` | `author_name_en` → `author_name_ar` → `author_name` |

Additionally, both raw bilingual titles are always returned: `name_en`, `name_ar`, `author_name_en`, `author_name_ar`.

Remaining fields returned identically in both locales: `id`, `slug`, `event_date`, `cover_image_url`, `journalist_name` (same as localized `author_name`), `url`.

#### Admin API (`EventAdminResource`)
The admin resource never collapses localized fields, returning all raw bilingual attributes simultaneously:
`id`, `slug`, `status`, `name_en`, `name_ar`, `category_en`, `category_ar`, `description_en`, `description_ar`, `place_en`, `place_ar`, `event_date`, `cover_image_url`, `author_name`, `author_name_en`, `author_name_ar`, `journalist_name`, `url`, `published_at`, `created_at`, `updated_at`.

---

## 11. Cover Image Handling

1. **Database Representation**:
   - The cover image is stored exclusively in `cover_image_url` as a `VARCHAR(500)` string.
   - It stores an absolute, publicly accessible HTTP or HTTPS URL.
2. **Paused Cloudflare R2 Uploads**:
   - Direct binary image uploads to Cloudflare R2 or local filesystem disks are currently **paused**.
   - There is no `cover_image_key` column on the `events` table.
   - The application expects clients to supply a pre-hosted or pre-signed URL in `cover_image_url`.
3. **Validation Requirements**:
   - For drafts: `cover_image_url` is optional, but if supplied must be a valid URL (`url`, `max:500`).
   - For publishing: `cover_image_url` is strictly required (`required`, `url`, `max:500`).

---

## 12. Authorization and Security

Security enforcement is built across two complementary layers:

### Middleware Layer
- **Admin Endpoints**:
  - `auth:sanctum`: Ensures a valid user API token.
  - `require.access`: Verifies that the authenticated user account is active/unblocked.
  - `role:admin,editor`: Restricts access to users holding the `admin` or `editor` role.
  - `role:admin` (applied specifically to `DELETE /api/v1/admin/events/{event}`): Prevents editors from accessing the deletion route.
- **Public Endpoints**:
  - Open to unauthenticated clients. No Sanctum middleware.

### Controller & Model Guardrails
- **Deletion Dual Guard**: In `EventController::destroy()`, an explicit authorization check ensures non-admin users cannot delete even if middleware routing changes:
  ```php
  if (! $user || ! $user->isAdmin()) {
      return response()->json([
          'message' => 'Forbidden. Only administrators can permanently delete events.',
      ], 403);
  }
  ```
- **Mass-Assignment Guard**:
  Protected attributes (`id`, `status`, `slug`, `published_at`, `created_at`, `updated_at`, `deleted_at`) are stripped from validated input in all three form requests (`StoreEventRequest`, `UpdateEventRequest`, `PublishEventRequest`), and additionally in the controller for `update` and `publish` actions.
- **Scope Isolation**:
  Public queries unconditionally apply `Event::published()` (`status = 'published'`, `published_at IS NOT NULL`, `published_at <= now()`). Unauthenticated requests cannot inspect drafts.

---

## 13. Tests and Verification Commands

The test suite covers unit logic (model scopes, slug service, timezone conversion) and feature workflows (validation, admin API, public API, permissions).

### Test Files

1. `tests/Unit/EventModelTest.php`
   - Model fillable attributes, casts, query scopes (`published`, `upcoming`, `past`, `forCategory`), status helper methods, and journalist name accessors.
2. `tests/Unit/Events/EventSlugServiceTest.php`
   - Slug formatting (`{slug}-{id}`), Latin character normalization, Arabic-only title handling, immutability of published events.
3. `tests/Unit/Events/EventDateTimeServiceTest.php`
   - Timezone-aware date parsing, UTC conversion, ISO 8601 formatting, contradiction rejection between embedded offsets and IANA timezones.
4. `tests/Feature/Events/EventValidationTest.php`
   - Draft creation minimums (`name_en` or `name_ar`), whitespace rejection, publishing mandatory bilingual field validation, bilingual author name validation.
5. `tests/Feature/Events/AdminEventApiTest.php`
   - Full admin CRUD lifecycle, partial updates, publish transitions, role restrictions (editor 403 on delete, admin 200), bilingual author creation and updates.
6. `tests/Feature/Events/PublicEventApiTest.php`
   - Public visibility of published events, draft isolation (404), timeline filtering (`upcoming`, `past`, `all`, `latest`), custom pagination, bilingual author localization and fallback.

### Verification Commands

```powershell
# Run the entire Event Calendar test suite
vendor/bin/pest tests/Unit/EventModelTest.php tests/Unit/Events tests/Feature/Events

# Run code style formatting check
vendor/bin/pint --dirty --format agent

# Inspect registered Event API routes
php artisan route:list --path=events
```

---

## 14. Known Limitations and Architectural Notes

1. **Paused Cloudflare R2 Uploads**:
   - The Events API currently receives and serves complete URLs (`cover_image_url`). When R2 image uploads resume, an upload endpoint will need to generate public URLs before passing them to the Events API.
2. **Arabic-Only Event Slugs**:
   - Following the existing News module convention, slugs are generated only from Latin characters in `name_en`. An event with an Arabic title only (`name_ar`) will have a `null` slug while in draft status.
   - Because public detail routes resolve events by `{slug}`, any event published without a Latin title or without a generated slug will not be accessible via the public slug route (`/api/v1/events/{slug}`). Publishing validation requires both `name_en` and `name_ar`, ensuring published events have a valid English title.
3. **Bilingual Author Fields**:
   - `author_name_en` and `author_name_ar` are explicitly defined as columns in the `events` table via migration `2026_10_24_000002_add_bilingual_author_names_to_events_table.php`, allowing distinct storage of English and Arabic author names alongside the legacy `author_name` column.
4. **Admin Pagination**:
   - Admin listing endpoints default to 10 items per page matching the Admin Management specification, and support custom `per_page` query parameters (1–100).
5. **Configurable Public Pagination**:
   - Public listing endpoints support `per_page` (1–100, default 20) for flexible frontend consumption.
6. **No Soft Deletion**:
   - Deletion permanently removes the row from the database. Deleted events cannot be recovered via a restore endpoint.
