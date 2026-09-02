# Backend Handoff: Auth & Registration API

**To:** Backend Team  
**Topic:** New endpoints for Agency/User Registration, Login, and GHL OAuth Exchange

Admin auth is untouched. These endpoints are exclusively for Agency and Sub-account User accounts stored in the Firestore `users` collection.

---

## Firestore `users` Collection — Schema Update

Add **two new fields** to every new document written by registration:

| Field | Type | Values | Notes |
|-------|------|---------|-------|
| `role` | string | `"agency"` \| `"user"` | Determines which dashboard to load after login |
| `company_id` | string \| null | GHL Company ID | Set for `agency` role only; null for `user` |

Existing documents (already in the collection) don't need to be migrated immediately — the login endpoint should default to `"user"` if `role` is missing.

---

## 1. `POST /api/auth/register.php`

Registers a new Agency or User account.

### Headers
```
Content-Type: application/json
```

### Payload
```json
{
  "firstName": "John",
  "lastName": "Doe",
  "email": "john@acme.com",
  "phone": "09171234567",
  "password": "securePass123",
  "role": "agency"
}
```

### Behavior
1. Check if `email` already exists in `users` collection → return 409 if yes
2. Hash the password (bcrypt recommended)
3. Write new document to `users` collection with all fields + `role` + `company_id: null` + `createdAt` timestamp
4. Return 201

### Response (201 Created)
```json
{ "status": "success", "message": "Account created." }
```

### Response (409 Conflict)
```json
{ "status": "error", "message": "Email already registered." }
```

### Response (422 Validation Error)
```json
{ "status": "error", "message": "All fields are required." }
```

---

## 2. `POST /api/auth/login.php` — **Modify existing endpoint**

The endpoint already exists. Modify the **response payload** to include role and IDs.

### Payload (unchanged)
```json
{
  "email": "john@acme.com",
  "password": "securePass123"
}
```

### Behavior
1. Find user in `users` collection by email
2. Verify password hash
3. Generate a JWT signed with your secret key
4. Return the role, and the relevant ID (`company_id` for agency, `active_location_id` for user)

### Response (200 OK) — **Updated**
```json
{
  "token": "eyJhbG...",
  "role": "agency",
  "company_id": "GHL_COMPANY_123",
  "location_id": null,
  "user": {
    "firstName": "John",
    "lastName": "Doe",
    "email": "john@acme.com"
  }
}
```

> For `role: "user"`, return `"company_id": null` and `"location_id": "LOC_XYZ"` from the user's `active_location_id` field.

### Response (401 Unauthorized)
```json
{ "error": "Invalid email or password." }
```

---

## 3. `POST /api/ghl/oauth_exchange.php` — **New** (implement when GHL Marketplace App is ready)

Exchanges a GHL authorization code for an access token during Agency registration. The frontend will call this from the GHL OAuth callback page.

### Payload
```json
{
  "code": "GHL_AUTH_CODE_FROM_URL",
  "redirect_uri": "https://app.nolacrm.io/oauth/callback"
}
```

### Behavior
1. Exchange code with GHL token endpoint using your Marketplace App's Client ID + Secret
2. Store the `access_token` and `refresh_token` in Firestore `ghl_tokens` collection (keyed by `company_id`)
3. Return the `company_id` and `company_name` to the frontend
4. Optionally: update the authenticated user's `users` document with their `company_id`

### Response (200 OK)
```json
{
  "success": true,
  "company_id": "GHL_COMPANY_123",
  "company_name": "Acme Marketing"
}
```

### Response (400 Bad Request)
```json
{ "success": false, "error": "Invalid or expired authorization code." }
```

---

## GHL Marketplace App Setup (for endpoint #3)

To get the **Client ID** and **Client Secret**:

1. Log into GoHighLevel → **Settings → Integrations → Marketplace**
2. Click **"My Apps"** → **"Create App"** (or open your existing one)
3. Go to the **OAuth** tab in your app settings
4. Copy:
   - `Client ID` → add to backend `.env` as `GHL_CLIENT_ID`
   - `Client Secret` → add to backend `.env` as `GHL_CLIENT_SECRET`
5. Add the **Redirect URI**: `https://app.nolacrm.io/oauth/callback`
6. Enable scopes: `contacts.readonly`, `conversations.write`, `locations.readonly`, `companies.readonly`

The frontend will use:
```
# agency/.env and user/.env
VITE_GHL_CLIENT_ID=paste_client_id_here
VITE_GHL_REDIRECT_URI=https://app.nolacrm.io/oauth/callback
```
*(Frontend only builds the authorization URL — the actual token exchange happens server-side)*

---

## CORS Requirements

If `register.php` and `login.php` are hosted on `smspro-api.nolacrm.io` (not relative path), ensure these headers are set:

```php
header('Access-Control-Allow-Origin: https://app.nolacrm.io');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
```

---

*Frontend implementation is completed by the frontend team concurrently. Backend endpoints must match the exact payload/response shapes above.*
