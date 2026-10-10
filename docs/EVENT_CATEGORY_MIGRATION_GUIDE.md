# Event Category Future Migration Architecture Guide
## Recommendation Only — Current Structure: Free-Text Bilingual Fields

---

### 1. Current Architectural Baseline (Active Design)
Event categories in this application are intentionally modeled as **direct bilingual free-text columns** on the `events` table:

```sql
-- Active events table structure (No relational event_categories table)
CREATE TABLE `events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name_ar` VARCHAR(255) NOT NULL,
    `name_en` VARCHAR(255) NOT NULL,
    `category_ar` VARCHAR(150) NOT NULL,
    `category_en` VARCHAR(150) NOT NULL,
    `description_ar` TEXT NULL,
    `description_en` TEXT NULL,
    `event_date` DATETIME NOT NULL,
    `place_ar` VARCHAR(255) NOT NULL,
    `place_en` VARCHAR(255) NOT NULL,
    `cover_image_path` VARCHAR(255) NOT NULL,
    `cover_image_url` VARCHAR(500) NOT NULL,
    `url` VARCHAR(500) NULL,
    `status` ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    `deleted_at` TIMESTAMP NULL,
    INDEX `idx_events_timeline` (`status`, `event_date`),
    INDEX `idx_events_category_ar` (`category_ar`),
    INDEX `idx_events_category_en` (`category_en`)
);
```

#### Key Active Invariants:
1. **NO `event_categories` table exists.**
2. **NO `category_id` foreign key exists on `events`.**
3. **NO category slug system exists for Events.**
4. **NO Event category CRUD, endpoints, or seeders exist.**
5. Categories are manually entered text strings in Arabic and English by Admins and Editors.

---

### 2. Isolation Strategy to Support Future Managed Categories

To ensure that a future transition from free-text categories to a managed relational system requires **zero rewrites** of core business logic or public queries, the backend isolates category access through the following conventions:

#### A. Query Isolation (Model Scopes & Accessors)
In `App\Models\Event`, category filtering and localized resolution are isolated through query scopes:
```php
// Query scope for category filtering (isolated from underlying column structure)
public function scopeForCategory(Builder $query, string $category): Builder
{
    // Currently queries bilingual text columns directly.
    // In a future relational migration, this single scope changes to:
    // return $query->whereRelation('category', 'slug', $category);
    return $query->where(function (Builder $q) use ($category) {
        $q->where('category_en', $category)
          ->orWhere('category_ar', $category);
    });
}
```

#### B. API Serialization Isolation
API Resources resolve the localized category string through a standardized accessor:
```php
// In PublicEventResource and PublicEventListResource:
'category' => app()->getLocale() === 'ar' ? $this->category_ar : $this->category_en,
```
This ensures the Angular Public Portal always receives `{ "category": "Technology" }` regardless of whether the category is backed by text columns or a future foreign key relation.

---

### 3. Future Step-by-Step Migration Blueprint (When Approved)

If the agency decides in a future phase to transition to managed Event Categories with dashboard CRUD and dropdowns, execute the following 3-step zero-downtime migration:

#### Step 1: Create the Managed Categories Table
```sql
CREATE TABLE `event_categories` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `name_ar` VARCHAR(150) NOT NULL,
    `name_en` VARCHAR(150) NOT NULL,
    `order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL
);
```

#### Step 2: Seed Unique Categories from Existing Data
Extract distinct `(category_ar, category_en)` pairs from the existing `events` table and insert them into `event_categories`, generating unique slugs using `Str::slug($category_en)`.

#### Step 3: Link Foreign Keys & Deprecate Text Columns
```sql
ALTER TABLE `events` ADD COLUMN `category_id` BIGINT UNSIGNED NULL AFTER `name_en`;

-- Populate foreign key mapping
UPDATE `events` e
JOIN `event_categories` ec ON ec.name_en = e.category_en AND ec.name_ar = e.category_ar
SET e.category_id = ec.id;

-- Enforce foreign key constraint
ALTER TABLE `events`
    MODIFY COLUMN `category_id` BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT `fk_events_category` FOREIGN KEY (`category_id`) REFERENCES `event_categories` (`id`) ON DELETE RESTRICT;

-- Drop or retain text columns as fallback
ALTER TABLE `events` DROP COLUMN `category_ar`, DROP COLUMN `category_en`;
```

---

### 4. Important Reminder
**DO NOT RUN OR IMPLEMENT THIS MIGRATION NOW.**  
The system strictly operates on manual, unmanaged bilingual free-text category fields as currently specified.
