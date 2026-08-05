# Backend Handoff: Admin User Management API

**Status:** Implementation Required / Fix Pending
**File Path:** `api/admin_users.php`

## Objective
Enable full CRUD management of administrative accounts within the Admin Dashboard. Current frontend requests are failing, likely due to missing or misconfigured files on the production server.

## Firestore Schema
**Collection:** `admins`
**Document ID:** `{username}` (e.g., `admin`, `admin_rae`)

| Field | Type | Description |
| :--- | :--- | :--- |
| `username` | string | Unique login name. |
| `role` | string | `super_admin`, `support`, or `viewer`. |
| `active` | boolean | Account status (true/false). |
| `hashed_password` | string | BCRYPT hashed password. |
| `created_at` | timestamp | Firestore Server Timestamp. |
| `last_login` | timestamp | Updated upon successful login. |

## Required Endpoints

### 1. GET - List All Admins
**Endpoint:** `api/admin_users.php`
- **Response Format:**
  ```json
  {
    "status": "success",
    "data": [
      {
        "username": "admin_rae",
        "role": "super_admin",
        "active": true,
        "created_at": "2026-03-24T10:11:17Z",
        "last_login": "2026-03-30T16:41:24Z"
      }
    ]
  }
  ```

### 2. POST - Create Admin
**Payload:** `{ "action": "create", "username": "...", "password": "...", "role": "..." }`
- Must check if user already exists.
- Must hash password using `password_hash(..., PASSWORD_BCRYPT)`.

### 3. POST - Reset Password
**Payload:** `{ "action": "reset_password", "username": "...", "new_password": "..." }`

### 4. POST - Toggle Status
**Payload:** `{ "action": "toggle_status", "username": "...", "active": boolean }`

### 5. DELETE - Delete Admin
**Payload:** `{ "username": "..." }`
- **Safety Rule:** Do not allow deletion of the last remaining `super_admin`.

## Dependencies
- `vendor/autoload.php` (Google Cloud SDK)
- `api/webhook/firestore_client.php` (for `get_firestore()`)
- `api/cors.php`
