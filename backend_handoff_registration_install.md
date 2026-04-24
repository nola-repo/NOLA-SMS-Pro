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
  - Also updates the `integrations/<location_id>` record to inject `owner_email`, `owner_name`, and `owner_phone`.

### 3. Updated `POST /api/auth/login`
- Added the `phone` field to the `user` object in the JSON response. This allows the frontend (e.g. `SharedLogin.tsx`) to store full user profile data in `localStorage` for checkout pre-filling.

## What Frontend Needs To Do
1. Update `SharedLogin.tsx` to cache the `user` profile (`firstName`, `lastName`, `email`, `phone`) to `localStorage` on login success.
2. Ensure the Checkout process correctly reads these values from `localStorage` to pre-fill billing forms seamlessly.
3. Verify Agency installs saving `companyId` out of `Register.tsx` to pre-fill elements.
