# Frontend Handoff: Add Request IDs To Every API Call

## Context

Cloud Run access logs currently show `request_id="-"` for some frontend API requests, for example:

```text
request="GET /api/admin_sender_requests.php HTTP/1.1" status=200 ... request_id="-"
```

Other requests already show a UUID request ID. This means only some frontend calls are sending a request ID header. To make logs traceable across all endpoints, the frontend should send a request ID with every API request.

## Goal

Every browser-to-backend request should include:

```http
X-Request-ID: <uuid>
```

Use one new UUID per HTTP request. Do not reuse the same ID for the whole browser session.

## Recommended Implementation

Create a shared request helper, then migrate direct `fetch(...)` calls to it.

Suggested helper:

```ts
export function createRequestId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function withRequestId(headers?: HeadersInit): Headers {
  const merged = new Headers(headers);
  if (!merged.has('X-Request-ID')) {
    merged.set('X-Request-ID', createRequestId());
  }
  return merged;
}

export function apiFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
  return fetch(input, {
    ...init,
    headers: withRequestId(init.headers),
  });
}
```

Then replace direct calls like:

```ts
fetch('/api/admin_sender_requests.php', { headers: { Accept: 'application/json' } })
```

with:

```ts
apiFetch('/api/admin_sender_requests.php', { headers: { Accept: 'application/json' } })
```

## Files In This Workspace That Need Migration

Current direct `fetch(...)` usage found in:

- `account.ts`
- `authService.ts`
- `hooks/useUserProfile.ts`
- `Settings.tsx`

Important examples:

- `account.ts`: `/api/account.php`, `/api/agency/profile.php`
- `authService.ts`: `/api/auth/login.php`, `/api/auth/register.php`, `/api/agency/link_company.php`
- `hooks/useUserProfile.ts`: `/api/agency/profile.php`, `/api/auth/me.php`
- `Settings.tsx`: `/api/billing/subaccount_wallet.php`

The admin dashboard frontend also needs the same change, especially calls to:

- `/api/admin_sender_requests.php`
- `/api/admin_list_users.php`
- `/api/admin_notifications.php`
- `/api/admin_settings.php`
- `/api/admin_profile.php`
- `/api/admin_manage_user.php`

## Backend / Logging Expectation

The backend or web server access log should read the same header name consistently:

```http
X-Request-ID
```

If the access log is currently reading a different header, align it to `X-Request-ID` or support both `X-Request-ID` and `X-Correlation-ID`.

## QA Checklist

- Open the app and perform normal user flows.
- Open the admin dashboard and load sender requests, users, and notifications.
- Run the local watcher:

```cmd
node watch-nola-logs.js
```

- Confirm every frontend API request shows a UUID instead of:

```text
request_id="-"
```

- Confirm request IDs are different per request.
- Confirm auth, registration, settings, billing, and admin API calls still work.
