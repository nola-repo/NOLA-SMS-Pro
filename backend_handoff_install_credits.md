# Backend Handoff: White-Label Branding & Credits

This document covers the synchronization between the frontend and backend for handling credits and branded (White-Label) Sender IDs.

---

## 1. Automatic White-Label Branding

The system now prioritizes an approved custom Sender ID over the system default `NOLASMSPro`.

### Account Configuration Fetch
The frontend calls `GET /api/account.php?location_id={locId}` on mount. 
**Required Response Fields:**
- `approved_sender_id`: The custom ID to use as default.
- `credit_balance`: For displaying remaining credits.

### Frontend Logic (Managed by Raely)
- If `approved_sender_id` is present, the UI will:
    - Automatically select it as the default sender.
    - **Hide** the "Nola SMS Pro" option from the dropdown to create a white-labeled experience.

---

## 2. Multi-Tenant Status Retrieval

Message statuses are updated via a backend worker triggered by a Cron job.

- **Script**: `api/webhook/retrieve_status.php`
- **Logic**:
    1. Fetches the `location_id` for every message in `sms_logs` with a status of 'Queued' or 'Pending'.
    2. Retrieves the specific `nola_pro_api_key` or `semaphore_api_key` for that location.
    3. Calls Semaphore with the correct key (crucial for messages sent using custom Sender IDs).
    4. Falls back to the system `SEMAPHORE_API_KEY` if no custom key is configured.
    5. Updates statuses in both `sms_logs` and `messages` collections.

---

## 3. Auto-Provision 10 Free Credits on GHL Installation

When a new location installs NOLA SMS Pro, they receive 10 free credits automatically.

### OAuth Callback (`ghl_callback.php`)
1. Determine sanitized `docId` as `ghl_{locationId}`.
2. If the `integrations/{docId}` document does not exist:
    - Set `free_credits_total: 10`
    - Set `free_usage_count: 0`
    - Set `location_id`, `location_name`, and `installed_at`.

---

## 4. Free Credit Tracking (Shared Sender)

In `send_sms.php`, when using the shared `NOLASMSPro` sender:
1. Validate that `free_usage_count < free_credits_total`.
2. Block send if limit is reached.
3. Increment `free_usage_count` upon successful send.

> [!NOTE]
> This tracking is bypassed if an `approved_sender_id` is used, as those sends rely on the account's standard `credit_balance`.

---

## Testing Checklist

- [ ] Verify `api/account.php` returns `approved_sender_id`.
- [ ] Verify UI hides "Nola SMS Pro" if an approved ID exists.
- [ ] Run `php api/webhook/retrieve_status.php` and verify status updates for custom sender messages.
- [ ] Verify 10 free credits are provisioned on first installation.
