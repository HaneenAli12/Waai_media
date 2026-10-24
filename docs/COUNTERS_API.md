# Waai Media — Counters API Documentation

This document describes the API contract and architecture for the **Counters Module** of the Waai Media platform (Laravel 13 API backend, Angular Admin Dashboard, and Angular Public Website).

The Counters module manages exactly five fixed system metrics displayed across the public marketing website and maintained via the authenticated admin dashboard.

---

## 1. Module Overview & Core Rules

### 1.1 Fixed System Counters

The system contains exactly five predefined counters. Their keys, names, initial values, and display order are immutable:

| Order | Key | Display Name | Initial Value | Supported Limits |
| :---: | :--- | :--- | :---: | :--- |
| **1** | `clients_served` | Clients Served | 15 | Non-negative integer (`0` to `2,147,483,647`) |
| **2** | `campaigns_launched` | Campaigns Launched | 400 | Non-negative integer (`0` to `2,147,483,647`) |
| **3** | `social_content_published` | Social Content Published | 2000 | Non-negative integer (`0` to `2,147,483,647`) |
| **4** | `industries_covered` | Industries Covered | 18 | Non-negative integer (`0` to `2,147,483,647`) |
| **5** | `marketing_experts` | Marketing Experts | 10 | Non-negative integer (`0` to `2,147,483,647`) |

### 1.2 Immutability & Safety Constraints
- **Stable Identifiers:** The 5 counters are permanent system records.
- **No Creation or Deletion:** Users cannot create new counters, delete existing counters, or rename them. Only numerical `value` is editable.
- **Fixed Display Order:** The order is permanently preserved (`1..5`).
- **English Labels:** Display names are stored in English. Localization (e.g. Arabic) is managed entirely within the Angular frontend.
- **Atomic Batch Updates:** All updates to one or multiple counters occur within a database transaction. If any submitted value fails validation, zero records are modified.
- **Partial Submission Allowed:** Unchanged counters do not need to be submitted. Unspecified counters retain their existing database values.
- **Frontend Interaction Ownership:** The Angular frontend manages increment/decrement arrows and manual numeric input. The backend exposes only the batch-update endpoint.

---

## 2. Authorization & Security

| Role | Public Endpoint (`/counters`) | Admin Read (`/admin/counters`) | Admin Update (`/admin/counters`) |
| :--- | :---: | :---: | :---: |
| **Public / Unauthenticated** | Allowed | `401 Unauthorized` | `401 Unauthorized` |
| **Admin** (`role: admin`) | Allowed | Allowed | Allowed |
| **Editor** (`role: editor`) | Allowed | Allowed | Allowed |
| **Other Roles** | Allowed | `403 Forbidden` | `403 Forbidden` |

Authenticated endpoints require:
1. `Authorization: Bearer <access_token>` (Valid Sanctum Access Token).
2. Access Token ability: `access` (enforced by `require.access` middleware; Remember Tokens are rejected with `403 Forbidden`).
3. User role must be either `admin` or `editor` (enforced by `role:admin,editor` middleware).

---

## 3. Endpoint Summary

| Endpoint | Method | Auth / Middleware | Purpose |
| :--- | :--- | :--- | :--- |
| `/api/v1/counters` | `GET` | Public | Returns all five counters in order for the public website. |
| `/api/v1/admin/counters` | `GET` | `auth:sanctum`, `require.access`, `role:admin,editor` | Returns all five counters for the dashboard editor. |
| `/api/v1/admin/counters` | `PUT` / `PATCH` | `auth:sanctum`, `require.access`, `role:admin,editor` | Atomically updates one or more counter values. |

---

## 4. Public API

### 4.1 Get Public Counters

Retrieve the five counters in agreed display order.

- **Route:** `GET /api/v1/counters`
- **Authentication:** None (Public)
- **Headers:**
  - `Accept: application/json`

#### Success Response (`200 OK`)
```json
{
  "data": [
    {
      "id": 1,
      "key": "clients_served",
      "name": "Clients Served",
      "value": 15,
      "order": 1
    },
    {
      "id": 2,
      "key": "campaigns_launched",
      "name": "Campaigns Launched",
      "value": 400,
      "order": 2
    },
    {
      "id": 3,
      "key": "social_content_published",
      "name": "Social Content Published",
      "value": 2000,
      "order": 3
    },
    {
      "id": 4,
      "key": "industries_covered",
      "name": "Industries Covered",
      "value": 18,
      "order": 4
    },
    {
      "id": 5,
      "key": "marketing_experts",
      "name": "Marketing Experts",
      "value": 10,
      "order": 5
    }
  ]
}
```

---

## 5. Admin Dashboard API

### 5.1 Get Admin Counters

Retrieve the current counter values for administrative display and editing.

- **Route:** `GET /api/v1/admin/counters`
- **Authentication:** Bearer Access Token (`Admin` or `Editor`)
- **Headers:**
  - `Accept: application/json`
  - `Authorization: Bearer <access_token>`

#### Success Response (`200 OK`)
```json
{
  "data": [
    {
      "id": 1,
      "key": "clients_served",
      "name": "Clients Served",
      "value": 15,
      "order": 1
    },
    {
      "id": 2,
      "key": "campaigns_launched",
      "name": "Campaigns Launched",
      "value": 400,
      "order": 2
    },
    {
      "id": 3,
      "key": "social_content_published",
      "name": "Social Content Published",
      "value": 2000,
      "order": 3
    },
    {
      "id": 4,
      "key": "industries_covered",
      "name": "Industries Covered",
      "value": 18,
      "order": 4
    },
    {
      "id": 5,
      "key": "marketing_experts",
      "name": "Marketing Experts",
      "value": 10,
      "order": 5
    }
  ]
}
```

---

### 5.2 Batch Update Counters

Update one, multiple, or all counters in a single atomic request.

- **Route:** `PUT /api/v1/admin/counters` or `PATCH /api/v1/admin/counters`
- **Authentication:** Bearer Access Token (`Admin` or `Editor`)
- **Headers:**
  - `Accept: application/json`
  - `Content-Type: application/json`
  - `Authorization: Bearer <access_token>`

#### Supported Request Formats

The backend normalizes both standard list formats and key-value maps:

##### Format A: Array of Objects (Recommended)
```json
{
  "counters": [
    {
      "key": "clients_served",
      "value": 20
    },
    {
      "key": "campaigns_launched",
      "value": 450
    }
  ]
}
```
*(Counters may be identified either by `"key"` or by `"id"`).*

##### Format B: Key-Value Dictionary
```json
{
  "counters": {
    "clients_served": 20,
    "campaigns_launched": 450
  }
}
```

##### Format C: Top-Level Array
```json
[
  {
    "key": "clients_served",
    "value": 20
  }
]
```

#### Field Specifications

| Field | Type | Required | Constraints |
| :--- | :--- | :---: | :--- |
| `counters` | `array` | Yes | Array of counter updates (minimum 1 item). |
| `counters.*.key` | `string` | Optional* | Must be one of: `clients_served`, `campaigns_launched`, `social_content_published`, `industries_covered`, `marketing_experts`. (*Either `key` or `id` required). |
| `counters.*.id` | `integer` | Optional* | Must match an existing counter ID (`1..5`). |
| `counters.*.value` | `integer` | Yes | Integer between `0` and `2,147,483,647` (inclusive). Non-negative. |

#### Success Response (`200 OK`)
Returns the complete, ordered list of all five counters reflecting the newly saved state:
```json
{
  "message": "Counters updated successfully.",
  "data": [
    {
      "id": 1,
      "key": "clients_served",
      "name": "Clients Served",
      "value": 20,
      "order": 1
    },
    {
      "id": 2,
      "key": "campaigns_launched",
      "name": "Campaigns Launched",
      "value": 450,
      "order": 2
    },
    {
      "id": 3,
      "key": "social_content_published",
      "name": "Social Content Published",
      "value": 2000,
      "order": 3
    },
    {
      "id": 4,
      "key": "industries_covered",
      "name": "Industries Covered",
      "value": 18,
      "order": 4
    },
    {
      "id": 5,
      "key": "marketing_experts",
      "name": "Marketing Experts",
      "value": 10,
      "order": 5
    }
  ]
}
```

---

## 6. Error Responses

### 6.1 Validation Errors (`422 Unprocessable Entity`)

Standardized format with top-level `message` string and detailed `errors` object.

#### Scenario: Negative Counter Value
```json
{
  "message": "The counters.0.value field must be at least 0.",
  "errors": {
    "counters.0.value": [
      "The counters.0.value field must be at least 0."
    ]
  }
}
```

#### Scenario: Unknown Counter Identifier
```json
{
  "message": "The selected counters.0.key is invalid.",
  "errors": {
    "counters.0.key": [
      "The selected counters.0.key is invalid."
    ]
  }
}
```

#### Scenario: Missing Value Field
```json
{
  "message": "The counters.0.value field is required.",
  "errors": {
    "counters.0.value": [
      "The counters.0.value field is required."
    ]
  }
}
```

#### Scenario: Value Exceeds Range
```json
{
  "message": "The counters.0.value field must not be greater than 2147483647.",
  "errors": {
    "counters.0.value": [
      "The counters.0.value field must not be greater than 2147483647."
    ]
  }
}
```

### 6.2 Authentication & Authorization Errors

#### Scenario: Unauthenticated (`401 Unauthorized`)
```json
{
  "message": "Unauthenticated."
}
```

#### Scenario: Insufficient Permissions (`403 Forbidden`)
When an authenticated user lacks the `admin` or `editor` role:
```json
{
  "message": "Unauthorized. Required role: admin,editor"
}
```

---

## 7. Angular Frontend Integration Pattern

In Angular, the `ManageCountersComponent` interacts with the API via the following lifecycle:

1. **Component Initialization (`ngOnInit`):**
   Calls `GET /api/v1/admin/counters` to retrieve all five counters.
   Stores them in an Angular Reactive Form array or signal state.
2. **User Interaction:**
   The user modifies numbers directly or clicks increment/decrement buttons.
   No API requests are sent during increment/decrement interactions.
3. **Save Action:**
   The user clicks **Save**. The component sends a single `PUT /api/v1/admin/counters` request containing the modified counters.
4. **State Refresh:**
   Upon receiving `200 OK`, the component displays a success toaster using the returned `message` and updates its local state with the returned `data` array.
