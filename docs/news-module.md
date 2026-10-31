# Waai Media — News Module API Documentation

Authoritative technical reference, security specification, and local testing guide for the **News Module** in the Waai Media Laravel API backend.

---

## A. Module Overview

### 1. Purpose and Supported Features
The **News Module** provides bilingual (Arabic and English) news article management for the Angular Admin Dashboard and public content delivery for the Waai Media website:

- **Iterative Drafts & Publication Lifecycle**: Create drafts with minimal fields (at least one Arabic or English title and a valid news leaf category), iteratively edit fields, publish when all bilingual fields and a featured image are complete, unpublish back to draft, and republish.
- **Immutable Slugs & Preserved Publication Timestamps**:
  - Slugs are generated from `title_en` and the article database ID (`{normalized-title-en}-{id}`).
  - Drafts with only an Arabic title have `slug: null` until an English title is provided.
  - While an article has never been published (`published_at === null`), updating `title_en` regenerates the slug.
  - On first publication, `published_at` is set to `now()` and `slug` becomes **permanently immutable**. Subsequent edits, unpublishing, saving as draft, or republishing never overwrite `published_at` or `slug`.
- **Standardized Admin JSON Contract (`NewsAdminResource`)**:
  - Returns a clean, canonical administrative representation containing `id`, `slug`, `status`, `title_ar`, `title_en`, `author_name_ar`, `author_name_en`, `short_description_ar`, `short_description_en`, `content_ar`, `content_en`, `category_id`, `category` (`id`, `name_ar`, `name_en`, `parent_id`, `parent`), `featured_image_id`, `featured_image_url`, `editor_images`, `published_at`, `created_at`, and `updated_at`.
  - Redundant localized convenience fields (`title`, `author_name`, `short_description`), duplicate top-level category name fields (`category_name_ar`, `category_name_en`), and redundant `featured_image` object wrappers are excluded from the Admin response.
- **Shared Categories Hierarchy**:
  - Reuses the shared `categories` table (`type = 'news'`).
  - Supports 9 top-level News categories (`health`, `food`, `fashion-beauty`, `travel`, `economy`, `sports`, `entertainment`, `podcast`, `events`) and 4 child subcategories under `entertainment` (`celebrities`, `in-theaters`, `tv-shows`, `book-reviews`).
  - **Assignment Rule**: Organizational parent categories (`entertainment`, which has child subcategories) cannot be directly assigned to an article (`422 Unprocessable Entity`); articles must be assigned to a leaf category or subcategory.
  - **Filtering Rule**: Filtering by a parent category (`category=entertainment` or `category_id={{entertainment_parent_id}}`) automatically expands to return articles across all of its child subcategories.
- **Cloudflare R2 Media Management & Staged Deletion**:
  - Featured images are stored under `news/covers/{uuid}.{ext}` and rich-text editor images under `news/editor/{uuid}.{ext}` (`jpeg`, `jpg`, `png`, `webp`, max 10 MB).
  - Unreferenced images detached during article updates or deletions are cleaned up after database transaction commit using a 3-phase staged deletion protocol (`deleting_at` row claim -> external R2 delete outside any DB transaction -> DB row delete), with automatic background retries via `DeleteUnreferencedNewsImagesJob`.
- **HTML5 DOM & Symfony HtmlSanitizer Protection**:
  - Rich-text fields (`content_ar`, `content_en`) are parsed via PHP's native HTML5 DOM parser (`\Dom\HTMLDocument`) and sanitized with Symfony `HtmlSanitizer`.
  - Strips `<script>`, `<style>`, `<iframe>`, `<object>`, `<embed>`, `<form>`, `<input>`, `<svg>`, `<math>`, inline event handlers (`onclick`, `onerror`, etc.), and `javascript:`/`data:` URLs.
  - Rejects base64 `data:` images, unregistered external image URLs, non-existent `data-image-id` attributes, and mismatched `data-image-id`/`src` pairs with `422 Unprocessable Entity`.

### 2. Authentication Requirements
- **Public Endpoints** (`/api/v1/news`, `/api/v1/news/latest`, `/api/v1/news/{identifier}`, `/api/v1/categories`): No authentication required.
- **Admin Endpoints** (`/api/v1/admin/news/*`): Protected by `auth:sanctum`, `require.access`, and `role:super_admin,admin,editor` middleware.
  - Requires an `Authorization: Bearer {{admin_token}}` header containing a valid Sanctum personal access token with the `'access'` ability.
  - Remember tokens (which only have the `'refresh'` ability) are rejected with `403 Forbidden` (`"Forbidden. This endpoint requires an access token."`).

### 3. Supported User Roles and Permissions

| Role | View & List Admin News | Upload Images | Create & Edit Drafts | Publish & Unpublish | Associate Unattached Image Uploaded by Another User | Permanently Delete News (`DELETE`) |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **`super_admin`** | Yes | Yes | Yes | Yes | Yes (Admin Override) | **Yes** |
| **`admin`** | Yes | Yes | Yes | Yes | Yes (Admin Override) | **Yes** |
| **`editor`** | Yes | Yes | Yes | Yes | **No** (`422` rejected; may only use own unattached uploads or already-attached/shared library images) | **No** (`403 Forbidden`) |
| **Unauthenticated / Other** | No (`401` / `403`) | No (`401` / `403`) | No (`401` / `403`) | No (`401` / `403`) | No | No |

---

## B. Endpoints Reference

### Summary Table

| # | Scope | Method | Route | Auth / Role | Description |
| :--- | :--- | :--- | :--- | :--- | :--- |
| 1 | Public | `GET` | `/api/v1/news/latest` | Public (None) | Latest published news for homepage (default 3) |
| 2 | Public | `GET` | `/api/v1/news` | Public (None) | Paginated published news listing (default 10/page) |
| 3 | Public | `GET` | `/api/v1/news/{identifier}` | Public (None) | Single published news article by slug or ID |
| 4 | Public (Shared) | `GET` | `/api/v1/categories?type=news` | Public (None) | Hierarchical News categories list |
| 5 | Admin | `POST` | `/api/v1/admin/news/images` | Bearer (`super_admin`, `admin`, `editor`) | Upload cover or editor image to Cloudflare R2 |
| 6 | Admin | `GET` | `/api/v1/admin/news` | Bearer (`super_admin`, `admin`, `editor`) | Paginated admin news listing with search & filters |
| 7 | Admin | `POST` | `/api/v1/admin/news` | Bearer (`super_admin`, `admin`, `editor`) | Create news article (draft or immediate publish) |
| 8 | Admin | `GET` | `/api/v1/admin/news/{news}` | Bearer (`super_admin`, `admin`, `editor`) | Retrieve complete news article for editing |
| 9 | Admin | `PUT\|PATCH\|POST` | `/api/v1/admin/news/{news}` | Bearer (`super_admin`, `admin`, `editor`) | Update news article (JSON or multipart `_method=PATCH`) |
| 10 | Admin | `POST\|PATCH` | `/api/v1/admin/news/{news}/publish` | Bearer (`super_admin`, `admin`, `editor`) | Publish or republish a news article |
| 11 | Admin | `POST\|PATCH` | `/api/v1/admin/news/{news}/unpublish` | Bearer (`super_admin`, `admin`, `editor`) | Unpublish article back to draft |
| 12 | Admin | `DELETE` | `/api/v1/admin/news/{news}` | Bearer (`super_admin`, `admin` **only**) | Permanently delete news article |

---

### Part 1: Public Endpoints

#### 1. Get Latest Published News (Homepage)
- **Method & Route**: `GET /api/v1/news/latest`
- **Authentication**: None (Public)
- **Query Parameters**:
  - `limit` (`integer`, optional, `min:1`, `max:100`, default: `3`): Number of articles to return.
  - `category_id` (`integer`, optional): Must belong to `type = 'news'`. If a parent category ID is passed, includes all child subcategories.
  - `category` (`string`, optional, `max:150`): Category slug, English name, Arabic name, or numeric ID.
- **Headers**:
  - `Accept: application/json`
  - `Accept-Language: en` or `ar` (optional; negotiates `title`, `author_name`, `short_description`, `content`, and `category_name`).
- **Success Response (`200 OK`)**:
```json
{
  "data": [
    {
      "id": 12,
      "slug": "latest-celebrity-news-12",
      "title": "Latest Celebrity News",
      "title_ar": "أحدث أخبار المشاهير",
      "title_en": "Latest Celebrity News",
      "author_name": "Sara Al-Mansour",
      "author_name_ar": "سارة المنصور",
      "author_name_en": "Sara Al-Mansour",
      "short_description": "Comprehensive coverage of top artistic events.",
      "short_description_ar": "تغطية شاملة لأهم الفعاليات الفنية.",
      "short_description_en": "Comprehensive coverage of top artistic events.",
      "content": "<p>Full article details in English.</p>",
      "content_ar": "<p>تفاصيل الخبر باللغة العربية.</p>",
      "content_en": "<p>Full article details in English.</p>",
      "category_id": 10,
      "category_name": "Celebrities",
      "category_name_ar": "المشاهير",
      "category_name_en": "Celebrities",
      "category": {
        "id": 10,
        "slug": "celebrities",
        "name": "Celebrities",
        "name_ar": "المشاهير",
        "name_en": "Celebrities",
        "parent_id": 7,
        "parent": {
          "id": 7,
          "slug": "entertainment",
          "name": "Entertainment",
          "name_ar": "الترفيه",
          "name_en": "Entertainment"
        }
      },
      "featured_image_url": "https://pub-r2.example.com/news/covers/550e8400-e29b-41d4-a716-446655440000.webp",
      "published_at": "2026-10-28T22:15:00.000000Z"
    }
  ]
}
```
- **Error Responses**:
  - `422 Unprocessable Entity`: Invalid `limit` (`< 1` or `> 100`) or `category_id` not belonging to the `news` module (`"The selected category does not belong to the news module."`).

---

#### 2. List Published News
- **Method & Route**: `GET /api/v1/news`
- **Authentication**: None (Public)
- **Query Parameters**:
  - `per_page` (`integer`, optional, `min:1`, `max:100`, default: `10`): Records per page.
  - `page` (`integer`, optional, `min:1`, default: `1`): Page number.
  - `limit` (`integer`, optional, `min:1`, `max:100`): When `limit` is provided and both `per_page` and `page` are omitted, returns an unpaginated `{"data": [...]}` list capped at `limit`.
  - `category_id` (`integer`, optional): Filter by News category ID (supports parent category expansion).
  - `category` (`string`, optional, `max:150`): Filter by category slug (`health`, `entertainment`, `celebrities`), English name, or Arabic name. Unknown category strings return an empty `data: []` collection (`200 OK`).
- **Sorting**: Ordered by `published_at DESC, id DESC`. Only articles with `status = 'published'`, `published_at IS NOT NULL`, and `published_at <= now()` are returned.
- **Success Response (`200 OK` Paginated)**:
  Returns `{"data": [...], "links": {"first": ..., "last": ..., "prev": ..., "next": ...}, "meta": {"current_page": 1, "per_page": 10, "total": ...}}` where each item matches `NewsPublicResource`.
- **Error Responses**:
  - `422 Unprocessable Entity`: Invalid pagination parameters or non-news `category_id`.

---

#### 3. Get Single Published News Article
- **Method & Route**: `GET /api/v1/news/{identifier}`
- **Authentication**: None (Public)
- **Path Parameters**:
  - `identifier` (`string` or `integer`, required): The article's `slug` (e.g. `latest-celebrity-news-12`) or numeric `id` (e.g. `12`).
- **Headers**:
  - `Accept: application/json`
  - `Accept-Language: en` or `ar`
- **Success Response (`200 OK`)**:
  `{"data": { ...NewsPublicResource... }}`
- **Error Responses**:
  - `404 Not Found`: Returned if the article does not exist, is in `draft` status, has `published_at = null`, or has `published_at > now()`:
    ```json
    {
      "message": "The requested resource was not found."
    }
    ```

---

### Part 2: Admin Endpoints

#### 4. Upload News Image (Cover or Editor)
- **Method & Route**: `POST /api/v1/admin/news/images`
- **Authentication**: Required (`Authorization: Bearer {{admin_token}}`)
- **Allowed Roles**: `super_admin`, `admin`, `editor`
- **Request Format**: `multipart/form-data`
- **Fields**:
  - `image` (`file`, required — `file` field name is also accepted as an alias if `image` is omitted): Image file (`mimes:jpeg,jpg,png,webp`, max `10240` KB / 10 MB; verified by actual binary MIME inspection and `getimagesize()`).
  - `type` (`string`, optional, `in:featured,cover,editor`, default: `editor`):
    - `featured` or `cover` stores under `news/covers/{uuid}.{ext}`.
    - `editor` (or omitted) stores under `news/editor/{uuid}.{ext}`.
- **Success Response (`201 Created`)**:
```json
{
  "success": true,
  "data": {
    "id": 15,
    "url": "https://pub-r2.example.com/news/editor/8f9d2a10-4b3c-4d5e-9f1a-2b3c4d5e6f70.webp"
  }
}
```
- **Error Responses**:
  - `401 Unauthenticated`: Missing or invalid bearer token.
  - `403 Forbidden`: Token lacks `'access'` ability or user role is not authorized.
  - `422 Unprocessable Entity`: Missing file, unsupported MIME/extension, corrupt image data, or file > 10 MB.
  - `502 Bad Gateway`: Upstream Cloudflare R2 upload failure (`ImageUploadException`); no database record is persisted.

---

#### 5. List News Articles (Admin Dashboard)
- **Method & Route**: `GET /api/v1/admin/news`
- **Authentication**: Required (`Authorization: Bearer {{admin_token}}`)
- **Allowed Roles**: `super_admin`, `admin`, `editor`
- **Query Parameters**:
  - `search` (`string`, optional, `max:255`): Case-insensitive substring search across `title_en` and `title_ar`. Wildcard characters (`%`, `_`, `!`) are escaped literally (`ESCAPE '!'`).
  - `category_id` (`integer`, optional): Filter by News category ID (passing the `entertainment` parent ID returns articles in all Entertainment subcategories; passing a non-news category ID returns `422`).
  - `category` (`string`, optional, `max:150`): Filter by category slug, English name, Arabic name, or ID.
  - `status` (`string`, optional, `in:all,draft,published`): Filter by status (`all` or omitted returns both drafts and published articles).
  - `per_page` (`integer`, optional, `min:1`, `max:100`, default: `10`): Page size.
  - `page` (`integer`, optional, `min:1`, default: `1`): Page number.
- **Sorting**: Ordered by `created_at DESC, id DESC`.
- **Success Response (`200 OK`)**:
```json
{
  "data": [
    {
      "id": 12,
      "slug": "latest-celebrity-news-12",
      "status": "published",
      "title_ar": "أحدث أخبار المشاهير",
      "title_en": "Latest Celebrity News",
      "author_name_ar": "سارة المنصور",
      "author_name_en": "Sara Al-Mansour",
      "short_description_ar": "تغطية شاملة لأهم الفعاليات الفنية.",
      "short_description_en": "Comprehensive coverage of top artistic events.",
      "content_ar": "<p>تفاصيل الخبر باللغة العربية.</p>",
      "content_en": "<p>Full article details in English.</p>",
      "category_id": 10,
      "category": {
        "id": 10,
        "name_ar": "المشاهير",
        "name_en": "Celebrities",
        "parent_id": 7,
        "parent": {
          "id": 7,
          "name_ar": "الترفيه",
          "name_en": "Entertainment"
        }
      },
      "featured_image_id": 14,
      "featured_image_url": "https://pub-r2.example.com/news/covers/550e8400-e29b-41d4-a716-446655440000.webp",
      "editor_images": [
        {
          "id": 15,
          "url": "https://pub-r2.example.com/news/editor/8f9d2a10-4b3c-4d5e-9f1a-2b3c4d5e6f70.webp"
        }
      ],
      "published_at": "2026-10-28T22:15:00.000000Z",
      "created_at": "2026-10-28T22:10:00.000000Z",
      "updated_at": "2026-10-28T22:15:00.000000Z"
    }
  ],
  "links": {
    "first": "http://127.0.0.1:8000/api/v1/admin/news?page=1",
    "last": "http://127.0.0.1:8000/api/v1/admin/news?page=1",
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

#### 6. Create News Article (Draft or Immediate Publish)
- **Method & Route**: `POST /api/v1/admin/news`
- **Authentication**: Required (`Authorization: Bearer {{admin_token}}`)
- **Allowed Roles**: `super_admin`, `admin`, `editor`
- **Request Format**: `application/json` or `multipart/form-data`
- **Fields & Validation Rules**:
  - `category_id` (`integer`, **required**): Must exist in `categories` with `type = 'news'` and must not have child categories (i.e., `entertainment` parent is rejected; select a child subcategory such as `celebrities`).
  - `action` (`string`, optional, `in:draft,publish`): Set to `publish` to publish immediately; omit or set to `draft` to save as draft.
  - `status` (`string`, optional, `in:draft,published`): Alternative to `action`. If `action=publish` or `status=published`, full publication validation applies.
  - `title_ar` (`string`, `max:255`, required when publishing; for drafts, at least one of `title_ar` or `title_en` is required).
  - `title_en` (`string`, `max:255`, required when publishing; if provided on draft or publish, must contain Latin alphanumeric characters `[a-zA-Z0-9]` so a slug can be generated).
  - `author_name_ar` (`string`, `max:255`, required when publishing, nullable for draft).
  - `author_name_en` (`string`, `max:255`, required when publishing, nullable for draft).
  - `short_description_ar` (`string`, `max:2000`, required when publishing, nullable for draft).
  - `short_description_en` (`string`, `max:2000`, required when publishing, nullable for draft).
  - `content_ar` (`string`, max 500,000 bytes, required & must be non-empty after HTML sanitization when publishing, nullable for draft).
  - `content_en` (`string`, max 500,000 bytes, required & must be non-empty after HTML sanitization when publishing, nullable for draft).
  - `featured_image_id` (`integer`, nullable for draft; either `featured_image_id` or `featured_image` file is required when publishing): Must reference an existing `news_images` record with `deleting_at = null` that the current user is authorized to associate.
  - `featured_image` (`file`, optional, `mimes:jpeg,jpg,png,webp`, `max:10240` KB): Direct cover file upload in `multipart/form-data` requests.
  - `featured_image_url` (**prohibited** in request payload): Sending `featured_image_url` (even as `null`) returns `422` (`"Direct image URLs are not accepted. Upload an image file or provide a valid featured_image_id."`).
- **Success Response (`201 Created` — Draft Example)**:
```json
{
  "data": {
    "id": 1,
    "slug": "waai-media-launches-regional-creative-summit-1",
    "status": "draft",
    "title_ar": null,
    "title_en": "Waai Media Launches Regional Creative Summit",
    "author_name_ar": null,
    "author_name_en": null,
    "short_description_ar": null,
    "short_description_en": "Initial draft summary for the upcoming creative summit.",
    "content_ar": null,
    "content_en": null,
    "category_id": 1,
    "category": {
      "id": 1,
      "name_ar": "الصحة",
      "name_en": "Health",
      "parent_id": null,
      "parent": null
    },
    "featured_image_id": null,
    "featured_image_url": null,
    "editor_images": [],
    "published_at": null,
    "created_at": "2026-10-29T20:26:50.000000Z",
    "updated_at": "2026-10-29T20:26:50.000000Z"
  }
}
```
- **Error Responses**:
  - `422 Unprocessable Entity`: Missing required fields, neither `title_ar` nor `title_en` provided for draft, parent category selected, unauthorized/non-existent/deleting `featured_image_id`, invalid embedded `<img>` in `content_ar`/`content_en`, or empty content after sanitization.

---

#### 7. Retrieve News Article for Editing
- **Method & Route**: `GET /api/v1/admin/news/{news}`
- **Authentication**: Required (`Authorization: Bearer {{admin_token}}`)
- **Allowed Roles**: `super_admin`, `admin`, `editor`
- **Path Parameters**:
  - `news` (`integer`, required): Article database ID.
- **Success Response (`200 OK`)**:
  `{"data": { ...NewsAdminResource... }}`
- **Error Responses**:
  - `404 Not Found`: Article ID does not exist.

---

#### 8. Update News Article
- **Method & Route**: `PUT /api/v1/admin/news/{news}`, `PATCH /api/v1/admin/news/{news}`, or `POST /api/v1/admin/news/{news}` (with `_method=PATCH` for `multipart/form-data` uploads)
- **Authentication**: Required (`Authorization: Bearer {{admin_token}}`)
- **Allowed Roles**: `super_admin`, `admin`, `editor`
- **Request Format**:
  - `application/json` on `PATCH` or `PUT`
  - `multipart/form-data` on `POST` with `_method=PATCH` (sending `multipart/form-data` on a raw `PATCH` or `PUT` request is rejected with `422`: `"Multipart/form-data is not supported on PATCH or PUT. Send a POST request with _method=PATCH."`).
- **Partial Update Behavior**:
  - Every field uses `sometimes` validation. Omitted fields (`title_ar`, `title_en`, `content_ar`, `content_en`, `featured_image_id`, etc.) are resolved from the latest row-locked (`SELECT ... FOR UPDATE`) database record inside the update transaction.
  - Sending an empty payload with no updatable fields returns `422` (`"No updatable fields were provided."`).
- **Target Status Resolution**:
  - If `action=publish` or `status=published` is sent: target status is `published` (all mandatory bilingual fields and featured image must be non-empty on the effective merged article).
  - If `action=draft` or `status=draft` is sent: target status is `draft` (converts even a previously published article back to `draft` while preserving its original `published_at` and `slug`).
  - If neither `action` nor `status` is sent: preserves the article's current status (`draft` stays `draft`; `published` stays `published` and enforces published completeness).
- **Clearing vs. Replacing Featured Image**:
  - On a **draft**: sending `"featured_image_id": null` clears both `featured_image_id` and `featured_image_url` to `null` and cleans up the detached image if unreferenced.
  - On a **published** article (or when `targetStatus = published`): sending `"featured_image_id": null` without uploading a `featured_image` file is rejected with `422` (`"A featured image is required for a published news article."`).
- **Success Response (`200 OK`)**:
  `{"data": { ...NewsAdminResource... }}`

---

#### 9. Publish or Republish News Article
- **Method & Route**: `POST /api/v1/admin/news/{news}/publish` (also accepts `PATCH`)
- **Authentication**: Required (`Authorization: Bearer {{admin_token}}`)
- **Allowed Roles**: `super_admin`, `admin`, `editor`
- **Request Format**: `application/json` (may be empty `{}` if all fields are already saved on the draft) or `multipart/form-data`.
- **Optional Override Fields**: `category_id`, `title_ar`, `title_en`, `author_name_ar`, `author_name_en`, `short_description_ar`, `short_description_en`, `content_ar`, `content_en`, `featured_image_id`, `featured_image`.
- **Behavior**:
  - Validates that the effective merged state (request overrides + locked DB record) has all 8 bilingual text/HTML fields non-empty, a valid leaf news `category_id`, and a valid featured image.
  - Explicitly sending `"featured_image_id": null` without a `featured_image` file is rejected with `422` (`"A featured image is required for publication."`) and never falls back to a stale URL.
  - Sets `status = 'published'`.
  - Sets `published_at = now()` **only on first publication** (`$locked->published_at === null`); preserves the original `published_at` on subsequent republishes.
- **Success Response (`200 OK`)**:
  `{"data": { ...NewsAdminResource... }}`

---

#### 10. Unpublish News Article
- **Method & Route**: `POST /api/v1/admin/news/{news}/unpublish` (also accepts `PATCH`)
- **Authentication**: Required (`Authorization: Bearer {{admin_token}}`)
- **Allowed Roles**: `super_admin`, `admin`, `editor`
- **Request Body**: Empty `{}`
- **Behavior**:
  - Transitions `status` to `'draft'`.
  - Preserves the original `published_at` timestamp and immutable `slug`.
  - Immediately removes the article from public endpoints (`/api/v1/news`, `/api/v1/news/latest`, `/api/v1/news/{identifier}`).
- **Success Response (`200 OK`)**:
```json
{
  "message": "News article converted to draft successfully.",
  "data": {
    "id": 12,
    "slug": "latest-celebrity-news-12",
    "status": "draft",
    "title_ar": "أحدث أخبار المشاهير",
    "title_en": "Latest Celebrity News",
    "author_name_ar": "سارة المنصور",
    "author_name_en": "Sara Al-Mansour",
    "short_description_ar": "تغطية شاملة لأهم الفعاليات الفنية.",
    "short_description_en": "Comprehensive coverage of top artistic events.",
    "content_ar": "<p>تفاصيل الخبر باللغة العربية.</p>",
    "content_en": "<p>Full article details in English.</p>",
    "category_id": 10,
    "category": {
      "id": 10,
      "name_ar": "المشاهير",
      "name_en": "Celebrities",
      "parent_id": 7,
      "parent": {
        "id": 7,
        "name_ar": "الترفيه",
        "name_en": "Entertainment"
      }
    },
    "featured_image_id": 14,
    "featured_image_url": "https://pub-r2.example.com/news/covers/550e8400-e29b-41d4-a716-446655440000.webp",
    "editor_images": [],
    "published_at": "2026-10-28T22:15:00.000000Z",
    "created_at": "2026-10-28T22:10:00.000000Z",
    "updated_at": "2026-10-28T22:20:00.000000Z"
  }
}
```

---

#### 11. Permanently Delete News Article
- **Method & Route**: `DELETE /api/v1/admin/news/{news}`
- **Authentication**: Required (`Authorization: Bearer {{admin_token}}`)
- **Allowed Roles**: `super_admin` and `admin` **only**.
- **Behavior**:
  - Permanently deletes the `news` row (and cascades `news_article_images`).
  - After transaction commit, cleans up any attached featured and editor images that are not referenced by any other article via `NewsImageService::deleteImageIfUnreferenced()`.
- **Success Response (`200 OK`)**:
```json
{
  "message": "News article permanently deleted successfully."
}
```
- **Error Responses**:
  - `403 Forbidden` (for `editor` role, intercepted by `EditorCannotDelete` middleware):
    ```json
    {
      "message": "Forbidden. Editors are strictly prohibited from performing delete operations."
    }
    ```
  - `404 Not Found`: Article ID does not exist.

---

## C. Complete Testing Workflow (Postman Sequence)

Use the accompanying Postman collection (`docs/news-module.postman_collection.json`) in the following order:

1. **Authentication Prerequisite**:
   - Authentication endpoints belong to the centralized Authentication Module (`docs/AUTHENTICATION_API.md` and `docs/Waai_Media_Authentication_API.postman_collection.json`).
   - Before executing Admin News requests, ensure you have an active Super Admin or Admin token populated in `{{admin_token}}` (and optionally an Editor token in `{{editor_token}}` for permission testing) in your Postman Environment (`Waai_Media_Local.postman_environment.json`).
2. **Fetch News Categories**:
   - Send `GET {{base_url}}/api/v1/categories?type=news` (located under `1. Public News & Categories`).
   - **Expected**: `200 OK` returning the 9 top-level News categories and the 4 `children` under `entertainment`. The test script automatically extracts a leaf category ID (`health`) into `{{category_id}}`, the `entertainment` parent ID into `{{entertainment_parent_id}}`, and the `celebrities` child subcategory ID into `{{celebrities_category_id}}`.
3. **Public Endpoints & Initial Listing**:
   - Send `GET {{base_url}}/api/v1/news/latest` and `GET {{base_url}}/api/v1/news`.
   - **Expected**: `200 OK` with `data` array containing only published articles (`published_at <= now()`).
4. **Create a Draft (English or Arabic-only)**:
   - Send `POST {{base_url}}/api/v1/admin/news` with `{"category_id": {{category_id}}, "title_en": "Waai Media Launches Regional Creative Summit", "short_description_en": "Initial draft summary for the upcoming creative summit."}`.
   - **Expected**: `201 Created`, `data.status = "draft"`, `data.published_at = null`, `data.slug = "waai-media-launches-regional-creative-summit-{id}"`, standardized `NewsAdminResource` structure (no `title`, `author_name`, `short_description`, `category_name_ar`, `category_name_en`, or `featured_image`). Saves `{{news_id}}` and `{{news_slug}}`.
   - Verify public isolation: `GET {{base_url}}/api/v1/news/{{news_slug}}` returns `404 Not Found`.
5. **Upload Featured & Editor Images**:
   - Send `POST {{base_url}}/api/v1/admin/news/images` (`multipart/form-data`) with `image` file and `type=featured`.
   - **Expected**: `201 Created`; saves `data.id` to `{{image_id}}` and `data.url` to `{{image_url}}`.
   - Send `POST {{base_url}}/api/v1/admin/news/images` (`multipart/form-data`) with `image` file and `type=editor`.
   - **Expected**: `201 Created`; saves `data.id` to `{{editor_image_id}}` and `data.url` to `{{editor_image_url}}`.
6. **Retrieve the Created Draft for Editing**:
   - Send `GET {{base_url}}/api/v1/admin/news/{{news_id}}`.
   - **Expected**: `200 OK` with standardized bilingual fields, category object, and image metadata.
7. **Partial Updates (Verify Omitted Fields Remain Unchanged)**:
   - Send `PATCH {{base_url}}/api/v1/admin/news/{{news_id}}` with `content_en` embedding `<img data-image-id="{{editor_image_id}}" src="{{editor_image_url}}" alt="Chart" />` and `content_ar`, `author_name_ar`, `author_name_en`, `short_description_ar`, `short_description_en`, `title_ar`.
   - Then send a second `PATCH {{base_url}}/api/v1/admin/news/{{news_id}}` updating **only** `{"title_en": "Healthcare Breakthrough 2026"}`.
   - **Expected**: `200 OK`; `title_en` and draft `slug` update, while omitted `content_en`, `content_ar`, and `editor_images` remain intact.
8. **Featured Image Assignment and Clearing on Draft**:
   - Send `PATCH {{base_url}}/api/v1/admin/news/{{news_id}}` with `{"featured_image_id": {{image_id}}}`.
   - **Expected**: `200 OK`; `data.featured_image_id = {{image_id}}` and `data.featured_image_url` is populated.
   - Note: Clearing (`"featured_image_id": null`) on a draft detaches and deletes an unshared image from R2; test clearing with a disposable image if desired, then re-assign a valid `{{image_id}}` before publishing.
9. **Publish, Edit Published Article, Unpublish, and Republish (`published_at` & `slug` Preservation)**:
   - Send `POST {{base_url}}/api/v1/admin/news/{{news_id}}/publish` with `{}`.
   - **Expected**: `200 OK`, `data.status = "published"`, `data.published_at` is set (saved to `{{original_published_at}}`), and `data.slug` is locked.
   - Verify public visibility: `GET {{base_url}}/api/v1/news/{{news_slug}}` (and `GET {{base_url}}/api/v1/news/{{news_id}}`) now returns `200 OK` (test with `Accept-Language: en` and `Accept-Language: ar`).
   - Edit `title_en` on the published article via `PATCH /api/v1/admin/news/{{news_id}}`: verify `data.slug` and `data.published_at` remain identical to the initial publication values.
   - Send `POST {{base_url}}/api/v1/admin/news/{{news_id}}/unpublish`: verify `data.status = "draft"`, `data.published_at` still equals `{{original_published_at}}`, and `GET {{base_url}}/api/v1/news/{{news_slug}}` returns `404`.
   - Send `POST {{base_url}}/api/v1/admin/news/{{news_id}}/publish`: verify `data.status = "published"` and `data.published_at` still equals `{{original_published_at}}`.
10. **Search, Filtering, Pagination, and Sorting**:
    - Test `GET /api/v1/admin/news?search=Breakthrough&status=published&per_page=10&page=1`.
    - Test literal wildcard escaping: `GET /api/v1/admin/news?search=100%25`.
    - Test Entertainment parent category filter expansion: `GET /api/v1/news?category=entertainment` and `GET /api/v1/admin/news?category_id={{entertainment_parent_id}}`.
11. **Delete an Article (Role Enforcement)**:
    - With `{{editor_token}}`, send `DELETE {{base_url}}/api/v1/admin/news/{{news_id}}` -> **Expected**: `403 Forbidden`.
    - With `{{admin_token}}` (`super_admin` or `admin`), send `DELETE {{base_url}}/api/v1/admin/news/{{news_id}}` -> **Expected**: `200 OK`.
12. **Image Ownership & Authorization Negative Tests**:
    - Upload an image as Admin (`{{admin_only_image_id}}`, unattached to any article).
    - Using `{{editor_token}}`, attempt `POST /api/v1/admin/news` with `featured_image_id: {{admin_only_image_id}}` or `<img data-image-id="{{admin_only_image_id}}" />` -> **Expected**: `422 Unprocessable Entity` (`"You are not authorized to associate an unattached image uploaded by another user."`).
13. **HTML Sanitization & Embedded Image Security Tests**:
    - Send `POST /api/v1/admin/news` with `<script>alert(1)</script><p onclick="evil()">Safe text</p><a href="https://waaimedia.com">Link</a>` -> **Expected**: `201 Created` with `<script>` and `onclick` stripped and `rel="noopener noreferrer"` forced on `<a>`.
    - Send `POST /api/v1/admin/news` with `<img src="data:image/png;base64,..." />` or `<img src="https://evil.example.com/pic.jpg" />` -> **Expected**: `422 Unprocessable Entity`.
14. **Publication Timestamp (`published_at`) Behavior**:
    - Confirmed in Step 9: set once on first transition to `published`, preserved across all subsequent edits, `action=draft`, `status=draft`, `/unpublish`, and `/publish`.
    - Note: Client-supplied `published_at` values in request payloads are stripped by `StoreNewsRequest`, `UpdateNewsRequest`, and `PublishNewsRequest`.
15. **Orphan-Image Cleanup Safeguards (CLI-Only — Dry-Run First)**:
    - Run `php artisan news:clean-orphaned-images --dry-run` in a terminal (never via an HTTP endpoint). See Section D.6 below.

---

## D. Image Storage and Cleanup

### 1. Image Upload and URL Generation
- Uploaded files are validated by `UploadNewsImageRequest` (`jpeg,jpg,png,webp`, max 10 MB) and `ImageStorageService::upload()` (which verifies real MIME type via `finfo` and image dimensions via `getimagesize()`, then generates a UUIDv4 storage key under `news/covers/` or `news/editor/`).
- If the database insert into `news_images` fails after the R2 upload succeeds, `NewsImageService::uploadAndCreate()` immediately executes compensating deletion (`safeDelete($stored->key)`) so no orphan is left in R2.
- Delivery URLs are resolved dynamically from `filesystems.disks.r2.url` (`CLOUDFLARE_R2_PUBLIC_URL`) + relative `storage_key`.

### 2. How Images Are Attached to Articles
- **Featured Image**: Referenced by `news.featured_image_id` (foreign key to `news_images.id` with `nullOnDelete()`) and synchronized to `news.featured_image_url` (stores the relative R2 `storage_key`) and `news_article_images` (`role = 'featured'`).
- **Editor Images**: Embedded inside `content_ar` and/or `content_en` using `<img data-image-id="{id}" src="{r2_url}" />`. `NewsHtmlSanitizerService` validates every `<img>` element against `news_images`, canonicalizes `src` and `data-image-id`, and `NewsImageService::syncArticleImages()` records all active editor image IDs in `news_article_images` (`role = 'editor'`).

### 3. Reuse and Ownership Restrictions (`NewsImageService::canUserAssociateImage()`)
1. Any image with `deleting_at !== null` (in-flight deletion) is **rejected** (`422`) for all users.
2. An image already attached to the article being edited (`$currentNewsId`) is allowed for all authorized CMS roles (`super_admin`, `admin`, `editor`).
3. An image already referenced by any existing article in the news library (shared image) is allowed for all authorized CMS roles.
4. An unattached image uploaded by the current user (`uploaded_by === $user->id`) or system-seeded (`uploaded_by === null`) is allowed.
5. `super_admin` and `admin` users may associate any valid unattached image (`isAnyAdmin()`).
6. `editor` users are **prohibited** from associating an unattached image uploaded by another user (`422`).

### 4. Shared Image Reference Protection
An image (and its R2 object) is **never** deleted as long as `NewsImageService::isImageReferenced()` finds any reference across:
- `news.featured_image_id`
- `news_article_images.news_image_id`
- `news.featured_image_url` (exact key or URL suffix)
- `news.content_ar` or `news.content_en` (containing the storage key)

This protects images referenced in both Arabic and English content of the same article, images used as both featured and editor images, and images shared across multiple articles.

### 5. Staged Deletion Lifecycle & Retry Queue (`DeleteUnreferencedNewsImagesJob`)
When an image is detached on article update/delete, `NewsImageService::deleteImageIfUnreferenced()` executes a 3-phase lifecycle:
1. **Phase 1 (Claim under row lock)**: Starts a short DB transaction, locks the `news_images` row (`SELECT ... FOR UPDATE`), re-verifies `isImageReferenced()`, sets `deleting_at = now()`, and commits. While `deleting_at` is set, no concurrent request can attach the image.
2. **Phase 2 (External R2 delete outside DB transaction)**: Calls `ImageStorageService::safeDelete()` with no DB transaction held open. If R2 deletion fails transiently, resets `deleting_at = null` and retains the `news_images` row. `NewsController::cleanupDetachedImages()` dispatches `DeleteUnreferencedNewsImagesJob` (`$tries = 3`, `$backoff = [10, 30, 60]`, `retryUntil = 2 hours`, `ShouldBeUnique` for 300s).
3. **Phase 3 (Finalize under row lock)**: Re-locks the `news_images` row, re-verifies `isImageReferenced()`, and deletes the DB row.

### 6. CLI-Only Orphan Cleanup Command (`news:clean-orphaned-images`)
> **Important:** Orphan cleanup is a **CLI-only Artisan command** (and scheduled weekly on Mondays at `05:00 AM` in `routes/console.php`). There is no HTTP endpoint for orphan cleanup. Do not run destructive `--force` commands against production storage during manual testing.

- **Safe Dry-Run (Default)**:
  ```bash
  php artisan news:clean-orphaned-images
  # or explicitly:
  php artisan news:clean-orphaned-images --dry-run
  ```
- **Command Options**:
  - `--dry-run`: Reports eligible candidate keys without deleting anything (default when `--force` is omitted; takes precedence over `--force` if both are passed).
  - `--force`: Executes destructive cleanup using `deleteImageIfUnreferenced()` for database-backed candidates and `safeDeleteKey()` for raw R2 objects without a `news_images` record.
  - `--grace-hours=24`: Minimum age in hours (checked against both `news_images.created_at` and R2 `lastModified` timestamp) before an unreferenced image is eligible (minimum `1`, default `24`).
  - `--max-deletions=50`: Circuit breaker that aborts the run without deleting anything if the number of eligible candidates exceeds this limit.
  - `--allow-empty-references`: By default, if zero news articles reference any image in the database (`$totalArticleRefs === 0`), `--force` aborts as a wrong-database safety signal unless `--allow-empty-references` is explicitly supplied.
- **Concurrency & Failure Behavior**:
  - Acquires an atomic cache lock (`news:clean-orphaned-images`, 3600s TTL) and skips with exit code `1` if another run is active.
  - Fails closed (aborts with exit code `1` before deleting) if any database lookup, storage listing, timestamp check, or just-in-time revalidation query fails.

---

## E. Configuration and Troubleshooting

### 1. Required Environment Keys (`.env`)
```dotenv
# Database (Must be mysql or mariadb in production for InnoDB row-level FOR UPDATE locks)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Queue (When set to database, a queue worker must process DeleteUnreferencedNewsImagesJob)
QUEUE_CONNECTION=database

# Cloudflare R2 Storage
CLOUDFLARE_R2_ACCESS_KEY_ID=
CLOUDFLARE_R2_SECRET_ACCESS_KEY=
CLOUDFLARE_R2_REGION=auto
CLOUDFLARE_R2_BUCKET=waai-media
CLOUDFLARE_R2_ENDPOINT=
CLOUDFLARE_R2_PUBLIC_URL=
CLOUDFLARE_R2_USE_PATH_STYLE_ENDPOINT=false

# Frontend Origins (CORS)
FRONTEND_URL=http://localhost:4200
PUBLIC_FRONTEND_URL=http://localhost:4201
CORS_ALLOWED_ORIGINS=http://localhost:4200,http://localhost:4201
```

### 2. PHP & Extension Requirements (Deployment Prerequisite)
- **PHP `>= 8.4.1`** with the **`ext-dom`** extension enabled is strictly required by:
  - `App\Services\News\NewsHtmlSanitizerService` (uses PHP 8.4's `\Dom\HTMLDocument::createFromString()`),
  - `symfony/html-sanitizer` v8.1 (`Symfony\Component\HtmlSanitizer\Parser\NativeParser`), and
  - `vendor/composer/platform_check.php` (`PHP_VERSION_ID >= 80401`).
- **Pre-Deployment Server Check**: Before deploying to Hostinger, verify both the web SAPI and CLI PHP versions on the server (`php -v` and `php -m | grep -i dom`). Production server compatibility has not been checked from this local workspace and must be confirmed on the host.
- **Queue Worker Prerequisite**: When `QUEUE_CONNECTION=database` is used in production, ensure a queue worker (`php artisan queue:work` or a scheduled `queue:work --stop-when-empty` cron job) is running so `DeleteUnreferencedNewsImagesJob` retries transient R2 delete failures.

### 3. Common Errors & Meanings

| HTTP Status | Error Message | Cause & Resolution |
| :--- | :--- | :--- |
| `401` | `"Unauthenticated."` | Missing, expired, or invalid `Authorization: Bearer {{admin_token}}` header. |
| `403` | `"Forbidden. This endpoint requires an access token."` | A remember token was sent instead of an access token with the `'access'` ability. |
| `403` | `"Forbidden. Editors are strictly prohibited from performing delete operations."` | An `editor` user attempted `DELETE /api/v1/admin/news/{news}`. Use an `admin` or `super_admin` token. |
| `422` | `"The selected category_id is an organizational parent category and cannot be assigned directly. Please select a subcategory."` | `category_id` points to the `entertainment` parent category. Assign one of its child subcategories (`celebrities`, `in-theaters`, `tv-shows`, `book-reviews`). |
| `422` | `"Direct image URLs are not accepted. Upload an image file or provide a valid featured_image_id."` | `featured_image_url` was included in the request body. Strip `featured_image_url` and send `featured_image_id` or a `featured_image` file. |
| `422` | `"Multipart/form-data is not supported on PATCH or PUT. Send a POST request with _method=PATCH."` | PHP does not parse `multipart/form-data` bodies on raw `PATCH`/`PUT`. Send `POST /api/v1/admin/news/{news}` with form field `_method=PATCH`. |
| `422` | `"You are not authorized to associate an unattached image uploaded by another user."` | An `editor` attempted to attach an unattached `NewsImage` uploaded by a different user. |
| `502` | `"Failed to upload image to storage."` | Cloudflare R2 credentials/endpoint in `.env` are missing or unreachable. |

### 4. Safe Read-Only Debugging Commands
```bash
# List all registered News API routes
php artisan route:list --path=news

# Inspect orphaned image candidates in dry-run mode (never deletes anything)
php artisan news:clean-orphaned-images --dry-run

# Run the News module automated test suite locally (uses isolated testing database & faked R2 disk)
php artisan test --compact tests/Unit/News/NewsUnitTest.php tests/Feature/News/AdminNewsApiTest.php tests/Feature/News/PublicNewsApiTest.php tests/Feature/News/NewsMediaAndCleanupTest.php
```
