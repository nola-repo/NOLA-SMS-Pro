# Handoff: GHL Location ID Mis-detection and Registration Required Screen

Date: 2026-06-24

## Summary

Users should be able to access installed subaccounts under their agency/company. The current failure mode shows a `Registration required` / `Not installed` screen for some subaccounts while another browser/session works.

The most likely root cause is frontend location-id detection accepting the wrong value from the GHL iframe context. In the observed screenshot, the displayed `GHL Location ID` was `102249651`, which looks like a numeric account/company/internal id, not a normal GHL location id. Once the frontend sends that value, backend checks `ghl_tokens/{locationId}` and correctly reports no installed/registered location.

## Ownership

Primary fix: Frontend

Backend support: Guardrails and improved agency fallback are now added locally, but backend cannot reliably recover if the frontend sends the wrong location id.

## Frontend Fix Required

### 1. Tighten location id extraction

The reviewed frontend commit changed `user/src/context/LocationContext.tsx` to accept overly generic keys:

- `location`
- `id`
- nested objects like `company`, `account`, `message`, etc.
- any `postMessage` origin

This can select a company/account id instead of the actual GHL location id.

Required change:

- Remove generic `id` and `location` from the accepted top-level candidate list unless the source object is explicitly a GHL location object.
- Prefer explicit fields only: `location_id`, `locationId`, `ghl_location_id`, `ghlLocationId`, `active_location_id`, `activeLocationId`.
- Do not search inside `company` or `account` objects for location ids.
- Validate `postMessage` origin/source where possible. Accept only expected GHL/LeadConnector origins or known embedded parent messages.
- Add logging that records which source was used: URL, hash, postMessage path, storage, session.

### 2. Do not let stale storage override current GHL context

Current URL/GHL message location id must win. Storage should be fallback only when no current GHL context is available.

If current GHL id conflicts with stored id, overwrite stored id and trigger fresh auto-login.

### 3. Add regression tests

Required cases:

- `postMessage` contains `{ company: { id: "102249651" } }` and no location id: must not set location id.
- `postMessage` contains `{ location: { id: "abcDEF123456" } }`: may set location id only if the object is explicitly a location object.
- URL contains `?location_id=abcDEF123456`: must set `abcDEF123456`.
- URL contains `?company_id=102249651`: must not set location id.
- Stored `nola_location_id` exists but URL has a different `location_id`: URL wins.

## Backend Fix / Guardrails

### 1. Agency fallback for installed but unregistered subaccounts

Backend should allow agency owners to access an installed subaccount even if that subaccount does not yet have its own registered NOLA user record.

Backend change added:

- `api/auth/ghl_autologin.php` now prefers a linked location user.
- If no location user exists, it verifies:
  - `ghl_tokens/{locationId}` exists
  - token is active
  - token `companyId` matches the resolved/requested agency company
  - agency token/account exists
- Then it returns an agency JWT scoped to that location.

This solves the valid case: installed subaccount under agency, no subaccount user yet.

### 2. Reject suspicious location ids earlier

Backend should return a clearer error when the incoming location id is likely not a GHL location id.

Backend change added:

- `api/auth/ghl_autologin.php` returns `INVALID_GHL_LOCATION_ID` for numeric-only ids that do not match an installed token/integration.
- `api/account.php` returns `INVALID_GHL_LOCATION_ID` for numeric-only ids that do not match an installed token/integration, before serving cached profile data.
- Numeric-only ids like `102249651` are still allowed only if they are already confirmed by a real `ghl_tokens` or `integrations` record.

### 3. Improve diagnostics

Log these fields on auto-login failure:

- requested `location_id`
- requested `company_id`
- whether `ghl_tokens/{location_id}` exists
- token `companyId`
- frontend source path, if frontend sends it
- returned error code

## Expected Final Behavior

Installed subaccount + registered location user:

- User JWT returned.
- App opens normally.

Installed subaccount + no location user + agency owns company:

- Agency JWT returned with `location_id`.
- App opens normally for agency/boss access.

Wrong id sent by frontend:

- Backend rejects clearly.
- Frontend does not overwrite stored location id with the wrong id.
- User does not see misleading registration-required flow for a fake/non-location id.

Uninstalled or not provisioned subaccount:

- Still blocked.
- User should be guided to complete install/provisioning.

## QA Checklist

- Open user app from a known installed GHL subaccount in a clean browser profile.
- Confirm Network request to `/api/auth/ghl_autologin` uses the real alphanumeric GHL location id.
- Confirm no request sends numeric `102249651` as `location_id`.
- Switch between subaccounts in GHL and confirm location id changes correctly.
- Test boss/agency account on installed subaccount with no registered subaccount user.
- Test truly uninstalled subaccount still shows install/onboarding required.
