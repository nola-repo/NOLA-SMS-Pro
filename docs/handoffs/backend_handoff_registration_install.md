# Backend Handoff: Marketplace Install Registration

**To:** Backend Team  
**Topic:** First-run account registration from GHL Marketplace install  
**Files Changed:** `ghl_callback.php`, `api/auth/register_from_install.php`, `api/auth/login.php`, `.htaccess`

---

## Overview

When a user installs NOLA SMS Pro from the GHL Marketplace, `ghl_callback.php` now renders a **registration form** instead of a plain success page. The form lets the new user set up a NOLA account with their name, phone, email, and password. The location ID and subaccount name are pre-filled from the OAuth data and are read-only.

On **re-installs** (same location installs the app again), the backend detects an existing `users` doc for that location and shows a "Welcome Back" screen instead — no form needed.

---

## 1. New File: `api/auth/register_from_install.php`

**URL:** `POST /api/auth/register-from-install`

### Payload
```json
{
  "full_name":   "Maria Santos",
  "phone":       "09171234567",
  "email":       "maria@example.com",
  "password":    "securePass123",
  "location_id": "MJoecBYPutNZwRw7N7Ud",
  "company_id":  ""
}
```
- `location_id` — GHL location ID (Location-level installs)
- `company_id`  — GHL company ID (Agency/Company-level installs; empty string for Location-level)

### Role Detection
| Condition | Role assigned |
|-----------|---------------|
| `company_id` is non-empty | `"agency"` |
| `location_id` only | `"user"` |

### Behavior
1. Validate all fields (full_name, phone, email, password ≥ 8 chars, location_id or company_id required).
2. Split `full_name` into `firstName` + `lastName` on first space.
3. Query `users` collection for matching `email`:
   - **Not found** → Create new document (201).
   - **Found** → Update `active_location_id` or `company_id`, return 200 `{status: "linked"}`.
4. Write/update `integrations/<intDocId>` with `owner_email`, `owner_name`, `owner_phone`.
5. Sign and return a JWT token (8-hour expiry) so the browser can immediately persist the session.

### Responses

| Code | Body |
|------|------|
| 201 | `{ "status": "success", "message": "Account created successfully.", "token": "eyJ...", "role": "user", "location_id": "...", "user": { firstName, lastName, email, phone } }` |
| 200 | `{ "status": "linked", "message": "Account already exists. Location has been linked.", "token": "eyJ...", ... }` |
| 422 | `{ "error": "All fields are required." }` |
| 500 | `{ "error": "Registration failed: <message>" }` |

---

## 2. Modified: `api/auth/login.php`

### Change
Added `phone` to the `user` object in the response payload:

```json
{
  "token":       "eyJ...",
  "role":        "user",
  "company_id":  null,
  "location_id": "MJoecBYPutNZwRw7N7Ud",
  "user": {
    "firstName": "Maria",
    "lastName":  "Santos",
    "email":     "maria@example.com",
    "phone":     "09171234567"
  }
}
```

This allows the frontend (`SharedLogin.tsx`) to persist the full user profile to `localStorage` after login for checkout pre-fill.

---

## 3. Modified: `.htaccess`

New rewrite rule added to the Auth section:

```apache
RewriteRule ^api/auth/register-from-install/?$ /api/auth/register_from_install.php [NC,L,QSA]
```

---

## 4. Modified: `ghl_callback.php`

### New Logic (after token save, lines ~638+)

1. **Query `users` where `active_location_id == $locationId`**
   - Found → render "Welcome Back" page (no form). `exit`.
   - Not found → render registration form.

2. **Registration form** (first install):
   - HTML form with Full Name, Phone, Email, Password fields
   - Location ID and Subaccount Name pre-filled as read-only
   - Submits via `fetch()` to `/api/auth/register-from-install`
   - On success: hides form, shows animated success card, saves JWT to `localStorage`
   - Password strength bar (client-side, 4 levels)
   - Password show/hide toggle

---

## 5. Firestore Schema: New Fields

### `users` collection

| Field | Type | Notes |
|-------|------|-------|
| `source` | string | `"marketplace_install"` for installs, `"manual_register"` for Register.tsx |
| `active_location_id` | string \| null | Set for `role: "user"` from marketplace install |

### `integrations` collection

| Field | Type | Notes |
|-------|------|-------|
| `owner_email` | string | Email of account owner (set during first-run registration) |
| `owner_name`  | string | Full name |
| `owner_phone` | string | Phone number |

---

## 6. GHL Marketplace Configuration Checklist

> [!IMPORTANT]
> Complete all of these in the GHL Developer Portal: `https://marketplace.gohighlevel.com/apps`

### OAuth Settings
- **Redirect URI:** `https://smspro-api.nolacrm.io/oauth/callback`
- **Location-level scopes:**
  ```
  locations.readonly
  conversations/message.readonly  conversations.readonly
  conversations.write             contacts.readonly
  contacts.write                  conversations/message.write
  workflows.readonly
  ```
- **Company-level scopes (for Agency installs):**
  ```
  companies.readonly   locations.readonly
  ```

### SSO Settings
- Enable **Custom Menu Link SSO**
- Copy **Shared Secret** → set `GHL_SSO_SECRET` in Cloud Run env vars

### Post-Install Redirect
- Set **Post-Install Redirect URL** to: `https://smspro-api.nolacrm.io/oauth/callback`

### Custom Menu Link (inside each GHL subaccount)
- URL: `https://app.nolacrm.io`
- ✅ Enable: **"Pass contact/user info as query parameters"**

### Cloud Run Environment Variables (`sms-api` service)

| Variable | Source |
|----------|--------|
| `GHL_CLIENT_ID` | GHL Developer Portal → OAuth tab |
| `GHL_CLIENT_SECRET` | GHL Developer Portal → OAuth tab |
| `GHL_SSO_SECRET` | GHL Developer Portal → SSO Settings |
| `JWT_SECRET` | Strong 32+ char random string |
| `WEBHOOK_SECRET` | `f7RkQ2pL9zV3tX8cB1nS4yW6` |

```powershell
# Set all at once (replace placeholders):
gcloud run services update sms-api `
  --region asia-southeast1 `
  --set-env-vars "GHL_CLIENT_ID=...,GHL_CLIENT_SECRET=...,GHL_SSO_SECRET=...,JWT_SECRET=...,WEBHOOK_SECRET=f7RkQ2pL9zV3tX8cB1nS4yW6"
```

---

## 7. localStorage Keys (Frontend Reference)

After successful install registration or login, the frontend stores:

| Key | Content | Set by |
|-----|---------|--------|
| `nola_token` | JWT string | `ghl_callback.php` (JS) + `authService` |
| `nola_user` | `{ firstName, lastName, email, phone, location_id, company_id }` | `ghl_callback.php` (JS), `SharedLogin.tsx` |
| `nola_agency` | `{ firstName, lastName, email, phone, company_id }` | `Register.tsx` (agency flow only) |

---

## 8. User Journeys

### Journey A — First Marketplace Install (Sub-account)
```
1. User installs from GHL Marketplace
2. GHL → ghl_callback.php?code=...
3. Token exchanged, saved to integrations + ghl_tokens
4. No users doc found for locationId → Registration form rendered
5. User fills form → POST /api/auth/register-from-install
6. 201: users doc created, integrations updated with owner info
7. JWT saved to localStorage, success card shown
8. "Open Dashboard" → app.nolacrm.io
9. User can also log in at app.nolacrm.io/login with email+password
```

### Journey B — Re-install
```
1. Same location re-installs the app
2. ghl_callback.php checks users collection: found → Welcome Back screen
3. Credits are preserved (existing merge logic in ghl_callback.php)
```

### Journey C — Agency Install (Company-level OAuth)
```
1. Agency installs at company level (companyId in token response)
2. Registration form shown with companyId context
3. POST /api/auth/register-from-install with company_id
4. users doc created with role: "agency"
5. Agency logs in at agency.nolasmspro.com
```

### Journey D — External Login
```
1. User visits app.nolacrm.io/login
2. Enters email + password → POST /api/auth/login
3. Response includes user.phone → stored in nola_user localStorage
4. Dashboard loads, checkout pre-fills from localStorage
```

---

*Frontend changes are complete. Backend must deploy `register_from_install.php` and the `.htaccess` update. No other backend changes needed.*
