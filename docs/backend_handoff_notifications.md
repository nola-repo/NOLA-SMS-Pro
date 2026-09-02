# Backend Handoff: Notification Settings

## Overview
The frontend currently provides a UI with toggle switches and sliders for users to manage their notification preferences. Right now, these are only saved to the user's local browser storage. We need a backend integration to persistently store these preferences per GHL sub-account and to implement the actual backend notification dispatch logic (e.g., sending emails).

---

## 1. Notification Preferences API

We need a pair of RESTful endpoints to manage a sub-account's notification settings.

**Endpoint Route**: `/api/notification-settings.php` (or routed via `/api/notification-settings`)

### Requirements:
- **Location Scoping**: All operations must be strictly scoped using the `X-GHL-Location-ID` header to identify the calling sub-account.
- **Database Architecture**: Suggest creating a new table `system_notification_settings` (or integrating into an existing `account_settings` table):
  - `location_id` (VARCHAR, Unique Index)
  - `delivery_reports_enabled` (BOOLEAN)
  - `low_balance_alert_enabled` (BOOLEAN)
  - `low_balance_threshold` (INT)
  - `marketing_emails_enabled` (BOOLEAN)
  - `updated_at` (TIMESTAMP)

### Supported Operations:

#### **GET `/api/notification-settings`**
- Retrieves the current preferences for the `location_id`.
- **Response Format Expected by Frontend**:
  ```json
  {
    "deliveryReports": true,
    "lowBalanceAlert": true,
    "lowBalanceThreshold": 50,
    "marketingEmails": false
  }
  ```

#### **POST / PUT `/api/notification-settings`**
- Upserts the notification preferences for the specified `location_id`.
- **Request Body format from Frontend**:
  ```json
  {
    "deliveryReports": true,
    "lowBalanceAlert": true,
    "lowBalanceThreshold": 100,
    "marketingEmails": false
  }
  ```

---

## 2. Notification Dispatch Implementation

Once the settings are persistently stored, the backend must implement the logical triggers to actually dispatch these alerts.

### A. SMS Delivery Reports (`deliveryReports`)
- **Trigger**: When a message status webhook (e.g. from Semaphore) updates a message terminal status to `Failed` or `Delivered`.
- **Action**: Use the stored preferences to check if `delivery_reports_enabled` is true for that sub-account.
- If true, dispatch an email (via SendGrid/Mailgun/etc.) or a system alert detailing the delivery status.

### B. Low Balance Alerts (`lowBalanceAlert` & `lowBalanceThreshold`)
- **Trigger**: Evaluated whenever credits are deducted from the user's wallet table.
- **Action**: Check if the resulting wallet balance is less than or equal to the `low_balance_threshold`, AND if `low_balance_alert_enabled` is true.
- **Circuit Breaker Requirement**: Add a mechanism (e.g., a `last_notified_low_balance_at` timestamp in the database) to prevent spamming the user on every single message deduction once they drop below the threshold. We recommend emitting this notification at most once per 24 or 48 hours for as long as they remain under the threshold.
- Dispatch an email advising the user to top-up their remaining balance.

### C. Marketing & Updates (`marketingEmails`)
- **Trigger**: When the preference is updated via the API.
- **Action**: Subscribe/Unsubscribe the associated user email address from the backend mailing list system (e.g., ActiveCampaign, Mailchimp, or an internal flag) for bulk product news and feature announcements.
