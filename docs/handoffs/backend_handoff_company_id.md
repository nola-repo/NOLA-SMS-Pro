# Backend Handoff: Agency Company ID Linking

**To:** Backend Team  
**Topic:** Ensuring `company_id` is returned on Agency login and properly stored in Firestore

---

## The Problem

Agency users are seeing a **"No GHL Company ID linked"** banner after logging in. This happens because the `POST /api/auth/login.php` endpoint returns a valid JWT + `role: "agency"` but **omits the `company_id` field**, leaving the frontend unable to identify which GHL agency to query.

### Why It Matters

The Agency Panel uses `company_id` (your GHL Company/Agency ID) as the primary key for:
- `X-GHL-Company-ID` header on all agency API calls
- Scoping subaccount queries to the correct agency (`agency_id` in Firestore)

Without it, the frontend cannot load any data.

---

## Required Fix: `POST /api/auth/login.php`

### Current (broken) response for agency accounts:
```json
{
  "token": "eyJhbG...",
  "role": "agency",
  "user": { "firstName": "John", "lastName": "Doe", "email": "john@acme.com" }
}
```

### Required response (add `company_id`):
```json
{
  "token": "eyJhbG...",
  "role": "agency",
  "company_id": "GHL_COMPANY_123",
  "location_id": null,
  "user": { "firstName": "John", "lastName": "Doe", "email": "john@acme.com" }
}
```

> **If `company_id` is null/missing**, the frontend will now show a "Connect GHL Account" step asking the user to manually paste their GHL Company ID. This is a temporary UX fallback — the backend fix is the permanent solution.

---

## Firestore `users` Collection — Schema

Each agency user document must have a `company_id` field set. Here are the two ways it gets populated:

### Path A — Set during GHL OAuth exchange (preferred)
When an agency registers and connects via GoHighLevel OAuth (`POST /api/ghl/oauth_exchange.php`):
1. Exchange the code for a GHL access token
2. Receive the `company_id` from GHL's response
3. **Write it to the user's `users` document** as `company_id`
4. Return `company_id` in the response so the frontend can cache it

### Path B — Set manually via Admin panel
If an agency hasn't done OAuth yet, the Admin can set their `company_id` directly in Firestore:

```
Collection: users
Document:   <user_uid>
Field:      company_id = "GHL_COMPANY_123"
```

---

## Login Endpoint Logic (PHP)

Update `login.php` to include `company_id` in the response:

```php
// After verifying password and fetching the user document from Firestore:
$company_id = $user['company_id'] ?? null;  // Read from Firestore document

$response = [
    'token'      => $jwt,
    'role'       => $user['role'],
    'company_id' => $company_id,         // ← ADD THIS
    'location_id'=> $user['active_location_id'] ?? null,
    'user'       => [
        'firstName' => $user['firstName'],
        'lastName'  => $user['lastName'],
        'email'     => $user['email'],
    ],
];

echo json_encode($response);
```

---

## New Endpoint (Optional but Recommended): `POST /api/agency/link_company.php`

This endpoint allows an already-authenticated agency user to link their GHL Company ID without a full re-login. The frontend "Connect GHL Account" step will call this.

### Headers
```
Content-Type: application/json
Authorization: Bearer <jwt_token>
X-Webhook-Secret: f7RkQ2pL9zV3tX8cB1nS4yW6
```

### Payload
```json
{
  "company_id": "GHL_COMPANY_123"
}
```

### Behavior
1. Validate JWT — extract user email/uid
2. Find user document in `users` Firestore collection
3. Update document: set `company_id = payload.company_id`
4. Return 200

### Response (200 OK)
```json
{ "success": true, "company_id": "GHL_COMPANY_123" }
```

### Response (401 Unauthorized)
```json
{ "success": false, "error": "Invalid or expired token." }
```

### Response (422 Validation Error)
```json
{ "success": false, "error": "company_id is required." }
```

---

## How to Find a GHL Company ID (for Agency users)

Agency users find their Company ID in **GoHighLevel → Settings → Business Info** (at the bottom of the page). It is a short alphanumeric string like `ABC123xyzabc`.

> This is the **Company-level** ID (top of the GHL account hierarchy), NOT a sub-location ID.

---

## Summary Checklist

| Task | Owner | Status |
|------|-------|--------|
| `login.php` → include `company_id` in response | Backend | ❌ Pending |
| Firestore `users` docs → populate `company_id` for existing agency users | Backend | ❌ Pending |
| `link_company.php` endpoint (optional) | Backend | ⬜ Optional |
| GHL OAuth exchange → auto-write `company_id` to Firestore | Backend | ⬜ Future |

---

*Frontend changes have been deployed: the login page now shows a fallback "Connect GHL Account" step if `company_id` is missing from the JWT, allowing users to enter their Company ID manually as a temporary workaround. The permanent fix is the backend returning `company_id` in the login response.*
