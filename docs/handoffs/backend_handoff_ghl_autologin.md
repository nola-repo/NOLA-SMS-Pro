# Backend Handoff: Agency Auth + GHL Iframe Auto-Login

**To:** Backend Team  
**Branch:** `https://github.com/nola-repo/NOLA-SMS-Pro.git`  
**Topic:** 4 new endpoints enabling Agency registration, login, GHL iframe auto-login, and company linking

---

## Overview

The Agency Panel now supports **GHL iframe auto-authentication**. When the NOLA Agency app is loaded inside a GoHighLevel marketplace iframe, the frontend detects the `companyId` URL param, calls `/api/agency/ghl_autologin`, and receives a JWT — no password needed. Outside GHL, the standard login flow is used.

---

## New Files Created

All files are already added to the repo under `backend/`:

| File | Purpose |
|------|---------|
| `api/jwt_helper.php` | Shared HS256 JWT sign/verify (no composer dependency) |
| `api/auth/register.php` | New agency/user account registration |
| `api/auth/login.php` | Email + password login returning a JWT |
| `api/agency/ghl_autologin.php` | **New** — GHL iframe auto-login via `company_id` |
| `api/agency/link_company.php` | Link a GHL `company_id` to an authenticated agency JWT |

`.htaccess` has also been updated with the 4 new rewrite rules.

---

## 1. `POST /api/auth/register` ✅

Registers a new agency or user account.

### Payload
```json
{
  "firstName": "John",
  "lastName":  "Doe",
  "email":     "john@acme.com",
  "phone":     "09171234567",
  "password":  "securePass123",
  "role":      "agency"
}
```

### Behavior
1. Validates all required fields
2. Checks for duplicate email in `Firestore.users`
3. Hashes password with `bcrypt`
4. Creates new Firestore document under `users` collection with `role`, `company_id: null`, `active: true`

### Responses
| Code | Body |
|------|------|
| 201 | `{ "status": "success", "message": "Account created." }` |
| 409 | `{ "error": "Email already registered." }` |
| 422 | `{ "error": "All fields are required." }` |

---

## 2. `POST /api/auth/login` ✅

Email + password login for agency and user accounts. Returns a signed HS256 JWT.

### Payload
```json
{ "email": "john@acme.com", "password": "securePass123" }
```

### Behavior
1. Finds user in `Firestore.users` by email  
2. Verifies bcrypt password hash  
3. Signs JWT with `JWT_SECRET` env var (8-hour expiry)  
4. Returns token + role + company_id (for agency) or location_id (for user)

### Response (200)
```json
{
  "token":       "eyJhbG...",
  "role":        "agency",
  "company_id":  "GHL_COMPANY_123",
  "location_id": null,
  "user": { "firstName": "John", "lastName": "Doe", "email": "john@acme.com" }
}
```

| Code | Body |
|------|------|
| 200 | JWT + session data |
| 401 | `{ "error": "Invalid email or password." }` |
| 403 | `{ "error": "Your account has been deactivated." }` |

---

## 3. `POST /api/agency/ghl_autologin` ✅ ← **New core endpoint**

Called by the Agency frontend when it detects a `companyId` in the GHL iframe URL. Authenticates the agency without a password by looking up the Firestore account linked to that company.

### Payload
```json
{ "company_id": "GHL_COMPANY_123" }
```

### Behavior
1. Queries `Firestore.users` where `role == 'agency'` AND `company_id == $company_id`
2. If found → signs an 8-hour JWT and returns it
3. If not found → `404` (agency must register + link their GHL first)

### Response (200)
```json
{
  "token":      "eyJhbG...",
  "role":       "agency",
  "company_id": "GHL_COMPANY_123",
  "user": { "firstName": "John", "lastName": "Doe", "email": "john@acme.com" }
}
```

| Code | Body |
|------|------|
| 200 | JWT + session |
| 404 | `{ "error": "No agency account is linked to this GoHighLevel company." }` |
| 403 | `{ "error": "This agency account has been deactivated." }` |

---

## 4. `POST /api/agency/link_company` ✅

Links a GHL `company_id` to an authenticated agency account. Called after:
- The agency manually enters their GHL Company ID during registration Step 3, OR
- GHL OAuth exchange returns the `company_id`

### Headers
```
Authorization: Bearer <token>
Content-Type: application/json
```

### Payload
```json
{ "company_id": "GHL_COMPANY_123" }
```

### Behavior
1. Verifies Bearer JWT with `JWT_SECRET`
2. Confirms role is `agency`
3. Updates `Firestore.users[userId].company_id = company_id`

### Response
| Code | Body |
|------|------|
| 200 | `{ "status": "success", "company_id": "GHL_COMPANY_123" }` |
| 401 | `{ "error": "Authorization token required." }` |
| 403 | `{ "error": "Only agency accounts can link a company." }` |

---

## Environment Variable Required

> [!IMPORTANT]
> Add `JWT_SECRET` to your Cloud Run environment variables / `.env`. All 4 JWT endpoints use this same secret.

```
JWT_SECRET=<generate-a-strong-random-string-32+chars>
```

Without this, the code falls back to the dev default (`nola_sms_pro_jwt_secret_change_in_production`) which is insecure for production.

---

## Firestore Schema: `users` Collection

Each user document should have these fields:

| Field | Type | Notes |
|-------|------|-------|
| `firstName` | string | |
| `lastName` | string | |
| `email` | string | lowercase, unique |
| `phone` | string | |
| `password_hash` | string | bcrypt hash |
| `role` | `"agency"` \| `"user"` | |
| `company_id` | string \| null | GHL Company ID, null until linked |
| `active_location_id` | string \| null | For user role — active GHL location |
| `active` | bool | false = deactivated |
| `createdAt` | timestamp | |
| `updatedAt` | timestamp | set on updates |

---

## Frontend ↔ Backend URL Map

| Frontend call | Backend file |
|--------------|-------------|
| `POST /api/auth/register` | `api/auth/register.php` |
| `POST /api/auth/login` | `api/auth/login.php` |
| `POST /api/agency/ghl_autologin` | `api/agency/ghl_autologin.php` |
| `POST /api/agency/link_company` | `api/agency/link_company.php` |
| `GET /api/agency/get_subaccounts` | `api/agency/get_subaccounts.php` |
| `POST /api/agency/update_subaccount` | `api/agency/update_subaccount.php` |

---

## Agency Subaccounts API (New)

The Agency Panel Subaccounts page fetches its data directly from the `ghl_tokens` collection (since that is where GHL OAuth places the marketplace token installations).

### 5. `GET /api/agency/get_subaccounts`
Fetches all locations where `companyId == <Agency-ID>`.

**Headers:**
```
X-Agency-ID: GHL_COMPANY_123
```

**Response:**
```json
{
  "status": "success",
  "subaccounts": [
    {
      "location_id": "MJoecBYPutNZwRw7N7Ud",
      "location_name": "NOLA EventPro CRM",
      "toggle_enabled": true,
      "rate_limit": 5,
      "attempt_count": 0,
      "toggle_activation_count": 1
    }
  ]
}
```

### 6. `POST /api/agency/update_subaccount`
Updates the subaccount's SMS settings. Enforces exactly a max of 3 toggle activations to prevent abuse.

**Headers:** `X-Agency-ID: GHL_COMPANY_123`
**Payload:**
```json
{
  "location_id": "MJoecBYPutNZwRw7N7Ud",
  "toggle_enabled": true,
  "rate_limit": 10,
  "reset_counter": false
}
```

**Behavior:**
1. Looks up `ghl_tokens` where `document_id == location_id`.
2. Validates that the doc's `companyId == X-Agency-ID`.
3. If `reset_counter` is `true`, sets `attempt_count` back to `0`.
4. If `toggle_enabled` is moving from `false` to `true`, bumps `toggle_activation_count`. Errors with status 403 if limit of 3 is reached.

---

## GHL Iframe Flow (for reference)

```
GHL loads: https://agency.nolasmspro.com/?companyId=ABC123
  │
  ├─ 1. useGhlCompany hook: detects companyId in URL
  ├─ 2. No JWT in localStorage → calls POST /api/agency/ghl_autologin
  ├─ 3. Backend: finds users where role=agency AND company_id=ABC123
  ├─ 4. Signs JWT → frontend stores in localStorage
  └─ 5. AgencyProtectedRoute allows access → Dashboard loads
```

*Backend implementation is complete. No further backend changes needed for the GHL iframe auto-login feature.*
