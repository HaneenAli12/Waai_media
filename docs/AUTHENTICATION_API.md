# Waai Media — Authentication API Documentation (Phase 2 Hardened)

This document describes the hardened authentication architecture and API contract for the Waai Media applications (Laravel 13 API backend, Angular Admin Dashboard, and Angular Public Website).

The system uses **Laravel Sanctum** with strict token ability separation, HttpOnly secure cookies, double-submit CSRF protection, fixed 30-day session windows, atomic token rotation with replay detection, and layered failure rate limiting.

---

## 1. Architecture & Security Model

### Token Separation & Delivery

| Feature | Access Token | Remember Token |
| :--- | :--- | :--- |
| **Storage Table** | `personal_access_tokens` | `personal_access_tokens` |
| **Sanctum Ability** | `access` | `remember` |
| **Lifetime** | 1 hour absolute (`min(now() + 1h, session_deadline)`) | Fixed 30 days from original login |
| **Delivery Mechanism** | Returned in Login/Refresh JSON response | Delivered strictly via `HttpOnly`, `SameSite` Cookie (`waai_remember_token`) |
| **Client Storage** | Angular **in-memory** only (never `localStorage`) | Browser cookie jar (managed automatically by browser) |
| **Transmission** | `Authorization: Bearer <access_token>` | Automatic via Cookie header on credentialed requests |
| **Usage Scope** | Normal protected API endpoints (`/me`, `/change-password`, etc.) | Strictly restricted to `/api/v1/auth/refresh` & cookie `/logout` |

> **Security Rule:** Any request presenting a Remember Token on normal protected API routes is rejected with **`403 Forbidden`** by the `RequireAccessTokenAbility` middleware.

### Fixed 30-Day Remember Me Session Window
- When `remember: true` is supplied during login, an original session deadline (`original_login_at + 30 days`) is anchored in `user_sessions.session_expires_at`.
- Token rotation on `/refresh` does **NOT** extend this 30-day deadline.
- When refreshing close to the 30-day boundary (e.g. 15 minutes remaining), the newly minted Access Token's `expires_in` is capped to remaining session time (900 seconds instead of 3600).
- When `remember: false`, no remember token or cookie is issued, and any stale cookie is cleared.

### Token Rotation & Replay Attack Detection
- Every refresh atomically generates a new Remember Token, invalidates the old Remember Token, and records its SHA-256 hash in `remember_token_rotations`.
- **Concurrency Grace Period (30 seconds):** Legitimate duplicate requests (e.g. network retry or concurrent tab loads within 30s) succeed without revoking the session.
- **Replay Compromise Detection:** If an invalidated Remember Token is presented **after** the 30-second grace period, the system detects a token replay/theft attack, immediately revokes the entire device session and all associated tokens, and responds with `401 Unauthorized`.

---

## 2. Endpoint Summary

| Endpoint | Method | Auth / Credentials | Purpose |
| :--- | :--- | :--- | :--- |
| `/api/v1/auth/csrf-token` | `GET` | Public | Obtains signed CSRF token and sets readable `XSRF-TOKEN` cookie. |
| `/api/v1/auth/login` | `POST` | Public | Authenticates credentials; issues Access Token and optional Remember Cookie. |
| `/api/v1/auth/refresh` | `POST` | Remember Cookie + CSRF | Rotates Remember Token and issues fresh Access Token without Bearer header. |
| `/api/v1/auth/logout` | `POST` | Access Token OR Remember Cookie | Revokes current device session and clears remember cookie. |
| `/api/v1/auth/logout-all` | `POST` | Access Token | Revokes all active sessions and tokens for user across all devices. |
| `/api/v1/auth/me` | `GET` | Bearer Access Token | Returns authenticated profile and session metadata. |
| `/api/v1/auth/change-password`| `POST` | Bearer Access Token | Updates password, revoking other devices while preserving current device. |
| `/api/v1/auth/forgot-password`| `POST` | Public | Requests 4-digit OTP via queued email (timing attack mitigated). |
| `/api/v1/auth/verify-otp` | `POST` | Public | Verifies 4-digit OTP and issues 64-character single-use reset token. |
| `/api/v1/auth/reset-password` | `POST` | Public | Consumes reset token, resets password, and revokes all sessions on all devices. |

---

## 3. Detailed Endpoint Contracts

### 3.1 CSRF Token Bootstrap

Obtain a CSRF token for cookie-authenticated endpoints (`/refresh` and cookie-based `/logout`).

**Endpoint:** `GET /api/v1/auth/csrf-token`

#### Response (200 OK)
Sets unencrypted, readable cookie `XSRF-TOKEN` (`HttpOnly=false`, `SameSite=Lax`, `Path=/`).
```json
{
  "csrf_token": "4a7f8e...signed_token"
}
```

---

### 3.2 Login

Authenticate user with layered failure rate limiting, multi-device support, and optional 30-day Remember Me cookie.

**Endpoint:** `POST /api/v1/auth/login`  
**Rate Limits:** 
- 5 failed attempts per normalized email + IP per minute
- 10 failed attempts per IP per minute
- 50 failed attempts per IP per 15 minutes

#### Request Body
```json
{
  "email": "admin@example.com",
  "password": "SecretPassword123!",
  "device_name": "Chrome on Windows",
  "remember": true
}
```
| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `email` | `string` | Yes | User's email address (normalized automatically) |
| `password` | `string` | Yes | Account password |
| `device_name` | `string` | Optional | Client device label (defaults to "Web Browser") |
| `remember` | `boolean`| Optional | If `true`, sets 30-day HttpOnly cookie. Defaults to `false`. |

#### Success Response (200 OK)
```json
{
  "message": "Login successful.",
  "token": "1|abcdef123456...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "user": {
    "id": 1,
    "name": "Admin User",
    "email": "admin@example.com",
    "role": "admin"
  },
  "session_id": 45
}
```
- When `remember: true`, response header sets:  
  `Set-Cookie: waai_remember_token=<token>; Path=/api/v1/auth; Max-Age=2592000; HttpOnly; SameSite=Lax`
- Remember Token is **NEVER** returned in JSON.

#### Error Responses
- **422 Unprocessable Entity:** `{"message": "Invalid email or password."}` (Timing-safe)
- **422 Unprocessable Entity:** `{"message": "Account is deactivated."}`
- **429 Too Many Requests:** `{"message": "Too many failed login attempts. Please try again later."}` with `Retry-After: 60` header.

---

### 3.3 Refresh Token

Restores or rotates a session using the HttpOnly Remember Cookie. Does not require a valid Access Token.

**Endpoint:** `POST /api/v1/auth/refresh`  
**Headers Required:**
```http
Accept: application/json
Origin: http://localhost:4200 (or http://localhost:4201)
X-XSRF-TOKEN: <csrf_token_from_XSRF-TOKEN_cookie>
```
**Credentials:** Client must send request with cookies (`withCredentials: true`).

#### Success Response (200 OK)
```json
{
  "message": "Token refreshed successfully.",
  "token": "2|new_access_token_value...",
  "token_type": "Bearer",
  "expires_in": 3600
}
```
- Sets rotated `waai_remember_token` cookie in response headers.
- Old Remember Token cannot be reused outside 30s grace period.

#### Error Responses
- **401 Unauthorized:** `{"message": "Remember token cookie missing or invalid."}`
- **401 Unauthorized:** `{"message": "Remember token has expired."}`
- **401 Unauthorized:** `{"message": "Token replay detected. Session revoked."}` (Triggered if rotated token reused)
- **403 Forbidden:** `{"message": "Forbidden. Invalid request origin."}`
- **419 Page Expired:** `{"message": "CSRF token mismatch."}`

---

### 3.4 Logout Current Device

Terminates the active session on this device and revokes both Access and Remember credentials.

**Endpoint:** `POST /api/v1/auth/logout`

#### Case A: Normal Logout (Access Token Valid)
- **Header:** `Authorization: Bearer <access_token>`
- Revokes current access token and paired remember token.
- Clears `waai_remember_token` cookie.

#### Case B: Expired Access Token Fallback
- If Access Token is expired, Angular sends `POST /api/v1/auth/logout` with credentials (`waai_remember_token` cookie + `X-XSRF-TOKEN` header).
- Server verifies cookie, revokes the remembered session and its tokens, and expires the cookie.

#### Success Response (200 OK)
```json
{
  "message": "Successfully logged out."
}
```

---

### 3.5 Logout All Devices

Revokes all active sessions, access tokens, and remember tokens for this user across all devices.

**Endpoint:** `POST /api/v1/auth/logout-all`  
**Headers:** `Authorization: Bearer <access_token>`

#### Success Response (200 OK)
```json
{
  "message": "Successfully logged out from all devices."
}
```
- Clears current browser's remember cookie.
- All tokens belonging to this user on any device are permanently deleted.

---

### 3.6 Change Password

Updates password while logged in. Revokes all other devices while preserving the current session.

**Endpoint:** `POST /api/v1/auth/change-password`  
**Headers:** `Authorization: Bearer <access_token>`

#### Request Body
```json
{
  "current_password": "OldPassword123!",
  "password": "NewSecretPassword456!",
  "password_confirmation": "NewSecretPassword456!"
}
```


#### Role Restriction
- **Admins (`super_admin`, `admin`)**: Permitted to update their own password.
- **Editors (`editor`)**: Strictly forbidden from changing their password (`403 Forbidden`).

#### Success Response (200 OK)
```json
{
  "message": "Password successfully changed."
}
```

---

### 3.7 Password Recovery (OTP)

#### Role-Based Policy & Editor Restriction
- **Admin & Super Admin**: Full self-service password recovery flow via OTP verification.
- **Editor Accounts**: Editors are strictly forbidden from self-account modification and password recovery.
  - On `POST /api/v1/auth/forgot-password`, if an email belongs to an `editor`, OTP generation and email dispatch are **silently suppressed**. To prevent account or role enumeration, the endpoint executes constant-time mitigation and returns the exact standard generic success message. *(Note: The identical response means suppression cannot be determined from the API response alone; verify via application activity logs or test instrumentation).*
  - On `POST /api/v1/auth/verify-otp`, any verification attempt for an `editor` account is rejected with **`422 Unprocessable Content`** (`"Invalid or expired OTP code."`).
  - On `POST /api/v1/auth/reset-password`, any reset attempt for an `editor` account is rejected with **`422 Unprocessable Content`** (`"Invalid or expired password reset token."`).

#### 1. Forgot Password (Request OTP)
**Endpoint:** `POST /api/v1/auth/forgot-password`  
- Preserves approved limits: 4 numeric digits, 90s expiry, 3 verification attempts, 6 sends/hr, 30s resend cooldown.
- Queues email asynchronously to prevent timing attacks and SMTP delay leakage.
- Returns identical response for existing, nonexistent, and editor emails.

```json
{
  "message": "If an account exists with that email, a password reset code has been sent."
}
```

#### 2. Verify OTP
**Endpoint:** `POST /api/v1/auth/verify-otp`  
Request: `{"email": "admin@waaimedia.com", "otp": "4821"}`  
Success Response (200 OK):
```json
{
  "message": "OTP verified successfully.",
  "reset_token": "64_character_hex_token...",
  "expires_in": 300
}
```

#### 3. Reset Password
**Endpoint:** `POST /api/v1/auth/reset-password`  
Request:
```json
{
  "email": "admin@waaimedia.com",
  "reset_token": "64_character_hex_token...",
  "password": "BrandNewPassword123!",
  "password_confirmation": "BrandNewPassword123!"
}
```
- Consumes reset token once.
- Revokes **all active sessions and tokens across all devices**.

---

## 4. Angular Integration Guide

### Token Lifecycle & Memory Storage
Angular should maintain the current Access Token strictly in an in-memory variable (e.g. within an `AuthService` private variable or `BehaviorSubject`), **never in `localStorage` or `sessionStorage`**.

### App Initialization & Session Restoration
When the Angular application bootstraps (`APP_INITIALIZER` or Angular Router Guard):
1. Angular checks if an in-memory Access Token exists.
2. If none exists (e.g. on fresh page load or page refresh), Angular attempts to restore the session by calling `/api/v1/auth/refresh` with credentials enabled (`withCredentials: true`).
3. If `/refresh` succeeds (200 OK):
   - Store the new `token` in memory.
   - Proceed to requested route.
4. If `/refresh` returns 401 or 419:
   - User does not have an active Remember Me session. Redirect to `/login`.

### Single-Flight Refresh Interceptor
To avoid sending multiple concurrent refresh requests when multiple parallel API requests fail with 401:
- Use an RxJS `BehaviorSubject` or locking mechanism in your `HttpInterceptor`.
- When the first 401 occurs, initiate `/refresh`.
- Queue subsequent 401 requests until `/refresh` completes.
- Retry queued requests with the new Access Token (maximum 1 retry).
- If refresh fails, clear auth state and redirect to `/login`.

### CORS & Credential Configuration
In Angular `HttpClient`:
```typescript
// Always pass withCredentials for cookie endpoints:
this.http.post('/api/v1/auth/refresh', {}, { withCredentials: true });
this.http.post('/api/v1/auth/logout', {}, { withCredentials: true });
```
And ensure your Angular proxy or environment configuration targets the correct API base URL.
