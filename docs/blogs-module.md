# Waai Media — Blogs Module API & Technical Architecture Documentation

Comprehensive technical reference, security specification, media management protocol, and operational guide for the **Blogs Module** in the Waai Media Laravel 13 API backend (`/api/v1`).

---

## 1. Module Overview & Supported Features

The **Blogs Module** provides bilingual (Arabic and English) blog article and thought leadership publication management for the Angular Admin Dashboard and public content delivery for the Waai Media client application:

- **Drafts & Publication Lifecycle**:
  - Create drafts with minimal required fields (at least one Arabic or English title, plus a valid blog category).
  - Iteratively edit fields, save partial updates, and transition to published status when all bilingual content requirements and a valid cover image are satisfied.
  - Seamlessly unpublish back to draft or republish without losing audit trails or historical publication timestamps.
- **Immutable Slugs & Preserved Publication Timestamps**:
  - Slugs are generated strictly from the English title (`title_en`) and the database primary key: `{normalized-title-en}-{id}` (e.g., `digital-marketing-frontiers-2026-42`).
  - While an article remains in draft and has never been published (`published_at === null`), modifying `title_en` automatically regenerates the slug.
  - Drafts with Arabic-only titles have `slug = null` until an English title is provided.
  - Upon first publication, `published_at` is set to the current UTC timestamp (`now()`), and the slug becomes **permanently immutable**. Subsequent updates, unpublishing back to draft, or republishing will never alter the slug or overwrite `published_at`.
- **Standardized Administrative JSON Contract (`BlogAdminResource`)**:
  - Exposes the full administrative entity: `id`, `slug`, `status`, `title_ar`, `title_en`, `author_name_ar`, `author_name_en`, `short_description_ar`, `short_description_en`, `content_ar`, `content_en`, `category_id`, `category` (`id`, `name_ar`, `name_en`, `parent_id`), `featured_image_id`, `featured_image_url`, `editor_images` (`id`, `url`), `published_at`, `created_at`, and `updated_at`.
  - Excludes internal storage hashes, internal uploader user IDs, and soft-delete/deleting claim metadata from the payload.
- **Standardized Public Content Delivery (`BlogPublicResource`)**:
  - Homepage latest 3 endpoint (`GET /api/v1/blogs/latest`) ordered strictly by `published_at DESC`.
  - Public listing endpoint (`GET /api/v1/blogs`) supporting 6 items per page by default for responsive category tab interfaces.
  - Unified bilingual contract: Exposes clear bilingual pairs (`title_ar` / `title_en`, `author_name_ar` / `author_name_en`, `short_description_ar` / `short_description_en`, `content_ar` / `content_en`, `category_name_ar` / `category_name_en`), and strictly excludes generic duplicate fields (`title`, `author_name`, `short_description`, `content`, `category_name`).
  - Strict isolation: Drafts and future-scheduled articles (`published_at > now()`) return `HTTP 404 Not Found`.
- **Cloudflare R2 Media Management & Staged Safe Deletion**:
  - Featured cover images stored under `blogs/covers/{uuid}.{ext}` and rich-text editor body images stored under `blogs/editor/{uuid}.{ext}`.
  - Unreferenced images detached during blog updates or deleted blogs are purged using a resilient 3-phase staged deletion protocol:
    1. **Phase 1 (Lock & Claim)**: Mark candidate `blog_images` row with `deleting_at = now()`.
    2. **Phase 2 (External Storage Purge)**: Delete object from Cloudflare R2 fake/real disk outside of database transactions.
    3. **Phase 3 (DB Finalization)**: Delete `blog_images` database row inside a clean transaction.
  - Transient failure recovery: If R2 deletion fails, `deleting_at` is safely reset to `null` for future retry. If Phase 2 succeeds but Phase 3 fails, stale `deleting_at` rows are cleaned up idempotently on subsequent runs.
- **HTML5 DOM & Symfony HtmlSanitizer Protection**:
  - Rich-text editor content (`content_ar`, `content_en`) is sanitized with `BlogHtmlSanitizerService` using Symfony HtmlSanitizer.
  - Eliminates XSS vectors (`<script>`, `<iframe>`, `<object>`, inline event handlers `onclick`, `onload`, etc.).
  - Rejects base64 data URIs and foreign/external images.
  - Validates embedded `<img data-image-id="...">` tags against `blog_images`, verifies the caller has authority to associate the image, and automatically syncs `blog_article_images` pivot associations.

---

## 2. Authentication & Role-Based Access Control (RBAC)

- **Public Endpoints** (`/api/v1/blogs`, `/api/v1/blogs/latest`, `/api/v1/blogs/{identifier}`): Publicly accessible without authentication.
- **Admin Endpoints** (`/api/v1/admin/blogs/*`): Protected by `auth:sanctum`, `require.access`, and `role:super_admin,admin,editor`.
  - Requires `Authorization: Bearer {{token}}` possessing the `'access'` ability. Tokens with only `'refresh'` ability are rejected with `HTTP 403 Forbidden`.

### Role Permissions Matrix

| Operation | Super Admin | Admin | Editor | Unauthenticated |
| :--- | :---: | :---: | :---: | :---: |
| **List Admin Blogs** (`GET /admin/blogs`) | Allowed | Allowed | Allowed | `401 Unauthorized` |
| **View Blog Details** (`GET /admin/blogs/{id}`) | Allowed | Allowed | Allowed | `401 Unauthorized` |
| **Create Blog Draft** (`POST /admin/blogs`) | Allowed | Allowed | Allowed | `401 Unauthorized` |
| **Update Blog** (`PATCH /admin/blogs/{id}`) | Allowed | Allowed | Allowed | `401 Unauthorized` |
| **Publish / Unpublish** (`POST .../publish`) | Allowed | Allowed | Allowed | `401 Unauthorized` |
| **Upload Blog Images** (`POST /admin/blogs/images`) | Allowed | Allowed | Allowed | `401 Unauthorized` |
| **Associate Another User's Unattached Image** | Allowed (Override) | Allowed (Override) | **Denied** (`422`) | `401 Unauthorized` |
| **Reuse Already-Referenced Library Image** | Allowed | Allowed | Allowed | `401 Unauthorized` |
| **Permanently Delete Blog** (`DELETE /admin/blogs/{id}`) | Allowed | Allowed | **Denied** (`403 Forbidden`) | `401 Unauthorized` |

*Note: In the Waai Media collaborative workflow, editors can collaboratively edit, update, publish, and unpublish blog drafts authored by other editors.*

---

## 3. Database Schema & Models Architecture

The Blogs module maintains strict architectural independence from the News module.

### Tables

1. **`blogs`**:
   - `id`: Unsigned Big Integer (Auto Increment, Primary Key).
   - `category_id`: Unsigned Big Integer (Foreign Key -> `categories.id`, `RESTRICT` on delete).
   - `featured_image_id`: Unsigned Big Integer Nullable (Foreign Key -> `blog_images.id`, `SET NULL` on delete).
   - `featured_image_url`: String (255) Nullable (Storage key / cache of the featured image path).
   - `title_ar`: String (255) Nullable.
   - `title_en`: String (255) Nullable.
   - `slug`: String (255) Nullable (Unique index `blogs_slug_unique`).
   - `author_name_ar`: String (255) Nullable.
   - `author_name_en`: String (255) Nullable.
   - `short_description_ar`: Text Nullable.
   - `short_description_en`: Text Nullable.
   - `content_ar`: MediumText Nullable (Sanitized HTML).
   - `content_en`: MediumText Nullable (Sanitized HTML).
   - `status`: String (20) Default `'draft'` (Indexed).
   - `published_at`: Timestamp Nullable (Indexed).
   - `created_at`, `updated_at`: Timestamps.

2. **`blog_images`**:
   - `id`: Unsigned Big Integer (Auto Increment, Primary Key).
   - `disk`: String (50) Default `'r2'`.
   - `storage_key`: String (255) (Unique index `blog_images_storage_key_unique`).
   - `mime_type`: String (50) (`image/jpeg`, `image/png`, `image/webp`).
   - `size_bytes`: Unsigned Big Integer.
   - `sha256`: String (64) Nullable.
   - `uploaded_by`: Unsigned Big Integer Nullable (Foreign Key -> `users.id`, `SET NULL` on delete).
   - `deleting_at`: Timestamp Nullable (Concurrency lock for background cleanup).
   - `created_at`, `updated_at`: Timestamps.

3. **`blog_article_images`** (Pivot Table):
   - `id`: Unsigned Big Integer (Auto Increment, Primary Key).
   - `blog_id`: Unsigned Big Integer (Foreign Key -> `blogs.id`, `CASCADE` on delete).
   - `blog_image_id`: Unsigned Big Integer (Foreign Key -> `blog_images.id`, `RESTRICT` on delete).
   - `role`: String (50) Default `'editor'`.
   - `created_at`, `updated_at`: Timestamps.
   - Unique compound index: `['blog_id', 'blog_image_id']`.

### Categories Integration
The Blogs module integrates with the shared `categories` table where `type = 'blogs'`. Currently, 6 flat categories are registered:
1. `digital-marketing-trends` (Digital Marketing Trends / اتجاهات التسويق الرقمي)
2. `case-studied` (Case Studied / دراسات الحالة)
3. `tips-guides` (Tips & Guides / نصائح وأدلة إرشادية)
4. `creative-ideas` (Creative Ideas / أفكار إبداعية)
5. `behind-the-scenes` (Behind the Scenes / خلف الكواليس)
6. `tools-tech` (Tools & Tech / الأدوات والتقنية) — Added via migration `2026_10_30_000002_add_tools_and_tech_to_blog_categories.php`.

Cross-module category assignments (e.g. attempting to assign a `type = 'news'` category to a blog) are rejected with `HTTP 422 Unprocessable Content`.

---

## 4. Endpoints Reference & Examples

### Summary Table

| Scope | Method | Route | Auth / Role | Description |
| :--- | :--- | :--- | :--- | :--- |
| **Public** | `GET` | `/api/v1/blogs/latest` | Public | Latest 3 published blogs for homepage |
| **Public** | `GET` | `/api/v1/blogs` | Public | Paginated published blog list (default 6/page) |
| **Public** | `GET` | `/api/v1/blogs/{identifier}` | Public | Details by slug or numeric ID (404 for drafts) |
| **Admin** | `GET` | `/api/v1/admin/blogs` | Sanctum (Super Admin, Admin, Editor) | Admin paginated blog listing (default 10/page) |
| **Admin** | `POST` | `/api/v1/admin/blogs` | Sanctum (Super Admin, Admin, Editor) | Create draft or directly publish blog |
| **Admin** | `GET` | `/api/v1/admin/blogs/{id}` | Sanctum (Super Admin, Admin, Editor) | Full admin details of a single blog |
| **Admin** | `PATCH` | `/api/v1/admin/blogs/{id}` | Sanctum (Super Admin, Admin, Editor) | Partial update of blog fields |
| **Admin** | `DELETE`| `/api/v1/admin/blogs/{id}` | Sanctum (**Super Admin, Admin Only**) | Permanent deletion of blog |
| **Admin** | `POST` | `/api/v1/admin/blogs/{id}/publish` | Sanctum (Super Admin, Admin, Editor) | Publish a draft blog article |
| **Admin** | `POST` | `/api/v1/admin/blogs/{id}/unpublish` | Sanctum (Super Admin, Admin, Editor) | Revert published article to draft |
| **Admin** | `POST` | `/api/v1/admin/blogs/images` | Sanctum (Super Admin, Admin, Editor) | Upload cover or editor image to R2 |

---

### Request & Response Examples

#### 1. Upload Blog Image (`POST /api/v1/admin/blogs/images`)
- **Headers:** `Accept: application/json`, `Authorization: Bearer {{token}}`
- **Body (Multipart Form-Data):**
  - `image`: binary file (`.jpg`, `.png`, `.webp`, max 10MB)
  - `type`: `cover` (or `featured`) or `editor`
- **Response (`201 Created`):**
```json
{
  "success": true,
  "data": {
    "id": 14,
    "url": "https://pub-r2.waai.test/blogs/covers/550e8400-e29b-41d4-a716-446655440000.webp"
  }
}
```

#### 2. Create Blog Draft (`POST /api/v1/admin/blogs`)
- **Headers:** `Accept: application/json`, `Content-Type: application/json`, `Authorization: Bearer {{token}}`
- **Body:**
```json
{
  "category_id": 16,
  "title_en": "SEO Trends for 2026",
  "title_ar": "اتجاهات تحسين محركات البحث لعام 2026",
  "author_name_en": "Sarah Jenkins",
  "author_name_ar": "سارة جنكينز",
  "short_description_en": "Key takeaways on next-generation search algorithms.",
  "status": "draft"
}
```
- **Response (`201 Created`):**
```json
{
  "data": {
    "id": 18,
    "slug": "seo-trends-for-2026-18",
    "status": "draft",
    "title_ar": "اتجاهات تحسين محركات البحث لعام 2026",
    "title_en": "SEO Trends for 2026",
    "author_name_ar": "سارة جنكينز",
    "author_name_en": "Sarah Jenkins",
    "short_description_ar": null,
    "short_description_en": "Key takeaways on next-generation search algorithms.",
    "content_ar": null,
    "content_en": null,
    "category_id": 16,
    "category": {
      "id": 16,
      "name_ar": "نصائح وأدلة إرشادية",
      "name_en": "Tips & Guides",
      "parent_id": null,
      "parent": null
    },
    "featured_image_id": null,
    "featured_image_url": null,
    "editor_images": [],
    "published_at": null,
    "created_at": "2026-10-31T01:30:00.000000Z",
    "updated_at": "2026-10-31T01:30:00.000000Z"
  }
}
```

#### 3. Publish Blog Article (`POST /api/v1/admin/blogs/{id}/publish`)
- **Headers:** `Accept: application/json`, `Content-Type: application/json`, `Authorization: Bearer {{token}}`
- **Body (Supplying missing publish fields):**
```json
{
  "content_en": "<p>Full substantive content in English.</p>",
  "content_ar": "<p>محتوى المقال الكامل باللغة العربية مع التفاصيل.</p>",
  "short_description_ar": "ملخص تحسين محركات البحث.",
  "featured_image_id": 14
}
```
- **Response (`200 OK`):**
```json
{
  "data": {
    "id": 18,
    "slug": "seo-trends-for-2026-18",
    "status": "published",
    "title_ar": "اتجاهات تحسين محركات البحث لعام 2026",
    "title_en": "SEO Trends for 2026",
    "category_id": 16,
    "featured_image_id": 14,
    "featured_image_url": "https://pub-r2.waai.test/blogs/covers/550e8400-e29b-41d4-a716-446655440000.webp",
    "published_at": "2026-10-31T01:32:15.000000Z",
    "created_at": "2026-10-31T01:30:00.000000Z",
    "updated_at": "2026-10-31T01:32:15.000000Z"
  }
}
```

#### 4. Public Latest Homepage Blogs (`GET /api/v1/blogs/latest`)
- **Headers:** `Accept: application/json`, `Accept-Language: ar`
- **Response (`200 OK`):**
```json
{
  "data": [
    {
      "id": 18,
      "slug": "seo-trends-for-2026-18",
      "title_ar": "اتجاهات تحسين محركات البحث لعام 2026",
      "title_en": "SEO Trends for 2026",
      "author_name_ar": "سارة جنكينز",
      "author_name_en": "Sarah Jenkins",
      "short_description_ar": "ملخص تحسين محركات البحث.",
      "short_description_en": "Key takeaways on next-generation search algorithms.",
      "content_ar": "<p>محتوى المقال الكامل باللغة العربية مع التفاصيل.</p>",
      "content_en": "<p>Full substantive content in English.</p>",
      "category_id": 16,
      "category_name_ar": "نصائح وأدلة إرشادية",
      "category_name_en": "Tips & Guides",
      "category": {
        "id": 16,
        "slug": "tips-guides",
        "name_ar": "نصائح وأدلة إرشادية",
        "name_en": "Tips & Guides",
        "parent_id": null,
        "parent": null
      },
      "featured_image_url": "https://pub-r2.waai.test/blogs/covers/550e8400-e29b-41d4-a716-446655440000.webp",
      "published_at": "2026-10-31T01:32:15.000000Z"
    }
  ]
}
```

---

## 5. Media Lifecycle & Safe Deletion Protocol

### Cloudflare R2 Storage Directories
- `blogs/covers/{uuid}.{ext}`: Used for hero/featured cover cards.
- `blogs/editor/{uuid}.{ext}`: Used for rich-text article body media.
- Allowed extensions: `jpeg`, `jpg`, `png`, `webp`.
- Maximum size: 10 MB (10,240 KB).

### Staged Deletion Protocol
When an article is deleted or an image is replaced, cleanup executes safely without blocking client requests:
```
[Candidate Image]
       │
       ▼
1. Claim Lock (DB Transaction)
   SET deleting_at = now()
       │
       ▼
2. External Purge (Outside DB Transaction)
   Storage::disk('r2')->delete($key)
   ├── Success ──> Proceed to Phase 3
   └── Failure ──> Reset deleting_at = null (Safe retry later)
       │
       ▼
3. Finalize Deletion (DB Transaction)
   DELETE FROM blog_images WHERE id = $id
```

### Background Asynchronous Cleanup (`DeleteUnreferencedBlogImagesJob`)
- When editor images are detached from article HTML during an update, `DeleteUnreferencedBlogImagesJob` is dispatched to the queue.
- The job calls `BlogImageService::deleteImageIfUnreferenced($imageId)` for each unreferenced ID.
- Automatically retries on failure (3 attempts, exponential backoff).

---

## 6. Scheduled Cleanup Command & Operational Requirements

### Command Signature
```bash
php artisan blogs:clean-orphaned-images {--grace-hours=24} {--max-deletions=50} {--force} {--dry-run}
```

### Options & Safety Circuit Breakers:
- `--grace-hours=24`: Protects recently uploaded unattached images. Images newer than 24 hours are never marked as orphans.
- `--max-deletions=50`: Circuit breaker limit. If eligible candidates exceed this number, the command aborts execution to protect against systemic storage errors.
- `--dry-run`: Scans and logs candidates without modifying storage or database.
- `--force`: Mandatory flag required to authorize destructive deletion. Without `--force`, the command automatically defaults to dry-run mode.

### Scheduled Execution Status (Critical Operational Clarification)
The scheduler entry registered in `routes/console.php` is:
```php
Schedule::command('blogs:clean-orphaned-images --grace-hours=24 --max-deletions=50')
    ->weeklyOn(1, '05:30')
    ->withoutOverlapping(120)
    ->onOneServer()
    ->runInBackground();
```
**Important Operational Facts:**
1. **Scheduled Runs are Dry-Run Only**: The scheduled entry intentionally omits `--force`. Therefore, the automated weekly run only scans and reports eligible orphans to logs. It **never deletes R2 objects or database rows**.
2. **Manual Destruction Required**: Deleting orphaned images requires an authorized operator to execute the command manually with `--force`:
   ```bash
   php artisan blogs:clean-orphaned-images --force
   ```
3. **OS Crontab Prerequisite**: Laravel scheduler registration is passive. The scheduler will not trigger on Hostinger unless a system crontab entry runs every minute:
   ```bash
   * * * * * cd /path/to/waai-media && php artisan schedule:run >> /dev/null 2>&1
   ```
4. **Queue & Cache Environment Limitations**:
   - The repository defaults to `CACHE_STORE=database` and `QUEUE_CONNECTION=database`.
   - The production cache driver and queue worker daemon (e.g. Supervisor) on Hostinger have **not been verified**.
   - If no background queue worker (`php artisan queue:work`) is running on Hostinger, queued jobs will sit inert in the database `jobs` table until processed.

---

## 7. Local Testing Guide with Postman

1. **Import Collections**:
   - Import the Blogs collection: [`docs/blogs-module.postman_collection.json`](file:///c:/Projects/Waai_media/docs/blogs-module.postman_collection.json).
   - For user login and Sanctum token issuance, refer to the dedicated Authentication Module documentation in [`docs/AUTHENTICATION_API.md`](file:///c:/Projects/Waai_media/docs/AUTHENTICATION_API.md) and import [`docs/Waai_Media_Authentication_API.postman_collection.json`](file:///c:/Projects/Waai_media/docs/Waai_Media_Authentication_API.postman_collection.json).
2. **Environment & Authentication Setup**:
   - `base_url`: Target host (default: `http://127.0.0.1:8000`).
   - Prior to executing protected administrative requests in Folder 2 (`Admin Blog Management`) and Folder 3 (`Blog Media & Image Management`), authenticate via the Authentication Collection (or your Postman Environment) to obtain valid Sanctum tokens possessing the `'access'` ability, and populate the following variables:
     - `admin_token`: Bearer access token for a `super_admin` or `admin` user.
     - `editor_token`: Bearer access token for an `editor` user.
3. **Execution Order**:
   - **Step 1: Public Discovery**: Execute requests in **Folder 1 (Public Blog Endpoints)** (`GET /api/v1/blogs/latest`, `GET /api/v1/blogs`, `GET /api/v1/blogs/{identifier}`) — no authentication required.
   - **Step 2: Media Upload**: Execute requests in **Folder 3 (Blog Media & Image Management)** using `{{editor_token}}` to upload cover images (`blogs/covers/`) and editor body images (`blogs/editor/`), capturing `{{cover_image_id}}`.
   - **Step 3: Admin Blog Management**: Execute requests in **Folder 2 (Admin Blog Management)** to test draft creation, direct publishing, partial updating, publishing, unpublishing, republishing, and role-based deletion restrictions.
4. **Guarded Permanent Deletion**:
   - The `Delete Blog - Admin Permanent Delete (Guarded)` request is skipped by default.
   - To test permanent deletion, set the collection variable `run_destructive_delete = "true"`.