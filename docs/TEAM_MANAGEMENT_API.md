# Waai Media — Team Management API Documentation

Comprehensive technical specification and API reference for the Team Management module in Waai Media.

---

## 1. Overview & Architecture

The **Team Management** module manages executive and staff profile records (name, position, and profile photos stored in Cloudflare R2).

### Architecture Summary:
- **Public Website (Home Page & About Us)**: Open, unauthenticated read-only endpoint returning all team members ordered newest first.
- **Admin Dashboard (Management)**: Authenticated CRUD endpoints for administrators and editors to list, inspect, create, update, and permanently delete team members.
- **Photo Storage**: Profile images are stored in Cloudflare R2 under `team/photos/`. Uploaded files are inspected for authentic JPEG, PNG, or WebP headers up to 5 MB.
- **Identifiers**: Records are uniquely identified by numeric `id`. Slugs are not used.

---

## 2. Authentication & Authorization

| Context | Endpoint | HTTP Method | Guard & Middleware | Allowed Roles |
|:---|:---|:---|:---|:---|
| **Public Website** | `/api/v1/team-members` | `GET` | None (Unauthenticated) | Anyone / Anonymous |
| **Admin List** | `/api/v1/admin/team-members` | `GET` | `auth:sanctum`, `require.access`, `role:super_admin,admin,editor` | Super Admin, Admin, Editor |
| **Admin Create** | `/api/v1/admin/team-members` | `POST` | `auth:sanctum`, `require.access`, `role:super_admin,admin,editor` | Super Admin, Admin, Editor |
| **Admin Show** | `/api/v1/admin/team-members/{id}` | `GET` | `auth:sanctum`, `require.access`, `role:super_admin,admin,editor` | Super Admin, Admin, Editor |
| **Admin Update** | `/api/v1/admin/team-members/{id}` | `PUT`, `PATCH`, `POST` | `auth:sanctum`, `require.access`, `role:super_admin,admin,editor` | Super Admin, Admin, Editor |
| **Admin Delete** | `/api/v1/admin/team-members/{id}` | `DELETE` | `role:super_admin,admin` + Controller check | Super Admin, Admin strictly (Editor receives `403`) |

---

## 3. Database Schema Contract

**Table Name**: `team_members`

| Column | Type | Nullable | Default | Description |
|:---|:---|:---|:---|:---|
| `id` | `BIGINT UNSIGNED` | No | Auto-increment | Primary Key |
| `name` | `VARCHAR(255)` | No | None | Full display name |
| `position` | `VARCHAR(255)` | No | None | Job title / position in the company |
| `photo_path` | `VARCHAR(500)` | Yes | `NULL` | Relative Cloudflare R2 storage key (`team/photos/...`) |
| `created_at` | `TIMESTAMP` | Yes | `NULL` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | Yes | `NULL` | Record update timestamp |

**Indexes**:
- `idx_team_members_created_id` (`created_at DESC`, `id DESC`)

---

## 4. Endpoints Specification

### 4.1 Public List Team Members (Home Page & About Us)
Retrieve all team members for public display on the website.

- **URL:** `GET /api/v1/team-members`
- **Auth:** None
- **Headers:** `Accept: application/json`
- **Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Sara Ahmed",
      "position": "Editor in Chief",
      "photo_url": "https://pub-r2.waai.test/team/photos/3f2b8c1e-5d4a-4e7b-9a60-1c2d3e4f5a6b.jpg",
      "created_at": "2026-10-27T09:00:00.000000Z",
      "updated_at": "2026-10-27T09:00:00.000000Z"
    },
    {
      "id": 2,
      "name": "Omar Khalid",
      "position": "Cinematographer",
      "photo_url": null,
      "created_at": "2026-10-27T08:30:00.000000Z",
      "updated_at": "2026-10-27T08:30:00.000000Z"
    }
  ]
}
```

---

### 4.2 Admin List Team Members (Paginated)
Retrieve paginated team members for the administration dashboard.

- **URL:** `GET /api/v1/admin/team-members?page=1&per_page=10`
- **Auth:** `Bearer <token>` (`super_admin`, `admin`, `editor`)
- **Query Parameters:**
  - `page` (optional, integer >= 1)
  - `per_page` (optional, integer between 1 and 100, default: 10)
- **Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Sara Ahmed",
      "position": "Editor in Chief",
      "photo_url": "https://pub-r2.waai.test/team/photos/3f2b8c1e-5d4a-4e7b-9a60-1c2d3e4f5a6b.jpg",
      "created_at": "2026-10-27T09:00:00.000000Z",
      "updated_at": "2026-10-27T09:00:00.000000Z"
    }
  ],
  "links": {
    "first": "http://127.0.0.1:8000/api/v1/admin/team-members?page=1",
    "last": "http://127.0.0.1:8000/api/v1/admin/team-members?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 10,
    "to": 1,
    "total": 1
  }
}
```

---

### 4.3 Admin Create Team Member
Create a new team member record, optionally uploading a profile photo.

- **URL:** `POST /api/v1/admin/team-members`
- **Auth:** `Bearer <token>` (`super_admin`, `admin`, `editor`)
- **Content-Type:** `multipart/form-data` (or `application/json` if photo is omitted)
- **Fields:**
  - `name`: Required string, max 255 chars
  - `position`: Required string, max 255 chars
  - `photo`: Optional image file (JPEG, PNG, WebP, max 5 MB)
- **Response `201 Created`:**
```json
{
  "data": {
    "id": 1,
    "name": "Sara Ahmed",
    "position": "Editor in Chief",
    "photo_url": "https://pub-r2.waai.test/team/photos/3f2b8c1e-5d4a-4e7b-9a60-1c2d3e4f5a6b.jpg",
    "created_at": "2026-10-27T09:00:00.000000Z",
    "updated_at": "2026-10-27T09:00:00.000000Z"
  }
}
```

---

### 4.4 Admin Show Team Member
Inspect a single team member by numeric ID.

- **URL:** `GET /api/v1/admin/team-members/{id}`
- **Auth:** `Bearer <token>` (`super_admin`, `admin`, `editor`)
- **Response `200 OK`:**
```json
{
  "data": {
    "id": 1,
    "name": "Sara Ahmed",
    "position": "Editor in Chief",
    "photo_url": "https://pub-r2.waai.test/team/photos/3f2b8c1e-5d4a-4e7b-9a60-1c2d3e4f5a6b.jpg",
    "created_at": "2026-10-27T09:00:00.000000Z",
    "updated_at": "2026-10-27T09:00:00.000000Z"
  }
}
```

---

### 4.5 Admin Update Team Member
Update an existing team member's details or replace their profile photo.

- **URL:** `POST /api/v1/admin/team-members/{id}` (or `PUT`/`PATCH` when not uploading files)
- **Note on File Uploads:** Because PHP does not parse `multipart/form-data` on native `PUT` or `PATCH` requests, send updates that include a `photo` file using `POST`.
- **Fields (Partial Update Supported):**
  - `name`: Sometimes, required string, max 255 chars
  - `position`: Sometimes, required string, max 255 chars
  - `photo`: Optional replacement image file (JPEG, PNG, WebP, max 5 MB)
- **Response `200 OK`:**
```json
{
  "data": {
    "id": 1,
    "name": "Sara Ahmed Al-Ali",
    "position": "Managing Editor",
    "photo_url": "https://pub-r2.waai.test/team/photos/new-uuid.webp",
    "created_at": "2026-10-27T09:00:00.000000Z",
    "updated_at": "2026-10-27T10:15:00.000000Z"
  }
}
```

---

### 4.6 Admin Delete Team Member
Permanently remove a team member and delete their associated R2 photo.

- **URL:** `DELETE /api/v1/admin/team-members/{id}`
- **Auth:** `Bearer <token>` (`super_admin` or `admin` only; `editor` returns `403 Forbidden`)
- **Response `200 OK`:**
```json
{
  "message": "Team member deleted successfully."
}
```

---

## 5. Storage & Scheduled Maintenance

- **Prefix**: `team/photos/` in Cloudflare R2 bucket (`FILESYSTEM_DISK=r2`).
- **Orphan Cleanup Command**:
  ```bash
  php artisan team:clean-orphaned-photos --force --grace-hours=24 --max-deletions=50
  ```
  Runs automatically every Monday at 04:00 AM (configured in `routes/console.php`).
