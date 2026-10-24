# Waai Media — App Links API Documentation

This document describes the API contract and architecture for the **App Links Module** of the Waai Media backend platform (Laravel 13 API backend, Angular Admin Dashboard, and Angular Public Website).

The App Links module manages the official mobile application download links for Google Play Store, Apple App Store, and Huawei AppGallery (or other platforms).

---

## 1. Module Overview & Core Architecture

### 1.1 Singleton Settings Pattern
- **One Logical Record:** The platform stores at most one App Links record.
- **Database-Enforced Singleton:** The `app_links` table uses a dedicated `singleton_lock` column (`TINYINT UNSIGNED DEFAULT 1 UNIQUE`) ensuring at the database engine level that no duplicate rows can ever be created.
- **Initial Unconfigured State:** When the database is fresh, the `app_links` table is empty. The public `GET` endpoint returns `HTTP 200 OK` with `{"data": null}`.
- **First-Time Initialization:** The first successful `PUT` request creates the singleton record. Subsequent `PUT` requests update this existing record atomically.
- **Complete Form Submission (PUT):** The dashboard submits all three link fields together in one atomic request on every "Save Changes" action, even if only one field was edited.

### 1.2 The 3 App Link Fields

| Field Name | Required in Payload? | Nullable? | Database Type | Validation Rules | Description |
| :--- | :---: | :---: | :--- | :--- | :--- |
| `google_app_link` | **Yes** (`present`) | **Yes** | `VARCHAR(500)`, nullable | `present`, `nullable`, `string`, `url:http,https`, `max:500` | Google Play Store mobile app download URL. |
| `apple_app_store_link` | **Yes** (`present`) | **Yes** | `VARCHAR(500)`, nullable | `present`, `nullable`, `string`, `url:http,https`, `max:500` | Apple App Store iOS app download URL. |
| `app_gallery_link` | **Yes** (`present`) | **Yes** | `VARCHAR(500)`, nullable | `present`, `nullable`, `string`, `url:http,https`, `max:500` | Huawei AppGallery or other mobile platform download URL. |

---

## 2. Authorization & Security

| Role | Public Read (`GET /api/v1/app-links`) | Save Changes (`PUT /api/v1/app-links`) |
| :--- | :---: | :---: |
| **Public / Guest (Unauthenticated)** | `200 OK` | `401 Unauthorized` |
| **Admin** (`role: admin`) | `200 OK` | `200 OK` |
| **Editor** (`role: editor`) | `200 OK` | `200 OK` |
| **Viewer** (`role: viewer`) | `200 OK` | `403 Forbidden` |
| **Other / Custom Roles** | `200 OK` | `403 Forbidden` |

### Security Middleware Stack (PUT)
1. `auth:sanctum`: Requires a valid Sanctum Bearer token in the `Authorization` header.
2. `require.access`: Enforces that only Access Tokens with the `access` ability are accepted. Tokens created with other abilities (e.g. `remember` or `refresh`) are strictly rejected with `403 Forbidden`.
3. `role:admin,editor`: Ensures the authenticated user possesses an administrative role (`admin` or `editor`). Non-admin roles (such as `viewer`) receive `403 Forbidden`.

---

## 3. Endpoints Summary

All routes are prefixed with `/api/v1`.

| Method | Endpoint | Access Level | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/v1/app-links` | Public (Unauthenticated) | Returns the 3 mobile download links. Returns `{"data": null}` if unconfigured. |
| `PUT` | `/api/v1/app-links` | Admin, Editor | Atomically creates or updates all three mobile app links in one request. |

---

## 4. Detailed Endpoint Specifications

### 4.1 Retrieve App Links (Public)

- **Route:** `GET /api/v1/app-links`
- **Auth:** None (Public)
- **Headers:** `Accept: application/json`

#### Response: Unconfigured (Fresh Database)
```http
HTTP/1.1 200 OK
Content-Type: application/json

{
    "data": null
}
```

#### Response: Configured
```http
HTTP/1.1 200 OK
Content-Type: application/json

{
    "data": {
        "google_app_link": "https://play.google.com/store/apps/details?id=com.waaimedia.app",
        "apple_app_store_link": "https://apps.apple.com/app/waai-media/id123456789",
        "app_gallery_link": "https://appgallery.huawei.com/app/C123456789"
    }
}
```

*Note: Internal fields (`id`, `singleton_lock`, `created_at`, `updated_at`) are strictly excluded.*

---

### 4.2 Save Changes (Admin / Editor)

- **Route:** `PUT /api/v1/app-links`
- **Auth:** `Bearer <token>` (`admin` or `editor`)
- **Headers:**
  - `Authorization: Bearer <access_token>`
  - `Accept: application/json`
  - `Content-Type: application/json`

#### Request Payload (All fields populated)
```json
{
    "google_app_link": "https://play.google.com/store/apps/details?id=com.waaimedia.app",
    "apple_app_store_link": "https://apps.apple.com/app/waai-media/id123456789",
    "app_gallery_link": "https://appgallery.huawei.com/app/C123456789"
}
```

#### Request Payload (Partial fields configured, unlinked platforms as null)
```json
{
    "google_app_link": "https://play.google.com/store/apps/details?id=com.waaimedia.app",
    "apple_app_store_link": null,
    "app_gallery_link": null
}
```

#### Success Response (200 OK)
```http
HTTP/1.1 200 OK
Content-Type: application/json

{
    "message": "App download links updated successfully.",
    "data": {
        "google_app_link": "https://play.google.com/store/apps/details?id=com.waaimedia.app",
        "apple_app_store_link": null,
        "app_gallery_link": null
    }
}
```

---

### 4.3 Error Responses

#### 401 Unauthorized (Unauthenticated or Expired Token)
```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json

{
    "message": "Unauthenticated."
}
```

#### 403 Forbidden (Insufficient Token Ability)
```http
HTTP/1.1 403 Forbidden
Content-Type: application/json

{
    "message": "Forbidden. This endpoint requires an access token."
}
```

#### 403 Forbidden (Insufficient Role Privileges — Viewer)
```http
HTTP/1.1 403 Forbidden
Content-Type: application/json

{
    "message": "Forbidden. Insufficient permissions."
}
```

#### 422 Unprocessable Content (Missing Required Field)
```http
HTTP/1.1 422 Unprocessable Content
Content-Type: application/json

{
    "message": "The app gallery link field must be present.",
    "errors": {
        "app_gallery_link": [
            "The app gallery link field must be present."
        ]
    }
}
```

#### 422 Unprocessable Content (Malformed URL or Unsupported Scheme)
```http
HTTP/1.1 422 Unprocessable Content
Content-Type: application/json

{
    "message": "The google app link must be a valid HTTP or HTTPS URL.",
    "errors": {
        "google_app_link": [
            "The google app link must be a valid HTTP or HTTPS URL."
        ]
    }
}
```

---

## 5. Concurrency & Deadlock Architecture

1. **Deadlock Retries:** All updates are wrapped in `DB::transaction(callback, 3)`. Under InnoDB, if two concurrent requests hit a gap-lock deadlock during initial creation, Laravel's transaction manager intercepts the deadlock, rolls back, and retries up to 3 times.
2. **Duplicate-Key Race Recovery:** If Process B hits a duplicate key collision (MySQL Error 1062) on the unique constraint `app_links_singleton_lock_unique`, the controller catches this specific constraint error, acquires a row lock (`lockForUpdate()`) on the committed record via a Current Read, applies the update, and commits cleanly.
3. **Audit Logging:** Every successful update creates an immutable audit trail entry in `activity_logs` with action `app_links_updated`.
