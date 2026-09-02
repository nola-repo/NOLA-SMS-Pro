# Backend Handoff — Agency Panel: Dynamic Company ID Fix

**Repo:** nola-repo/NOLA-SMS-Pro → `/api/agency`
**Priority:** High
**Date:** 2026-04-14
**Related Frontend PR:** Agency Panel — Fix stale companyId (useGhlCompany + AgencyContext)

---

## Summary

The frontend fix for the stale-subaccounts bug has been shipped. This document describes:

1. What the frontend now expects from the backend
2. Two gaps found between the **local** backend copy and the **GitHub (deployed)** backend
3. The one routing entry that is **missing** from `.htaccess` and **must be added**
4. Verification steps

No new endpoints are required. The existing endpoints are correct.

---

## Background: What the Frontend Changed

The Agency Panel inside GHL now resolves the agency `companyId` exclusively through:

| Source | How | Notes |
|--------|-----|-------|
| URL param `?companyId={{company.id}}` | Synchronous fast-path on mount | Set by GHL custom menu template |
| `POST /api/agency/ghl_sso_decrypt.php` | Async — GHL postMessage SSO handshake | **Primary authoritative source** |
| `POST /api/agency/ghl_autologin` | Async — exchanges companyId for JWT | Runs after SSO resolves |

The frontend **no longer reads `nola_agency_id` from localStorage on iframe mount** and **no longer reacts to plain GHL postMessages** that contain subaccount-level `companyId` fields (those messages were overwriting the correct agency ID).

---

## Issues Found

### ❌ Issue 1 — `ghl_sso_decrypt.php` is MISSING from local backend copy

**File:** `api/agency/ghl_sso_decrypt.php`

This file **exists on GitHub** (deployed) but is **not present** in the local
`c:\Users\User\nola-sms-pro\backend\api\agency\` directory.

The frontend calls `POST /api/agency/ghl_sso_decrypt.php` on every iframe load
(via the persisted postMessage listener). If this file is not deployed the SSO
handshake will 404 and the agency panel will show an error.

**Action:** Pull the deployed version into the local repo so it stays in sync.
The deployed file is: https://github.com/nola-repo/NOLA-SMS-Pro/blob/main/api/agency/ghl_sso_decrypt.php

Key logic to verify in it:
```php
$companyId = $userData['companyId'] ?? $userData['company_id'] ?? null;
// This MUST return the agency-level companyId, not the location/subaccount ID.
// GHL's SSO payload contains the top-level agency companyId in this field.
```

---

### ❌ Issue 2 — `.htaccess` is MISSING the route for `ghl_sso_decrypt`

**File:** `backend/.htaccess`

The current `.htaccess` has routes for:
```apache
RewriteRule ^api/agency/ghl_autologin/?$     /api/agency/ghl_autologin.php    [NC,L,QSA]
RewriteRule ^api/agency/link_company/?$      /api/agency/link_company.php     [NC,L,QSA]
RewriteRule ^api/agency/get_subaccounts/?$   /api/agency/get_subaccounts.php  [NC,L,QSA]
RewriteRule ^api/agency/update_subaccount/?$ /api/agency/update_subaccount.php [NC,L,QSA]
```

**Missing:**
```apache
RewriteRule ^api/agency/ghl_sso_decrypt/?$   /api/agency/ghl_sso_decrypt.php  [NC,L,QSA]
```

The frontend calls `/api/agency/ghl_sso_decrypt.php` (with `.php` extension),
so this particular endpoint is accessed directly without URL rewriting.
It will work **only if** the catch-all rule at the bottom of `.htaccess` fires:
```apache
RewriteCond %{REQUEST_FILENAME}.php -f
RewriteRule ^(.*)/?$ $1.php [NC,L,QSA]
```
This catch-all **should** cover it, but adding an explicit rule is safer and
consistent with the other agency endpoints. Add the explicit rule.

**Action — add this line** to `.htaccess` in the Agency management block:

```apache
# ── Agency management endpoints ──────────────────────────────────────────────
RewriteRule ^api/agency/ghl_autologin/?$     /api/agency/ghl_autologin.php     [NC,L,QSA]
RewriteRule ^api/agency/ghl_sso_decrypt/?$   /api/agency/ghl_sso_decrypt.php   [NC,L,QSA]   ← ADD THIS
RewriteRule ^api/agency/link_company/?$      /api/agency/link_company.php      [NC,L,QSA]
RewriteRule ^api/agency/get_subaccounts/?$   /api/agency/get_subaccounts.php   [NC,L,QSA]
RewriteRule ^api/agency/update_subaccount/?$ /api/agency/update_subaccount.php [NC,L,QSA]
```

---

### ⚠️  Issue 3 — `ghl_autologin.php` divergence between local and GitHub

The **local** copy (`backend/api/agency/ghl_autologin.php`) returns a 404 when
no agency user is found for a companyId:
```php
// LOCAL version
if (!$userData) {
    http_response_code(404);
    echo json_encode(['error' => 'No agency account is linked to this GHL company...']);
    exit;
}
```

The **GitHub (deployed)** version auto-creates a new user doc when none is found:
```php
// GITHUB (DEPLOYED) version
if (!$userData) {
    error_log('[GHL_AUTOLOGIN] No existing agency user found... Creating new user doc on the fly.');
    $userData = [
        'role'       => 'agency',
        'company_id' => $companyId,
        'createdAt'  => new \Google\Cloud\Core\Timestamp(new \DateTime()),
        'active'     => true,
        'email'      => 'agency_' . $companyId . '@ghl.nolasmspro.com'
    ];
    $newUserRef = $db->collection('users')->add($userData);
    $userId = $newUserRef->id();
}
```

**Risk:** The auto-create path uses a placeholder email
`agency_{companyId}@ghl.nolasmspro.com`. This means any GHL company that opens
the Agency Panel will silently get a user doc created — including test installs,
bot traffic, or wrong companyIds sent by the subaccount-level URL params.

**Recommendation:** Now that the frontend correctly sends the **agency-level**
companyId (via SSO decrypt, not a stale localStorage value), this auto-create
path becomes safer. However, consider:
- Adding a validation step: verify the `companyId` exists in `ghl_tokens` with `appType == 'agency'` before auto-creating
- OR: keep returning 404 (local version) and require agencies to register first

The frontend handles 404 gracefully — it shows an error message asking the user to register.

**Action:** Sync local and deployed versions. Decide on auto-create vs 404 policy.

---

### ✅  Issue 4 — `ghl_autologin.php` — `active` field check may block new auto-created users

In the deployed version, after auto-creating a user, the `active` field is set
to `true`. But the check happens **after** the auto-create block:
```php
if (empty($userData['active'])) {
    http_response_code(403); // ← This would block a user with active=false
}
```
This is fine for auto-created users since `active` defaults to `true`.
No change needed, but keep in mind when manually setting `active = false` on
an agency account.

---

### ✅  `get_subaccounts.php` — No changes required

The endpoint correctly queries `ghl_tokens` by `companyId`:
```php
$results = $db->collection('ghl_tokens')->where('companyId', '=', $agencyId)->documents();
```
Now that the frontend sends the correct **agency-level** companyId via
`X-Agency-ID`, this query will return the right subaccounts.

---

### ✅  `auth_helper.php` — No changes required

The GitHub version (deployed) correctly validates both:
1. JWT Bearer token → extracts `company_id` from payload
2. Legacy `X-Webhook-Secret` + `X-Agency-ID` headers

Both paths return the agency-level `companyId` as expected.

---

## Required Backend Actions (Summary)

| # | File | Action | Priority |
|---|------|--------|----------|
| 1 | `api/agency/ghl_sso_decrypt.php` | Pull from GitHub into local repo (already deployed) | **Required** |
| 2 | `backend/.htaccess` | Add explicit route for `ghl_sso_decrypt` | **Required** |
| 3 | `api/agency/ghl_autologin.php` | Sync local vs deployed; decide on auto-create policy | **Recommended** |
| 4 | `GHL_SSO_SECRET` env var | Confirm it's set in Cloud Run to match GHL Developer Portal shared secret | **Verify** |

---

## Verification Steps

### 1. Test SSO decrypt endpoint directly
```bash
curl -X POST https://smspro-api.nolacrm.io/api/agency/ghl_sso_decrypt.php \
  -H "Content-Type: application/json" \
  -d '{"encryptedPayload": "U2FsdGVkX1+REPLACE_WITH_REAL_PAYLOAD"}'

# Expected response:
# { "success": true, "companyId": "AGENCY_COMPANY_ID", "userId": "...", "activeLocation": "..." }
# NOT a 404 (missing file) nor a 403 (routing issue)
```

### 2. Check server logs during agency panel load
```
[GHL_SSO] Decrypted successfully — companyId=<AGENCY_ID> userId=... activeLocation=...
[GHL_AUTOLOGIN] Found existing agency user for company_id: <AGENCY_ID>
```
The `companyId` in these logs should be the **agency-level** company ID
(longer alphanumeric string, NOT a GHL Location ID format like `0OYXPGWM9ep2I37dgxAo`).

### 3. Browser repro test
1. Open GHL Agency Panel → confirm subaccounts load ✅
2. Switch to a different subaccount in GHL sidebar
3. `console.log` in browser should show:
   ```
   NOLA SMS: Detected GHL Company: <AGENCY_COMPANY_ID>
   ```
   NOT a location ID. The agency companyId should NOT change between step 1 and step 3.

### 4. Multi-agency browser test (original bug)
1. Open Agency Panel as Agency A → note `nola_agency_id` in localStorage
2. Open Agency Panel as Agency B in same browser
3. Expected: Agency B's subaccounts load (not Agency A's)
4. Expected: `nola_agency_id` in localStorage is now Agency B's ID

---

## Custom Menu Link Recommendation

Current:
```
https://agency.nolasmspro.com/?companyId={{company.id}}&company_id={{company.id}}&location_id={{location.id}}
```

**Keep this exactly as-is.** 

Because this is the Agency Panel, GHL correctly resolves `{{company.id}}` to the top-level agency ID (e.g. `kzIDzbJPrxuBhOGmp32U`), which perfectly matches the `company_id` in your Firestore `ghl_tokens` collection (as shown in your screenshot). 

The frontend `useGhlCompany` hook specifically looks for this `companyId` parameter on mount to instantly load the correct agency data, and safely ignores the `location_id` parameter to prevent subaccount contamination.

---

## No New Endpoints Required

All three endpoints already exist and are deployed:

| Endpoint | Local File | Status |
|----------|-----------|--------|
| `POST /api/agency/ghl_sso_decrypt.php` | Missing local copy | ✅ Deployed on GitHub |
| `POST /api/agency/ghl_autologin` | `ghl_autologin.php` | ✅ Deployed (diverges from local) |
| `GET /api/agency/get_subaccounts` | `get_subaccounts.php` | ✅ No change needed |
