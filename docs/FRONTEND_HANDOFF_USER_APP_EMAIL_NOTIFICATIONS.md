# Frontend Handoff: User App Email Notifications

## Summary

Backend email workflow events are now mirrored into the user app in-app notification feed. The bell drawer should fetch `/api/notifications` and render records from `admin_notifications` for the authenticated user's active GHL location.

This aligns the visible user app notification drawer with email/workflow events for:

- Registration/welcome email
- Support ticket submission
- Sender ID approval/rejection email
- Top-up success email
- Existing low/zero balance alerts

## Backend Status

Deployed backend changes write user-visible notification records with a top-level `email` field. The frontend does not need to infer the email from profile state.

New/confirmed notification sources:

| Event | Notification `type` | Email location |
|---|---|---|
| Registration complete / welcome email | `location_registration` | `email`, `metadata.registered_email` |
| Support ticket submitted | `support_ticket` | `email` |
| Sender ID requested | `sender_request` | `email` |
| Sender ID approved | `sender_id_approved` | `email` |
| Sender ID rejected | `sender_id_rejected` | `email` |
| Top-up success | `top_up_success` | `email` |
| Low balance | `low_balance` | `email` |
| Zero balance | `zero_balance` | `email` |

## Fetch Requirements

Use the existing user-app endpoint:

```http
GET /api/notifications?location_id={activeLocationId}&limit=20
Authorization: Bearer {userJwt}
```

The backend also accepts `locationId`, and can fall back to the token/profile location, but the frontend should send the active location explicitly when available.

Expected response:

```json
{
  "status": "success",
  "data": [
    {
      "id": "firestoreDocId",
      "type": "location_registration",
      "location_id": "GHL_LOCATION_ID",
      "location_name": "Account Name",
      "email": "owner@example.com",
      "balance": null,
      "threshold": null,
      "created_at": "2026-07-08T10:30:00Z",
      "read": false,
      "metadata": {
        "registered_email": "owner@example.com",
        "full_name": "Owner Name",
        "phone": "+15555555555",
        "role": "user"
      }
    }
  ]
}
```

## Render Requirements

In the user app notification drawer:

1. Fetch notifications when the bell opens and after key actions that create notifications.
2. Show newest first using `created_at`.
3. Display the email for these records, preferring:
   - `notification.email`
   - `notification.metadata.registered_email`
4. Do not hide records only because the email is empty; show a fallback like `Account notification`.
5. Keep the current empty state only when `data.length === 0`.
6. Badge count should use unread records where `read === false`.

Suggested copy mapping:

| Type | Title | Body |
|---|---|---|
| `location_registration` | Registration email sent | `{email} completed registration.` |
| `support_ticket` | Support ticket submitted | `{email} submitted "{metadata.subject}".` |
| `sender_request` | Sender ID request submitted | `{email} requested {metadata.sender_id}.` |
| `sender_id_approved` | Sender ID approved | `{metadata.sender_id} was approved for {email}.` |
| `sender_id_rejected` | Sender ID rejected | `{metadata.sender_id} was rejected for {email}.` |
| `top_up_success` | Credits added | `{metadata.amount} credits added for {email}.` |
| `low_balance` | Low balance alert | `{email} is below the credit threshold.` |
| `zero_balance` | Zero balance alert | `{email} has 0 credits remaining.` |

## Mark Read

Single notification:

```http
POST /api/notifications
Authorization: Bearer {userJwt}
Content-Type: application/json

{
  "action": "mark_read",
  "location_id": "{activeLocationId}",
  "notification_id": "{notificationId}"
}
```

All notifications:

```json
{
  "action": "mark_all_read",
  "location_id": "{activeLocationId}"
}
```

## Support Ticket Flow

When the user submits a support ticket:

```http
POST /api/tickets
Authorization: Bearer {userJwt}
X-GHL-Location-ID: {activeLocationId}
Content-Type: application/json
```

Body:

```json
{
  "subject": "Cannot send SMS",
  "message": "Details from the user",
  "priority": "normal"
}
```

Backend behavior:

- Creates `support_tickets/{ticketId}`
- Creates an in-app `admin_notifications` record with `type=support_ticket`
- Sets top-level `email` to the submitting user's email
- Sends the central GHL support ticket email workflow

Frontend behavior:

- After successful ticket creation, refetch `/api/notifications`
- The bell drawer should show the `support_ticket` item with the user's email and subject

## Registration Flow

When registration from install completes, backend calls the welcome notifier and creates:

- `type=location_registration`
- `email={registered email}`
- `metadata.registered_email={registered email}`
- `metadata.full_name`
- `metadata.phone`
- `metadata.role`

Frontend behavior:

- After first login/auto-login following registration, fetch `/api/notifications`
- Show `location_registration` in the bell drawer for the active location

## QA Checklist

Use a disposable test location.

1. Complete registration with a known email.
2. Log into the user app for that same location.
3. Open the bell drawer.
4. Confirm a `Registration email sent` notification appears and includes the registered email.
5. Submit a support ticket from the user app.
6. Reopen or refresh the bell drawer.
7. Confirm a `Support ticket submitted` notification appears and includes the submitter email and subject.
8. Mark one notification read; confirm unread badge decreases.
9. Mark all read; confirm unread badge clears.
10. Switch to another location; confirm notifications are scoped to that location only.

## Notes

Existing old events may not appear unless they already have `admin_notifications` records or are backfilled. The backend change applies automatically to new events after deployment.
