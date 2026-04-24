# Backend Handoff — Registration & Account Unification via Marketplace Install

## Overview
We've updated the GHL Marketplace installation flow. The `ghl_callback.php` now serves a First-Run Registration form if a `users` record does not already exist for the installing `location_id` or `company_id`.

## Changes Implemented

### 1. `ghl_callback.php` (Frontend/Backend Logic)
- **Re-install Check:** After saving OAuth tokens, it queries the `users` collection.
  - If a user exists (via `active_location_id` or `company_id`), it renders a "Welcome Back" screen.
  - If not, it renders the "Complete Your Account" registration form with location details pre-filled (read-only).

### 2. New Endpoint: `POST /api/auth/register-from-install`
- Native route: `/api/auth/register_from_install.php` (rewritten via `.htaccess`).
- Accepts JSON payload: `full_name`, `phone`, `email`, `password`, `location_id`, `company_id`.
- **Logic:**
  - If the email already exists, it links the new location to the existing account and returns `{status: "linked"}`.
  - If it's a new email, it creates a `users` record:
    - `role`: `"user"` (if `location_id` exists) or `"agency"` (if `companyId` installs without `location_id`).
    - `source`: `"marketplace_install"`
  - Updates the `integrations/<location_id>` record to inject `owner_email`, `owner_name`, and `owner_phone`.
  - **[NEW] Writes to GHL Custom Values via API:** Injects `owner_name`, `owner_email`, and `owner_phone` into the GHL location as Custom Values so that Funnel Order Forms (like the checkout page) can be pre-filled dynamically using `{{location.custom_values.owner_name}}` etc.

### 3. Updated `POST /api/auth/login`
- Added the `phone` field to the `user` object in the JSON response. This allows the frontend (e.g. `SharedLogin.tsx`) to store full user profile data in `localStorage` for checkout pre-filling.

## What Frontend Needs To Do
1. Update `SharedLogin.tsx` to cache the `user` profile (`firstName`, `lastName`, `email`, `phone`) to `localStorage` on login success.
2. Ensure the Checkout process correctly reads these values from `localStorage` to pre-fill billing forms seamlessly (via URL params `?name=...&email=...`).
3. Verify Agency installs saving `companyId` out of `Register.tsx` to pre-fill elements.

## Immediate Action Required — OAuth Scopes Update
To allow the backend to write the custom fields dynamically, you **must add the `locations.write` scope** to the GHL Marketplace App.

1. Go to the GHL Developer Portal.
2. Navigate to your app → OAuth tab → Scopes.
3. Add `locations.write`.

*(Note: Existing users will be prompted to re-authorize the app to accept this new scope. You may need to plan a migration/re-install campaign for current users).*
