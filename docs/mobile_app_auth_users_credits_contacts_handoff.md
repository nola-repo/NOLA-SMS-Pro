# Mobile App Implementation Handoff

Goal: update the Flutter mobile app so it uses the same user/session, workspace, credits, and contacts behavior as the web app.

## What Mobile Should Implement

### 1. Replace Firebase-Only Login With NOLA Backend Session

Current mobile app logs in with Firebase Auth. The web app logs in through the NOLA backend and stores a NOLA JWT. Mobile should do the same.

Login endpoint:

```http
POST /api/auth/login.php
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password"
}
```

Expected response:

```json
{
  "token": "jwt_here",
  "role": "user",
  "company_id": "GHL_COMPANY_ID",
  "location_id": "GHL_LOCATION_ID",
  "user": {
    "name": "Jane Doe",
    "firstName": "Jane",
    "lastName": "Doe",
    "email": "user@example.com",
    "phone": "09171234567",
    "location_id": "GHL_LOCATION_ID",
    "company_id": "GHL_COMPANY_ID",
    "location_name": "Workspace Name",
    "company_name": "Company Name",
    "role": "user"
  }
}
```

Store these after login:

```text
secure storage:
- nola_auth_token

shared preferences:
- nola_auth_role
- nola_company_id
- nola_location_id
- nola_auth_user
- active_location_id_<user/session>
- active_location_name_<user/session>
```

Implementation notes:
- The JWT is the API session.
- Firebase Auth can remain temporarily only as a legacy fallback, but app API calls should use the NOLA JWT.
- Do not store or ship `X-Webhook-Secret` in the mobile app.

### 2. Add A Mobile Session Service

Create a central service, for example:

```text
lib/services/nola_session_service.dart
```

Responsibilities:
- `login(email, password)`
- `logout()`
- `loadSession()`
- `refreshMe()`
- `getAuthHeaders()`
- `activeLocationId`
- `currentUser`

Every API call should use:

```http
Authorization: Bearer <nola_auth_token>
Content-Type: application/json
```

When the request is workspace/location-scoped, also send:

```http
X-GHL-Location-ID: <active_location_id>
```

### 3. Sync Session On App Start And Resume

On splash/app start:

1. Read `nola_auth_token`.
2. If no token, route to login.
3. If token exists, call:

```http
GET /api/auth/me
Authorization: Bearer <token>
```

Use the returned `user` object to refresh local cached user data.

If `/api/auth/me` returns `401`, clear session and route to login.

### 4. Fetch User And Workspace Details Dynamically

After login and after `/api/auth/me`, fetch account/workspace details:

```http
GET /api/account?location_id=<location_id>
Authorization: Bearer <token>
X-GHL-Location-ID: <location_id>
```

Use response fields for:
- profile name
- email
- phone
- workspace/location name
- sender ID
- credits
- free trial counters

Expected shape:

```json
{
  "status": "success",
  "data": {
    "location_id": "GHL_LOCATION_ID",
    "location_name": "Workspace Name",
    "name": "Jane Doe",
    "email": "user@example.com",
    "phone": "09171234567",
    "approved_sender_id": "NOLACRM",
    "free_usage_count": 2,
    "free_credits_total": 10,
    "credit_balance": 500,
    "currency": "PHP"
  }
}
```

Mobile screens to update:
- `lib/screens/profile_screen.dart`
- `lib/screens/settings_screen.dart`
- `lib/widgets/header.dart`
- `lib/screens/main_screen.dart`

### 5. Fetch And Display Credits

Add a credits fetch method:

```http
GET /api/credits?location_id=<location_id>
Authorization: Bearer <token>
X-GHL-Location-ID: <location_id>
```

Expected response:

```json
{
  "success": true,
  "account_id": "GHL_LOCATION_ID",
  "credit_balance": 500,
  "free_usage_count": 2,
  "free_credits_total": 10,
  "currency": "PHP",
  "stats": {
    "sent_today": 3,
    "credits_used_today": 3,
    "credits_used_month": 24
  }
}
```

Display logic, same as web:

```text
if free_credits_total > free_usage_count:
  show trial credits left = free_credits_total - free_usage_count
else:
  show paid credit_balance
```

Refresh credits:
- after login
- after app resume
- after successful SMS send
- after top-up return
- on pull-to-refresh/manual refresh

Mobile UI task:
- Re-enable or replace the credits badge in `lib/widgets/header.dart`.
- Pass credits into headers/screens from the central session/account state.

### 6. Contacts Should Use Active Workspace

Current mobile contacts are split between local cache, Firestore, and mobile backend calls. Keep local contacts if needed, but GHL contacts should be fetched through the active `location_id`.

Contacts fetch should include:

```http
Authorization: Bearer <token>
X-GHL-Location-ID: <location_id>
```

Recommended behavior:
- Resolve active `location_id` from session.
- Fetch GHL contacts for that location.
- Cache contacts per user and per location.
- When switching locations, clear old visible GHL contacts and reload for the new location.

Normalize contact data to:

```json
{
  "id": "contact_id",
  "name": "Contact Name",
  "phone": "09171234567",
  "email": "contact@example.com",
  "tags": []
}
```

Phone normalization:
- Display PH numbers as `09XXXXXXXXX`.
- Convert `+639XXXXXXXXX` and `639XXXXXXXXX` to `09XXXXXXXXX`.

Mobile screens/files to update:
- `lib/screens/contact_screen.dart`
- `lib/screens/compose_message_screen.dart`
- `lib/screens/chat_screen.dart`

### 7. SMS Send Must Use Synced Session Data

When sending SMS, mobile must pass:

```json
{
  "number": "09171234567",
  "message": "Hello",
  "sendername": "NOLACRM",
  "locationId": "<active_location_id>",
  "contactId": "<contact_id>",
  "contactName": "<contact_name>",
  "ownerUid": "<nola user/session id or existing mobile uid>",
  "requestId": "<unique_request_id>",
  "conversationId": "<thread_id>"
}
```

After send succeeds:
- refresh credits
- update local conversation/message state
- show insufficient credit errors clearly if backend returns `402`

### 8. Logout

Logout should clear:

```text
nola_auth_token
nola_auth_role
nola_company_id
nola_location_id
nola_auth_user
active_location_id_*
active_location_name_*
ghl_connected_*
location_id
sender_id
cached account/credits/contact state
```

Then route to login.

## Suggested Mobile Work Plan

1. Create `NolaSessionService`.
2. Update login screen to call `/api/auth/login.php`.
3. Update splash screen to restore NOLA session and call `/api/auth/me`.
4. Add `AccountService` methods for `/api/account` and `/api/credits`.
5. Re-enable credits display in `CustomHeader`.
6. Update profile/settings to use dynamic backend user/account data.
7. Update contacts to load by active `location_id`.
8. Refresh credits after SMS send.
9. Remove any client-side use of `X-Webhook-Secret`.

## Acceptance Checklist

- Login uses NOLA backend JWT.
- Session survives app restart.
- Invalid/expired JWT returns user to login.
- Profile shows dynamic name, email, phone.
- Workspace name and active location come from backend.
- Credits badge shows trial credits or paid credits correctly.
- Credits refresh after successful send.
- Contacts load for the active workspace.
- No mobile client secret is hardcoded.
- Firebase Auth is no longer the primary API session.
