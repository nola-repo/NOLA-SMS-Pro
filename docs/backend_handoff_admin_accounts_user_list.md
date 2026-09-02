# Backend Handoff: Admin Accounts User List

Date: 2026-05-26
Frontend area: `admin/src/pages/components/AdminAccounts.tsx`
Backend repo inspected read-only: `C:\Users\User\nola-sms-pro-backend`

## Problem

The Admin > All Subaccounts screen should show registered user data, including email and phone, while still showing the GHL location name and location ID in the same account/name column.

Currently the frontend first calls:

```http
GET /api/admin_list_users.php
Authorization: Bearer <nola_admin_token>
```

When that request returns `401`, the frontend falls back to:

```http
GET /api/admin_sender_requests.php?action=accounts
```

That fallback is integration-based and does not return user email or phone, so the admin table shows blanks for those columns.

## Backend Files Inspected

- `api/admin_auth.php`
- `api/admin_list_users.php`
- `api/admin_sender_requests.php`
- `api/jwt_helper.php`
- `api/cors.php`

No backend files were changed.

## Required Backend Changes

### 1. Fix deployed admin JWT compatibility

`api/admin_list_users.php` requires a Bearer token and rejects the request with `401` when the token is missing, invalid, expired, or signed with a different secret.

Please verify the deployed versions of these endpoints use the same `JWT_SECRET`:

- `api/admin_auth.php`
- `api/admin_list_users.php`

The scanned local backend signs the admin token in `admin_auth.php` with:

```php
$secret = getenv('JWT_SECRET') ?: 'nola-super-admin-secret';
```

and verifies in `admin_list_users.php` with the same expression. If Cloud Run has different revision/env values, `admin_auth.php` can issue a token that `admin_list_users.php` rejects.

Expected login response:

```json
{
  "status": "success",
  "token": "admin_jwt_here",
  "user": {
    "username": "admin",
    "role": "super_admin"
  }
}
```

Acceptance test:

1. Log in via `POST /api/admin_auth.php`.
2. Copy the returned `token`.
3. Call `GET /api/admin_list_users.php` with `Authorization: Bearer <token>`.
4. The response should be HTTP 200 with `status: "success"`.

### 2. Add legacy admin header support or return a clearer auth error

Most admin frontend calls still include these headers:

```http
X-Admin-Auth: true
X-Admin-User: <username>
```

`admin_list_users.php` currently ignores them and only accepts Bearer JWT. Either keep strict JWT-only auth and make sure every deployed login returns a token, or allow the same admin fallback used by older admin endpoints.

If strict JWT-only is preferred, return one of these messages so the frontend can prompt a clean re-login:

```json
{ "status": "error", "message": "Admin token missing. Please log in again." }
{ "status": "error", "message": "Admin token expired. Please log in again." }
{ "status": "error", "message": "Admin token invalid. Please log in again." }
```

### 3. Ensure `/api/admin_list_users.php` is deployed

The local backend has `api/admin_list_users.php`, but the live behavior still looks like the admin screen is relying on the fallback endpoint. Confirm the Cloud Run image includes the latest file and route.

Expected response shape:

```json
{
  "status": "success",
  "data": [
    {
      "id": "user_doc_id",
      "name": "Rae Ivan",
      "email": "rae@example.com",
      "phone": "09155147644",
      "role": "user",
      "active": true,
      "location_id": "abc123",
      "location_name": "NOLA EventPro CRM",
      "company_id": "company123",
      "credit_balance": 13,
      "free_usage_count": 10,
      "free_credits_total": 10,
      "approved_sender_id": "NOLASMSPro",
      "source": "marketplace_install",
      "created_at": "2026-05-26T00:00:00Z"
    }
  ],
  "total": 1
}
```

### 4. If the integration fallback remains, enrich it with user data

`api/admin_sender_requests.php?action=accounts` currently fetches `integrations` and joins credit balance from `users`, but it does not include:

- `email`
- `phone`
- `name`
- `firstName`
- `lastName`
- `active`
- `role`

If the frontend must continue falling back to this endpoint, build an in-memory map from `users` by both `active_location_id` and `location_id`, then merge matching user profile fields into each integration result.

Suggested merge logic:

```php
$locationToUserMap = [];
foreach ($usersRaw as $userDoc) {
    if (!$userDoc->exists()) continue;
    $uData = $userDoc->data();
    foreach (['active_location_id', 'location_id'] as $field) {
        $loc = trim((string)($uData[$field] ?? ''));
        if ($loc !== '') {
            $locationToUserMap[$loc] = ['id' => $userDoc->id()] + $uData;
            $locationToUserMap['ghl_' . $loc] = ['id' => $userDoc->id()] + $uData;
        }
    }
}
```

Then, when building each integration account:

```php
$userData = $locationToUserMap[$locId] ?? $locationToUserMap['ghl_' . $locId] ?? [];

'name' => $userData['name'] ?? '',
'firstName' => $userData['firstName'] ?? '',
'lastName' => $userData['lastName'] ?? '',
'email' => $userData['email'] ?? '',
'phone' => $userData['phone'] ?? '',
'role' => $userData['role'] ?? 'user',
'active' => !array_key_exists('active', $userData) || !empty($userData['active']),
```

### 5. Keep location data source

Location name can continue to come from `ghl_tokens` or `integrations`. The frontend now displays `location_name` and `location_id` together in the Name column and no longer needs separate Location ID / Location Name columns.

## Frontend Changes Already Made

- Removed Location ID and Location Name table columns.
- Combined location name and location ID under the Name column.
- Changed the Accounts pagination to match Platform Activity pagination.
- Made the admin auth headers send both Bearer token and legacy admin headers when available.
- Made admin login accept `token`, `admin_token`, `access_token`, or `jwt` from the login response.
- Reworded the fallback warning so it no longer says "locally" when this is a deployed 401/session issue.

