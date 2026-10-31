# Waai Media — Blogs Module Production Deployment & Integration Guide

Production deployment guide for installing the **Blogs Module** on the Waai Media Hostinger Production Server.

---

## 1. Package Summary & Included Files

This deployment archive (`deployment/waai_media_blogs_module_deployment.zip`) includes:

### Application Code:
- `app/Console/Commands/CleanOrphanedBlogImagesCommand.php`
- `app/Http/Controllers/Api/Admin/BlogController.php`
- `app/Http/Controllers/Api/BlogController.php`
- `app/Http/Requests/Admin/Blog/IndexBlogRequest.php`
- `app/Http/Requests/Admin/Blog/PublishBlogRequest.php`
- `app/Http/Requests/Admin/Blog/StoreBlogRequest.php`
- `app/Http/Requests/Admin/Blog/UpdateBlogRequest.php`
- `app/Http/Requests/Admin/Blog/UploadBlogImageRequest.php`
- `app/Http/Requests/Blog/IndexPublicBlogRequest.php`
- `app/Http/Resources/BlogAdminResource.php`
- `app/Http/Resources/BlogPublicResource.php`
- `app/Jobs/DeleteUnreferencedBlogImagesJob.php`
- `app/Models/Blog.php`
- `app/Models/BlogImage.php`
- `app/Models/Category.php` (Includes `blogs()` relationship)
- `app/Services/Blog/BlogHtmlSanitizerService.php`
- `app/Services/Blog/BlogImageService.php`
- `app/Services/Blog/BlogSlugService.php`
- `app/Services/Storage/ImageStorageService.php` (Includes `blogs/covers/` and `blogs/editor/` prefixes)

### Routes:
- `routes/api.php` (Includes Blogs Admin and Public routes)
- `routes/console.php` (Includes weekly dry-run cleanup schedule for Blogs)

### Database Migrations:
- `database/migrations/2026_10_30_000001_create_blogs_module_tables.php`
- `database/migrations/2026_10_30_000002_add_tools_and_tech_to_blog_categories.php`

### Documentation & Postman:
- `docs/blogs-module.md`
- `docs/blogs-module.postman_collection.json`
- `docs/CATEGORIES_API.md`

---

## 2. Deployment Instructions (Step-by-Step)

### Step 1: Upload and Extract Files
Upload `waai_media_blogs_module_deployment.zip` to the root project directory on Hostinger and extract it, preserving relative file paths.

### Step 2: Run Production Database Migrations
Run the Artisan migrate command:
```bash
php artisan migrate --force
```
This safely runs:
- `2026_10_30_000001_create_blogs_module_tables` (Creates `blog_images`, `blogs`, and `blog_article_images` tables).
- `2026_10_30_000002_add_tools_and_tech_to_blog_categories` (Safely and idempotently adds `tools-tech` category).

### Step 3: Clear and Rebuild Configuration & Route Caches
```bash
php artisan config:clear
php artisan route:clear
php artisan route:cache
php artisan config:cache
```

### Step 4: Verify Hostinger System Crontab (Scheduler Prerequisite)
Ensure Hostinger has a system cron job executing every minute:
```bash
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```
*Note: The scheduled Blogs cleanup job executes in dry-run mode (without `--force`) to safely scan and report orphans.*

### Step 5: Queue Worker Verification
Ensure background queue processing is running (either via Supervisor daemon or periodic cron):
```bash
php artisan queue:work --stop-when-empty
```
*Note: Detached rich-text editor images dispatch `DeleteUnreferencedBlogImagesJob` to the queue.*