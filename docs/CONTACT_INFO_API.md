# Waai Media — Contact Info API Documentation

This document describes the API contract and architecture for the **Contact Info Module** of the Waai Media backend platform (Laravel 13 API backend, Angular Admin Dashboard, and Angular Public Website).

The Contact Info module manages the official contact channels, social media profiles, physical address, and Google Maps location of Waai Media.

---

## 1. Module Overview & Core Architecture

### 1.1 Singleton Settings Pattern
- **One Logical Record:** The platform stores exactly one Contact Information record.
- **Database-Enforced Singleton:** The `contact_info` table uses a dedicated `singleton_lock` column (`TINYINT UNSIGNED DEFAULT 1 UNIQUE`) ensuring at the database engine level that no duplicate rows can ever be created.
- **Initial Unconfigured State:** When the database is fresh, the `contact_info` table is initially empty. Both public and admin `GET` endpoints return `HTTP 200 OK` with `{"data": null}`.
- **First-Time Initialization:** The first successful Admin `PUT` request creates the singleton record. Subsequent `PUT` requests update this existing record atomically.
- **Complete Form Submission (PUT):** The Angular Dashboard submits the complete 12-field form state on every "Save Changes" action. Partial `PATCH` updates or single-field update endpoints are neither required nor provided.

### 1.2 The 12 Contact Fields

| Field Name | Required | Database Type | Validation Rules | Description |
| :--- | :---: | :--- | :--- | :--- |
| `phone_1` | **Yes** | `VARCHAR(50)` | `required`, `string`, `min:5`, `max:50`, regex: `/^(\+?[0-9\s\-\(\)\.]+)$/` | Primary official telephone number. |
| `phone_2` | No | `VARCHAR(50)`, nullable | `nullable`, `string`, `min:5`, `max:50`, regex: `/^(\+?[0-9\s\-\(\)\.]+)$/` | Secondary telephone number. |
| `phone_3` | No | `VARCHAR(50)`, nullable | `nullable`, `string`, `min:5`, `max:50`, regex: `/^(\+?[0-9\s\-\(\)\.]+)$/` | Additional hotline or telephone number. |
| `official_email` | **Yes** | `VARCHAR(255)` | `required`, `string`, `email`, `max:255` | Official inquiries email address. |
| `facebook` | **Yes** | `VARCHAR(500)` | `required`, `string`, `url:http,https`, `max:500` | Official Facebook profile/page URL. |
| `instagram` | **Yes** | `VARCHAR(500)` | `required`, `string`, `url:http,https`, `max:500` | Official Instagram profile URL. |
| `youtube` | **Yes** | `VARCHAR(500)` | `required`, `string`, `url:http,https`, `max:500` | Official YouTube channel or video URL. |
| `linkedin` | **Yes** | `VARCHAR(500)` | `required`, `string`, `url:http,https`, `max:500` | Official LinkedIn company profile URL. |
| `tiktok` | **Yes** | `VARCHAR(500)` | `required`, `string`, `url:http,https`, `max:500` | Official TikTok account URL. |
| `whatsapp` | **Yes** | `VARCHAR(500)` | `required`, `string`, `url:http,https`, `max:500` | Official WhatsApp chat or click-to-chat URL. |
| `complete_address` | **Yes** | `TEXT` | `required`, `string`, `max:2000` | Full physical address of headquarters. |
| `google_maps_url` | **Yes** | `TEXT` | `required`, `string`, `url:http,https`, `max:2000` | Embed or shareable Google Maps location URL. |

---

## 2. Authorization & Security

| Role | Public Endpoint (`/contact-info`) | Admin Read (`/admin/contact-info`) | Admin Update (`/admin/contact-info`) |
| :--- | :---: | :---: | :---: |
| **Public / Guest** | `200 OK` | `401 Unauthorized` | `401 Unauthorized` |
| **Admin** (`role: admin`) | `200 OK` | `200 OK` | `200 OK` |
| **Editor** (`role: editor`) | `200 OK` | `200 OK` | `200 OK` |
| **Other / Disallowed Roles** | `200 OK` | `403 Forbidden` | `403 Forbidden` |

### Security Middleware Stack
1. `auth:sanctum`: Requires a valid Sanctum Bearer token in the `Authorization` header.
2. `require.access`: Enforces that only Access Tokens with the `access` ability are accepted. Tokens created with other abilities (e.g. `remember` or `refresh`) are strictly rejected with `403 Forbidden`.
3. `role:admin,editor`: Ensures the authenticated user possesses an administrative role (`admin` or `editor`).

---

## 3. Endpoints Summary

All routes are prefixed with `/api/v1`.

| Method | Endpoint | Access Level | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/v1/contact-info` | Public (Unauthenticated) | Returns the 12 contact fields. Returns `{"data": null}` if unconfigured. |
| `GET` | `/api/v1/admin/contact-info` | Admin, Editor | Returns the 12 contact fields for dashboard form initialization. Returns `{"data": null}` if unconfigured. |
| `PUT` | `/api/v1/admin/contact-info` | Admin, Editor | Creates the singleton record or updates it atomically. Returns updated contact info. |

---

## 4. Mandatory Business Rules & Validation Details

### 4.1 Flexible URL Validation
The seven URL fields (`facebook`, `instagram`, `youtube`, `linkedin`, `tiktok`, `whatsapp`, `google_maps_url`) use Laravel's standard `url:http,https` rule:
- **Accepted:** Any valid absolute HTTP or HTTPS URL, including shortened URLs (e.g., `goo.gl/maps`, `maps.app.goo.gl`, `wa.me`, `youtu.be`), long links with path, query parameters (`?q=...&z=15`), and hash fragments (`#location`).
- **No Domain Lock-in:** The backend does not lock down URLs to specific domain names or require specific username patterns.
- **Rejected:** Malformed strings, plain text, and non-web schemes (e.g., `javascript:`, `file:`, `ftp:`).

### 4.2 Safe Phone Number Normalization
In `UpdateContactInfoRequest::prepareForValidation()`:
- `trim()` is invoked **strictly** when the input value is a PHP string (`is_string($value)`).
- Empty or whitespace-only optional phone strings (`""` or `"   "`) are safely normalized to `null`.
- Non-string inputs (e.g., JSON arrays `[]` or nested objects `{}`) are preserved untouched so Laravel's validation engine cleanly rejects them with `HTTP 422 Unprocessable Content`, preventing any PHP `TypeError` or `500 Internal Server Error`.
- Valid international phone formatting (`+20 100 000 0000`, `+966-50-000-0000`, `01000000000`) is preserved.

### 4.3 Atomic Updates & Concurrency Safety
- **Transaction Safety:** All mutations execute inside `DB::transaction()`.
- **Row Locking:** Existing rows are retrieved using `lockForUpdate()` during updates.
- **Race Condition Protection:** If concurrent requests attempt to create the initial record simultaneously, a database unique violation (MySQL Error 1062 / SQLSTATE 23000) on `singleton_lock` is caught gracefully, and the request automatically falls back to updating the newly created record.
- **Rollback on Validation Failure:** If validation fails, zero database changes are committed.

---

## 5. API Endpoint Specifications

### 5.1 Public GET Contact Info

Retrieve published contact details for public display.

- **Route:** `GET /api/v1/contact-info`
- **Authentication:** None
- **Headers:**
  ```http
  Accept: application/json
  ```

#### Response: Unconfigured (`200 OK`)
```json
{
  "data": null
}
```

#### Response: Configured (`200 OK`)
```json
{
  "data": {
    "phone_1": "+201000000000",
    "phone_2": "+201100000000",
    "phone_3": null,
    "official_email": "info@waaimedia.com",
    "facebook": "https://facebook.com/waaimedia",
    "instagram": "https://instagram.com/waaimedia",
    "youtube": "https://youtube.com/@waaimedia",
    "linkedin": "https://linkedin.com/company/waaimedia",
    "tiktok": "https://tiktok.com/@waaimedia",
    "whatsapp": "https://wa.me/201000000000",
    "complete_address": "Damietta, New Damietta City, Central District, Egypt",
    "google_maps_url": "https://maps.app.goo.gl/abcdef123456"
  }
}
```
*Note: Internal fields (`id`, `singleton_lock`, `created_at`, `updated_at`) are never exposed.*

---

### 5.2 Admin GET Contact Info

Retrieve contact details for populating the Admin Dashboard form.

- **Route:** `GET /api/v1/admin/contact-info`
- **Authentication:** Sanctum Bearer Token (`admin` or `editor`)
- **Headers:**
  ```http
  Accept: application/json
  Authorization: Bearer <access_token>
  ```

#### Response: Unconfigured (`200 OK`)
```json
{
  "data": null
}
```

#### Response: Configured (`200 OK`)
```json
{
  "data": {
    "phone_1": "+201000000000",
    "phone_2": "+201100000000",
    "phone_3": null,
    "official_email": "info@waaimedia.com",
    "facebook": "https://facebook.com/waaimedia",
    "instagram": "https://instagram.com/waaimedia",
    "youtube": "https://youtube.com/@waaimedia",
    "linkedin": "https://linkedin.com/company/waaimedia",
    "tiktok": "https://tiktok.com/@waaimedia",
    "whatsapp": "https://wa.me/201000000000",
    "complete_address": "Damietta, New Damietta City, Central District, Egypt",
    "google_maps_url": "https://maps.app.goo.gl/abcdef123456"
  }
}
```

#### Error: Unauthenticated (`401 Unauthorized`)
```json
{
  "message": "Unauthenticated."
}
```

#### Error: Forbidden (`403 Forbidden`)
```json
{
  "message": "Forbidden. Insufficient permissions."
}
```

---

### 5.3 Admin PUT Contact Info

Initialize or update the singleton contact info record.

- **Route:** `PUT /api/v1/admin/contact-info`
- **Authentication:** Sanctum Bearer Token (`admin` or `editor`)
- **Headers:**
  ```http
  Accept: application/json
  Content-Type: application/json
  Authorization: Bearer <access_token>
  ```

#### Request Body
```json
{
  "phone_1": "+201000000000",
  "phone_2": "+201100000000",
  "phone_3": null,
  "official_email": "info@waaimedia.com",
  "facebook": "https://facebook.com/waaimedia",
  "instagram": "https://instagram.com/waaimedia",
  "youtube": "https://youtube.com/@waaimedia",
  "linkedin": "https://linkedin.com/company/waaimedia",
  "tiktok": "https://tiktok.com/@waaimedia",
  "whatsapp": "https://wa.me/201000000000",
  "complete_address": "Damietta, New Damietta City, Central District, Egypt",
  "google_maps_url": "https://maps.app.goo.gl/abcdef123456"
}
```

#### Success Response (`200 OK`)
```json
{
  "message": "Contact information updated successfully.",
  "data": {
    "phone_1": "+201000000000",
    "phone_2": "+201100000000",
    "phone_3": null,
    "official_email": "info@waaimedia.com",
    "facebook": "https://facebook.com/waaimedia",
    "instagram": "https://instagram.com/waaimedia",
    "youtube": "https://youtube.com/@waaimedia",
    "linkedin": "https://linkedin.com/company/waaimedia",
    "tiktok": "https://tiktok.com/@waaimedia",
    "whatsapp": "https://wa.me/201000000000",
    "complete_address": "Damietta, New Damietta City, Central District, Egypt",
    "google_maps_url": "https://maps.app.goo.gl/abcdef123456"
  }
}
```

#### Error: Validation Failed (`422 Unprocessable Content`)
```json
{
  "message": "The official email field must be a valid email address. (and 2 more errors)",
  "errors": {
    "official_email": [
      "The official email field must be a valid email address."
    ],
    "facebook": [
      "The facebook field must be a valid URL."
    ],
    "phone_1": [
      "The phone 1 field must be a valid phone number."
    ]
  }
}
```

---

## 6. Audit Logging

Every successful `PUT /api/v1/admin/contact-info` mutation is automatically logged using the backend's `ActivityLogger` service:
- **Action:** `contact_info_updated`
- **Logged Details:** User ID, IP address, user agent, and timestamp.
- **Sensitive Scrubbing:** Redacted securely if applicable; no sensitive credentials stored.

---

## 7. Frontend (Angular) Integration Contract

1. **Initialization:**
   - On page load, call `GET /api/v1/admin/contact-info`.
   - If `response.data === null`, pre-fill form fields with empty strings (or keep initial placeholder UI state).
   - If `response.data` is an object, populate all 12 form controls.

2. **Save Changes:**
   - User clicks **Save Changes**.
   - Frontend validates client-side and dispatches a single `PUT /api/v1/admin/contact-info` with the full 12-field payload.
   - On `200 OK`, display a success toast ("Contact information updated successfully.") and update local state.
   - On `422`, map validation error messages to their corresponding form inputs.

3. **Cancel Button:**
   - User clicks **Cancel**.
   - Discards dirty form changes and resets controls back to the last loaded or saved data.
   - **No network request** is made to the backend.
