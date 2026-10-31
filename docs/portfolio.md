# Portfolio Management API

Backend for the dashboard page **Portfolio Management** (list, add, update, publish, delete) and the public website portfolio. All base paths are under `/api/v1`.

Postman collection: `docs/Waai_Media_Portfolio_API.postman_collection.json` (reuses `base_url`, `admin_token`, `editor_token`; no tokens are stored in it).

**Concepts - three different images, do not confuse them:** the **Client logo** (Clients module), the **Portfolio cover** (`cover_image`, the card image of one portfolio item) and the **work images** (`media`, the content inside the portfolio). Every item belongs to exactly one existing **Client**.

## 1. Category hierarchy

Portfolio uses the existing `categories` table (`type = 'portfolio'`, seeded by `CategorySeeder`). **Nothing is duplicated and the seeded categories are unchanged.** Items store `category_id` (main category) and `subcategory_id` (nullable) and behavior is decided from the **stable slugs**, never from translated names.

| Main category (slug) | Subcategory (slug) | Kind | Accepts |
|---|---|---|---|
| Social Media Campaign (`social-media-campaign`) | Graphic Design (`graphic-design`) | `images` | image uploads |
| | Branding (`branding`) | `images` | image uploads |
| Media Production (`media-production`) | Videos (`videos`) | `video_urls` | video URLs only |
| PR (`pr`) | Media Covering (`media-covering`) | `images` | image uploads, no links |
| | Press Covering (`press-covering`) | `images_with_url` | image uploads, **each image with its own URL** |
| Events (`events`) | Image (`events-image`) | `images` | image uploads |
| | Video (`events-video`) | `video_urls` | video URLs only |
| Opening (`opening`) | Image (`opening-image`) | `images` | image uploads |
| | Video (`opening-video`) | `video_urls` | video URLs only |
| Web Design (`web-design`) | *(none)* | `web_design` | featured image + links |

* Events and Opening subcategories are distinct rows with different parents and slugs (`events-image` ≠ `opening-image`).
* A subcategory must belong to the selected main category; Web Design must be sent without a subcategory. A subcategory slug that is not mapped to a kind is rejected (fail closed).
* Display names come from the existing seeded data (`Social Media Campaign`, `Video`, ...). Categories, ids, slugs, translations and relationships are kept exactly as seeded; this module never renames or modifies them.

The dashboard reads the hierarchy dynamically from `GET /admin/portfolio/categories` (includes the input contract per kind). The existing public `GET /categories?type=portfolio` also returns it, localized.

## 2. Data model

`portfolio_items`: `id`, `client_id` (FK `clients`, `RESTRICT`, nullable only for rows created before the client requirement), `category_id`, `subcategory_id` (nullable), `title_en`, `title_ar` (both nullable), `cover_image_path` (R2 key of the dedicated cover, nullable), `status` (`draft`|`published`), `link_1`, `link_2`, `link_3` (Web Design only), `published_at`, `slot_subcategory_key` (generated, see below), timestamps. Foreign keys to `categories` use `RESTRICT`.

### Client / category slot uniqueness

A client can have items in many main categories and many subcategories, but only **one item per slot**, whether draft or published:

* category with subcategories: one item per **client + subcategory**;
* Web Design (no subcategories): one item per **client + main category**;
* drafts count exactly like published items, and unpublishing keeps the slot.

Because a subcategory is now mandatory for every item whose category has subcategories (also drafts), the only items without a subcategory are Web Design items. The generated column is still what makes that case unique: `UNIQUE (client_id, category_id, subcategory_id)` alone would let a client hold any number of Web Design items, since `NULL`s never compare equal.

Enforcement is layered: request validation returns **422** with the conflicting item id (`subcategory_id` or `category_id` error) for create and update (also when moving an item or changing its client), and the database has the final say: `UNIQUE (client_id, category_id, slot_subcategory_key)` where `slot_subcategory_key` is a STORED generated column `COALESCE(subcategory_id, 0)`. A plain composite unique index would allow unlimited duplicates because `NULL`s are never equal in MySQL; the generated column removes that gap. A concurrent request that slips past the validation hits the index and is returned as the same 422. Rows with no client (legacy) are not constrained.

### Deleting a client deletes its portfolio

`DELETE /admin/clients/{id}` (Admin / Super Admin only; Editor receives 403) permanently deletes the client **and every portfolio item of that client** (draft or published, with their media rows) in ONE database transaction. If anything in the transaction fails, nothing is deleted. There is no 409 any more. After the commit, the client logo and the portfolio covers and work images are removed from R2, skipping any object that another record still references. See section 10 for the cleanup and failure handling.

`portfolio_media`: `id`, `portfolio_item_id` (cascade delete), `image_path` (nullable R2 key), `url` (nullable), `sort_order`, timestamps. One row per image / video URL. For Press Covering the image and its URL live in the **same row**, so the association cannot drift. For Web Design the single row holds the featured image.

Video files are never stored. Only R2 keys are stored; responses expose resolved public URLs and never storage keys.

## 3. Roles

| Action | Editor | Admin / Super Admin |
|---|---|---|
| List, show, categories | yes | yes |
| Create, update, save draft | yes | yes |
| Publish / unpublish / change status | yes | yes |
| Delete | **no (403)** | yes |

Unauthenticated requests receive 401. Deleting a client (which cascades to its portfolio) is Admin / Super Admin only as well. Deletion is enforced by route middleware (`role:super_admin,admin`), the controller, and the global editor-delete guard.

## 4. Admin endpoints

All require `Authorization: Bearer <access token>` with the `access` ability.

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/portfolio/categories` | category/subcategory hierarchy + input contract |
| GET | `/admin/portfolio` | list (search, filters, pagination) |
| GET | `/admin/portfolio/{id}` | one item with its media |
| POST | `/admin/portfolio` | create (draft by default) |
| PUT / PATCH / POST | `/admin/portfolio/{id}` | update (use `POST` + `_method=PATCH` for multipart) |
| POST / PATCH | `/admin/portfolio/{id}/publish` | publish the stored item |
| POST / PATCH | `/admin/portfolio/{id}/unpublish` | back to draft |
| DELETE | `/admin/portfolio/{id}` | delete (Admin only) |

### Request fields (create and update)

| Field | Type | Notes |
|---|---|---|
| `client_id` | integer | required on create (and on update for a legacy item that has none); must be an existing client |
| `category_id` | integer | required on create; must be a portfolio main category |
| `subcategory_id` | integer | **required whenever the main category has subcategories** (drafts too); must belong to the category; must be omitted/null for Web Design |
| `cover_image` | file | PNG/JPG ≤ 10 MB, the dedicated cover. Optional for drafts, required to publish. Omitted on update = the current cover is kept |
| `title_en`, `title_ar` | string ≤ 255 | at least one is required to save; both to publish (see section 8) |
| `status` | `draft` \| `published` | create: default `draft`. update: omitted = keep current status |
| `media[N][image]` | file | PNG/JPG, ≤ 10 MB (image kinds) |
| `media[N][url]` | string | http(s) URL, ≤ 500 (video URLs; press covering link) |
| `media[N][id]` | integer | update only: targets an existing media entry (replace its image / change its URL) |
| `remove_media_ids[]` | integer | update only: remove existing entries |
| `featured_image` | file | Web Design only (the work image; separate from `cover_image`) |
| `link_1`, `link_2`, `link_3` | http(s) URL | Web Design only. `link_1`,`link_2` required to publish, `link_3` optional |
| `confirm_media_reset` | boolean | update only: see category changes |

**There is no application-level limit** on the number of images (or video URLs) per request or per item, and none on the number removed per request. New entries are appended after the existing ones in the order sent, so ordering is preserved. Entries without `id` are new; existing entries not mentioned are never touched - adding images can never replace the existing collection (e.g. an item with 15 images updated with 20 more has 35). Only PHP/server request limits apply (see section 12); when a single request is too large, send the images in several update requests.

### Per kind

* `images`: each new entry needs `image`; `url`, links and `featured_image` are rejected.
* `images_with_url` (Press Covering): each new entry needs `image` **and its own `url`** (also in drafts). Existing pairs keep their URL when other images are added or removed; a pair's URL can be changed but not cleared. Publishing re-checks that every stored image has a URL.
* `video_urls`: each entry needs `url`; files are rejected.
* `web_design`: `featured_image`, `link_1`, `link_2`, optional `link_3`; `media` entries are rejected.

### Examples (multipart)

Press Covering, published:

```
POST /api/v1/admin/portfolio        (form-data)
category_id=3  subcategory_id=<press-covering id>
title_en=Launch  title_ar=الإطلاق  status=published
media[0][image]=<file>  media[0][url]=https://example.com/article-1
media[1][image]=<file>  media[1][url]=https://example.com/article-2
```

Videos:

```
category_id=<media-production id>  subcategory_id=<videos id>
title_en=Reel  title_ar=عرض  status=published
media[0][url]=https://www.youtube.com/watch?v=abc
```

Web Design (no subcategory):

```
category_id=<web-design id>  title_en=Site  title_ar=موقع  status=published
featured_image=<file>  link_1=https://a.example  link_2=https://b.example  link_3=https://c.example (optional)
```

Update with files (replace image of entry 12, append one):

```
POST /api/v1/admin/portfolio/7   (form-data)
_method=PATCH
media[0][id]=12  media[0][image]=<file>
media[1][image]=<file>
```

### Response (admin item)

```json
{
  "data": {
    "id": 7,
    "title": "Launch",
    "title_en": "Launch",
    "title_ar": "الإطلاق",
    "status": "published",
    "client_id": 5,
    "client": { "id": 5, "name": "Acme", "logo_url": "https://<public-r2-domain>/clients/logos/<uuid>.png" },
    "cover_image_url": "https://<public-r2-domain>/portfolio/images/<uuid>.jpg",
    "category": { "id": 3, "slug": "pr", "name": "PR" },
    "subcategory": { "id": 11, "slug": "press-covering", "name": "Press Covering" },
    "kind": "images_with_url",
    "link_1": null, "link_2": null, "link_3": null,
    "published_at": "2026-10-30T10:00:00.000000Z",
    "created_at": "...", "updated_at": "...",
    "media_count": 2,
    "media": [
      { "id": 12, "image_url": "https://<public-r2-domain>/portfolio/images/<uuid>.jpg", "url": "https://example.com/article-1", "sort_order": 0 }
    ]
  }
}
```

`title` is the title in the language negotiated from `Accept-Language` (falls back to the other language when empty). In list responses `media` is replaced by `media_count` (`client` and `cover_image_url` are in both).

## 5. Draft / publish behavior

* **Draft** (default on create): needs **one title (Arabic or English), a valid existing client, a valid main portfolio category and - when that category has subcategories - a valid subcategory of it** (Web Design has none and keeps `subcategory_id` null). The cover, images, video URLs and Web Design links may stay empty. Anything that *is* sent is still validated (image type/size, URL format, Press Covering image + URL pairs). The same rule applies to every operation that leaves an item in draft: create, update of a draft, saving a published item as draft (`status=draft`) and unpublish. It is enforced in the request validation **and again in the service**, so a draft stored without a subcategory by an earlier version cannot be updated, published or unpublished until a subcategory is chosen (`422 subcategory_id`).
* **Publish** (`status=published` on create/update, or `POST /publish`): validated against the final stored state, atomically. On failure the response is 422 and nothing is changed or left in R2.
  * all kinds: `title_en` and `title_ar` (see section 8), a valid client, a **cover image**, and a subcategory when the category has subcategories
  * `images`: ≥ 1 image · `images_with_url`: ≥ 1 image and a URL on every image · `video_urls`: ≥ 1 video URL · `web_design`: featured image **and** cover image (two different images), `link_1`, `link_2`
* A published item can only be updated into a state that is still publish-ready; set `status=draft` to relax.
* **`published_at` lifecycle - identical to Events** (verified by a side-by-side test against the Events endpoints):
  * Save as draft never publishes; create defaults to `draft` with `published_at = null`.
  * Publishing (explicit request by an Admin/Editor) sets `published_at` to the actual publish time.
  * Publishing an already published item keeps the original timestamp, and editing a published item does not change it.
  * Unpublish returns the item to `draft`, clears `published_at` and hides it from the public API.
  * Republishing after an unpublish records a new timestamp (the previous one was cleared).
  * Public APIs show an item only if it is `published` **and** has a recorded `published_at` that is not in the future (same rule as Events). There is no scheduled or automatic publishing and no input accepts a publication time.

## 6. Category / subcategory changes

* Same kind (e.g. Graphic Design → Branding): media and links are kept.
* Different kind with stored media or Web Design links: **422 on `confirm_media_reset`**; nothing is changed or deleted. Resend with `confirm_media_reset=true` to remove the old media/links (R2 objects are deleted after the commit when unshared) and apply the new category. Moving to Web Design also requires `subcategory_id=null`.
* No stored media/links: the change needs no confirmation.

## 7. Search, filters, pagination

`GET /admin/portfolio?search=&status=&client_id=&category_id=&subcategory_id=&per_page=&page=`

* `search`: server-side LIKE on `title_en` and `title_ar` (wildcards escaped); works with Arabic and English.
* `client_id`: an existing client. All filters combine (e.g. draft items of client A under PR, searched by Arabic title).
* `status`: `draft` or `published`; `category_id`: a main category id; `subcategory_id`: a subcategory id; when both are given the subcategory must belong to the category.
* `per_page` 1–100 (default 10), `page` ≥ 1. Order is `created_at DESC, id DESC`.
* Response is the project standard Laravel paginator: `data`, `links`, `meta` (`current_page`, `from`, `to`, `last_page`, `per_page`, `total`) - sufficient for "Showing 1 to 3 of 3 results" and page buttons.
* Invalid values return 422.

## 8. Arabic / English

Titles are the only translated item fields; the dashboard supplies both - nothing is machine-translated. Category names are localized from the seeded `name_en` / `name_ar`. The `Accept-Language` header (`ar` or `en`, default `en`, handled by the existing `LocaleNegotiator`) selects the public `title` and category names.

> **Approved rule:** a draft needs at least one title (Arabic or English); publishing requires **both** (same as Events). Public responses fall back to the other language only if a title is empty.

## 9. Public endpoints (no authentication)

| Method | Path | Purpose |
|---|---|---|
| GET | `/portfolio` | Experience A: published **Portfolio cards** (All, filters, homepage) |
| GET | `/portfolio/{id}` | one published item with its content and sibling navigation |
| GET | `/portfolio/clients?category_id=` | Experience B: **unique Client cards** with published work in a main category |
| GET | `/portfolio/clients/{client}?category_id=` | one client's subcategory tabs inside a main category |
| GET | `/categories?type=portfolio` | category hierarchy (see section 14) |

Only published items are ever visible (status published, `published_at` recorded and not in the future); drafts and unknown ids return 404. No status, storage keys, other-language titles or internal timestamps are exposed. Titles and category names follow `Accept-Language` (`ar`/`en`, default `en`).

### 9.1 Portfolio cards - `GET /portfolio`

Query (all optional, combinable): `category_id` (main category, **includes its subcategories**), `subcategory_id`, `client_id`, `per_page` (default 12, 1-100), `page`. A `subcategory_id` that does not belong to `category_id`, an unknown client, or a non-portfolio/child id given as `category_id` returns 422. Order: `published_at DESC, id DESC`. Standard paginator: `data`, `links`, `meta`. The result is always a list of portfolio items; a filter never changes the response type.

```json
{ "data": [ {
    "id": 7, "title": "Launch",
    "cover_image_url": "https://<public-r2-domain>/portfolio/images/<uuid>.jpg",
    "client": { "id": 5, "name": "Acme", "logo_url": "https://.../clients/logos/<uuid>.png" },
    "category": { "id": 1, "slug": "social-media-campaign", "name": "Social Media Campaign" },
    "subcategory": { "id": 2, "slug": "branding", "name": "Branding" },
    "kind": "images",
    "published_at": "2026-10-30T10:00:00.000000Z"
} ], "links": {}, "meta": {} }
```

* **Homepage latest three:** `GET /portfolio?per_page=3&page=1` (no separate endpoint). The same endpoint serves other pages with other `per_page` values; the 100 maximum is unchanged.
* Cards carry no media (an item may have any number of images); media is in the details.

### 9.2 Details - `GET /portfolio/{id}`

Card fields plus `media[] {id, image_url, url}`, `link_1..3` and `client_category_items[]`: the client's published items in the **same main category** (including the current one), ordered like the subcategory list, each `{id, title, cover_image_url, subcategory, is_current}`. The frontend opens the selected tab by `id` (stable, never inferred from the client) and shows the others as tabs.

### 9.3 Unique clients by main category - `GET /portfolio/clients?category_id=`

`category_id` is required and must be a main category. Returns clients with **at least one published item in that category**, each exactly once (an `EXISTS` filter, no join duplicates), newest client first, `id DESC` tie-break, with `per_page` (1-100, default 12) and `page`. Draft-only clients are excluded. Each client uses the Clients module's public fields:

```json
{ "data": [ { "id": 5, "name": "Acme", "logo_url": "https://.../clients/logos/<uuid>.png",
              "category": { "id": 1, "slug": "social-media-campaign", "name": "Social Media Campaign" },
              "published_items_count": 2 } ], "links": {}, "meta": {} }
```

The page runs a constant number of queries regardless of its size (checked by a test).

### 9.4 Client navigation - `GET /portfolio/clients/{client}?category_id=`

Called when a Client card is opened. Returns only the subcategories in which the client has a **published** item; draft-only subcategories never appear. Web Design (no subcategories) returns one entry with `subcategory: null`. 404 when the client has nothing published in that category.

```json
{ "data": {
    "client": { "id": 5, "name": "Acme", "logo_url": "..." },
    "category": { "id": 1, "slug": "social-media-campaign", "name": "Social Media Campaign" },
    "default_portfolio_id": 9,
    "items": [
      { "portfolio_id": 9, "title": "...", "cover_image_url": "...", "subcategory": { "id": 1, "slug": "graphic-design", "name": "Graphic Design" }, "published_at": "..." },
      { "portfolio_id": 7, "title": "...", "cover_image_url": "...", "subcategory": { "id": 2, "slug": "branding", "name": "Branding" }, "published_at": "..." }
    ] } }
```

`items` is one tab per subcategory (a client has at most one item per subcategory) in the configured subcategory order; the details of a tab come from `GET /portfolio/{portfolio_id}`.

### 9.5 The two browsing experiences

* **A - Portfolio-based:** All → `GET /portfolio` (cards, filters, pagination) → `GET /portfolio/{id}` (tabs from `client_category_items`).
* **B - Client-based:** main category → `GET /portfolio/clients?category_id=` (client cards) → `GET /portfolio/clients/{client}?category_id=` (tabs) → `GET /portfolio/{portfolio_id}`.

Both read the same items, clients and categories.

## 10. Image storage and cleanup

* Images go to Cloudflare R2 under `portfolio/images/<uuid>.<ext>` through the existing `ImageStorageService` (the `portfolio/images/` prefix was added to its allowlist). Client filenames are never used. Only real PNG/JPEG content is accepted (MIME sniffed; WebP, GIF, SVG and disguised files are rejected), max 10 MB.
* Uploads happen before the database transaction. On any failure (validation, upload of a later file, database error) the new objects are removed, and existing data and objects are untouched.
* The item row and its media rows are read under a row lock; replaced/removed/deleted images are deleted **only after the commit** and only if no other media row references the key. A storage failure at that point is logged and never fails the request (the object becomes an orphan).
* **Cover image:** uploaded with the same pipeline (type sniffing, 10 MB, UUID key under `portfolio/images/`) and stored in `portfolio_items.cover_image_path`, never in `portfolio_media`. A replacement is uploaded first, saved under the row lock, and the old object is deleted only after the commit. A failed validation, upload or database write keeps the old cover and removes the new upload. Deletion of any image key (cover or work image) is skipped while another cover or media row still references it; deleting an item removes its cover after commit when unshared. The orphan cleanup counts covers as references.
* **Deleting an item** (`DELETE /admin/portfolio/{id}`, Admin / Super Admin only) is a permanent hard delete (no soft deletes): in one transaction the row is locked and deleted, the media rows go with it through the foreign key cascade and the cover reference disappears with the row. Only after the commit are the cover and work-image objects deleted, skipping any key that another cover or media row still references.
* **Deleting a client** removes the client, all of its portfolio items and their media rows in a single transaction (rows are locked first, the R2 keys are collected inside the transaction). If the transaction fails, no client or portfolio record is deleted and no R2 object is touched. After the commit the portfolio keys are cleaned up with the same shared-reference check, then the client logo with the existing logo lifecycle.
* **R2 failures after commit never undo the database deletion.** A storage failure is logged (warning `Compensating storage cleanup failed for object ...`) and the request still succeeds; the leftover object is unreferenced, so the weekly `portfolio:clean-orphaned-images` (and `clients:clean-orphaned-logos` for logos) removes it after the grace period. Nothing deletes an R2 object before the database transaction commits.
* Weekly orphan cleanup: `php artisan portfolio:clean-orphaned-images [--dry-run] [--force] [--grace-hours=24] [--max-deletions=50] [--allow-empty-references]`. Scheduled Mondays 05:00 with `--force --grace-hours=24 --max-deletions=50` and no overlap. Same safeguards as the other modules: dry-run default, R2 last-modified age, only `portfolio/images/`, UUID key validation, re-check before each delete, atomic lock, fail-closed on database/listing errors, abort when nothing references any image (wrong-database signal) or more than the maximum would be deleted. The window between an R2 upload and its database commit is protected only by the grace period.

## 11. Errors

Standard project JSON: `{"message": "...", "errors": {"field": ["..."]}}` with 401 (unauthenticated), 403 (forbidden, e.g. editor delete), 404 (unknown/unpublished item), 422 (validation / publish rules), 500 (unexpected, generic message in production).

## 12. Deployment (run later, not executed here)

1. Back up the database.
2. `php artisan migrate --force` - creates `portfolio_items` and `portfolio_media` (first release) and applies `2026_10_31_000001_add_client_and_cover_to_portfolio_items`: **additive only** (adds nullable `client_id`, `cover_image_path`, the generated `slot_subcategory_key` and the indexes; no data is dropped or rewritten, no client is assigned automatically). Requires a database that supports **stored generated columns**: MySQL 5.7.6+ or MariaDB 10.2.1+ (verified on MariaDB 10.4.32 in the local test environment; the Hostinger version has NOT been verified, check it first with the read-only command below). The foreign keys on `subcategory_id` use `RESTRICT`, which is allowed for columns used by a generated column (`CASCADE`/`SET NULL` update actions are not). Rows that already exist have no client: they stay editable and must be given a client (and a cover) before they can be published again; list them with `portfolio_items.client_id IS NULL`. Rows without a subcategory in a category that has subcategories (drafts created before the subcategory became mandatory) can be listed with `subcategory_id IS NULL` and a non-Web-Design `category_id`; they cannot be saved, published or unpublished until a subcategory is chosen. Deployment also changes `DELETE /admin/clients/{id}`: it now cascades to the client's portfolio.
3. Read-only database version check (changes nothing): `php artisan tinker --execute="echo DB::selectOne('select version() as v')->v;"` or, from any SQL client, `SELECT VERSION();`. It must be MySQL ≥ 5.7.6 or MariaDB ≥ 10.2.1 before step 2.
4. Confirm the portfolio categories exist (`GET /api/v1/categories?type=portfolio` returns 6 parents). If not, `php artisan db:seed --class=CategorySeeder` (idempotent `updateOrCreate`; it also re-applies the News/Blogs seed data, so review first).
5. Ensure `CLOUDFLARE_R2_*` variables and `CLOUDFLARE_R2_PUBLIC_URL` (https public domain, not the S3 endpoint) are set.
6. PHP/server limits (not changed by the application; configure on the host when needed): `upload_max_filesize` ≥ 10M for a single image, and `post_max_size`, `max_file_uploads` (PHP default 20) and `max_input_vars` (default 1000, each `media[N][...]` field counts) sized for the number of images a dashboard sends per request. The application imposes no image-count limit; when a request exceeds a server limit, add the images through several update requests.
7. Cron `* * * * * php artisan schedule:run` and a lock-capable cache store (`cache_locks` table with `CACHE_STORE=database`).
8. Run `php artisan portfolio:clean-orphaned-images --dry-run` once and review before relying on the weekly job.
9. `php artisan route:cache` / `config:cache` as usual.

## 13. Tests

```
php vendor/bin/pest tests/Feature/Portfolio tests/Unit/PortfolioKindTest.php
php vendor/bin/pest                 # full regression
```

`PortfolioClientIntegrationTest` covers (among others) the mandatory subcategory for drafts, the client delete cascade (items, media rows, covers, shared objects, rollback on a database failure, R2 failure after commit, logo cleanup) and permanent portfolio deletion; it also covers client ownership and slot uniqueness (application and database level, nullable subcategory, concurrent duplicate), draft/publish rules, the cover lifecycle, public cards/filters/homepage/pagination, unique client listing, client navigation, details, and admin filters; `tests/Feature/CategoryHierarchyApiTest.php` covers the categories modes.

Includes `PortfolioAdjustmentsTest` (publication lifecycle parity with Events, no count limits, incremental add/remove/replace including Press Covering pairs).

Tests run on `waai_media_testing` with `Storage::fake('r2')`; no real R2 is contacted.

## 14. Categories API (hierarchy modes)

`GET /categories?type=portfolio` is the existing public endpoint; the new query parameters are optional and backward compatible (without them the response is exactly as before: main categories with `children`).

| Request | Result |
|---|---|
| `?type=portfolio` or `&level=all` or `&include_children=1` | the six main categories, each with `children` (`id`, `name`, `slug`, `parent_id`) |
| `&level=parent` or `&include_children=0` | the six main categories only (`id`, `name`, `slug`; no `children` key) |
| `&parent_id={id}` | a flat list of that parent's subcategories (empty for Web Design) |

`parent_id` must be a main category of the same `type` (a child id or a News category returns 422); it cannot be combined with `level`/`include_children`, and `level=parent` cannot be combined with `include_children=1`. The parameters work for `news` too; `blogs` has no children. Names follow `Accept-Language`. Ids and slugs are the seeded ones.

This endpoint lists **all configured** subcategories of a parent. The subcategories in which a particular client has published work come from `GET /portfolio/clients/{client}?category_id=` (section 9.4) - the two are intentionally separate.

## 15. Postman

`docs/Waai_Media_Portfolio_API.postman_collection.json` (112 requests, folders 0-9) with the existing `docs/Waai_Media_Local.postman_environment.json` (`base_url`, `admin_email`/`admin_password`, `editor_email`/`editor_password`, `device_name`). No token, password or secret is stored in the collection; the login requests (folder 0) call the existing `POST /auth/login` and store `admin_token` / `editor_token`.

Recommended sequence (use a local or test server):

1. **0 Authentication** - log in as Admin and Editor.
2. **1 Setup** - categories (stores every `cat_*` / `sub_*` id), the category hierarchy modes, and the clients (stores `client_id` = client A and `client_id_2` = client B).
3. **2 Create** - a draft with cover and images, one published example for each of the six main categories (image, video URL, Press Covering, Web Design) and a minimal draft.
4. **3 Update and Media** - update text, add work images, replace a selected image, remove selected images, replace the cover, Press URL and Web Design link changes, category changes with `confirm_media_reset`.
5. **4 Publish** - publish, save as draft, publish through update.
6. **5 Public** - cards, homepage latest three, category/subcategory/client filters, pagination, Arabic/English, unique clients, client navigation, direct details, draft exclusion.
7. **6 Admin list** - search, status, category, subcategory, client, combined filters, pagination.
8. **7 Unpublish** - unpublish, confirm the public API no longer shows it, republish.
9. **8 Negative tests** - validation and authorization (all refused, nothing is changed).
10. **9 DESTRUCTIVE** - creates a disposable client and portfolios, then deletes them. The two DELETE requests are skipped by a pre-request guard unless the collection variable `confirm_destructive` is `yes`, and they only use `test_client_id` / `test_portfolio_id` created in the same folder. Never run it against production.

File fields (`cover_image`, `media[N][image]`, `featured_image`, `logo`) are type File; select a PNG/JPG before sending. Because a client holds one item per slot, re-running folder 2 against the same clients answers 422; delete the earlier items first or choose other clients. Every request path was checked against the application's route list.
