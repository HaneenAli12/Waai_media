# Authentication API Documentation

This document describes the authentication endpoints for the Waai Media frontend application. The authentication flow uses Laravel Sanctum to issue Bearer tokens. 

The API is fully verified against the backend configuration, ensuring exactly documented rate limits, timeouts, session rules, and concurrency safety.

---

## Endpoint Summary

| Endpoint | Method | Requires Auth | Purpose |
| :--- | :--- | :--- | :--- |
| `/api/v1/auth/login` | `POST` | No | Log in and receive a Bearer token. |
| `/api/v1/auth/forgot-password` | `POST` | No | Request a 4-digit OTP code via email. |
| `/api/v1/auth/verify-otp` | `POST` | No | Verify OTP to get a single-use reset token. |
| `/api/v1/auth/reset-password` | `POST` | No | Reset password & **revoke all existing sessions**. |
| `/api/v1/auth/me` | `GET` | Yes | Get the authenticated user's profile and session. |
| `/api/v1/auth/logout` | `POST` | Yes | Log out and revoke **only the current** token. |
| `/api/v1/auth/change-password` | `POST` | Yes | Change password & **revoke other devices' sessions**. |

---

## General Rules & Headers

All requests require the following headers to ensure correct JSON responses:

```http
Accept: application/json
Content-Type: application/json
```

For endpoints that require authentication, include the Bearer token returned from `/login`:

```http
Authorization: Bearer 1|abcdefghijklmnopqrstuvwxyz1234567890
```

### Multiple-Device Session Support
A user can be logged in on multiple devices (e.g., Desktop and Mobile) simultaneously.
- **Login:** Generating a new token on a new device will *not* invalidate previous tokens.
- **Logout:** Logging out on one device invalidates *only* the token used for that request. Other devices will remain logged in.

### Token Security (Frontend Guidance)
For a browser-based Angular application, storing the Bearer token in `localStorage` is vulnerable if your application suffers an XSS (Cross-Site Scripting) attack, as JavaScript can read `localStorage`.
*Note: Because this backend uses Sanctum API tokens instead of session cookies, you must manage token storage manually. If you require XSS-proof storage, the backend would need to be reconfigured to use Sanctum SPA cookie authentication instead of token-based authentication.*

### Standard Error Response Structure (Angular Interceptor)

Every API error returned by this backend is standardized to include a top-level **`message`** field containing a clear, non-empty string.

#### 1. General Error (400, 401, 403, 404, 405, 422, 429, 500)
```json
{
  "message": "Invalid email or password."
}
```

#### 2. Validation Error (422 Unprocessable Entity)
When Form Request validation fails, the response contains the top-level `message` string (summarizing the validation issue) and an `errors` object containing field-specific arrays of error messages:
```json
{
  "message": "The email field is required.",
  "errors": {
    "email": [
      "The email field is required."
    ]
  }
}
```

#### Angular HttpInterceptor & Toastr Guidance:
> **Important Angular Distinction (`error.message` vs `error.error.message`):**
> In Angular's standard `HttpErrorResponse`:
> - `error.message` is Angular's synthesized client-side / HTTP status description (e.g., `"Http failure response for http://localhost:8000/api/v1/auth/login: 422 Unprocessable Entity"`). If displayed in a Toaster, users see a technical HTTP wrapper rather than the user-friendly message from Laravel.
> - `error.error.message` is the backend's actual JSON message string returned by Laravel (e.g., `"Invalid email or password."`).
>
> In your Angular `HttpInterceptor`, extract the backend message as follows:
> ```typescript
> catchError((error: HttpErrorResponse) => {
>   const apiMessage = error.error?.message || 'An unexpected error occurred.';
>   this.toastr.error(apiMessage);
>
>   if (error.status === 401) {
>     this.authService.clearSession();
>     this.router.navigate(['/login']);
>   }
>
>   return throwError(() => error);
> })
> ```

### 429 Rate Limits
Endpoints are protected by strict rate limits. If a limit is exceeded, the server returns `429 Too Many Requests`:
```json
{
  "message": "Too many requests. Please try again later."
}
```
*(Or dynamic wait time messages such as `"Please wait 28 seconds before requesting a new code."` during OTP cooldown).*

---

## 1. Login

Log into the application and receive a Bearer token.

**Endpoint:** `POST /api/v1/auth/login`  
**Rate Limit:** 5 requests per minute (per normalized email or IP)

### Request Body
| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `email` | `string` | Yes | User's email address (case-insensitive) |
| `password` | `string` | Yes | User's password |
| `device_name` | `string` | Optional | Identifier for the user's current device (max: 100) |

### Success Response (200 OK)
Save the `token` securely on the client (e.g., in memory or a secure cookie/storage).
```json
{
  "message": "Login successful.",
  "token": "1|abcdef123456",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "Jane Doe",
    "email": "jane@example.com",
    "role": "editor"
  },
  "session_id": 45
}
```

### Error Responses
- **422 Unprocessable Entity:** `"message": "Invalid email or password."`
- **422 Unprocessable Entity:** `"message": "Account is deactivated."`

### cURL Example
```bash
curl -X POST https://<YOUR_API_BASE_URL>/api/v1/auth/login \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email": "jane@example.com", "password": "SecretPassword123!", "device_name": "iPhone 15"}'
```

---

## 2. Forgot Password (Request OTP)

Request a 4-digit OTP for password recovery. 
*Security Note: To prevent enumeration, this endpoint will return a 200 OK success message even if the email does not exist.*

**Endpoint:** `POST /api/v1/auth/forgot-password`  
**Rate Limits:** 
- 1 request per 30 seconds (cooldown per email)
- Maximum 6 successful emails sent per 60 minutes (per email)
- 20 requests per hour (per IP)
- 5 requests per minute (throttle limit per IP)

### Request Body
| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `email` | `string` | Yes | Email address of the account |

### Success Response (200 OK)
```json
{
  "message": "If an account exists with that email, a password reset code has been sent."
}
```

### Error Responses
- **429 Too Many Requests:** `"message": "Please wait X seconds before requesting a new code."` (Cooldown triggered)
- **429 Too Many Requests:** `"message": "Too many requests for this email. Please try again later."` (Max 6 sends/hr triggered)
- **429 Too Many Requests:** `"message": "Please wait a moment before requesting again."` (Concurrent bypass lock)

### cURL Example
```bash
curl -X POST https://<YOUR_API_BASE_URL>/api/v1/auth/forgot-password \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email": "jane@example.com"}'
```

---

## 3. Verify OTP

Verify the 4-digit code sent to the user's email.

**Endpoint:** `POST /api/v1/auth/verify-otp`  
**Security Rules:**
- The OTP expires strictly after **90 seconds**.
- The OTP becomes permanently invalid after **3 incorrect attempts**.
- Re-requesting an OTP via `/forgot-password` instantly invalidates the previous pending OTP.

### Request Body
| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `email` | `string` | Yes | Email address of the account |
| `otp` | `string` | Yes | The exact 4-digit OTP |

### Success Response (200 OK)
The `reset_token` is a 64-character string required for the final reset step. It expires in 5 minutes (300 seconds) and is strictly single-use.
```json
{
  "message": "OTP verified successfully.",
  "reset_token": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2",
  "expires_in": 300
}
```

### Error Responses
- **400 Bad Request:** `"message": "No active OTP request found for this email address."`
- **422 Unprocessable Entity:** `"message": "Invalid verification code. Attempts remaining: X."`
- **422 Unprocessable Entity:** `"message": "The verification code has expired. Please request a new code."`
- **429 Too Many Requests:** `"message": "Maximum verification attempts exceeded. Please request a new code."`

### cURL Example
```bash
curl -X POST https://<YOUR_API_BASE_URL>/api/v1/auth/verify-otp \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email": "jane@example.com", "otp": "1234"}'
```

---

## 4. Reset Password

Finalize the password recovery using the `reset_token`. 
*Security Note: A successful reset will immediately terminate all active sessions across all devices for this user. The user must log in again.*

**Endpoint:** `POST /api/v1/auth/reset-password`  

### Request Body
| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `email` | `string` | Yes | Email address of the account |
| `reset_token` | `string` | Yes | The 64-character token from `/verify-otp` |
| `password` | `string` | Yes | New password (minimum 8 characters) |
| `password_confirmation` | `string` | Yes | Must exactly match `password` |

### Success Response (200 OK)
```json
{
  "message": "Password has been reset successfully. Please log in with your new password."
}
```

### Error Responses
- **422 Unprocessable Entity:** `"message": "Invalid or expired password reset token."`

### cURL Example
```bash
curl -X POST https://<YOUR_API_BASE_URL>/api/v1/auth/reset-password \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "jane@example.com", 
    "reset_token": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2",
    "password": "NewPassword123!",
    "password_confirmation": "NewPassword123!"
  }'
```

---

## 5. Get Current User Profile (`/me`)

Retrieve the authenticated user's profile and active session context.

**Endpoint:** `GET /api/v1/auth/me`  
**Headers:** `Authorization: Bearer {token}`

### Success Response (200 OK)
```json
{
  "user": {
    "id": 1,
    "name": "Jane Doe",
    "email": "jane@example.com",
    "role": "editor",
    "created_at": "2026-10-01T12:00:00.000000Z"
  },
  "session": {
    "id": 45,
    "device_name": "iPhone 15",
    "ip_address": "192.168.1.1",
    "created_at": "2026-10-10T14:22:11.000000Z"
  }
}
```

### Error Response
- **401 Unauthorized:** Missing, revoked, or expired token. Note: Tokens expire automatically after 7 days of complete inactivity.

### cURL Example
```bash
curl -X GET https://<YOUR_API_BASE_URL>/api/v1/auth/me \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 1|abcdef123456"
```

---

## 6. Logout

Terminate the current device's session.

**Endpoint:** `POST /api/v1/auth/logout`  
**Headers:** `Authorization: Bearer {token}`

### Success Response (200 OK)
```json
{
  "message": "Successfully logged out."
}
```
*Frontend Integration Note:* Discard the token locally after receiving this response. Other logged-in devices are unaffected.

### cURL Example
```bash
curl -X POST https://<YOUR_API_BASE_URL>/api/v1/auth/logout \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 1|abcdef123456"
```

---

## 7. Change Password

Change the password while actively logged in.
*Security Note: Unlike Reset Password, a successful Change Password keeps the CURRENT session active, but revokes the tokens belonging to ALL OTHER devices.*

**Endpoint:** `POST /api/v1/auth/change-password`  
**Headers:** `Authorization: Bearer {token}`

### Request Body
| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `current_password` | `string` | Yes | The user's existing password |
| `password` | `string` | Yes | New password (minimum 8 chars, must differ from current) |
| `password_confirmation` | `string` | Yes | Must exactly match `password` |

### Success Response (200 OK)
```json
{
  "message": "Password successfully changed."
}
```

### Error Responses
- **422 Unprocessable Entity (Incorrect Current Password):**
```json
{
  "message": "The password is incorrect.",
  "errors": {
    "current_password": [
      "The password is incorrect."
    ]
  }
}
```
- **422 Unprocessable Entity (Same Password as Current):**
```json
{
  "message": "The password field and current password must be different.",
  "errors": {
    "password": [
      "The password field and current password must be different."
    ]
  }
}
```
- **401 Unauthorized:**
```json
{
  "message": "Unauthenticated."
}
```

### cURL Example
```bash
curl -X POST https://<YOUR_API_BASE_URL>/api/v1/auth/change-password \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer 1|abcdef123456" \
  -d '{
    "current_password": "OldPassword123!",
    "password": "NewPassword123!",
    "password_confirmation": "NewPassword123!"
  }'
```

---

## Frontend Integration Checklist
- [ ] Add an Angular `HttpInterceptor` to globally append the `Accept: application/json` header and the `Authorization: Bearer {token}` header to all requests.
- [ ] Bind Toaster notifications to `error.error?.message` (not `error.message`) so user-friendly Laravel error messages are displayed.
- [ ] Implement a unified error handler in your `HttpInterceptor` for `401 Unauthorized` that deletes the local token, clears local user state, and redirects the user to `/login` via the Angular `Router`.
- [ ] Standardize parsing of `422 Unprocessable Entity` responses in your form components to display inline form validation errors (read from `error.error?.errors`).
- [ ] During the OTP flow, start a 30-second countdown timer on the UI before allowing the user to click "Resend Code" (aligns with the backend's 30-second cooldown rate limit).
- [ ] Ensure the OTP input explicitly blocks submission or displays a timeout error locally after 90 seconds (when the OTP expires).
- [ ] Handle `429 Too Many Requests` gracefully by parsing the `error.error?.message` string, which contains dynamic wait-time instructions during cooldowns.
