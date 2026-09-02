# Backend Handoff — PHP Install Registration & Login
**Priority:** 🔴 Critical — eliminates blank page after GHL Marketplace install  
**Prepared by:** Frontend team  
**Date:** 2026-05-05  

---

## Root Cause (confirmed)

`app.nolacrm.io` is a React CDN. It has no nginx SPA fallback and no `/api/` proxy.  
When `ghl_callback.php` redirects to `https://app.nolacrm.io/register-from-install?install_token=…`, the server returns 404 HTML — React never boots — blank page.

**Solution:** Move registration and login to `smspro-api.nolacrm.io` (the PHP host). No nginx changes needed.

---

## New Flow

```
GHL Marketplace Install
        │
        ▼
ghl_callback.php  (smspro-api.nolacrm.io — already working)
        │
        ├─ First install  → 302 smspro-api.nolacrm.io/install-register.php?install_token=JWT
        │                          ↓ user fills form, clicks Create Account
        │                    POST /api/auth/register-from-install (same server, no proxy needed)
        │                          ↓ 201 Created — JWT + user object returned
        │                    302 smspro-api.nolacrm.io/auth-handoff.html?token=JWT&user=BASE64
        │                          ↓ vanilla JS writes localStorage, redirects
        │                    302 app.nolacrm.io/  ← React dashboard (root always works)
        │
        ├─ Re-install     → 302 smspro-api.nolacrm.io/install-login.php?welcome_back=1&name=…
        │                          ↓ user signs in → same auth-handoff.html bridge
        │
        └─ Bulk install   → 302 smspro-api.nolacrm.io/install-login.php?bulk_install=1&count=N
                                   ↓ shows amber banner + login form → same auth-handoff.html bridge
```

---

## Files to Deploy

All three new files go in the **repo root** alongside `ghl_callback.php`:

| File | Action | URL after deploy |
|---|---|---|
| `install-register.php` | **NEW** | `https://smspro-api.nolacrm.io/install-register.php` |
| `install-login.php` | **NEW** | `https://smspro-api.nolacrm.io/install-login.php` |
| `auth-handoff.html` | **NEW** | `https://smspro-api.nolacrm.io/auth-handoff.html` |
| `ghl_callback.php` | **MODIFY** | 5 redirect lines changed (diff below) |

Full code for the three new files is in this folder:
- `install-register.php`
- `install-login.php`  
- `auth-handoff.html`

---

## ghl_callback.php — Exact Diff

### Change 1 — Normal sub-account first install (line ~884)
```diff
-    $redirectUrl = $reactAppUrl . '/register-from-install?install_token=' . urlencode($installToken);
+    $redirectUrl = 'https://smspro-api.nolacrm.io/install-register.php?install_token=' . urlencode($installToken);
```

### Change 2 — Case A single-location bulk first install (line ~534)
```diff
-    header('Location: ' . $reactAppUrl2 . '/register-from-install?install_token=' . urlencode($installToken2), true, 302);
+    header('Location: https://smspro-api.nolacrm.io/install-register.php?install_token=' . urlencode($installToken2), true, 302);
```

### Change 3 — Case B single provisioned first install (line ~684)
```diff
-    header('Location: ' . $reactAppUrlB . '/register-from-install?install_token=' . urlencode($tokenB), true, 302);
+    header('Location: https://smspro-api.nolacrm.io/install-register.php?install_token=' . urlencode($tokenB), true, 302);
```

### Change 4 — Re-install welcome-back redirects (all occurrences)
```diff
-    header('Location: ' . $reactAppUrl . '/login?welcome_back=1&name=' . $locationNameEnc, true, 302);
+    header('Location: https://smspro-api.nolacrm.io/install-login.php?welcome_back=1&name=' . $locationNameEnc, true, 302);
```
*(Occurs in ~3 places — Cases A, B, and normal flow. Replace all.)*

### Change 5 — Bulk install redirect (line ~690)
```diff
-    header('Location: ' . $reactAppUrlB . '/login?bulk_install=1&count=' . count($successfulLocIds), true, 302);
+    header('Location: https://smspro-api.nolacrm.io/install-login.php?bulk_install=1&count=' . count($successfulLocIds), true, 302);
```

---

## How auth-handoff.html Passes the Session to React

After successful registration/login, PHP redirects to:
```
https://smspro-api.nolacrm.io/auth-handoff.html
  ?token=<8-hour JWT>
  &user=<base64_encoded_user_json>
  &redirect=https://app.nolacrm.io
```

The standalone HTML page runs vanilla JS that:
1. Reads `token` → `localStorage.setItem('nola_auth_token', token)` ← same key authService reads
2. Reads `user` → base64 decodes → `localStorage.setItem('nola_user', JSON.stringify({...}))`
3. Waits 600ms (spinner reassures user) → `window.location.replace('https://app.nolacrm.io')`

If `localStorage` is blocked (private mode / iframe), it falls through gracefully and still redirects.  
React's `/api/auth/me` self-heal in `Settings.tsx` will re-fetch the profile from the JWT.

---

## User object shape expected by auth-handoff.html

This is what `register_from_install.php` and `login.php` already return in `data.user`:

```json
{
  "name": "Jane Smith",
  "firstName": "Jane",
  "lastName": "Smith",
  "email": "jane@company.com",
  "phone": "+15550000000",
  "location_id": "ugBqfQsPtGijLjrmLdmA",
  "company_id": "abc123",
  "location_name": "My Sub-Account",
  "company_name": "My Agency",
  "location_memberships": ["ugBqfQsPtGijLjrmLdmA"]
}
```

No backend changes to the API endpoints — they already return this shape.

---

## Quick Test After Deploy

```
# 1. Registration form loads
curl -I "https://smspro-api.nolacrm.io/install-register.php?install_token=INVALID"
# → expect: 200 (renders "Link Expired" page)

# 2. Login page loads  
curl -I "https://smspro-api.nolacrm.io/install-login.php?welcome_back=1&name=TestLoc"
# → expect: 200 (renders login page with blue banner)

# 3. Bulk banner
curl -I "https://smspro-api.nolacrm.io/install-login.php?bulk_install=1&count=3"
# → expect: 200 (renders login page with amber "3 sub-accounts" banner)

# 4. Auth handoff
# Visit in browser: https://smspro-api.nolacrm.io/auth-handoff.html?token=FAKE&redirect=https://app.nolacrm.io
# → expect: spinner shows, redirects to app.nolacrm.io within 1s
```

---

## Acceptance Criteria

- [ ] Fresh install → `install-register.php` form renders with location name badge
- [ ] Form submit → account created → `app.nolacrm.io` dashboard loads with correct name/email in Settings
- [ ] Re-install → `install-login.php` with blue "Welcome back" banner → dashboard
- [ ] Bulk install (2+ locations) → `install-login.php` with amber "N sub-accounts" banner → dashboard  
- [ ] Expired install_token → styled expiry page with "Reinstall from Marketplace" button (not blank)
- [ ] `app.nolacrm.io` root `/` still loads React dashboard (unchanged)
- [ ] Settings page shows real name, email, phone (not N/A) after registration

---

## What Does NOT Change

| File | Status |
|---|---|
| `api/auth/register_from_install.php` | ✅ No changes — API unchanged |
| `api/auth/login.php` | ✅ No changes — API unchanged |
| `api/auth/me.php` | ✅ No changes |
| React `RegisterFromInstall.tsx` | ✅ No changes — kept as fallback if nginx is ever fixed |
| React `SharedLogin.tsx` | ✅ No changes — kept for direct login at `app.nolacrm.io/login` |
| React `App.tsx` | ✅ No changes |
