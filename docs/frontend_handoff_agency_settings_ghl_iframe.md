# Frontend Handoff: Fix Agency Settings N/A Inside GHL Iframe

**Status**: Ready to implement  
**Priority**: High  
**Repo**: `nola-repo/nola-sms-pro-frontend` (agency app)  
**Affects**: `agency/src/context/AgencyContext.tsx`, `agency/src/pages/Settings.tsx`, `agency/src/services/agencyAuthHelper.ts`  
**Backend dependency**: Deploy latest `nola-sms-pro` API (`ghl_autologin.php`, `profile.php`, `.htaccess` alias) before testing

---

## Symptom

Inside GHL (`app.nolacrm.io` / `app.nolasmspro.com` agency iframe):

| Field | Current |
|-------|---------|
| Agency / Company ID | ✅ Populated from GHL (`companyId` postMessage / URL) |
| Full Name | ❌ N/A |
| Email Address | ❌ N/A (header shows placeholder `agency@example.com`) |
| Phone Number | ❌ N/A |
| Company Name | ❌ N/A |

Outside GHL (standalone agency login) works because JWT + profile fetch run normally.

**Network tab may show:** `POST /api/auth/ghl_autologin` → **404 Not Found** (wrong path).

---

## ⚠️ Wrong autologin URL (404 inside GHL)

Some builds call a path that **does not exist**:

| ❌ Wrong (404) | ✅ Correct |
|----------------|------------|
| `POST /api/auth/ghl_autologin` | `POST /api/agency/ghl_autologin` |
| `GET /api/auth/ghl_autologin` (browser address bar) | `POST` only — never GET |

**File on disk:** `api/agency/ghl_autologin.php`  
**Rewrite (canonical):** `.htaccess` → `^api/agency/ghl_autologin/?$`  
**Laravel v2 (optional):** `POST /api/v2/agency/ghl_autologin`

Opening the URL in a browser sends **GET** and will fail even on the correct path. Autologin must be called from JavaScript as **POST** with JSON body.

### Backend compatibility alias (deployed by backend team)

To unblock old frontend builds, backend adds:

```apache
RewriteRule ^api/auth/ghl_autologin/?$ /api/agency/ghl_autologin.php [NC,L,QSA]
```

**Frontend should still fix the URL** — do not rely on the alias long-term.

### Fix in `agencyAuthHelper.ts`

Search the repo for `auth/ghl_autologin` and replace with `agency/ghl_autologin`:

```diff
  export const ghlAutoLogin = async (companyId: string): Promise<...> => {
-   const res = await fetch('/api/auth/ghl_autologin', {
+   const res = await fetch('/api/agency/ghl_autologin', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ company_id: companyId }),
    });
```

Canonical repo already uses `/api/agency/ghl_autologin` — verify **deployed** `app.nolasmspro.com` build matches.

### Quick API test (not browser)

```bash
curl -X POST "https://smspro-api.nolacrm.io/api/agency/ghl_autologin" \
  -H "Content-Type: application/json" \
  -d '{"company_id":"YOUR_GHL_COMPANY_ID"}'
```

Expected: `200` + `{ token, role, company_id, user }`.  
`404` on company = agency not linked in Firestore yet.

---

## Root Cause

The agency iframe flow **skips authentication and profile loading**, and some deployments hit the **wrong autologin path**:

### 1. `AgencyContext.tsx` — auto-login disabled in iframe

```ts
// Current behavior (lines ~74–85):
useEffect(() => {
  if (!isGhlFrame || !ghlCompanyId) return;
  // Only sets agencyId — does NOT call ghlAutoLogin()
  setAgencyId(ghlCompanyId);
  safeStorage.setItem('nola_agency_id', ghlCompanyId);
  setAutoLoginLoading(false);
}, [isGhlFrame, ghlCompanyId]);
```

Result: `agencySession.token` is **null** inside GHL. Company ID works; user profile does not.

### 2. `AgencyContext.tsx` + `Settings.tsx` — profile fetch skipped in iframe

```ts
if (!agencySession?.token || isGhlFrame) return; // ← blocks fetchAgencyProfile()
```

### 3. Wrong autologin path (if using `/api/auth/ghl_autologin`)

No backend route existed at `/api/auth/ghl_autologin` → Apache 404 (`smspro-api.nolacrm.io`). JWT never issued → profile stays N/A.

### 4. `agencyAuthHelper.ts` — wrong profile endpoint

`fetchAgencyProfile()` calls `GET /api/auth/me` instead of the agency-specific `GET /api/agency/profile.php`.

### 5. Placeholder UI masks missing data

`Settings.tsx` shows `agency@example.com` and `Agency Owner` when fields are null — looks like bad data instead of a loading/error state.

---

## Target Flow (After Fix)

```
GHL iframe loads agency app
      │
      ▼
useGhlCompany → companyId via postMessage / SSO decrypt / URL
      │
      ▼
ghlAutoLogin(companyId)  →  POST /api/agency/ghl_autologin   ← NOT /api/auth/...
      │
      ▼
Store JWT in sessionSafeStorage + user in safeStorage
      │
      ▼
fetchAgencyProfile()  →  GET /api/agency/profile.php  (Bearer JWT)
      │
      ▼
Settings merges agencySession.user + freshProfile → real name, email, phone, company_name
```

---

## Fix 1 — Re-enable `ghlAutoLogin` in iframe (`AgencyContext.tsx`)

**File:** `agency/src/context/AgencyContext.tsx`

Import `ghlAutoLogin`:

```diff
- import { getAgencySession, clearAgencySession, type AgencySession, fetchAgencyProfile } from '../services/agencyAuthHelper.ts';
+ import { getAgencySession, clearAgencySession, ghlAutoLogin, type AgencySession, fetchAgencyProfile } from '../services/agencyAuthHelper.ts';
```

Replace the iframe effect that only sets `agencyId`:

```diff
  useEffect(() => {
    if (!isGhlFrame || !ghlCompanyId) return;

-   setAgencyId(ghlCompanyId);
-   safeStorage.setItem('nola_agency_id', ghlCompanyId);
-   setAutoLoginError(null);
-   setAutoLoginLoading(false);
+   setAgencyId(ghlCompanyId);
+   safeStorage.setItem('nola_agency_id', ghlCompanyId);
+
+   if (agencySession?.token && agencySession.companyId === ghlCompanyId) {
+     setAutoLoginError(null);
+     setAutoLoginLoading(false);
+     return;
+   }
+
+   let cancelled = false;
+   setAutoLoginLoading(true);
+
+   ghlAutoLogin(ghlCompanyId)
+     .then(result => {
+       if (cancelled) return;
+       setAgencySession({
+         token: result.token,
+         role: 'agency',
+         companyId: result.companyId,
+         user: result.user,
+       });
+       setAutoLoginError(null);
+     })
+     .catch(err => {
+       if (cancelled) return;
+       console.warn('[AgencyContext] GHL auto-login failed:', err);
+       setAutoLoginError(null);
+     })
+     .finally(() => {
+       if (!cancelled) setAutoLoginLoading(false);
+     });
+
+   return () => { cancelled = true; };
- }, [isGhlFrame, ghlCompanyId]);
+ }, [isGhlFrame, ghlCompanyId, agencySession?.token, agencySession?.companyId]);
```

Handle 404 from autologin gracefully (agency not linked yet). Log a warning; do not block the app if `companyId` is already known.

---

## Fix 2 — Correct autologin URL (`agencyAuthHelper.ts`)

**File:** `agency/src/services/agencyAuthHelper.ts`

Ensure `ghlAutoLogin` uses the **agency** path:

```ts
const res = await fetch('/api/agency/ghl_autologin', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ company_id: companyId }),
});
```

**Repo grep before deploy:**

```bash
rg "auth/ghl_autologin" agency/
# Should return zero matches after fix
```

---

## Fix 3 — Fetch profile inside iframe (`AgencyContext.tsx`)

Remove the `isGhlFrame` guard on the profile-sync effect:

```diff
  useEffect(() => {
-   if (!agencySession?.token || isGhlFrame) return;
+   if (!agencySession?.token) return;

    let isMounted = true;
    fetchAgencyProfile()
      .then(profile => { /* existing merge logic */ })
      .catch(() => { /* keep autologin payload as fallback */ });

    return () => { isMounted = false; };
- }, [agencySession?.token, isGhlFrame]);
+ }, [agencySession?.token, agencyId]);
```

---

## Fix 4 — Fetch profile inside iframe (`Settings.tsx`)

**File:** `agency/src/pages/Settings.tsx`

```diff
  useEffect(() => {
-   if (!agencySession?.token || isGhlFrame) return;
+   if (!agencySession?.token) return;

    let isMounted = true;
    fetchAgencyProfile()
      .then(profile => {
        if (!isMounted || !profile) return;
        setFreshProfile(profile);
        if (profile.company_id) setLocalCompanyId(profile.company_id);
      })
      .catch(() => {});

    return () => { isMounted = false; };
- }, [agencySession?.token, agencyId, isGhlFrame]);
+ }, [agencySession?.token, agencyId]);
```

Optional UX: show loading while `autoLoginLoading` or profile fetch runs instead of `N/A`.

---

## Fix 5 — Use agency profile endpoint (`agencyAuthHelper.ts`)

```diff
  export const fetchAgencyProfile = async (): Promise<AgencyAuthUser | null> => {
    const token = getAuthToken();
    if (!token) return null;

-   const res = await fetch('/api/auth/me', {
+   const res = await fetch('/api/agency/profile.php', {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
+       'Accept': 'application/json',
      },
      credentials: 'include',
    });
```

Ensure `getAuthToken()` reads from `sessionSafeStorage` **and** `safeStorage`.

---

## Backend API Reference (deploy before QA)

### POST `/api/agency/ghl_autologin`

Alias (temporary): `POST /api/auth/ghl_autologin` → same handler after backend `.htaccess` deploy.

**Request:**
```json
{ "company_id": "80VXPG6M9ep2Z37dgsAo" }
```

**Response (200):**
```json
{
  "token": "eyJhbG...",
  "role": "agency",
  "company_id": "80VXPG6M9ep2Z37dgsAo",
  "user": {
    "name": "Jane Smith",
    "firstName": "Jane",
    "lastName": "Smith",
    "email": "jane@agency.com",
    "phone": "+639...",
    "company_id": "80VXPG6M9ep2Z37dgsAo",
    "company_name": "Acme Agency",
    "role": "agency"
  }
}
```

JWT payload includes `auth_collection: "agency_users"`.

| Code | Meaning |
|------|---------|
| 404 | No agency linked to this GHL company — install/register first |
| 403 | Agency account deactivated |
| 405 | Used GET — must be POST |

### GET `/api/agency/profile.php`

**Headers:** `Authorization: Bearer <token>`

**Response (200):**
```json
{
  "status": "success",
  "user": {
    "name": "...",
    "email": "...",
    "phone": "...",
    "company_id": "...",
    "company_name": "..."
  },
  "data": { }
}
```

`company_name` resolved from `ghl_tokens/{company_id}` when missing on user doc.

---

## Expected Data After Fix

| Scenario | Name / Email / Phone | Company Name |
|----------|----------------------|--------------|
| Agency registered via install/login | From Firestore `agency_users` | From `ghl_tokens` if not on user doc |
| Auto-provisioned at first iframe visit | Placeholder `agency_{companyId}@ghl.nolasmspro.com`; name/phone empty until registration | From `ghl_tokens` |

If company name populates but name/email/phone are empty, frontend + autologin work — user may need to complete agency registration.

---

## Test Plan

1. Deploy backend (`ghl_autologin.php`, `profile.php`, `.htaccess` alias).
2. Apply frontend fixes; deploy agency app to `app.nolasmspro.com` / GHL custom page.
3. Open agency app **inside GHL** → **Settings → Account Details**.
4. DevTools → Network:
   - [ ] `POST /api/agency/ghl_autologin` → **200** (not 404 on `/api/auth/ghl_autologin`)
   - [ ] `GET /api/agency/profile.php` → **200** + user object
5. UI:
   - [ ] Company ID matches GHL `companyId`
   - [ ] Company Name not N/A (if in `ghl_tokens`)
   - [ ] Email / name / phone from `agency_users` or autologin payload
6. Regression: standalone agency login (non-iframe) still works.
7. `rg "auth/ghl_autologin"` in agency app → no matches.

---

## Files to Change (summary)

| File | Change |
|------|--------|
| `agency/src/services/agencyAuthHelper.ts` | `ghlAutoLogin` → `/api/agency/ghl_autologin`; `fetchAgencyProfile` → `/api/agency/profile.php` |
| `agency/src/context/AgencyContext.tsx` | Re-enable `ghlAutoLogin` in iframe; remove `isGhlFrame` guard on profile sync |
| `agency/src/pages/Settings.tsx` | Remove `isGhlFrame` guard on `fetchAgencyProfile` |

---

## Backend owner (`nola-repo/nola-sms-pro`)

- `api/agency/ghl_autologin.php` — full profile in response + `auth_collection` in JWT
- `api/agency/profile.php` — `company_id` / `company_name` resolution
- `.htaccess` — alias `api/auth/ghl_autologin` → `api/agency/ghl_autologin.php` (compat only)

Coordinate deploy so frontend QA hits updated API.
