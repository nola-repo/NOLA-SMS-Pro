# Backend Handoff: Stale Location Reversion Bug

**Date:** 2026-05-28  
**Priority:** High  
**Status:** Both frontend and backend fixes successfully committed and pushed!

---

## 1. The Bug We Fixed
**Symptom:** When a user opened a different subaccount (like **NOLA CRM**), the page first showed **NOLA CRM** (correct!) but then shortly after reverted back to **INTERN Account** (cached!).
**Root Cause:**
1. In `useUserProfile.ts` (frontend), on boot, it tries to fetch `/api/auth/me` with the `X-GHL-Location-ID` header to let the backend know the user's active subaccount.
2. However, the frontend resolved the active location ID using:
   ```ts
   const currentLocationId =
     safeStorage.getItem('nola_location_id') ||
     getAccountSettings().ghlLocationId ||
     '';
   ```
3. Because the user had previously loaded the **INTERN Account**, `safeStorage.getItem('nola_location_id')` was populated with `"LOC_INTERN_ACCOUNT"`.
4. Due to the `||` priority, the frontend sent `X-GHL-Location-ID: LOC_INTERN_ACCOUNT` to `/api/auth/me` instead of the URL's current `location_id` parameter (`LOC_NOLA_CRM`).
5. The backend, seeing the header, returned the **INTERN Account** profile.
6. The frontend received the **INTERN Account** profile, and because standard users are locked to their profile's location, the frontend dispatched `ghl-location-set` back to **INTERN Account**, flipping the whole UI back.

---

## 2. Frontend Fix Shipped 🚀
We updated `user/src/hooks/useUserProfile.ts` to use a robust location resolver that matches `LocationContext`'s priorities perfectly (URL query/hash parameters always take priority over cached storage):

```ts
// Read the location GHL already provided (via URL query params, localStorage settings,
// or session storage) before this fetch, prioritizing URL/current-active settings
// to avoid loading the stale nola_location_id from the previous session.
const currentLocationId = (() => {
  // 1. Check URL parameters (highest priority since GHL loads/refreshes iframe with it)
  const urlParams = new URLSearchParams(window.location.search);
  const fromUrl = urlParams.get('location_id') || urlParams.get('locationId') || urlParams.get('location') || urlParams.get('id');
  if (fromUrl && fromUrl.length > 4) return fromUrl;

  // 2. Check URL hash parameters
  if (window.location.hash.includes('?')) {
    const hashQuery = window.location.hash.split('?')[1];
    const fromHash = new URLSearchParams('?' + hashQuery).get('location_id') || new URLSearchParams('?' + hashQuery).get('locationId');
    if (fromHash && fromHash.length > 4) return fromHash;
  }

  // 3. Check localStorage settings (which LocationContext updates synchronously from URL on boot)
  const settingsLoc = getAccountSettings().ghlLocationId;
  if (settingsLoc && settingsLoc.length > 4) return settingsLoc;

  // 4. Check safeStorage session cache (fallback)
  const sessionLoc = safeStorage.getItem('nola_location_id');
  if (sessionLoc && sessionLoc.length > 4) return sessionLoc;

  return '';
})();
```
This forces `/api/auth/me` to receive the correct active subaccount location ID, sync the Firestore document correctly, and load the correct dashboard.

---

## 3. Backend Fix Shipped 🚀
We resolved the git merge state on the backend repo. 
1. Git pulled the latest main changes.
2. Cleaned up conflicting frontend files (`App.tsx`, `Settings.tsx`) that had accidentally bled into the backend repo.
3. Fully committed and pushed the resolved merge to `origin/main` (commit `267d999`).
4. This unblocks the backend pipeline so that Cloud Build triggers and deploys the new location sync code in `/api/auth/me`.

---

## 4. Verification Checklist for the Backend Team

When the backend deployment completes:

1. **Verify Cloud Run / Hosting Deployment Success**
   Check that Google Cloud Build successfully deployed the backend following commit `267d999`.

2. **Test Active Location Update via Postman/curl**
   Send a GET request to `/api/auth/me` with:
   - Header `Authorization: Bearer <JWT_TOKEN>`
   - Header `X-GHL-Location-ID: <NEW_LOCATION_ID>`
   
   Confirm that:
   - The response HTTP status is `200 OK`.
   - The response JSON `user.active_location_id` (and all location aliases) matches `<NEW_LOCATION_ID>`.
   - The Firestore document `users/{uid}` updates `active_location_id` to `<NEW_LOCATION_ID>`.

3. **Check Reverse-Proxy Header Passing**
   Ensure your reverse-proxy configuration passes the `X-GHL-Location-ID` header directly to PHP. If it is stripped, `get_ghl_location_id()` will fall back to `?location_id` in URL, which works but headers are preferred.
