# Backend Handoff: Credits Checkout Must Use Account Details Prefill

**Date:** 2026-05-08  
**Repos scanned:**  
- Frontend: `C:\Users\User\nola-sms-pro`
- Backend: `C:\Users\User\nola-sms-pro-backend`

## Problem

The credits checkout popup still opens with only the GHL Location ID filled. Full Name, Email Address, and Phone Number stay empty, even though the Account Details page shows the correct values:

```text
Full Name: Raely Ivan
Email: raely@gmail.com
Phone: +639707567469
Location ID: V52Lp7YQo1ISiSf907Lu
```

The checkout URL should include the same Account Details values:

```text
https://sms.nolawebsolutions.com/nola-sms-pro---500-credits-page-8465-657955
  ?location_id=V52Lp7YQo1ISiSf907Lu
  &name=Raely%20Ivan
  &full_name=Raely%20Ivan
  &first_name=Raely
  &last_name=Ivan
  &email=raely%40gmail.com
  &phone=%2B639707567469
```

Current screenshot shows the popup URL only starts with `?locat...`, and the visible checkout form has only the Location ID field populated.

## What I Found

### 1. Account Details already has the correct backend data

Backend file:

```text
C:\Users\User\nola-sms-pro-backend\api\account.php
```

`GET /api/account?location_id=...` already returns owner/profile fields:

```json
{
  "status": "success",
  "data": {
    "location_id": "V52Lp7YQo1ISiSf907Lu",
    "location_name": "INTERN Account",
    "name": "Raely Ivan",
    "full_name": "Raely Ivan",
    "email": "raely@gmail.com",
    "email_address": "raely@gmail.com",
    "phone": "+639707567469",
    "phone_number": "+639707567469"
  }
}
```

This is why Account Details can show the correct Full Name, Email, and Phone.

### 2. Checkout does not use the Account Details source of truth

Frontend file:

```text
C:\Users\User\nola-sms-pro\user\src\pages\Settings.tsx
```

The Account Details section uses:

```ts
fetchAccountProfile()
```

which calls:

```text
GET /api/account?location_id={location_id}
```

But the Credits checkout section builds the URL from:

```text
liveProfile from /api/auth/me
localStorage nola_user
localStorage nola_auth_user
```

It does **not** use `fetchAccountProfile()` / `/api/account` before opening checkout.

So this can happen:

```text
Account Details fields are correct from /api/account
Checkout builder has missing auth cache/profile
Checkout URL appends location_id only
GHL checkout form only fills Location ID
```

### 3. Backend auth/profile responses are mostly correct, but not guaranteed to match Account Details

These backend files shape the auth profile:

```text
C:\Users\User\nola-sms-pro-backend\api\auth\user_profile_helper.php
C:\Users\User\nola-sms-pro-backend\api\auth\login.php
C:\Users\User\nola-sms-pro-backend\api\auth\me.php
C:\Users\User\nola-sms-pro-backend\api\auth\register_from_install.php
```

`auth_user_payload_for_api()` returns:

```json
{
  "name": "...",
  "full_name": "...",
  "firstName": "...",
  "lastName": "...",
  "email": "...",
  "email_address": "...",
  "phone": "...",
  "phone_number": "...",
  "location_id": "..."
}
```

That is good, but checkout should still fall back to `/api/account` because Account Details is already proving the backend has the correct owner values by `location_id`.

## Required Backend/Integration Contract

Please make the checkout prefill contract explicit and stable:

### Preferred source

For a given checkout `location_id`, backend must expose the same values Account Details uses:

```text
GET /api/account?location_id={location_id}
```

Required response fields under `data`:

```json
{
  "location_id": "V52Lp7YQo1ISiSf907Lu",
  "name": "Raely Ivan",
  "full_name": "Raely Ivan",
  "email": "raely@gmail.com",
  "email_address": "raely@gmail.com",
  "phone": "+639707567469",
  "phone_number": "+639707567469",
  "location_name": "INTERN Account"
}
```

### Frontend checkout should use this exact same payload

Before opening the GHL checkout popup, the frontend should resolve `location_id`, call `/api/account?location_id=...`, and append those returned values to the selected package link.

Required query params:

```text
location_id
name
full_name
first_name
last_name
email
phone
```

### Fallback order

Use this order so checkout always matches Account Details:

```text
1. /api/account?location_id=... response
2. /api/auth/me response
3. localStorage nola_user / nola_auth_user
4. Open checkout with location_id only if no owner fields are available
```

## GHL Funnel Requirement Still Applies

The checkout funnel page must read these URL params and fill fields:

```text
location_id -> input[name="companyname"] or Location ID field
name/full_name -> Full Name field
email -> Email Address field
phone -> Phone Number field
```

If the URL includes the params but GHL still leaves fields empty, the funnel custom code is the remaining issue. If the URL does not include the params, the app/backend prefill source is the issue.

## Test Case

Use the screenshot account:

```text
Location ID: V52Lp7YQo1ISiSf907Lu
Name: Raely Ivan
Email: raely@gmail.com
Phone: +639707567469
```

Expected popup URL:

```text
https://sms.nolawebsolutions.com/nola-sms-pro---500-credits-page-8465-657955?location_id=V52Lp7YQo1ISiSf907Lu&name=Raely+Ivan&full_name=Raely+Ivan&first_name=Raely&last_name=Ivan&email=raely%40gmail.com&phone=%2B639707567469
```

Expected checkout fields:

```text
Location ID: V52Lp7YQo1ISiSf907Lu
Full Name: Raely Ivan
Email Address: raely@gmail.com
Phone Number: +639707567469
```

## Summary

Backend already has the needed Account Details fields in `/api/account`. The current checkout path is not guaranteed to use that same data. Wire the checkout prefill to `/api/account?location_id=...` so the popup URL always matches Account Details, then verify the GHL funnel script consumes the appended params.
