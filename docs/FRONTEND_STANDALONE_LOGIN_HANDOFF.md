# Frontend Handoff: Fix Standalone Login Redirecting to GHL

**Target Repository**: `nola-repo/nola-sms-pro-frontend`  
**Deploy Trigger**: `nola-sms-pro-user-deploy` (`app.nolasmspro.com`)

---

## Issue
Logging in on `app.nolasmspro.com` in a standalone browser tab redirects the user into `app.gohighlevel.com` (`/v2/location/.../custom-page-link/...`).

---

## Cause
When post-login URL parameters (`post_auth_redirect` or `/v2/location/...`) are present, the frontend executes a top-level redirect to GHL without checking if the app is embedded in an iframe.

---

## Required Fix

In `nola-repo/nola-sms-pro-frontend` (e.g., `App.tsx`, `SharedLogin.tsx`, `AuthContext.tsx`, `LocationContext.tsx`):

1. **Add Iframe Check Before GHL Redirect**:
   ```ts
   const isInIframe = window.self !== window.top;

   if (isInIframe && postAuthRedirect) {
     // Embedded in GHL -> Deep-link inside GHL
     window.top.location.href = postAuthRedirect;
   } else {
     // Standalone browser tab -> Stay in standalone dashboard
     navigate('/');
   }
   ```

2. **Sanitize URL on Top-Level Load**:
   If `window.self === window.top`, strip any `post_auth_redirect` query params or `/v2/location/` path components before completing login routing.
