# Frontend Handoff: `/api/notifications` 401

## Issue

The user app is still logging:

```text
GET https://app.nolasmspro.com/api/notifications?limit=30&location_id=ugBqfQsPtGijLjrmLdmA 401 (Unauthorized)
```

Backend has been changed so `/api/notifications.php` is no longer admin-only. It now accepts normal app JWTs and scopes all notification reads/writes to the authenticated user's allowed `location_id`.

If this 401 is still happening, the deployed frontend request is almost certainly not sending a valid app auth token with the notifications request.

## Required Frontend Fix

Every request to `/api/notifications` must include the same user auth token used by the rest of the app:

```ts
const token = localStorage.getItem('nola_auth_token');
const locationId = localStorage.getItem('nola_location_id');

const res = await fetch(`/api/notifications?limit=30&location_id=${encodeURIComponent(locationId ?? '')}`, {
  headers: {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
    'X-GHL-Location-ID': locationId ?? '',
  },
});
```

Do not call this endpoint before the auth/session bootstrap has loaded `nola_auth_token`.

## Recommended Helper

Use one shared helper for notification calls:

```ts
function getNotificationAuthHeaders(locationId?: string): HeadersInit {
  const token = localStorage.getItem('nola_auth_token');

  const headers: Record<string, string> = {
    Accept: 'application/json',
  };

  if (token) headers.Authorization = `Bearer ${token}`;
  if (locationId) headers['X-GHL-Location-ID'] = locationId;

  return headers;
}
```

Then:

```ts
const params = new URLSearchParams({
  limit: '30',
  location_id: locationId,
});

const res = await fetch(`/api/notifications?${params.toString()}`, {
  headers: getNotificationAuthHeaders(locationId),
});
```

## Mutations

`mark_read` must also send auth:

```ts
await fetch('/api/notifications', {
  method: 'POST',
  headers: {
    ...getNotificationAuthHeaders(locationId),
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    action: 'mark_read',
    notification_id: notificationId,
    location_id: locationId,
  }),
});
```

`mark_all_read`:

```ts
await fetch('/api/notifications', {
  method: 'POST',
  headers: {
    ...getNotificationAuthHeaders(locationId),
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    action: 'mark_all_read',
    location_id: locationId,
  }),
});
```

## Backend Behavior Now

`/api/notifications.php` now:

- Accepts normal app JWTs from `Authorization: Bearer <token>`.
- Also accepts fallback token sources: `?token=...`, `nola_auth_token` cookie, `auth_token` cookie, or `token` cookie.
- Rejects anonymous requests with `401`.
- Rejects cross-location requests with `403`.
- Returns only notifications where `admin_notifications.location_id` matches the authorized location.
- Leaves `/api/admin_notifications.php` admin-only.

## Frontend Checks

In browser devtools, inspect the failing request:

- Confirm `Request Headers` includes `Authorization: Bearer ...`.
- Confirm `location_id` matches the logged-in session's `nola_location_id`.
- Confirm the request does not fire before auth bootstrap finishes.
- If the app runs in an iframe or protected browser context, verify the token is available to the notification polling code, not only to profile/account calls.

## Expected Result

With a valid user JWT:

```json
{
  "status": "success",
  "data": []
}
```

or:

```json
{
  "status": "success",
  "data": [
    {
      "id": "notification_doc_id",
      "type": "sender_request",
      "location_id": "ugBqfQsPtGijLjrmLdmA",
      "read": false
    }
  ]
}
```

