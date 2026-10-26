# Waai Media — User Management API Documentation

This document describes the administrative User Management API for the Waai Media backend (Laravel 13 API backend, Angular Admin Dashboard).

The User Management module allows authenticated administrators (`role: admin`) to manage team accounts, assign roles, reset editor passwords, and manage permissions with built-in safety constraints protecting the system's administration continuity.

---

## 1. Security & Business Rules

### Role-Based Access Control (RBAC)
- **Admin Only**: All endpoints (`/api/v1/admin/users/*`) require authentication with `role: admin` and a token bearing the `access` ability (`auth:sanctum`, `role:admin`, `require.access`).
- **Editor Forbidden (403)**: Users with `role: editor` are strictly forbidden from all User Management endpoints and receive `403 Forbidden`.
- **Unauthenticated (401)**: Requests without a valid Bearer token or with an expired/revoked token receive `401 Unauthorized`.

### First Administrator Ever Created Protection
- The first administrator account ever created in the database is automatically and permanently recorded in `system_settings` (`first_admin_user_id`).
- This protected identity remains permanent and immutable even across role changes, soft deletes, or subsequent account creation.
- **Permanent Delete Guard**: Any attempt to delete the first admin ever created (`DELETE /api/v1/admin/users/{id}`) is rejected with **`403 Forbidden`**:
  `"The first administrator account ever created in the database is protected and cannot be deleted."`

### Last Active Administrator Protection
- The system prevents any operation that would leave zero active administrators in the database.
- **Demotion Guard**: An admin cannot demote themselves or another admin to `editor` if they are the only remaining active admin. Rejected with **`403 Forbidden`**:
  `"Cannot delete or demote the last remaining active administrator."`
- **Deletion Guard**: An admin cannot delete another admin or themselves if that admin is the last active admin remaining. Rejected with **`403 Forbidden`**:
  `"Cannot delete or demote the last remaining active administrator."`

### Peer Admin Isolation & Self-Service
- **Profile Updates (`PUT/PATCH /admin/users/{user}`)**:
  - An Admin can update an Editor's profile (`name`, `email`).
  - An Admin can update their **own** profile.
  - An Admin **cannot** update another Admin's profile (rejected with `403 Forbidden`).
- **Password Resets (`PATCH /admin/users/{user}/password`)**:
  - An Admin can reset an Editor's password.
  - An Admin **cannot** reset another Admin's password via Users Management (rejected with `403 Forbidden`).
  - Admins resetting their own password must use self-service `/api/v1/auth/change-password`.

### Session & Token Invalidation
- When a user's **role is modified**, all their active sessions and Sanctum tokens across all devices are immediately revoked to enforce new authorization boundaries.
- When an editor's **password is reset by an admin**, all their active sessions and tokens are immediately revoked.
- When a user is **deleted**, all their active sessions and tokens are revoked prior to account removal.

### Audit Logging
- All user creation, modification, role change, password reset, and deletion events are permanently logged in `activity_logs` with the actor ID, IP address, user-agent, and relevant metadata (excluding passwords and sensitive credentials).

---

## 2. Resource Schema

### UserAdminResource
Exposed safely without password, remember tokens, or internal session fields:
```json
{
  "id": 1,
  "name": "Jane Doe",
  "email": "jane@waaimedia.com",
  "role": "admin",
  "created_at": "2026-10-25T10:00:00.000000Z",
  "updated_at": "2026-10-25T10:00:00.000000Z"
}
```

---

## 3. Endpoint Reference

| Method | URI | Description | Auth Required |
|:---|:---|:---|:---|
| `GET` | `/api/v1/admin/users` | List dashboard users (paginated, search, filter) | Admin |
| `POST` | `/api/v1/admin/users` | Create a new user (Admin or Editor) | Admin |
| `GET` | `/api/v1/admin/users/{user}` | View a single user details | Admin |
| `PUT`/`PATCH` | `/api/v1/admin/users/{user}` | Update user profile details | Admin (Self or Editor) |
| `PATCH` | `/api/v1/admin/users/{user}/role` | Change a user's role | Admin (Last-Admin Guarded) |
| `PATCH` | `/api/v1/admin/users/{user}/password`| Reset an editor's password | Admin (Editor only) |
| `DELETE` | `/api/v1/admin/users/{user}` | Permanently delete a user | Admin (First/Last Admin Guarded) |

---

## 4. Detailed Endpoint Contracts

### 4.1 List Users
**`GET /api/v1/admin/users`**

Retrieves a paginated list of users ordered by creation date descending.

#### Query Parameters
- `page` *(optional, integer)*: Page number (default: 1).
- `per_page` *(optional, integer)*: Items per page (default: 15).
- `role` *(optional, string)*: Filter by role (`admin` or `editor`).
- `search` *(optional, string)*: Search substring in `name` or `email` (auto-escaped for SQL LIKE safety).

#### Headers
```http
Authorization: Bearer <admin_access_token>
Accept: application/json
```

#### Success Response (200 OK)
```json
{
  "data": [
    {
      "id": 2,
      "name": "John Editor",
      "email": "editor@waaimedia.com",
      "role": "editor",
      "created_at": "2026-10-25T12:00:00.000000Z",
      "updated_at": "2026-10-25T12:00:00.000000Z"
    },
    {
      "id": 1,
      "name": "Super Admin",
      "email": "admin@waaimedia.com",
      "role": "admin",
      "created_at": "2026-10-25T10:00:00.000000Z",
      "updated_at": "2026-10-25T10:00:00.000000Z"
    }
  ],
  "links": {
    "first": "http://127.0.0.1:8000/api/v1/admin/users?page=1",
    "last": "http://127.0.0.1:8000/api/v1/admin/users?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "links": [
      {
        "url": null,
        "label": "&laquo; Previous",
        "active": false
      },
      {
        "url": "http://127.0.0.1:8000/api/v1/admin/users?page=1",
        "label": "1",
        "active": true
      },
      {
        "url": null,
        "label": "Next &raquo;",
        "active": false
      }
    ],
    "path": "http://127.0.0.1:8000/api/v1/admin/users",
    "per_page": 15,
    "to": 2,
    "total": 2
  }
}
```

---

### 4.2 Create User
**`POST /api/v1/admin/users`**

Creates a new user account (`admin` or `editor`).

#### Headers
```http
Authorization: Bearer <admin_access_token>
Content-Type: application/json
Accept: application/json
```

#### Request Body
```json
{
  "name": "Alice Cooper",
  "email": "alice@waaimedia.com",
  "password": "SecurePassword123!",
  "role": "editor"
}
```

#### Validation Rules
- `name`: Required, string, max: 255.
- `email`: Required, valid email (`email:rfc,dns`), max: 255, unique in `users` table.
- `password`: Required, string, min: 8 characters, satisfies Laravel Password defaults.
- `role`: Required, string, must be one of `admin`, `editor`.

#### Success Response (201 Created)
```json
{
  "message": "User created successfully.",
  "data": {
    "id": 3,
    "name": "Alice Cooper",
    "email": "alice@waaimedia.com",
    "role": "editor",
    "created_at": "2026-10-26T01:00:00.000000Z",
    "updated_at": "2026-10-26T01:00:00.000000Z"
  }
}
```

#### Error Responses
- **422 Unprocessable Content** (Duplicate email):
  ```json
  {
    "message": "This email address is already in use.",
    "errors": {
      "email": ["This email address is already in use."]
    }
  }
  ```
- **422 Unprocessable Content** (Weak password or invalid role):
  ```json
  {
    "message": "The selected role must be either admin or editor.",
    "errors": {
      "role": ["The selected role must be either admin or editor."]
    }
  }
  ```

---

### 4.3 View User Details
**`GET /api/v1/admin/users/{user}`**

Retrieves a single user's detailed information by ID.

#### Headers
```http
Authorization: Bearer <admin_access_token>
Accept: application/json
```

#### Success Response (200 OK)
```json
{
  "data": {
    "id": 2,
    "name": "John Editor",
    "email": "editor@waaimedia.com",
    "role": "editor",
    "created_at": "2026-10-25T12:00:00.000000Z",
    "updated_at": "2026-10-25T12:00:00.000000Z"
  }
}
```

#### Error Responses
- **404 Not Found**: If user ID does not exist.

---

### 4.4 Update User
**`PUT|PATCH /api/v1/admin/users/{user}`**

Updates profile fields (`name`, `email`, optional `password`).

#### Authorization Rules
- Admin can update an Editor's account.
- Admin can update their **own** account.
- Admin **cannot** update another Admin's account (returns 403 Forbidden).

#### Headers
```http
Authorization: Bearer <admin_access_token>
Content-Type: application/json
Accept: application/json
```

#### Request Body
```json
{
  "name": "Alice Cooper Updated",
  "email": "alice.updated@waaimedia.com"
}
```
*(Optional field `password`: if provided, updates password and revokes existing sessions).*

#### Success Response (200 OK)
```json
{
  "message": "User updated successfully.",
  "data": {
    "id": 3,
    "name": "Alice Cooper Updated",
    "email": "alice.updated@waaimedia.com",
    "role": "editor",
    "created_at": "2026-10-26T01:00:00.000000Z",
    "updated_at": "2026-10-26T01:10:00.000000Z"
  }
}
```

#### Error Responses
- **403 Forbidden** (Attempting to modify another Admin):
  ```json
  {
    "message": "This action is unauthorized."
  }
  ```

---

### 4.5 Change User Role
**`PATCH /api/v1/admin/users/{user}/role`**

Promotes an Editor to Admin or demotes an Admin to Editor. Enforces the last-active-admin guard and immediately invalidates all active sessions/tokens of the target user.

#### Headers
```http
Authorization: Bearer <admin_access_token>
Content-Type: application/json
Accept: application/json
```

#### Request Body
```json
{
  "role": "admin"
}
```

#### Success Response (200 OK)
```json
{
  "message": "User role updated successfully.",
  "data": {
    "id": 3,
    "name": "Alice Cooper",
    "email": "alice@waaimedia.com",
    "role": "admin",
    "created_at": "2026-10-26T01:00:00.000000Z",
    "updated_at": "2026-10-26T01:15:00.000000Z"
  }
}
```

#### Error Responses
- **403 Forbidden** (Demoting the last active administrator):
  ```json
  {
    "message": "Cannot delete or demote the last remaining active administrator."
  }
  ```

---

### 4.6 Reset User Password (Admin-Managed)
**`PATCH /api/v1/admin/users/{user}/password`**

Resets an editor's password and immediately revokes all of their active sessions and tokens.

#### Authorization Rules
- Allowed for target user with `role: editor`.
- Disallowed if target user has `role: admin` (Admin cannot reset peer Admin passwords; returns `403 Forbidden`).

#### Headers
```http
Authorization: Bearer <admin_access_token>
Content-Type: application/json
Accept: application/json
```

#### Request Body
```json
{
  "password": "BrandNewPassword123!",
  "password_confirmation": "BrandNewPassword123!"
}
```

#### Success Response (200 OK)
```json
{
  "message": "User password reset successfully."
}
```

#### Error Responses
- **403 Forbidden** (Attempting to reset an Admin's password):
  ```json
  {
    "message": "This action is unauthorized."
  }
  ```
- **422 Unprocessable Content** (Password confirmation mismatch):
  ```json
  {
    "message": "The password field confirmation does not match.",
    "errors": {
      "password": ["The password field confirmation does not match."]
    }
  }
  ```

---

### 4.7 Delete User
**`DELETE /api/v1/admin/users/{user}`**

Permanently deletes a user account after revoking all sessions and tokens.

#### Safety Guards
1. **First Admin Guard**: If the target user is the first admin ever created, request is rejected with `403 Forbidden`.
2. **Last Active Admin Guard**: If the target user is the last remaining active admin, request is rejected with `403 Forbidden`.
3. **Editors**: Can always be safely hard deleted.

#### Headers
```http
Authorization: Bearer <admin_access_token>
Accept: application/json
```

#### Success Response (200 OK)
```json
{
  "message": "User permanently deleted successfully."
}
```

#### Error Responses
- **403 Forbidden** (Deleting first admin ever created):
  ```json
  {
    "message": "The first administrator account ever created in the database is protected and cannot be deleted."
  }
  ```
- **403 Forbidden** (Deleting last active admin):
  ```json
  {
    "message": "Cannot delete or demote the last remaining active administrator."
  }
  ```
