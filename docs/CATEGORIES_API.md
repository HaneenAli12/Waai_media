# Waai Media — Categories API Documentation

This document describes the API contract, database design, and architecture for the **Shared Static Categories Module** of the Waai Media backend platform (Laravel 13 API backend, Angular Admin Dashboard, and Angular Public Website).

The Categories module provides a centralized, multilingual, read-only category taxonomy for three content modules:
1. **News** (Hierarchical: 9 top-level categories, including Entertainment with 4 subcategories)
2. **Blogs** (Flat: 5 categories)
3. **Portfolio** (Flat: 6 categories)

Total: **Exactly 24 category records**.

---

## 1. Module Overview & Core Architecture

### 1.1 Single Shared Endpoint Pattern
- **Public & Unauthenticated:** Category discovery is public and does not require authentication.
- **Single Canonical Endpoint:** `GET /api/v1/categories?type={news|blogs|portfolio}`
- **No Route Aliases:** The endpoint is strictly served at `/api/v1/categories`. No `/api/categories` alias exists.
- **Required Query Parameter:** The `type` query parameter is required. Requests with missing or invalid `type` return `HTTP 422 Unprocessable Content`.

### 1.2 Localization & Language Negotiation
- **Header:** Localization is negotiated via the standard HTTP `Accept-Language` header.
- **Language Negotiation:** Implemented using `App\Support\LocaleNegotiator`, supporting:
  - Language subtags: `ar`, `en`
  - Regional tags: e.g. `ar-SA`, `ar-EG`, `en-US`, `en-GB`
  - Quality weights: e.g. `Accept-Language: ar-SA,ar;q=0.9,en;q=0.8`
  - Unsupported languages (e.g. `fr-FR`) fallback gracefully to English (`en`).
  - Missing or empty `Accept-Language` headers default to English (`en`).
- **Response Format:** Returns localized `name` (`name_ar` when Arabic is negotiated, `name_en` when English is negotiated).

### 1.3 Structural Distinction Between Modules
- **News (Hierarchical):** Top-level categories expose a `children` array.
  - The `entertainment` category contains exactly 4 children (`celebrities`, `in-theaters`, `tv-shows`, `book-reviews`).
  - Other News categories return an empty array `[]` for `children`.
- **Blogs & Portfolio (Flat):** Returns a flat list of category objects without a `children` key.

### 1.4 Idempotent & Deterministic Seeding
- The database seeder (`CategorySeeder`) operates in two deterministic passes:
  1. Top-level categories are seeded/updated by unique compound key `['type', 'slug']`.
  2. Child categories are resolved and linked by verifying the parent slug within the same module type.
- Reseeding preserves existing primary keys (`id`) without truncating tables or duplicating rows.

---

## 2. Complete Static Taxonomy (24 Categories)

### 2.1 News Categories (9 Top-Level + 4 Children = 13 records)

| ID (Sample) | Type | Sort Order | Slug | English Name | Arabic Name | Parent Slug | Hierarchy Level |
| :---: | :---: | :---: | :--- | :--- | :--- | :--- | :--- |
| 1 | `news` | 1 | `health` | Health | الصحة | *None* | Top-Level (Leaf) |
| 2 | `news` | 2 | `food` | Food | الطعام | *None* | Top-Level (Leaf) |
| 3 | `news` | 3 | `fashion-beauty` | Fashion & Beauty | الموضة والجمال | *None* | Top-Level (Leaf) |
| 4 | `news` | 4 | `travel` | Travel | السفر | *None* | Top-Level (Leaf) |
| 5 | `news` | 5 | `economy` | Economy | الاقتصاد | *None* | Top-Level (Leaf) |
| 6 | `news` | 6 | `sports` | Sports | الرياضة | *None* | Top-Level (Leaf) |
| 7 | `news` | 7 | `entertainment` | Entertainment | الترفيه | *None* | Top-Level (Parent) |
| 8 | `news` | 8 | `podcast` | Podcast | بودكاست | *None* | Top-Level (Leaf) |
| 9 | `news` | 9 | `events` | Events | الفعاليات | *None* | Top-Level (Leaf) |
| 10 | `news` | 1 | `celebrities` | Celebrities | المشاهير | `entertainment` | Child Subcategory |
| 11 | `news` | 2 | `in-theaters` | In Theaters | في دور السينما | `entertainment` | Child Subcategory |
| 12 | `news` | 3 | `tv-shows` | TV Shows | البرامج والمسلسلات التلفزيونية | `entertainment` | Child Subcategory |
| 13 | `news` | 4 | `book-reviews` | Book Reviews | مراجعات الكتب | `entertainment` | Child Subcategory |

### 2.2 Blogs Categories (5 Records — Flat)

| ID (Sample) | Type | Sort Order | Slug | English Name | Arabic Name | Parent Slug | Note |
| :---: | :---: | :---: | :--- | :--- | :--- | :--- | :--- |
| 14 | `blogs` | 1 | `digital-marketing-trends` | Digital Marketing Trends | اتجاهات التسويق الرقمي | *None* | Flat category |
| 15 | `blogs` | 2 | `case-studied` | Case Studied | دراسات الحالة | *None* | Preserved exact name |
| 16 | `blogs` | 3 | `tips-guides` | Tips & Guides | نصائح وأدلة إرشادية | *None* | Flat category |
| 17 | `blogs` | 4 | `creative-ideas` | Creative Ideas | أفكار إبداعية | *None* | Flat category |
| 18 | `blogs` | 5 | `behind-the-scenes` | Behind the Scenes | خلف الكواليس | *None* | Flat category |

### 2.3 Portfolio Categories (6 Records — Flat)

| ID (Sample) | Type | Sort Order | Slug | English Name | Arabic Name | Parent Slug | Note |
| :---: | :---: | :---: | :--- | :--- | :--- | :--- | :--- |
| 19 | `portfolio` | 1 | `social-media-campaign` | Social Media Campaign | حملات وسائل التواصل الاجتماعي | *None* | Flat category |
| 20 | `portfolio` | 2 | `media-production` | Media Production | الإنتاج الإعلامي | *None* | Flat category |
| 21 | `portfolio` | 3 | `pr` | PR | العلاقات العامة | *None* | Flat category |
| 22 | `portfolio` | 4 | `events` | Events | الفعاليات | *None* | Coexists with news events |
| 23 | `portfolio` | 5 | `opening` | Opening | الافتتاحات | *None* | Flat category |
| 24 | `portfolio` | 6 | `web-design` | Web Design | تصميم المواقع الإلكترونية | *None* | Flat category |

---

## 3. Reusable Category Validation Rule

The application provides a dedicated validation rule:
`App\Rules\ValidCategoryForModule($module, $disallowParentCategories = true)`

### Validation Rules Enforced:
1. **Module Scoping:** Verifies that the given category belongs to the target module (`news`, `blogs`, or `portfolio`). Cross-module category IDs are rejected.
2. **Parent Disallowance for News:** In News, the `entertainment` category is an organizational parent. Articles cannot be directly assigned to `entertainment`; they must be assigned to one of its 4 children. The rule enforces this automatically.
3. **Leaf Category Allowance:** Leaf categories (e.g. `health`, `sports`, `celebrities`, `case-studied`) pass validation for their respective modules.
4. **Existence & Type Safety:** Rejects non-numeric, non-existent, or soft-deleted IDs.

Usage in Form Requests:
```php
public function rules(): array
{
    return [
        'category_id' => ['required', 'integer', new ValidCategoryForModule('news')],
    ];
}
```

---

## 4. API Endpoint Specification

### 4.1 Get Categories

- **Route:** `GET /api/v1/categories?type={news|blogs|portfolio}`
- **Authentication:** Public (None)
- **Headers:**
  - `Accept: application/json`
  - `Accept-Language: ar-SA,ar;q=0.9,en;q=0.8` (Optional, defaults to `en`)
- **Query Parameters:**
  - `type` (required, string): `news`, `blogs`, or `portfolio`.

#### Success Response — News (English Default)
`GET /api/v1/categories?type=news`
```json
{
  "data": [
    {
      "id": 1,
      "name": "Health",
      "slug": "health",
      "children": []
    },
    {
      "id": 2,
      "name": "Food",
      "slug": "food",
      "children": []
    },
    {
      "id": 3,
      "name": "Fashion & Beauty",
      "slug": "fashion-beauty",
      "children": []
    },
    {
      "id": 4,
      "name": "Travel",
      "slug": "travel",
      "children": []
    },
    {
      "id": 5,
      "name": "Economy",
      "slug": "economy",
      "children": []
    },
    {
      "id": 6,
      "name": "Sports",
      "slug": "sports",
      "children": []
    },
    {
      "id": 7,
      "name": "Entertainment",
      "slug": "entertainment",
      "children": [
        {
          "id": 10,
          "name": "Celebrities",
          "slug": "celebrities"
        },
        {
          "id": 11,
          "name": "In Theaters",
          "slug": "in-theaters"
        },
        {
          "id": 12,
          "name": "TV Shows",
          "slug": "tv-shows"
        },
        {
          "id": 13,
          "name": "Book Reviews",
          "slug": "book-reviews"
        }
      ]
    },
    {
      "id": 8,
      "name": "Podcast",
      "slug": "podcast",
      "children": []
    },
    {
      "id": 9,
      "name": "Events",
      "slug": "events",
      "children": []
    }
  ]
}
```

#### Success Response — News (Arabic Localized)
`GET /api/v1/categories?type=news` with `Accept-Language: ar`
```json
{
  "data": [
    {
      "id": 1,
      "name": "الصحة",
      "slug": "health",
      "children": []
    },
    {
      "id": 2,
      "name": "الطعام",
      "slug": "food",
      "children": []
    },
    {
      "id": 3,
      "name": "الموضة والجمال",
      "slug": "fashion-beauty",
      "children": []
    },
    {
      "id": 4,
      "name": "السفر",
      "slug": "travel",
      "children": []
    },
    {
      "id": 5,
      "name": "الاقتصاد",
      "slug": "economy",
      "children": []
    },
    {
      "id": 6,
      "name": "الرياضة",
      "slug": "sports",
      "children": []
    },
    {
      "id": 7,
      "name": "الترفيه",
      "slug": "entertainment",
      "children": [
        {
          "id": 10,
          "name": "المشاهير",
          "slug": "celebrities"
        },
        {
          "id": 11,
          "name": "في دور السينما",
          "slug": "in-theaters"
        },
        {
          "id": 12,
          "name": "البرامج والمسلسلات التلفزيونية",
          "slug": "tv-shows"
        },
        {
          "id": 13,
          "name": "مراجعات الكتب",
          "slug": "book-reviews"
        }
      ]
    },
    {
      "id": 8,
      "name": "بودكاست",
      "slug": "podcast",
      "children": []
    },
    {
      "id": 9,
      "name": "الفعاليات",
      "slug": "events",
      "children": []
    }
  ]
}
```

#### Success Response — Blogs (English Default)
`GET /api/v1/categories?type=blogs`
```json
{
  "data": [
    {
      "id": 14,
      "name": "Digital Marketing Trends",
      "slug": "digital-marketing-trends"
    },
    {
      "id": 15,
      "name": "Case Studied",
      "slug": "case-studied"
    },
    {
      "id": 16,
      "name": "Tips & Guides",
      "slug": "tips-guides"
    },
    {
      "id": 17,
      "name": "Creative Ideas",
      "slug": "creative-ideas"
    },
    {
      "id": 18,
      "name": "Behind the Scenes",
      "slug": "behind-the-scenes"
    }
  ]
}
```

#### Success Response — Blogs (Arabic Localized)
`GET /api/v1/categories?type=blogs` with `Accept-Language: ar`
```json
{
  "data": [
    {
      "id": 14,
      "name": "اتجاهات التسويق الرقمي",
      "slug": "digital-marketing-trends"
    },
    {
      "id": 15,
      "name": "دراسات الحالة",
      "slug": "case-studied"
    },
    {
      "id": 16,
      "name": "نصائح وأدلة إرشادية",
      "slug": "tips-guides"
    },
    {
      "id": 17,
      "name": "أفكار إبداعية",
      "slug": "creative-ideas"
    },
    {
      "id": 18,
      "name": "خلف الكواليس",
      "slug": "behind-the-scenes"
    }
  ]
}
```

#### Success Response — Portfolio (English Default)
`GET /api/v1/categories?type=portfolio`
```json
{
  "data": [
    {
      "id": 19,
      "name": "Social Media Campaign",
      "slug": "social-media-campaign"
    },
    {
      "id": 20,
      "name": "Media Production",
      "slug": "media-production"
    },
    {
      "id": 21,
      "name": "PR",
      "slug": "pr"
    },
    {
      "id": 22,
      "name": "Events",
      "slug": "events"
    },
    {
      "id": 23,
      "name": "Opening",
      "slug": "opening"
    },
    {
      "id": 24,
      "name": "Web Design",
      "slug": "web-design"
    }
  ]
}
```

#### Success Response — Portfolio (Arabic Localized)
`GET /api/v1/categories?type=portfolio` with `Accept-Language: ar`
```json
{
  "data": [
    {
      "id": 19,
      "name": "حملات وسائل التواصل الاجتماعي",
      "slug": "social-media-campaign"
    },
    {
      "id": 20,
      "name": "الإنتاج الإعلامي",
      "slug": "media-production"
    },
    {
      "id": 21,
      "name": "العلاقات العامة",
      "slug": "pr"
    },
    {
      "id": 22,
      "name": "الفعاليات",
      "slug": "events"
    },
    {
      "id": 23,
      "name": "الافتتاحات",
      "slug": "opening"
    },
    {
      "id": 24,
      "name": "تصميم المواقع الإلكترونية",
      "slug": "web-design"
    }
  ]
}
```

#### Error Response — Missing Type Parameter (HTTP 422)
`GET /api/v1/categories`
```json
{
  "message": "The category type is required.",
  "errors": {
    "type": [
      "The category type is required."
    ]
  }
}
```

#### Error Response — Invalid Type Parameter (HTTP 422)
`GET /api/v1/categories?type=invalid_type`
```json
{
  "message": "The selected category type is invalid. Supported types are news, blogs, and portfolio.",
  "errors": {
    "type": [
      "The selected category type is invalid. Supported types are news, blogs, and portfolio."
    ]
  }
}
```
