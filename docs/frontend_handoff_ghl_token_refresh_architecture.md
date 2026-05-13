# Frontend handoff: GHL token refresh and reconnect UX

## Main principle

GHL access token expiry should not be visible to the user.

The frontend should never read, store, refresh, or reason about GHL OAuth tokens. It should only call the backend and render the state the backend returns.

## Current repo status

Scanned frontend files:

- `frontend_old/src/api/contacts.ts`
- `frontend_old/src/components/ContactsTab.tsx`
- `frontend_old/src/api/sms.ts`
- `frontend_old/src/pages/GhlCallback.tsx`
- `frontend_old/src/api/config.ts`
- root `App.tsx`
- root `Settings.tsx`
- root `authService.ts`
- root `settingsStorage.ts`

Important backend contract from the scanned PHP repo:

- `GET /api/ghl-contacts` returns success as `{ "contacts": [...] }`.
- GHL reconnect currently returns HTTP `401` with `{ "requires_reconnect": true }`.
- Temporary token failures currently return HTTP `503` with `{ "requires_reconnect": false }`.

The frontend must treat these as separate states.

## GHL facts absorbed into the frontend strategy

Official HighLevel docs confirm:

- Access tokens expire after about 24 hours.
- Refresh tokens rotate. When one is used, GHL returns a new refresh token and the previous one becomes invalid.
- GHL API rate limits are 100 requests per 10 seconds and 200,000 requests per day per Marketplace app per location/company resource.

References:

- https://marketplace.gohighlevel.com/docs/oauth/Faqs/index.html
- https://marketplace.gohighlevel.com/docs/2021-07-28/Authorization/OAuth2.0/index.html
- https://help.gohighlevel.com/support/solutions/articles/48001060529-highlevel-api-documentation

Frontend takeaway:

```text
Do not create request storms.
Do not retry reconnect-required failures.
Do retry or allow manual retry for temporary/rate-limit failures.
Do not show reconnect unless backend explicitly says requires_reconnect: true.
```

## Required frontend architecture

Frontend flow:

```text
Frontend page/hook
   -> backend API endpoint
   -> backend GhlClient/GhlTokenProvider
   -> GHL API
```

The frontend should not care whether the backend:

- used an existing access token,
- refreshed the access token,
- saved a rotated refresh token,
- exchanged a company token for a location token,
- retried after a GHL `401`.

The frontend should only handle:

```text
loading
success with data
success with empty data
temporary failure
reconnect required
user not logged in
permission denied
```

## Repo-specific potential issues to fix

### 1. `contacts.ts` currently turns backend errors into an empty list

File:

- `frontend_old/src/api/contacts.ts`

Current behavior:

```text
if !res.ok -> console.error -> return []
catch -> return []
```

Potential issue:

The UI cannot tell the difference between:

```text
real empty contacts
GHL reconnect required
temporary GHL failure
backend/server error
```

Apply rule:

Do not return `[]` for non-2xx responses. Return a typed result or throw a typed error.

### 2. `contacts.ts` does not parse the current backend success shape

File:

- `frontend_old/src/api/contacts.ts`

The backend returns:

```json
{
  "contacts": []
}
```

But the current frontend parser checks only:

```text
Array.isArray(data)
data.data
```

Potential issue:

Even successful contact responses can show as empty.

Apply rule:

Parse all expected success shapes:

```ts
const contacts = Array.isArray(data)
  ? data
  : Array.isArray(data.contacts)
    ? data.contacts
    : Array.isArray(data.data)
      ? data.data
      : [];
```

### 3. `ContactsTab.tsx` has no reconnect state

File:

- `frontend_old/src/components/ContactsTab.tsx`

Current behavior:

```text
fetchContacts().then(setContacts)
empty list -> "No contacts available"
```

Potential issue:

Reconnect-required token failure can be shown as "No contacts available."

Apply rule:

Add an explicit state:

```ts
type ContactsLoadState =
  | { kind: "loading" }
  | { kind: "ready"; contacts: Contact[] }
  | { kind: "reconnect"; message: string }
  | { kind: "temporary"; message: string }
  | { kind: "error"; message: string };
```

Only show "No contacts available" when:

```text
state.kind === "ready" && contacts.length === 0
```

### 4. Reconnect-required requests should not be retried in a loop

If the team adds React Query, SWR, or a retry helper, do not retry when:

```ts
error.requiresReconnect === true
```

Retry only temporary failures:

```ts
if (error.requiresReconnect) return false;
if (error.retryable || error.status === 503 || error.status === 429) return count < 2;
return false;
```

### 5. Frontend request storms can amplify backend refresh/rate-limit issues

GHL rate limits apply per app per location/company resource. Even though the frontend never calls GHL directly, multiple frontend components can still cause the backend to hit GHL many times at once.

Potential issue:

```text
Contacts page loads
Settings loads profile/location
Sidebar loads conversations
pull-to-refresh fires
background refetch fires
backend receives a burst
GHL returns 429 or multiple requests compete for token refresh
```

Apply rule:

- De-dupe in-flight requests for the same location and resource.
- Avoid polling contacts aggressively.
- Disable pull-to-refresh while a fetch is already in progress.
- Use one shared contacts query/store instead of each component fetching independently.
- Back off on `retryable === true`, especially `429` and `503`.
- Do not make a reconnect OAuth attempt automatically; require a user click.

### 6. Reconnect success must clear stale UI/cache

File:

- `frontend_old/src/pages/GhlCallback.tsx`

Potential issue:

OAuth can succeed but Contacts still shows the old reconnect/empty state because local component state or query cache was not refreshed.

Apply rule:

After reconnect:

- save/update the active location ID if backend returns one,
- invalidate/refetch GHL data,
- clear the reconnect state,
- navigate back to the app section that triggered reconnect if possible.

If using React Query:

```ts
queryClient.invalidateQueries({ queryKey: ["ghl"] });
```

Without React Query, call the same `loadContacts()` function again after returning from OAuth.

### 7. Reconnect CTA should use backend-owned OAuth flow

Files:

- `frontend_old/src/pages/GhlCallback.tsx`
- root `Settings.tsx`

The current frontend has some hard-coded/direct OAuth behavior. The safer pattern is:

```text
User clicks Reconnect GHL
   -> frontend calls backend OAuth start endpoint
   -> backend returns/redirects to the correct GHL OAuth URL
   -> GHL callback hits backend
   -> backend saves canonical ghl_tokens doc
   -> frontend refetches GHL data
```

Apply rule:

Do not construct token exchange behavior in the browser. The frontend may redirect to an OAuth URL, but backend should own the URL generation and callback/token save.

### 8. Frontend app requests should include JWT when available

File:

- `frontend_old/src/api/config.ts`
- `frontend_old/src/api/contacts.ts`
- root `authService.ts`

The old frontend sends `X-Webhook-Secret`, but not necessarily `Authorization: Bearer <JWT>`.

Potential issue:

The backend can only enforce user/location ownership when JWT context is available.

Apply rule:

For browser app API calls, include:

```text
Authorization: Bearer <nola_auth_token>
X-Webhook-Secret: <existing value while backend still requires it>
X-GHL-Location-ID: <active location id>
Content-Type: application/json
```

### 9. Multi-location state can become stale

Files:

- root `settingsStorage.ts`
- root `Settings.tsx`
- `frontend_old/src/hooks/useGhlLocation.ts`

Potential issue:

GHL can load multiple subaccounts in the same browser. If local storage has a stale location ID, the frontend may request Location A while the user is viewing Location B.

Apply rule:

Use the active location from the current session/profile or current GHL URL context. When the location changes, refetch GHL queries and reset reconnect state for the new location.

### 10. Temporary failures should not show reconnect

Temporary backend responses may be:

```text
503 with requires_reconnect: false
429
500
network timeout
```

Apply rule:

Show a retryable message, not reconnect:

```text
GoHighLevel is temporarily unavailable. Please try again.
```

Only show reconnect when:

```ts
body?.requires_reconnect === true
```

## Recommended API helper

Create one shared helper for backend calls:

```ts
export class ApiError extends Error {
  status: number;
  code?: string;
  requiresReconnect: boolean;
  retryable: boolean;

  constructor(message: string, options: {
    status: number;
    code?: string;
    requiresReconnect?: boolean;
    retryable?: boolean;
  }) {
    super(message);
    this.status = options.status;
    this.code = options.code;
    this.requiresReconnect = options.requiresReconnect === true;
    this.retryable = options.retryable === true;
  }
}

async function readJsonOrText(res: Response): Promise<any> {
  const text = await res.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return { message: text };
  }
}

export async function apiRequest<T>(url: string, init: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem("nola_auth_token");

  const headers = {
    Accept: "application/json",
    "Content-Type": "application/json",
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(init.headers || {})
  };

  const res = await fetch(url, {
    ...init,
    headers
  });

  const body = await readJsonOrText(res);

  if (!res.ok) {
    throw new ApiError(body?.message || body?.error || "Request failed", {
      status: res.status,
      code: body?.code,
      requiresReconnect: body?.requires_reconnect === true,
      retryable:
        body?.retryable === true ||
        (body?.requires_reconnect !== true && [429, 500, 502, 503, 504].includes(res.status))
    });
  }

  return body as T;
}
```

Keep `X-Webhook-Secret` and `X-GHL-Location-ID` in the GHL-specific wrapper while the backend requires them.

## Recommended contacts API shape

Preferred result type:

```ts
export type FetchContactsResult =
  | { ok: true; contacts: Contact[] }
  | {
      ok: false;
      contacts: [];
      kind: "reconnect" | "temporary" | "error";
      message: string;
      status: number;
    };
```

Example implementation shape:

```ts
export const fetchContacts = async (): Promise<FetchContactsResult> => {
  try {
    const data = await apiRequest<any>(CONTACTS_API_URL, {
      headers: getGhlHeaders()
    });

    const contacts = Array.isArray(data)
      ? data
      : Array.isArray(data.contacts)
        ? data.contacts
        : Array.isArray(data.data)
          ? data.data
          : [];

    return { ok: true, contacts };
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.requiresReconnect) {
        return {
          ok: false,
          contacts: [],
          kind: "reconnect",
          message: err.message || "GoHighLevel needs to be reconnected.",
          status: err.status
        };
      }

      if (err.retryable) {
        return {
          ok: false,
          contacts: [],
          kind: "temporary",
          message: err.message || "GoHighLevel is temporarily unavailable.",
          status: err.status
        };
      }

      return {
        ok: false,
        contacts: [],
        kind: "error",
        message: err.message,
        status: err.status
      };
    }

    return {
      ok: false,
      contacts: [],
      kind: "error",
      message: "Failed to load contacts.",
      status: 0
    };
  }
};
```

## Contacts UI rules

In `ContactsTab.tsx`, show:

```text
loading -> loading spinner
ready + contacts.length > 0 -> contacts list
ready + contacts.length === 0 -> "No contacts available"
reconnect -> reconnect banner/button
temporary -> retryable temporary error
error -> generic error
```

Reconnect banner copy:

```text
GoHighLevel needs to be reconnected.
Reconnect your CRM to load contacts again.
```

CTA:

```text
Reconnect GoHighLevel
```

The CTA should route to Settings or call the backend OAuth start endpoint, depending on the final backend route.

## Retry/backoff rules

Use different behavior for each error category:

```text
requiresReconnect === true -> no retry; show reconnect CTA
status === 429 -> back off; show temporary retry state
status === 503 -> allow limited retry/manual retry
status === 401 without requiresReconnect -> app auth/login issue
status === 403 -> permission issue, not reconnect
200 with [] -> real empty contacts state
```

If using React Query:

```ts
retry: (count, error: any) => {
  if (error.requiresReconnect) return false;
  if (error.status === 401 || error.status === 403) return false;
  if (error.retryable || error.status === 429 || error.status === 503) return count < 2;
  return false;
},
retryDelay: attempt => Math.min(1000 * 2 ** attempt, 8000)
```

## Mutation rules

Apply the same error parsing to:

- add contact
- update contact
- delete contact
- create GHL conversation
- send SMS if the UI cares about GHL sync results

If mutation returns `requires_reconnect: true`, show reconnect banner/toast. Do not show a generic "Failed to add contact" only.

## Frontend QA checklist

Healthy token:

```text
GET /api/ghl-contacts -> 200 { contacts: [...] }
contacts render correctly
200 { contacts: [] } shows real empty state
```

Reconnect token:

```text
backend returns 401 + requires_reconnect true
contacts page shows reconnect banner
contacts page does not show "No contacts available"
frontend does not retry in a loop
after reconnect, contacts refetch
```

Temporary failure:

```text
backend returns 503 + requires_reconnect false
frontend shows temporary retry state
frontend does not show reconnect
```

Rate limit:

```text
backend returns 429 or retryable true
frontend backs off
frontend does not show reconnect
frontend does not keep refetching in a loop
```

Multi-location:

```text
switch active location
frontend sends correct X-GHL-Location-ID
old reconnect state does not leak into the new location
```

Auth:

```text
app JWT missing/expired -> app login/auth flow
GHL reconnect required -> GHL reconnect flow
permission denied -> no reconnect prompt
```

## Ownership rule

```text
Frontend owns UI state.
Backend owns OAuth and refresh.
GhlClient owns GHL request/retry behavior.
GhlTokenProvider owns token lookup and refresh classification.
Firestore owns canonical token state.
```
