# Frontend Caching Handoff

Date: 2026-06-23

## Purpose

This handoff explains how the frontend should support the caching strategy across:

- GoHighLevel iframe use.
- External public-domain use.
- Agency portals.
- Admin/internal tools.

The UX goal is simple: show useful data immediately when safe, refresh quietly in the background, and make important updates feel reliable.

## Core Frontend Rules

1. Use a shared request/query cache.
   Adopt one frontend data layer for authenticated GET requests. Query keys must include the access surface, role, company ID, location ID, endpoint, and filters.

2. Treat stored data as last-known good.
   Cached data can render immediately, but the UI should know whether it is fresh, refreshing, stale, or failed to refresh.

3. URL and active session context win over storage.
   In GHL iframes, the current URL or GHL-provided context must always override stored location/company values.

4. Never share cached tenant data across locations or agencies.
   Local storage keys should be scoped. Avoid using unscoped keys for mutable account, sender, contact, billing, or dashboard data.

5. Do not hard reload pages to get fresh data.
   Use cache invalidation and targeted refetch after successful actions.

## Suggested Query Key Shape

Use stable keys similar to:

```ts
[
  "nola",
  surface,       // "ghl-iframe" | "external" | "agency" | "admin"
  role,          // "user" | "agency" | "admin"
  companyId,
  locationId,
  resource,      // "credits" | "conversations" | "messages" | etc.
  filtersHash
]
```

For admin data without a location, use `locationId = "global"` and include role/permission scope if responses differ by admin role.

## Screens That May Display Cached Information

| Screen or feature | Can show cached data? | Notes |
| --- | --- | --- |
| Main dashboard | Yes | Show last-known cards immediately, then refresh summary and credits. |
| Conversations sidebar | Yes | Cached list is acceptable briefly, but open thread should refresh quickly. |
| Open message thread | Limited | Show cached messages while fetching, but poll or refetch active thread. |
| Contacts | Yes | Cached contacts are fine if a refresh indicator is shown. |
| Templates | Yes | Low-risk cache. Invalidate after create/update/delete. |
| Reports and analytics | Yes | Show cache age, especially for current-day reports. |
| Credits and billing balance | Yes, short-lived | Must refetch after financial or message-send actions. |
| Transactions ledger | Yes | Cache pages; refresh after credit-affecting actions. |
| User profile/settings | Yes | Render cached profile, refetch on mount/focus/save. |
| Sender IDs | Yes | Show pending/live status clearly. Refetch after request or admin action. |
| Agency subaccounts | Yes | Show cached list, refresh after install/sync/toggle/rate-limit changes. |
| Agency wallet | Yes, short-lived | Refetch after gift, auto-recharge, payment, or lock change. |
| Admin users/accounts/agencies | Yes | Show cache age and manual refresh. |
| Admin sender requests | Yes | Refetch after approve/reject/revoke. |
| Whitelabel branding | Yes | Cache by domain; refresh after branding changes. |

## Automatic Refresh Guidance

Use these default stale times unless a backend response supplies a stricter `cache_ttl`.

| Data | Suggested stale time | Refetch triggers |
| --- | --- | --- |
| Auth profile/session shell | 5 to 10 minutes | App start, focus return, login, profile save |
| Dashboard summary | 30 to 120 seconds | Focus return, send, inbound event, credit event |
| Credits balance | 15 to 30 seconds | Send, top-up, gift, credit request, focus return |
| Conversations list | 15 to 60 seconds | Send, inbound event, rename, delete, focus return |
| Active message thread | 0 to 15 seconds | Thread open, send, inbound event, status pending |
| Historical message pages | 1 to 5 minutes | Manual refresh or filter change |
| Contacts | 5 to 10 minutes | Create/update/delete/import/contact sync |
| GHL contacts | 10 to 30 minutes | Manual refresh, contact mutation, reconnect |
| Templates | 5 to 10 minutes | Create/update/delete |
| Sender IDs | 5 to 10 minutes | Request, approval, rejection, revoke |
| Agency subaccounts | 2 to 5 minutes | Install/provision/sync/toggle/rate-limit change |
| Agency wallet | 30 to 60 seconds | Gift, auto-recharge change, payment event |
| Credit requests | 30 to 60 seconds for pending | Submit, approve, deny |
| Transactions current month | 60 seconds | Credit mutation or manual refresh |
| Closed-month reports | 6 to 24 hours | Manual refresh |
| Admin lists | 5 minutes | Admin mutation or manual refresh |
| Whitelabel branding | 15 to 60 minutes | Branding save or domain change |

## Actions That Must Force Fresh Data

After these actions succeed, invalidate/refetch the listed data:

| User action | Refetch immediately |
| --- | --- |
| Send SMS | Credits, conversations, active thread, dashboard summary, current-day reports |
| Receive/see inbound SMS event | Conversations, active thread, dashboard summary |
| Rename/delete conversation | Conversations, affected thread |
| Create/update/delete contact | Contacts, conversations if name/phone changed |
| Create/update/delete template | Templates |
| Save profile/account settings | Profile, account settings, dashboard shell |
| Request sender ID | Sender IDs, account profile |
| Admin approve/reject sender ID | Sender requests, sender IDs, account profile, admin dashboard |
| Top up credits | Credits, transactions, dashboard summary |
| Agency gift credits | Agency wallet, subaccount credits, transactions, agency subaccounts |
| Credit request submit | Credit requests, subaccount wallet |
| Credit request approve/deny | Credit requests, agency wallet, subaccount credits, transactions |
| Toggle subaccount or update rate limit | Agency subaccounts, install/status summary |
| Sync/provision agency locations | Agency locations, subaccounts, install checks |
| Save admin settings | Admin settings, dependent admin dashboard data |
| Update whitelabel branding | Whitelabel branding by domain, agency profile if displayed |

## Loading State Behavior

Initial screen load:

- If no cached data exists, show skeletons or compact loading placeholders.
- If cached data exists, render it immediately and show a small non-blocking updating state.
- Avoid clearing a populated screen to a full-page spinner during refetch.

Background refresh:

- Keep existing data visible.
- Show subtle indicators such as "Updating..." near the affected section.
- Disable only the specific control that is saving or mutating, not the whole page.

Failed refresh:

- Keep last-known good data visible.
- Show a concise inline message for the affected section.
- Do not replace balances, reports, or profile values with zero/empty states after a failed refresh.

Mutation in progress:

- Use button-level loading states.
- For financial actions, wait for backend confirmation before changing displayed balance unless the mutation response returns the authoritative new balance.
- For sends, show the message in a pending state once the backend accepts it, then let status refresh update it.

Empty state:

- Only show empty states when the fresh response confirms empty data.
- If stale data exists and refresh fails, show stale data with a refresh warning instead of an empty state.

## How To Inform Users When Data Is Updating

Use quiet, local status indicators:

- "Updating..." for background refetch.
- "Updated just now" or a relative timestamp when helpful.
- "Showing last synced contacts" when backend returns `stale: true`.
- "Refresh" button on admin, reports, agency install, and billing screens.
- Avoid modal warnings for normal background refreshes.

For sensitive data:

- Credits and wallet cards should show a small spinner or "Updating balance..." after send/top-up/gift.
- Reports should show cache age when the date range includes today.
- Admin lists should show a refresh button and last updated time.

## Iframe and External Domain Consistency

The same screens should behave the same across GHL iframe and external domain access, with these differences handled internally:

- In iframe mode, storage may be blocked. Keep an in-memory query cache for the current session.
- In iframe mode, active `location_id` from URL/GHL context always overrides stored values.
- On external domains, persistent storage is usually available, but keys still must be scoped by tenant and role.
- Do not assume cookies are available in iframe mode.
- Do not assume the external domain and iframe share browser storage.
- Use the same cache metadata from the backend in both surfaces.

## Request Reduction Recommendations

- De-duplicate identical in-flight GET requests.
- Reuse cached data during route changes.
- Prefetch likely next screens after dashboard load:
  - Credits.
  - Conversations.
  - Templates.
  - Sender IDs.
  - Account profile.
- Avoid refetching profile/account data from every component. Use a shared provider/query.
- Debounce search/filter requests for contacts, messages, reports, and admin lists.
- Use pagination and infinite loading instead of fetching entire histories.
- For message status, prefer lightweight polling of active/pending records over refetching all messages.
- Stop polling when the tab/iframe is hidden, then refetch on visibility return.
- Use `refresh=1` or `bypass_cache=1` only after user actions or explicit manual refresh, not on every navigation.

## Backend Metadata To Consume

When present, consume:

```json
{
  "cached": true,
  "stale": false,
  "generated_at": "2026-06-23T05:00:00Z",
  "cache_ttl": 60
}
```

Expected UI interpretation:

- `cached: true`, `stale: false`: render normally and optionally show cache age.
- `cached: true`, `stale: true`: render with "showing last synced data" state and offer refresh.
- `cached: false`: render as fresh.
- Missing metadata: treat response as fresh for the current render, but apply frontend stale-time rules.

Also inspect response headers when available:

- `X-Nola-Cache`.
- `Cache-Control`.
- `X-Request-ID`.

## UX By Feature

### Dashboard

- Render cached cards immediately.
- Refresh credits and summary independently.
- Do not block navigation while dashboard cards refresh.
- If one card fails, keep the rest of the dashboard usable.

### Conversations and Messaging

- Keep the conversation list visible during refresh.
- Open thread should prioritize freshness over long caching.
- After send, append the accepted pending message and refetch credits plus the thread.
- Pending/sending messages should refresh until terminal state: sent, delivered, failed, or expired.

### Contacts

- Show cached list while refreshing.
- After create/update/delete, update the visible row optimistically only if the backend action succeeded.
- If GHL contacts are stale due to temporary GHL outage, show "Showing last synced contacts" and keep search/filter usable against cached data.

### Reports

- Display last updated time.
- Keep filters stable while refresh runs.
- Cache closed date ranges longer than current-day data.
- Manual refresh should pass the backend cache bypass flag.

### Credits and Billing

- Never reset balance to zero on failed refresh.
- After checkout success, refetch balance and transactions until the new transaction appears or a reasonable timeout is reached.
- Agency wallet/subaccount wallet changes should refresh both sides of a transfer.

### Profiles and Settings

- Render cached profile while refreshing.
- After save, use the response payload to update local cache, then refetch.
- Avoid cross-tenant settings leakage by moving from legacy shared localStorage keys to scoped keys.

### Sender IDs

- Show cached approved/pending sender IDs.
- After a request, mark the sender as pending and refetch the canonical list.
- Send forms should refresh sender IDs when opened if the cache is stale.

### Agency Management

- Subaccount tables can show cached data with last updated time.
- Install/provision/sync actions should invalidate subaccount, install status, and agency wallet queries.
- Use manual refresh for agency sync screens because backend work can be slow.

### Admin Tools

- Show cache age and refresh controls.
- After any admin mutation, invalidate the exact list/detail affected and the admin dashboard summary.
- Do not assume cached admin counts are realtime.

## QA Checklist

- In a GHL iframe, switching between two subaccounts never shows the previous subaccount's contacts, credits, sender IDs, or conversations.
- External domain login reuses cached profile but refreshes it on app start.
- Dashboard renders cached data immediately and updates without full-page flicker.
- Credits update after send/top-up/gift without resetting to zero on failure.
- Conversations refresh after sending and receiving messages.
- Contacts show last-good GHL data when backend marks it stale.
- Agency subaccount list refreshes after install/provision/toggle/rate-limit changes.
- Admin user/account lists show cache age and support manual refresh.
- Duplicate requests are de-duplicated during route switches.
- Hidden tabs/iframes stop polling and refetch when visible again.

