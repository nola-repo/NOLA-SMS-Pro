# SMS Pro Backend Implementation & Handoff Guide

This guide documents critical backend requirements for the synchronization of the frontend with the Cloud Run API.

## 1. Messaging: Store `batch_id` in Firestore

When sending bulk messages, the frontend generates a unique `batch_id`. The backend `send_sms.php` must capture and store this.

- **Required**: Capture `batch_id` from POST/JSON body.
- **Required**: Save `batch_id` as a top-level field in both `messages` and `sms_logs` collections.

---

## 2. Messaging: Filter by `batch_id` and `conversation_id`

The API must support filtering messages to enable the group chat and direct chat views.

- **Endpoint**: `GET /api/messages`
- **Supported Params**: `?batch_id={id}`, `?conversation_id={id}`, `?recipient_key={key}`.
- **Logic**: If `batch_id` is present, return only messages matching that batch.

---

## 3. GHL Authentication & Token Refresh

The GHL API returns **401 Invalid JWT** when the `access_token` expires (typically every 24 hours).

- **Issue**: The current backend implementation does not automatically refresh tokens.
- **Requirement**: Backend must implement a refresh loop:
    1. If a 401 error is received from GHL, retrieve the `refresh_token` from the `integrations` collection.
    2. POST to `https://services.leadconnectorhq.com/oauth/token` using your client secret and relevant params.
    3. Update the `integrations` document in Firestore with the new `access_token` and `refresh_token`.
    4. Retry the original request.

---

## 5. Multi-Tenancy: `location_id` Scoping

All data endpoints must filter results by the `X-GHL-Location-ID` header (or `location_id` query param) to ensure data isolation between subaccounts.

- **Endpoints**: `/api/conversations`, `/api/contacts`, `/api/messages`.
- **Logic**: Add `.where('location_id', '==', $locId)` to all Firestore queries.
- **Creation**: Ensure newly created records (contacts, conversations) store the `location_id`.

---

## 6. Required Firestore Composite Indices

To prevent "Missing index" errors in production, the following composite indices must be created in the Firebase/GCP Console:

| Collection | Field 1 | Field 2 | Sort Order |
| :--- | :--- | :--- | :--- |
| `messages` | `location_id` | `created_at` | Descending |
| `messages` | `batch_id` | `date_created` | Descending |
| `messages` | `conversation_id` | `created_at` | Descending |
| `conversations` | `location_id` | `last_message_at` | Descending |
| `contacts` | `location_id` | `created_at` | Descending |

---

## 7. Implementation Checklist

- [x] Update `auth_helpers.php` with `get_ghl_location_id()`.
- [x] Implement `location_id` scoping in `conversations.php`, `contacts.php`, and `messages.php`.
- [x] Implement `location_id` scoping in `credits.php` and `get_credit_transactions.php`.
- [x] Implement GHL `access_token` refresh logic in `ghl_contacts.php`.
- [ ] Create missing composite indexes in Firestore Console (Manual Step).
- [x] Verify `X-GHL-Location-ID` header awareness.
