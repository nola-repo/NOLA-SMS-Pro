# Backend handoff: GHL manual reconnect flow

Status: Ready for backend review  
Priority: High, paired with frontend `requires_reconnect` banner UX  
Frontend scope: `user/src/pages/Settings.tsx`, `user/src/config.ts`, `user/src/pages/GhlCallback.tsx`

## Problem

When `/api/ghl-contacts` returns:

```json
{
  "error": "Token refresh failed",
  "requires_reconnect": true
}
```

the frontend now explains the empty Contacts state and sends the user to Settings. Settings needs a visible manual reconnect action that re-runs the GoHighLevel OAuth flow so the backend can store fresh tokens for the current location/account.

## Frontend change

The user app now exposes a primary Settings control:

- Label: `Reconnect GoHighLevel`
- Location: Settings > Account > Workspace / GHL Info
- Action: redirects to the existing GHL Marketplace OAuth location picker:

```text
https://marketplace.gohighlevel.com/oauth/chooselocation?appId=65f8a0c2837bc281e59eef7b
```

The URL is now centralized in:

```text
user/src/config.ts
```

Both the Dashboard "Connect GHL" alert and Settings reconnect button use the same `GHL_MARKETPLACE_CONNECT_URL`.

## Expected walkthrough

1. User opens Contacts.
2. Frontend calls `GET /api/ghl-contacts` with session auth plus the current GHL location ID.
3. Backend cannot refresh the stored GHL token.
4. Backend returns `401` with `requires_reconnect: true`.
5. Contacts shows "GoHighLevel connection expired" and an `Open Settings` CTA.
6. User opens Settings.
7. User clicks `Reconnect GoHighLevel`.
8. Browser goes to the GHL Marketplace OAuth location picker.
9. User chooses/approves the same GHL location.
10. GHL redirects back to the frontend callback route:

```text
/oauth/callback?code=...
```

11. `user/src/pages/GhlCallback.tsx` posts the OAuth code to:

```text
POST /api/ghl_oauth
```

with body:

```json
{
  "code": "<authorization code>",
  "redirectUri": "<current origin>/oauth/callback"
}
```

12. Backend exchanges the code with GHL.
13. Backend stores fresh `access_token`, `refresh_token`, expiry metadata, and the returned location/company identifiers in the canonical token document(s).
14. Frontend callback marks local account settings as connected and preserves/updates `ghlLocationId` from the backend response.
15. User returns to Contacts.
16. `GET /api/ghl-contacts` succeeds with valid tokens.

## Backend expectations

`POST /api/ghl_oauth` should support reconnect as an idempotent OAuth re-authorization, not only first install.

On success, return JSON including the current location identifier when available:

```json
{
  "success": true,
  "locationId": "<ghl location id>",
  "locationName": "<optional location name>",
  "companyId": "<optional company id>"
}
```

Backend should upsert token storage for the selected GHL location. Do not create duplicate or stale token records for the same location if a document already exists.

Recommended token write targets to verify:

- `ghl_tokens` or the current canonical GHL token collection
- any related user/profile/location mapping used by `/api/ghl-contacts`
- any agency/company mapping required for agency subaccount views

## Backend acceptance criteria

- Re-running OAuth for an already connected location replaces expired tokens with fresh tokens.
- `/api/ghl-contacts` uses the refreshed token record immediately after reconnect.
- The reconnect flow does not require manually pasting tokens.
- The reconnect flow does not require reinstalling the whole NOLA app.
- If token exchange fails, `/api/ghl_oauth` returns a clear non-2xx JSON error.
- If `/api/ghl-contacts` still cannot refresh after reconnect, it continues returning `401` with `requires_reconnect: true`.

## QA checklist

Healthy token:

1. Open Contacts.
2. Confirm `/api/ghl-contacts` returns `200`.
3. Confirm Contacts shows real contacts or the genuine empty state.

Expired/revoked token:

1. Revoke or invalidate the GHL refresh token for a test location.
2. Open Contacts.
3. Confirm `/api/ghl-contacts` returns `401` and `requires_reconnect: true`.
4. Confirm Contacts shows reconnect banner, not "No contacts available".
5. Click `Open Settings`.
6. Click `Reconnect GoHighLevel`.
7. Complete OAuth for the same location.
8. Confirm `/api/ghl_oauth` stores fresh tokens.
9. Return to Contacts.
10. Confirm contacts load without backend changes beyond token refresh.

## Notes

Manual Location ID entry in Settings is not a token refresh. It only changes which location ID the frontend sends. Token refresh must happen through OAuth.

The frontend callback currently redirects back to the same path after a short success state. If backend needs a stronger post-reconnect landing path, return enough metadata for frontend to route back to Settings or Contacts in a later polish pass.
