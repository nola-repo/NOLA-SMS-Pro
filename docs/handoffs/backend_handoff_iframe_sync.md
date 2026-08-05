# Backend Handoff: Iframe Syncing & Token URL Append

## The Problem
The NOLA SMS Pro frontend runs as a React SPA embedded inside a GoHighLevel (GHL) iframe. Modern browsers like Safari (with Intelligent Tracking Prevention) and Chrome (in Incognito mode) aggressively block third-party `localStorage` and cookie access to prevent cross-site tracking.

During the GHL Marketplace installation or login flow, the PHP backend successfully processes the user and redirects to `auth-handoff.html`. Previously, `auth-handoff.html` attempted to store the JWT token directly into `localStorage` before redirecting the user to the React dashboard (`app.nolasmspro.com`). 

Because of the iframe privacy restrictions, the `localStorage.setItem()` call was silently failing. When the React app subsequently booted up, it had no token, causing all API calls to `/api/auth/me` to fail and displaying "N/A" for the user's profile settings.

## The Solution
To guarantee the frontend receives the JWT token regardless of browser privacy restrictions, `auth-handoff.html` must pass the token directly in the redirect URL as a fallback. 

The React frontend has already been successfully updated to intercept this `?token=` parameter on boot, store it in an in-memory session manager, and instantly wipe it from the browser's URL history for security.

### Backend Changes Required

A single modification is required in `auth-handoff.html` (the standalone bridge page hosted on the PHP server). This change guarantees the token survives the cross-domain jump.

**File:** `auth-handoff.html`
**Location:** Around line 86 (inside the `setTimeout` block right before the final redirect).

**Exact Diff:**
```diff
    // Small delay so spinner shows briefly (reassures user something happened)
    setTimeout(function () {
-        window.location.replace(redirect);
+        // Always append token to the redirect URL as a fallback for iframe environments
+        // where localStorage is blocked (Safari ITP, Chrome Incognito).
+        var safeRedirect = redirect;
+        if (token) {
+            safeRedirect += (safeRedirect.indexOf('?') === -1 ? '?' : '&') + 'token=' + encodeURIComponent(token);
+        }
+        window.location.replace(safeRedirect);
    }, 600);
```

### Full Handoff Flow
1. User completes registration/login on the PHP backend (`install-register.php` / `install-login.php`).
2. Backend redirects to `auth-handoff.html?token=<JWT>&user=<B64>`.
3. `auth-handoff.html` attempts to write to `localStorage` (which may silently fail in Safari/Incognito).
4. `auth-handoff.html` redirects to `https://app.nolasmspro.com/?token=<JWT>` (New Change).
5. The React app (`AuthContext.tsx`) boots up, detects `?token=` in the URL, saves it to memory (`safeStorage`), and uses `window.history.replaceState` to immediately hide the token from the address bar.
6. The app dynamically fetches `/api/auth/me` to populate the user profile settings with 100% reliability.
