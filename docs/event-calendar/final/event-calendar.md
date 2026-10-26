# Waai Media — Event Calendar API Documentation

A comprehensive, authoritative reference and technical specification for the Event Calendar module in Waai Media.

---

## 1. Overview & Scope

The **Event Calendar** module manages bilingual events, schedule announcements, and public exhibition discovery for the Waai Media platform.

### Core Architecture
- **Admin API**: Authenticated management endpoints for `admin` and `editor` roles to create iterative drafts, update details, publish validated events, unpublish back to drafts, and permanently delete events (`admin` only).
- **Public API**: Read-only, unauthenticated endpoints returning published events with timeline/category filtering, automatic language negotiation (`Accept-Language`), and configurable pagination.
- **Underlying Database Table**: `event_calendar` (managed by migration `database/migrations/2026_10_24_000001_create_events_table.php`).
- **No External Category Table**: Categories are direct bilingual free-text columns (`category_en`, `category_ar`). There is no relational `event_categories` table.
- **No Author Foreign Keys**: Author attributes are stored as direct text columns (`author_name`, `author_name_en`, `author_name_ar`), with backward-compatible aliases like `journalist_name`.

---

## 2. Authentication & Authorization

| Context | Guard / Mechanism | Requirements | Allowed Roles |
|---|---|---|---|
| **Public Routes** | None | No authentication required | Anyone / Anonymous |
| **Admin CRUD & Lifecycle** | `auth:sanctum`, `require.access` | Bearer token with `'access'` ability | `admin`, `editor` |
| **Event Deletion** | `role:admin` middleware + `$user->isAdmin()` check | Bearer token with `'access'` ability | `admin` strictly (Editors receive `403 Forbidden`) |

---

## 3. Database Schema Contract

**Table Name**: `event_calendar`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | Auto-increment | Primary Key |
| `name_en` | `VARCHAR(255)` | Yes | `NULL` | Event title in English (must contain Latin characters) |
| `name_ar` | `VARCHAR(255)` | Yes | `NULL` | Event title in Arabic |
| `slug` | `VARCHAR(255)` | Yes | `NULL` | Unique URL slug (`{slug-en}-{id}`) |
| `category_en` | `VARCHAR(150)` | Yes | `NULL` | Free-text category in English |
| `category_ar` | `VARCHAR(150)` | Yes | `NULL` | Free-text category in Arabic |
| `description_en` | `TEXT` | Yes | `NULL` | English event description |
| `description_ar` | `TEXT` | Yes | `NULL` | Arabic event description |
| `place_en` | `VARCHAR(255)` | Yes | `NULL` | Venue / location name in English |
| `place_ar` | `VARCHAR(255)` | Yes | `NULL` | Venue / location name in Arabic |
| `event_date` | `DATE` | Yes | `NULL` | Date of the event (`Y-m-d` format) |
| `event_time` | `TIME` | Yes | `NULL` | Time of the event (`H:i` or `H:i:s` format) |
| `cover_image_url` | `VARCHAR(500)` | Yes | `NULL` | Public HTTP URL or relative R2 storage key (`events/covers/...`) |
| `url` | `VARCHAR(500)` | Yes | `NULL` | Optional external link |
| `status` | `ENUM('draft', 'published')` | No | `'draft'` | Current publication status |
| `published_at` | `TIMESTAMP` | Yes | `NULL` | Timestamp of initial publication (UTC) |
| `created_at` | `TIMESTAMP` | Yes | `NULL` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | Yes | `NULL` | Last record update timestamp |

### Indexes
- `events_slug_unique` (`slug` UNIQUE)
- `event_calendar_status_index` (`status` INDEX)
- `event_calendar_published_at_index` (`published_at` INDEX)
- `idx_event_calendar_timeline` (`status`, `event_date`, `event_time` INDEX)
- `idx_event_calendar_category_en` (`category_en` INDEX)
- `idx_event_calendar_category_ar` (`category_ar` INDEX)
- `idx_event_calendar_created_id` (`created_at`, `id` INDEX)

---

## 4. Lifecycle & Business Rules

### 4.1 Draft Creation (`POST /api/v1/admin/events`)
- **Required Fields**: `name_en` is strictly mandatory and must contain valid Latin alphanumeric characters to allow slug generation.
- **Incomplete Drafts**: All other fields (`name_ar`, `category_en`, `category_ar`, `place_en`, `place_ar`, `description_en`, `description_ar`, `event_date`, `event_time`, `cover_image`, `cover_image_url`, `url`) are optional.
- **Server Attributes**: The server automatically sets `status = 'draft'`, `published_at = null`, and strips any client-submitted `id`, `status`, `slug`, or `published_at`.
- **Slug Derivation**: Saved as `{normalized-name-en}-{persisted_id}` immediately after database insert in a transaction.

### 4.2 Updating Events (`PUT|PATCH /api/v1/admin/events/{event}`)
- **Partial Updates**: Any omitted field is preserved without modification.
- **Protected Fields**: Client-supplied `id`, `status`, `slug`, and `published_at` are stripped.
- **Slug Regeneration**:
  - If the event is in **draft** status (`published_at === null`) and `name_en` is updated, the slug is recalculated (`{new-normalized-name-en}-{id}`).
  - If the event has been **published** (`published_at !== null`), the slug is **permanently immutable** and will never change even if `name_en` is altered.
- **Published Event Protection**: On a published event, mandatory fields cannot be cleared or set to null/empty string.

### 4.3 Publishing (`POST|PATCH /api/v1/admin/events/{event}/publish`)
- **Mandatory Completeness**: Validates that all 11 required fields are present (either already saved on the event or submitted in the request):
  1. `name_en` (string, max 255, Latin characters required)
  2. `name_ar` (string, max 255)
  3. `category_en` (string, max 150)
  4. `category_ar` (string, max 150)
  5. `place_en` (string, max 255)
  6. `place_ar` (string, max 255)
  7. `description_en` (string)
  8. `description_ar` (string)
  9. `event_date` (`Y-m-d` date format)
  10. `event_time` (`H:i` or `H:i:s` time format)
  11. `cover_image_url` (valid HTTP/HTTPS URL or valid `events/covers/...` R2 key)
- **Optional Field**: `url` (external URL).
- **Status Transition**: `status` becomes `'published'`.
- **Timestamp**: `published_at` is set to `now()` on initial publish. On subsequent republishes, the original `published_at` timestamp is preserved.

### 4.4 Unpublishing (`POST|PATCH /api/v1/admin/events/{event}/unpublish`)
- **Status Transition**: `status` reverts to `'draft'`.
- **Timestamp Reset**: `published_at` is set to `null`.
- **Slug Preservation**: The existing slug remains assigned and immutable.
- **Immediate Effect**: The event is immediately excluded from all public API endpoints (`GET /api/v1/events` and `GET /api/v1/events/{slug}`).

### 4.5 Permanent Deletion (`DELETE /api/v1/admin/events/{event}`)
- **Hard Delete**: Records are permanently deleted (`DELETE FROM event_calendar WHERE id = ?`).
- **No Soft Deletes**: The model does not use Laravel SoftDeletes.
- **Storage Cleanup**: Associated R2 cover images are safely pruned via `EventCoverImageService::deleteIfUnshared()`.
- **Authorization**: Strictly restricted to Administrator (`role:admin`). Editors receive HTTP `403 Forbidden`.

---

## 5. Endpoints Reference

### Summary Table

| # | Method | Path | Auth | Roles | Description |
|---|---|---|---|---|---|
| 1 | `GET` | `/api/v1/events` | None | Public | List published events (default 3/page) |
| 2 | `GET` | `/api/v1/events/{slug}` | None | Public | Show single published event by slug |
| 3 | `GET` | `/api/v1/admin/events` | Bearer Token | `admin`, `editor` | Admin event listing & search (default 10/page) |
| 4 | `POST` | `/api/v1/admin/events` | Bearer Token | `admin`, `editor` | Create draft event (multipart or JSON) |
| 5 | `GET` | `/api/v1/admin/events/{event}` | Bearer Token | `admin`, `editor` | Admin event details by ID |
| 6 | `PUT\|PATCH` | `/api/v1/admin/events/{event}` | Bearer Token | `admin`, `editor` | Update existing event |
| 7 | `POST\|PATCH` | `/api/v1/admin/events/{event}/publish` | Bearer Token | `admin`, `editor` | Publish event |
| 8 | `POST\|PATCH` | `/api/v1/admin/events/{event}/unpublish` | Bearer Token | `admin`, `editor` | Unpublish event back to draft |
| 9 | `DELETE` | `/api/v1/admin/events/{event}` | Bearer Token | `admin` only | Permanently delete event |

---

### Detailed Endpoints

#### 1. Public Event Listing
- **Route**: `GET /api/v1/events`
- **Query Parameters**:
  - `timeline` (`string`, optional): `upcoming` (default), `past`, `latest`, `all`.
  - `category` (`string`, optional): Matches `category_en` or `category_ar`.
  - `per_page` (`integer`, optional): Defaults to `3` (accepted: 1–100).
  - `page` (`integer`, optional): Page number.
- **Headers**:
  - `Accept-Language`: `ar` or `en` (controls resolved `name`, `description`, `category`, `place`).
- **Response Format (200 OK)**:
```json
{
  "data": [
    {
      "id": 1,
      "slug": "global-future-media-summit-2026-1",
      "name": "Global Future Media Summit 2026",
      "name_en": "Global Future Media Summit 2026",
      "name_ar": "القمة العالمية للإعلام المستقبلي 2026",
      "description": "A premier gathering of media innovators...",
      "category": "Media",
      "category_en": "Media",
      "category_ar": "الإعلام",
      "place": "Riyadh International Convention Center",
      "place_en": "Riyadh International Convention Center",
      "place_ar": "مركز الرياض الدولي للمعارض والمؤتمرات",
      "event_date": "2026-11-20",
      "event_time": "18:30:00",
      "cover_image_url": "https://pub-your-id.r2.dev/events/covers/sample.webp",
      "url": "https://waaimedia.com/events/summit-2026"
    }
  ],
  "links": {
    "first": "https://api.waaimedia.com/api/v1/events?page=1",
    "last": "https://api.waaimedia.com/api/v1/events?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 3,
    "to": 1,
    "total": 1
  }
}
```

#### 2. Public Event Details by Slug
- **Route**: `GET /api/v1/events/{slug}`
- **Path Parameter**: `slug` (string)
- **Response (200 OK)**: Single event resource wrapped in `{"data": { ... }}`.
- **Drafts / Missing Slugs (404 Not Found)**:
```json
{
  "message": "Record not found."
}
```

#### 3. Admin Event Listing
- **Route**: `GET /api/v1/admin/events`
- **Headers**: `Authorization: Bearer {{token}}`
- **Query Parameters**:
  - `search` (`string`, optional): Case-insensitive match on `name_en` or `name_ar`.
  - `category` (`string`, optional): Match on `category_en` or `category_ar`.
  - `status` (`string`, optional): `draft` or `published`.
  - `timeline` (`string`, optional): `upcoming` or `past`.
  - `per_page` (`integer`, optional): Defaults to `10` (accepted: 1–100).
  - `page` (`integer`, optional): Page number.
- **Response Format (200 OK)**:
```json
{
  "data": [
    {
      "id": 1,
      "slug": "global-future-media-summit-2026-1",
      "status": "draft",
      "name_en": "Global Future Media Summit 2026",
      "name_ar": "القمة العالمية للإعلام المستقبلي 2026",
      "category_en": "Media",
      "category_ar": "الإعلام",
      "description_en": "A premier gathering of media innovators...",
      "description_ar": "تجمع رائد لنخبة من مبتكري الإعلام...",
      "place_en": "Riyadh International Convention Center",
      "place_ar": "مركز الرياض الدولي للمعارض والمؤتمرات",
      "event_date": "2026-11-20",
      "event_time": "18:30:00",
      "cover_image_url": "https://pub-your-id.r2.dev/events/covers/sample.webp",
      "url": "https://waaimedia.com/events/summit-2026",
      "published_at": null,
      "created_at": "2026-10-24T20:00:00.000000Z",
      "updated_at": "2026-10-24T20:00:00.000000Z"
    }
  ]
}
```

#### 4. Admin Create Draft Event
- **Route**: `POST /api/v1/admin/events`
- **Headers**:
  - `Authorization: Bearer {{token}}`
  - `Accept: application/json`
  - For file uploads: Postman sets `Content-Type: multipart/form-data; boundary=...` automatically.
- **Accepted Request Formats**:
  1. `multipart/form-data` with `cover_image` as binary file (`jpeg`, `jpg`, `png`, `webp`, max 10MB).
  2. `application/json` with `cover_image_url` string.
- **Status Code**: `201 Created`

#### 5. Admin Get Event Details
- **Route**: `GET /api/v1/admin/events/{event}`
- **Headers**: `Authorization: Bearer {{token}}`
- **Response (200 OK)**: Full admin representation with raw bilingual attributes and timestamps.

#### 6. Admin Update Event
- **Route**: `PUT|PATCH /api/v1/admin/events/{event}`
- **Headers**: `Authorization: Bearer {{token}}`
- **Status Code**: `200 OK`

#### 7. Admin Publish Event
- **Route**: `POST|PATCH /api/v1/admin/events/{event}/publish`
- **Headers**: `Authorization: Bearer {{token}}`
- **Request Body**: Empty `{}` if draft already contains all 11 required fields, or provide missing/updated fields.
- **Status Code**: `200 OK`

#### 8. Admin Unpublish Event
- **Route**: `POST|PATCH /api/v1/admin/events/{event}/unpublish`
- **Headers**: `Authorization: Bearer {{token}}`
- **Status Code**: `200 OK`
- **Response Format**:
```json
{
  "message": "Event converted to draft successfully.",
  "data": {
    "id": 1,
    "slug": "global-future-media-summit-2026-1",
    "status": "draft",
    "published_at": null
  }
}
```

#### 9. Admin Permanently Delete Event
- **Route**: `DELETE /api/v1/admin/events/{event}`
- **Headers**: `Authorization: Bearer {{token}}`
- **Role**: `admin` only.
- **Status Code**: `200 OK` (`403 Forbidden` if user role is `editor`).
- **Response Format**:
```json
{
  "message": "Event permanently deleted successfully."
}
```

---

## 6. Postman Collection Configuration Guide

The official Postman Collection is located at:
`docs/event-calendar/final/event-calendar.postman_collection.json`

### Import Instructions
1. Open Postman.
2. Click **Import** (top left).
3. Drag and drop `docs/event-calendar/final/event-calendar.postman_collection.json`.
4. The collection `Waai Media - Event Calendar API` will appear in your workspace.

### Collection Variables Configuration
Click on the collection name and select the **Variables** tab:

| Variable | Initial Value | Description |
|---|---|---|
| `base_url` | `https://api.waaimedia.com` | Base URL of the API (or `http://127.0.0.1:8000` for local dev) |
| `token` | *(empty)* | Bearer token obtained from `/api/v1/auth/login` |
| `event_id` | `1` | Automatically populated when running `Create Draft Event` |
| `slug` | `global-media-summit-2026-1` | Automatically populated when running `Create Draft Event` or `Publish Event` |

### Testing File Uploads in Postman
1. Select request `2.2 Admin - Create Draft Event (Multipart with Image)`.
2. Under the **Body** tab, ensure `form-data` is selected.
3. Locate the `cover_image` row (type: **File**).
4. Click **Select Files** and choose a local image (`.jpg`, `.png`, or `.webp` under 10MB).
5. Ensure Postman automatically generates the header (do not set `Content-Type` manually).
6. Send the request. Postman test scripts will automatically capture the generated `event_id` and `slug`.
