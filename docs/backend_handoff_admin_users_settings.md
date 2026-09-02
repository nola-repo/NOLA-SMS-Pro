# Backend Handoff: Admin Users & System Settings

> **Frontend branch**: `AdminLayout.tsx`  
> **Date**: 2026-03-25  
> This document describes every backend API endpoint that the frontend now calls. Implement these PHP endpoints in your backend to make all admin features fully operational.

---

## 1. `GET /api/admin_users.php` — List Admin Users

Returns all admin users stored in Firestore.

### Response
```json
{
  "status": "success",
  "data": [
    {
      "username": "admin",
      "role": "super_admin",
      "active": true,
      "created_at": "2025-01-15",
      "last_login": "2026-03-25T05:23:11Z"
    }
  ]
}
```

**Firestore path**: `admins/{username}`  
**Fields required**: `username`, `role`, `active`, `created_at`, `last_login`

---

## 2. `POST /api/admin_users.php` — Create / Reset / Toggle / Record Login

All mutating actions use POST with a JSON body. The `action` field routes the operation.

### 2a. Create Admin

```json
{ "action": "create", "username": "nola_support", "password": "SecurePass1!", "role": "support" }
```

- Hash password with `password_hash($password, PASSWORD_BCRYPT)`
- Write to Firestore: `admins/{username}`
- Set `created_at` to current ISO timestamp, `active: true`, `last_login: null`

**Response**:
```json
{ "status": "success" }
```

---

### 2b. Reset Password

```json
{ "action": "reset_password", "username": "nola_support", "new_password": "NewPass456!" }
```

- Hash and update `password_hash` field at `admins/{username}`

**Response**:
```json
{ "status": "success" }
```

---

### 2c. Toggle Active Status

```json
{ "action": "toggle_status", "username": "nola_support", "active": false }
```

- Update `active` field at `admins/{username}`
- Inactive users should be rejected at login time by `admin_auth.php`

**Response**:
```json
{ "status": "success" }
```

---

### 2d. Record Login (Last Login Tracking)

Called automatically after every successful login. Fire-and-forget from the frontend.

```json
{ "action": "record_login", "username": "admin" }
```

- Update `last_login` field at `admins/{username}` to `new DateTime()->format(DateTime::ATOM)`

**Response**:
```json
{ "status": "success" }
```

---

## 3. `DELETE /api/admin_users.php` — Delete Admin

```json
{ "username": "nola_support" }
```

- Delete document at `admins/{username}`
- Prevent deletion of the last `super_admin`

**Response**:
```json
{ "status": "success" }
```

---

## 4. `GET /api/admin_settings.php` — Load Settings

Returns global platform settings stored in Firestore.

### Response
```json
{
  "status": "success",
  "data": {
    "sender_default": "NOLASMSPro",
    "free_limit": 10,
    "maintenance_mode": false,
    "poll_interval": 15
  }
}
```

**Firestore path**: `admin_config/global`  
**Fields**: all keys in `data` above

---

## 5. `POST /api/admin_settings.php` — Save Settings

```json
{
  "sender_default": "NOLASMSPro",
  "free_limit": 10,
  "maintenance_mode": false,
  "poll_interval": 15
}
```

- Merge/upsert into `admin_config/global` in Firestore

**Response**:
```json
{ "status": "success" }
```

> **Maintenance Mode enforcement**: When `maintenance_mode` is `true`, your SMS-sending backend (`sms.ts`, `retrieve_status.php`, etc.) should check this flag at the start of each send and return an error if active.

---

## 6. Existing: `POST /api/admin_auth.php` — Login (update needed)

The frontend already calls this. Make sure you also:
1. Verify `active === true` before allowing login — return `{ "status": "error", "message": "Account is deactivated." }` otherwise.
2. Validate credentials via `password_verify($password, $storedHash)`.

```json
// Request
{ "username": "admin", "password": "admin123" }

// Success response
{ "status": "success" }

// Failure response
{ "status": "error", "message": "Invalid credentials." }
```

---

## Firestore Schema Summary

| Collection | Document | Key Fields |
|---|---|---|
| `admins` | `{username}` | `username`, `role`, `active`, `password_hash`, `created_at`, `last_login` |
| `admin_config` | `global` | `sender_default`, `free_limit`, `maintenance_mode`, `poll_interval` |

---

## CORS / Auth Notes

- All `/api/admin_*.php` endpoints must allow `Content-Type: application/json`
- Consider verifying a session token / cookie for mutating operations in production
- Current auth is `localStorage`-based — suitable for internal tools, upgrade to cookie sessions for production hardening
