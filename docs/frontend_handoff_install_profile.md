# Frontend Handoff: Install, Auth, and Settings Profile Hydration

## Overview

This handoff documents the updated install/auth flow and the profile field contract required by frontend Settings screens.

The backend now uses a user-owned subaccount model and removes dependency on `location_members` for active install/auth routing.

## Flow Summary

### New subaccount install

1. GHL install callback resolves selected `location_id`.
2. Backend redirects to `/register?install_token=...`.
3. User submits registration.
4. Backend creates `users/{uid}` and `users/{uid}/subaccounts/{locationId}`.
5. Browser is handed off to app with token + user payload.

### Existing linked subaccount

1. GHL callback checks if location is already linked.
2. Backend redirects directly to `/login?welcome_back=1&name=<locationName>`.
3. User logs in, then app loads normally.

## Data Model (Active)

- Root user doc: `users/{uid}`
- User subaccounts: `users/{uid}/subaccounts/{entityId}`
  - for location users: `entityId = locationId`
  - for agency users: `entityId = companyId`

### Notes

- `location_memberships` is removed from auth payloads.
- `location_members` is no longer used by active install/auth paths.

## API Contracts Used by Frontend

### 1) `POST /api/auth/register-from-install`

Request body:
- `full_name`
- `email`
- `phone`
- `password`
- `install_token` (required)

Success response:
- `token`
- `role`
- `location_id`
- `company_id`
- `location_name`
- `company_name`
- `user` (normalized user object)

Conflict response:
- `409` when location is already linked to a different email.
- This should usually be preempted by callback/register pre-check redirects.

### 2) `POST /api/auth/login`

Request body:
- `email`
- `password`

Success response:
- `token`
- `role`
- `location_id`
- `company_id`
- `user` (normalized user object)

### 3) `GET /api/auth/me`

Headers:
- `Authorization: Bearer <token>`

Success response:
- `user` (normalized user object)
- `subaccounts` (array from `users/{uid}/subaccounts/*`)

## Normalized `user` Object (Important)

Backend now returns both naming styles for profile identity fields:

- `name`
- `full_name` (alias)
- `firstName`
- `lastName`
- `email`
- `email_address` (alias)
- `phone`
- `phone_number` (alias)
- `location_id`
- `company_id`
- `location_name`
- `company_name`

## Frontend Mapping Recommendation

Use fallback mapping in Settings/profile UI:

- Full name: `user.full_name || user.name`
- Email: `user.email_address || user.email`
- Phone: `user.phone_number || user.phone`

This guarantees consistent display across old/new payload readers.

## Local Storage Handoff

`auth-handoff.html` writes `nola_auth_user` with both naming styles:

- `name`, `full_name`
- `email`, `email_address`
- `phone`, `phone_number`
- plus location/company fields

Recommended frontend behavior:

1. Read `nola_auth_user` for instant render.
2. Call `/api/auth/me` to self-heal stale cache.
3. Re-save normalized values into app state/store.

## Install Diagnostics for QA

`/register` page logs:

- `[Install Register] Token diagnostics`

Includes:

- `token_type`
- `location_id`
- `company_id`
- `resolution_source`

Use this in DevTools console when QA validates callback resolution branches.

## QA Checklist

- New subaccount install -> lands on registration.
- Already linked subaccount -> lands on login (welcome back), not registration.
- After auth handoff, Settings shows:
  - full name
  - email
  - phone
- Validate inside GHL iframe and outside direct app URL.

## Known Expectation

If a user manually reaches registration for an already-linked subaccount, backend still blocks create/link with conflict. Active flow should redirect to login earlier via callback/register guards.

