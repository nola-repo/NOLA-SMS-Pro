# Security Frontend Handoff

## Context

Latest commit before this hardening pass:

- `c0241df4a5169f3c757adbff621dff9b3c5adede`
- Message: `Fetching Real Agency Name`
- Date: `2026-06-09 13:55:54 +0800`

This backend security pass keeps the current working flows intact while removing the highest-risk production shortcuts.

## Backend Changes Frontend Should Know

Admin APIs now require a real admin JWT in:

- `Authorization: Bearer <admin_token>`

Legacy browser headers are no longer accepted by the live code path:

- `X-Admin-Auth`
- `X-Admin-User`

Admin role enforcement is now stricter:

- `api/admin_users.php`: `super_admin` only
- `api/admin_settings.php`: `GET` allows `super_admin`, `support`, `viewer`; writes require `super_admin`
- `api/admin_profile.php`: `super_admin`, `support`
- `api/admin_manage_user.php`: `super_admin`, `support`
- `api/admin_agencies.php`, `api/admin_list_users.php`, `api/admin_notifications.php`: admin JWT required

CORS is now allowlisted. Production/staging frontend origins must be present in `CORS_ALLOWED_ORIGINS`, comma-separated.

Required backend env vars now include:

- `JWT_SECRET`
- `WEBHOOK_SECRET`
- `SEMAPHORE_API_KEY`
- `GHL_CLIENT_ID`
- `GHL_CLIENT_SECRET`
- `GHL_AGENCY_CLIENT_ID`
- `GHL_AGENCY_CLIENT_SECRET`
- optional: `GHL_USER_CLIENT_ID`
- optional: `GHL_USER_CLIENT_SECRET`
- optional: `CORS_ALLOWED_ORIGINS`

Debug-only endpoints are blocked in production when `APP_ENV=production` or unset:

- `api/webhook/debug_ghl.php`
- `api/seed_admin.php`

## Frontend Requirements

Admin frontend must send the admin token on every admin API call:

```ts
headers: {
  Authorization: `Bearer ${adminToken}`,
  Accept: 'application/json',
}
```

Do not send `X-Admin-Auth` or `X-Admin-User`; those are legacy bypass headers.

Handle these auth errors by redirecting to admin login:

- `401 Admin token missing`
- `401 Admin token invalid or expired`
- `403 Admin account is inactive or no longer exists`

Handle permission errors without logout:

- `403 Forbidden: insufficient admin permissions`

## Important Follow-Up

The current user/agency frontend still has compatibility code in `account.ts` that can send `VITE_WEBHOOK_SECRET` as `X-Webhook-Secret`. Keep it for this release so existing account/profile flows do not break.

Next security phase should migrate browser-facing user/agency endpoints to JWT-only access and remove `VITE_WEBHOOK_SECRET` from frontend builds.

## QA Checklist

- Admin login succeeds only when `JWT_SECRET` is configured.
- Admin user list loads with a valid admin JWT.
- Admin settings can be read by viewer/support/super admin.
- Admin settings writes are rejected for viewer/support and allowed for super admin.
- Admin notifications load with admin JWT.
- Agency OAuth install still succeeds with env-provided GHL agency credentials.
- Subaccount OAuth install still succeeds with env-provided GHL subaccount credentials.
- SMS sending still works with `SEMAPHORE_API_KEY` from env.
- Production cannot access `/api/webhook/debug_ghl.php` or `/api/seed_admin.php`.
