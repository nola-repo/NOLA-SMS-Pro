# Backend Handoff: Admin Dashboard Controls

## Overview
The frontend `AdminLayout.tsx` has been fully wired up to communicate with the backend for managing Admin Users and global System Settings. It currently utilizes optimistic UI updates and local fallbacks until the backend APIs are deployed.

This document outlines the exact endpoints, request payloads, and response structures the frontend expects.

---

## 1. Admin Users Management (`/api/admin_users.php`)

This endpoint manages the root administrative users who can access the overarching Nola SMS Pro Admin Dashboard.

### A. Fetch All Admin Users
- **Method**: `GET`
- **Request**: `/api/admin_users.php`
- **Response Format**:
  ```json
  {
    "status": "success",
    "data": [
      {
        "username": "nola_admin",
        "role": "super_admin",
        "active": true,
        "created_at": "2026-03-24",
        "last_login": "2026-03-26T14:30:00Z" // ISO string or null
      },
      {
        "username": "support_agent",
        "role": "support",
        "active": false,
        "created_at": "2026-03-25",
        "last_login": null
      }
    ]
  }
  ```

### B. Create Admin User
- **Method**: `POST`
- **Body**:
  ```json
  {
    "action": "create",
    "username": "new_admin",
    "password": "secure_password123",
    "role": "support" // ENUM: 'super_admin', 'support', 'viewer'
  }
  ```
- **Response Format**: `{ "status": "success", "message": "Admin user created." }`

### C. Reset Password
- **Method**: `POST`
- **Body**:
  ```json
  {
    "action": "reset_password",
    "username": "existing_admin",
    "new_password": "new_secure_password"
  }
  ```
- **Response Format**: `{ "status": "success", "message": "Password reset successfully." }`

### D. Toggle Status (Activate / Deactivate)
- **Method**: `POST`
- **Body**:
  ```json
  {
    "action": "toggle_status",
    "username": "target_admin",
    "active": false // Boolean
  }
  ```
- **Response Format**: `{ "status": "success", "message": "Status updated." }`

### E. Delete Admin User
- **Method**: `DELETE`
- **Body**:
  ```json
  {
    "username": "target_admin"
  }
  ```
- **Response Format**: `{ "status": "success", "message": "Admin deleted." }`

---

## 2. Global System Settings (`/api/admin_settings.php`)

This endpoint manages platform-wide variables that apply across all sub-accounts (unless overridden).

### A. Fetch Current Settings
- **Method**: `GET`
- **Request**: `/api/admin_settings.php`
- **Response Format**:
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

### B. Update Settings
- **Method**: `POST`
- **Body Format** (Frontend sends the exact schema as above):
  ```json
  {
    "sender_default": "NOLASMSPro",
    "free_limit": 10,
    "maintenance_mode": true,
    "poll_interval": 15
  }
  ```
- **Response Format**: `{ "status": "success", "message": "Settings updated." }`

### Notes on Settings
* `sender_default`: The fallback alphanumeric Sender ID used for broadcasts.
* `free_limit`: The global number of allowed free SMS a new GHL sub-account can send before needing to purchase credits.
* `maintenance_mode`: A boolean flag. When `TRUE`, the frontend/backend should reject new outbound SMS requests globally.
* `poll_interval`: Rate limit for frontend background polling (Admin Dashboard activity feeds, typically 15 seconds).
