# Waai Media — Clients Management API Documentation

Comprehensive technical specification and API reference for the Clients Management module in the Waai Media Laravel API.

---

## 1. Overview & Architecture

The **Clients Management** module manages corporate and brand partner records (name and logos stored in Cloudflare R2).

### Architecture Summary:
- **Public Website (Home Page)**: Open, unauthenticated read-only endpoint returning paginated client records with only essential public fields (`id`, `name`, `logo_url`).
- **Admin Dashboard (Management)**: Authenticated CRUD endpoints for administrators and editors to list, inspect, create, update, and permanently delete client records.
- **Logo Storage**: Logos are stored in Cloudflare R2 under `clients/logos/`. Uploaded files are inspected for authentic JPEG, PNG, or WebP content headers up to 5 MB.
- **Identifiers**: Records are uniquely identified by numeric `id`. Slugs are not used.

---

## 2. Environment & Postman Prerequisites

When importing [`docs/Waai_Media_Clients_Management_API.postman_collection.json`](file:///c:/Projects/Waai_media/docs/Waai_Media_Clients_Management_API.postman_collection.json), select the [`docs/Waai_Media_Local.postman_environment.json`](file:///c:/Projects/Waai_media/docs/Waai_Media_Local.postman_environment.json) environment.

The collection reuses established environment variables:
- `base_url`: Target server URL (e.g. `http://127.0.0.1:8000`).
- `admin_token`: Bearer access token for Super Admin or Admin user.
- `editor_token`: Bearer access token for Editor user.
- `client_id`: Automatically captured after running the Create request.

---

## 3. Authentication & Authorization

| Context | Endpoint | HTTP Method | Guard & Middleware | Allowed Roles |
|:---|:---|:---|:---|:---|
| **Public Website** | `/api/v1/clients` | `GET` | None (Unauthenticated) | Anyone / Anonymous |
| **Admin List** | `/api/v1/admin/clients` | `GET` | `auth:sanctum`, `require.access`, `role:super_admin,admin,editor` | Super Admin, Admin, Editor |
| **Admin Create** | `/api/v1/admin/clients` | `POST` | `auth:sanctum`, `require.access`, `role:super_admin,admin,editor` | Super Admin, Admin, Editor |
| **Admin Show** | `/api/v1/admin/clients/{id}` | `GET` | `auth:sanctum`, `require.access`, `role:super_admin,admin,editor` | Super Admin, Admin, Editor |
| **Admin Update** | `/api/v1/admin/clients/{id}` | `PUT`, `PATCH`, `POST` | `auth:sanctum`, `require.access`, `role:super_admin,admin,editor` | Super Admin, Admin, Editor |
| **Admin Delete** | `/api/v1/admin/clients/{id}` | `DELETE` | `role:super_admin,admin` + Controller check | Super Admin, Admin strictly (Editor receives `403`) |

---

## 4. Database Schema Contract

**Table Name**: `clients`

| Column | Type | Nullable | Default | Description |
|:---|:---|:---|:---|:---|
| `id` | `BIGINT UNSIGNED` | No | Auto-increment | Primary Key |
| `name` | `VARCHAR(255)` | No | None | Client business / brand name |
| `logo_path` | `VARCHAR(500)` | No | None | Relative Cloudflare R2 storage key (`clients/logos/{uuid}.{ext}`) |
| `created_at` | `TIMESTAMP` | Yes | `NULL` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | Yes | `NULL` | Record update timestamp |

**Indexes**:
- `idx_clients_created_id` (`created_at DESC`, `id DESC`) for stable, deterministic pagination.

---

## 5. Endpoints Specification

### 5.1 Public List Clients (Home Page)
Retrieve paginated client records for the public website.

- **URL:** `GET /api/v1/clients`
- **Auth:** None (Unauthenticated)
- **Headers:** `Accept: application/json`
- **Query Parameters:**
  - `page` (optional, integer >= 1)
  - `per_page` (optional, integer between 1 and 100, default: 10)
- **Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Horizon Group",
      "logo_url": "https://pub-r2.waai.test/clients/logos/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d.png"
    }
  ],
  "links": {
    "first": "http://127.0.0.1:8000/api/v1/clients?page=1",
    "last": "http://127.0.0.1:8000/api/v1/clients?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "http://127.0.0.1:8000/api/v1/clients",
    "per_page": 10,
    "to": 1,
    "total": 1
  }
}
```
*Note: Public response strictly withholds internal storage keys (`logo_path`) and audit timestamps (`created_at`, `updated_at`).*

---

### 5.2 Admin List Clients (Paginated)
Retrieve paginated client records for the administration dashboard.

- **URL:** `GET /api/v1/admin/clients`
- **Auth:** `Bearer <token>` (`super_admin`, `admin`, `editor`)
- **Headers:** `Accept: application/json`
- **Query Parameters:**
  - `page` (optional, integer >= 1)
  - `per_page` (optional, integer between 1 and 100, default: 10)
- **Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Horizon Group",
      "logo_url": "https://pub-r2.waai.test/clients/logos/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d.png",
      "created_at": "2026-10-27T20:25:00.000000Z",
      "updated_at": "2026-10-27T20:25:00.000000Z"
    }
  ],
  "links": {
    "first": "http://127.0.0.1:8000/api/v1/admin/clients?page=1",
    "last": "http://127.0.0.1:8000/api/v1/admin/clients?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "http://127.0.0.1:8000/api/v1/admin/clients",
    "per_page": 10,
    "to": 1,
    "total": 1
  }
}
```

---

### 5.3 Admin Create Client
Create a new client record with required name and logo.

- **URL:** `POST /api/v1/admin/clients`
- **Auth:** `Bearer <token>` (`super_admin`, `admin`, `editor`)
- **Headers:** `Accept: application/json`
- **Content-Type:** `multipart/form-data` *(Do NOT set manual Content-Type in Postman; let Postman generate the boundary)*
- **Body Fields (form-data):**
  - `name`: Required string, maximum 255 characters.
  - `logo`: **Required** image file (JPEG, PNG, or WebP up to 5 MB).
- **Response `201 Created`:**
```json
{
  "data": {
    "id": 1,
    "name": "Horizon Group",
    "logo_url": "https://pub-r2.waai.test/clients/logos/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d.png",
    "created_at": "2026-10-27T20:25:00.000000Z",
    "updated_at": "2026-10-27T20:25:00.000000Z"
  }
}
```

---

### 5.4 Admin Show Client
Inspect a single client by numeric ID.

- **URL:** `GET /api/v1/admin/clients/{id}`
- **Auth:** `Bearer <token>` (`super_admin`, `admin`, `editor`)
- **Headers:** `Accept: application/json`
- **Response `200 OK`:**
```json
{
  "data": {
    "id": 1,
    "name": "Horizon Group",
    "logo_url": "https://pub-r2.waai.test/clients/logos/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d.png",
    "created_at": "2026-10-27T20:25:00.000000Z",
    "updated_at": "2026-10-27T20:25:00.000000Z"
  }
}
```
- **Response `404 Not Found` (when missing):**
```json
{
  "message": "The requested resource was not found."
}
```

---

### 5.5 Admin Update Client
Update client name, replacement logo, or both.

#### Mode A: Update Name Only (JSON PATCH)
- **URL:** `PATCH /api/v1/admin/clients/{id}`
- **Headers:** `Accept: application/json`, `Content-Type: application/json`
- **Body (raw JSON):**
```json
{
  "name": "Horizon International"
}
```
- **Response `200 OK`:** Existing `logo_url` and R2 object are preserved.

#### Mode B: Replace Logo Only (Multipart POST)
- **URL:** `POST /api/v1/admin/clients/{id}` *(PHP does not parse multipart on native PUT/PATCH, so POST is used)*
- **Headers:** `Accept: application/json`
- **Body (form-data):**
  - `_method`: `PATCH`
  - `logo`: Replacement image file (JPEG, PNG, or WebP up to 5 MB)
- **Response `200 OK`:** Existing name is preserved; new logo is persisted and previous R2 object deleted.

#### Mode C: Update Both Name and Logo (Multipart POST)
- **URL:** `POST /api/v1/admin/clients/{id}`
- **Headers:** `Accept: application/json`
- **Body (form-data):**
  - `_method`: `PATCH`
  - `name`: `Horizon Media Global`
  - `logo`: Replacement image file

---

### 5.6 Admin Delete Client
Permanently delete a client record and its R2 logo.

- **URL:** `DELETE /api/v1/admin/clients/{id}`
- **Auth:** `Bearer <token>` (`super_admin` and `admin` only; `editor` returns `403 Forbidden`)
- **Headers:** `Accept: application/json`
- **Response `200 OK` (Admin / Super Admin):**
```json
{
  "message": "Client deleted successfully."
}
```
- **Response `403 Forbidden` (Editor):**
```json
{
  "message": "Forbidden. Editors are strictly prohibited from performing delete operations."
}
```

---

## 6. Storage & Orphan Cleanup Maintenance

- **R2 Prefix**: `clients/logos/` in Cloudflare R2 bucket.
- **Key Format**: `clients/logos/{uuid}.{ext}` (e.g. `clients/logos/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d.png`).
- **Orphan Cleanup Command**:
  ```bash
  php artisan clients:clean-orphaned-logos --force --grace-hours=24 --max-deletions=50
  ```
- **Scheduled Run**: Automatically registered in `routes/console.php` to run weekly every Monday at 04:30 AM (`0 4 * * 1`) with atomic overlapping prevention (`withoutOverlapping(120)`).

---

## 7. Server Prerequisites & Testing Tips

1. **PHP Upload Limits**:
   - The validation rules enforce up to 5 MB (`max:5120`).
   - Ensure the server's `php.ini` has `upload_max_filesize = 8M` and `post_max_size = 10M` (or higher) to prevent silent HTTP truncation before reaching Laravel.
2. **Postman Testing Steps**:
   - Step 1: Run `GET Public Clients` to view existing public records.
   - Step 2: Run `POST Create Client` (ensure you select a local PNG/JPG file in the `logo` form-data field). This automatically sets `client_id` in collection variables.
   - Step 3: Run `GET Show Client` to inspect the newly created client.
   - Step 4: Run `PATCH Update Name Only` to verify name updates without touching the logo.
   - Step 5: Run `POST Replace Logo Only` to verify replacement.
   - Step 6: Run `DELETE as Editor` to verify that `403 Forbidden` is returned.
   - Step 7: Run `DELETE as Admin` to verify successful deletion.
