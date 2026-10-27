# Waai Media — User Management API Documentation

This document describes the administrative User Management API for the Waai Media backend (Laravel 13 API backend, Angular Admin Dashboard).

The User Management module provides administrative endpoints for managing accounts, roles, and credentials subject to authoritative role-based authorization constraints and permanent protections.

---

## 1. Security & Business Rules

### Authoritative Role Hierarchy & Permissions

#### Super Admin
- **Full Operational Access**: Has full access to all dashboard modules and administrative operations.
- **Account Management**: Can view, create, update, delete, and reset passwords for `admin` and `editor` accounts.
- **Role Assignment**: Only Super Admin can change roles between `admin` and `editor` (`PATCH /api/v1/admin/users/{id}/role`).
- **Creation Constraint**: Cannot create another Super Admin account through ordinary user-management API endpoints (`super_admin` role assignment is strictly rejected).
- **Designated Protected Account**: The designated Super Admin account `waaimedia@gmail.com` is permanently protected against deletion, demotion, and email changes through ordinary user-management operations.

#### Admin
- **Editor Management Only**: Within user management, regular Administrators can manage `editor` accounts only.
- **No Admin Creation**: Cannot create `admin` accounts (rejected with `403 Forbidden` / `422`).
- **No Role Assignment**: Cannot change any user's role (rejected with `403 Forbidden`).
- **Peer & Super Admin Isolation**: Cannot view (`GET /api/v1/admin/users/{id}`), modify (`PUT/PATCH`), delete (`DELETE`), or reset the password (`PATCH /password`) of another `admin` or the `super_admin` account (rejected with `403 Forbidden`).

#### Editor
- **Forbidden from User Management**: Users with `role: editor` are strictly forbidden from all User Management endpoints (`/api/v1/admin/users/*`) and receive `403 Forbidden`.
- **Self-Account Immutability**: Editors cannot modify any aspect of their own account (name, email, role, or password) through any API endpoint, including public password recovery flows.

---

### Protected Super Admin & Safety Constraints

1. **Designated Super Admin Protection (`waaimedia@gmail.com`)**:
   - The designated Super Admin account is permanently protected at the model, request, and service layers.
   - Deletion (`DELETE /api/v1/admin/users/{id}`), role demotion (`PATCH /role`), and email changes (`PUT/PATCH`) targeting this account are unconditionally rejected with `403 Forbidden`.
2. **Last Active Administrator Protection**:
   - The system prevents any operation that would leave zero active administrators (`super_admin` or `admin`) in the database.
   - Any deletion or demotion that reduces active administrators below 1 is rejected with `403 Forbidden`:
     `"Cannot delete or demote the last remaining active administrator."`
3. **Session & Token Invalidation**:
   - When a user's role is modified, all active sessions and Sanctum tokens across all devices are immediately revoked.
   - When an account's password is reset by an administrator, all active sessions and tokens for that user are immediately revoked.
   - When a user is deleted, all active sessions and tokens are revoked prior to removal.
4. **Audit Logging**:
   - All user creation, modification, role change, password reset, and deletion events are permanently logged in `activity_logs` with the actor ID, IP address, user-agent, and relevant metadata.

---

## 2. Resource Schema

### UserAdminResource
Exposed safely without password, remember tokens, or internal session fields:
```json
{
  "id": 1,
  "name": "Super Admin",
  "email": "waaimedia@gmail.com",
  "role": "super_admin",
  "created_at": "2026-10-25T10:00:00.000000Z",
  "updated_at": "2026-10-25T10:00:00.000000Z"
}
```

---

## 3. Endpoint Reference

| Method | URI | Description | Allowed Roles |
|:---|:---|:---|:---|
| `GET` | `/api/v1/admin/users` | List dashboard users (paginated, search, filter) | `super_admin`, `admin` |
| `POST` | `/api/v1/admin/users` | Create a user account (`editor` for Admin; `admin`/`editor` for Super Admin) | `super_admin` (Admin/Editor), `admin` (Editor only) |
| `GET` | `/api/v1/admin/users/{user}` | View a single user details | `super_admin` (All), `admin` (Editor only) |
| `PUT`/`PATCH` | `/api/v1/admin/users/{user}` | Update user profile details | `super_admin` (Admin/Editor), `admin` (Editor only) |
| `PATCH` | `/api/v1/admin/users/{user}/role` | Change a user's role (`admin` <-> `editor`) | `super_admin` strictly |
| `PATCH` | `/api/v1/admin/users/{user}/password`| Reset a user's password | `super_admin` (Admin/Editor), `admin` (Editor only) |
| `DELETE` | `/api/v1/admin/users/{user}` | Permanently delete a user | `super_admin` (Admin/Editor), `admin` (Editor only) |

---

## 4. Detailed Endpoint Contracts

### 4.1 List Users
**`GET /api/v1/admin/users`**

Retrieves a paginated list of users ordered by creation date descending.

#### Query Parameters
- `page` *(optional, integer)*: Page number (default: 1).
- `per_page` *(optional, integer)*: Items per page (default: 15).
- `role` *(optional, string)*: Filter by role (`super_admin`, `admin`, or `editor`).
- `search` *(optional, string)*: Search substring in `name` or `email` (auto-escaped for SQL LIKE safety).

#### Headers
```http
Authorization: Bearer <access_token>
Accept: application/json
```

#### Success Response (200 OK)
```json
{
  "data": [
    {
      "id": 2,
      "name": "Jane Editor",
      "email": "editor@waaimedia.com",
      "role": "editor",
      "created_at": "2026-10-25T12:00:00.000000Z",
      "updated_at": "2026-10-25T12:00:00.000000Z"
    },
    {
      "id": 1,
      "name": "Super Admin",
      "email": "waaimedia@gmail.com",
      "role": "super_admin",
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

Creates a new user account. Super Admin can create `admin` or `editor`. Regular Admin can create `editor` only.

#### Headers
```http
Authorization: Bearer <access_token>
Content-Type: application/json
Accept: application/json
```

#### Request Body
```json
{
  "name": "Alice Editor",
  "email": "alice@waaimedia.com",
  "password": "SecurePassword123!",
  "role": "editor"
}
```

#### Validation Rules
- `name`: Required, string, max: 255.
- `email`: Required, valid email (`email:rfc,dns`), max: 255, unique in `users` table.
- `password`: Required, string, min: 8 characters, satisfies Password defaults.
- `role`: Required, string. For Super Admin: `in:admin,editor`. For Admin: `in:editor`. (`super_admin` is never assignable).

#### Success Response (201 Created)
```json
{
  "message": "User created successfully.",
  "data": {
    "id": 3,
    "name": "Alice Editor",
    "email": "alice@waaimedia.com",
    "role": "editor",
    "created_at": "2026-10-26T01:00:00.000000Z",
    "updated_at": "2026-10-26T01:00:00.000000Z"
  }
}
```

#### Error Responses
- **403 Forbidden** (Regular Admin attempting to create an `admin` account):
  ```json
  {
    "message": "Forbidden. Regular administrators cannot create admin accounts."
  }
  ```
- **422 Unprocessable Content** (Duplicate email):
  ```json
  {
    "message": "This email address is already in use.",
    "errors": {
      "email": ["This email address is already in use."]
    }
  }
  ```

---

### 4.3 View User Details
**`GET /api/v1/admin/users/{user}`**

Retrieves a single user's detailed information by ID.

#### Authorization Rules
- **Super Admin**: Can view details of any account (`super_admin`, `admin`, or `editor`).
- **Admin**: Can view details of `editor` accounts only. Requesting an `admin` or `super_admin` account returns **`403 Forbidden`**.

#### Headers
```http
Authorization: Bearer <access_token>
Accept: application/json
```

#### Success Response (200 OK)
```json
{
  "data": {
    "id": 2,
    "name": "Jane Editor",
    "email": "editor@waaimedia.com",
    "role": "editor",
    "created_at": "2026-10-25T12:00:00.000000Z",
    "updated_at": "2026-10-25T12:00:00.000000Z"
  }
}
```

#### Error Responses
- **403 Forbidden** (Admin attempting to inspect an Admin or Super Admin account):
  ```json
  {
    "message": "This action is unauthorized."
  }
  ```
- **404 Not Found**: If target user ID does not exist.

---

### 4.4 Update User Profile
**`PUT|PATCH /api/v1/admin/users/{user}`**

Updates profile fields (`name`, `email`, optional `password`).

#### Authorization Rules
- **Super Admin**: Can edit `admin` and `editor` accounts. Protected account `waaimedia@gmail.com` cannot have its email changed.
- **Admin**: Can edit `editor` accounts only. Attempting to edit an `admin` or `super_admin` account returns **`403 Forbidden`**.

#### Headers
```http
Authorization: Bearer <access_token>
Content-Type: application/json
Accept: application/json
```

#### Request Body
```json
{
  "name": "Jane Editor Updated",
  "email": "jane.updated@waaimedia.com"
}
```

#### Success Response (200 OK)
```json
{
  "message": "User updated successfully.",
  "data": {
    "id": 2,
    "name": "Jane Editor Updated",
    "email": "jane.updated@waaimedia.com",
    "role": "editor",
    "created_at": "2026-10-25T12:00:00.000000Z",
    "updated_at": "2026-10-26T01:10:00.000000Z"
  }
}
```

#### Error Responses
- **403 Forbidden** (Admin attempting to edit an Admin or Super Admin account):
  ```json
  {
    "message": "This action is unauthorized."
  }
  ```

---

### 4.5 Change User Role
**`PATCH /api/v1/admin/users/{user}/role`**

Promotes an Editor to Admin or demotes an Admin to Editor. Super Admin only.

#### Authorization Rules
- **Super Admin Strictly**: Only Super Admin can change user roles.
- **Admin**: Receives **`403 Forbidden`**.
- **Protected Super Admin Guard**: The designated Super Admin account cannot be demoted (`403 Forbidden`).
- **Last Active Admin Guard**: An admin cannot be demoted if they are the only remaining active administrator (`403 Forbidden`).

#### Headers
```http
Authorization: Bearer <super_admin_token>
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
    "id": 2,
    "name": "Jane Editor",
    "email": "editor@waaimedia.com",
    "role": "admin",
    "created_at": "2026-10-25T12:00:00.000000Z",
    "updated_at": "2026-10-26T01:15:00.000000Z"
  }
}
```

#### Error Responses
- **403 Forbidden** (Regular Admin attempting role change):
  ```json
  {
    "message": "This action is unauthorized."
  }
  ```

---

### 4.6 Reset User Password (Admin-Managed)
**`PATCH /api/v1/admin/users/{user}/password`**

Resets an account's password and immediately revokes all of their active sessions and tokens across all devices.

#### Authorization Rules
- **Super Admin**: Can reset passwords for `admin` and `editor` accounts. Cannot reset the protected Super Admin account password via this endpoint (`403 Forbidden`).
- **Admin**: Can reset passwords for `editor` accounts only. Attempting to reset an `admin` or `super_admin` password returns **`403 Forbidden`**.

#### Headers
```http
Authorization: Bearer <access_token>
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
- **403 Forbidden** (Admin attempting to reset another Admin or Super Admin password):
  ```json
  {
    "message": "This action is unauthorized."
  }
  ```

---

### 4.7 Delete User
**`DELETE /api/v1/admin/users/{user}`**

Permanently deletes a user account after revoking all sessions and tokens.

#### Authorization Rules & Safety Guards
- **Super Admin**: Can delete `admin` and `editor` accounts. Protected account `waaimedia@gmail.com` cannot be deleted (`403 Forbidden`). Cannot delete last remaining active administrator (`403 Forbidden`).
- **Admin**: Can delete `editor` accounts only. Cannot delete other `admin` or `super_admin` accounts (`403 Forbidden`).

#### Headers
```http
Authorization: Bearer <access_token>
Accept: application/json
```

#### Success Response (200 OK)
```json
{
  "message": "User permanently deleted successfully."
}
```

#### Error Responses
- **403 Forbidden** (Deleting designated Super Admin account):
  ```json
  {
    "message": "The designated Super Admin account is permanently protected and cannot be deleted."
  }
  ```
- **403 Forbidden** (Admin attempting to delete another Administrator):
  ```json
  {
    "message": "Regular administrators cannot delete other administrators."
  }
  ```
