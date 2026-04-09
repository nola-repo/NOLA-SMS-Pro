# Frontend Handoff: SMS Status Architecture

**To: Raely (Frontend Developer)**  
**Topic:** The "Pending" vs "Sent" UI Flickering Fix

---

## ⚠️ The Original Problem
When a user sent an SMS, the optimistic UI would show a green **"Sent"**. 
However, when the UI polled the backend for real message history (via `/api/messages.php` and `/api/bulk-campaigns.php`), the backend returned **"Pending"** or **"Queued"** because that is the literal status returned by the Semaphore SMS API when a message is first fired off.

This caused the UI to "downgrade" from Green ("Sent") to Gray ("Pending"), causing an annoying visual flickering effect, until it eventually reached "Delivered" 5 minutes later.

## ❌ What We Tried (And Why It Failed)
Initially, we tried fixing this by forcing the PHP backend (`send_sms.php`) to save the message as literally `"Sent"` in the Firestore Database.

This stopped the UI flickering, **but it completely broke the background Cloud Scheduler synchronizer (`StatusSync.php`)**. The synchronizer was specifically querying Firestore for messages that were `"Pending"` or `"Queued"`. Because the messages were saved as `"Sent"`, they became invisible to the synchronizer and were never updated to "Delivered".

## ✅ The Modernized Architecture (April 9 Update)
In this latest update, we have completely **unified** the terminology. The UI, DB, and Backend are now perfectly in sync.

### The New Status Flow
1. **`Sending`**: The message is in process (replaces all old terms like `Pending` or `Queued`).
2. **`Sent`**: The message is confirmed delivered.
3. **`Failed`**: The message could not be sent or delivered.

### Changes Needed on Your Side:
1. **Remove Old Mapping Logic**: You no longer need to manually map `pending` or `queued` to `sent` in your hooks (`useConversationMessages.ts`). The backend now sends a clean `Sending` status that stays stable.
2. **Simplify CSS/UI**: You only need 3 UI states:
    *   `sending` -> (Gray/Loading/Spinner)
    *   `sent` -> (Green/Checkmark)
    *   `failed` -> (Red/Cross)
3. **Trust the Source**: When you fetch from `/api/messages.php`, you can display the status directly. No more "Flickering Fix" hacks!

### Why this is better:
We updated the background synchronizer to be **API-Key Aware**. This means messages actually resolve to "Sent" much faster and more reliably than before. You don't have to "pretend" a message is sent anymore—you can wait for the real "Sent" status from the DB.

## 🌟 Agency App Installation Updates (April 9)
We have updated the GHL Client IDs and Client Secrets across the backend to the newly provided credentials.

### Changes Needed on Your Side:
1. **Update Subaccount Install Link**: The GHL App Client ID has been updated. In your frontend repository (`agency/src/pages/Subaccounts.tsx` around line 563), please update the hardcoded installation URL to use the new Client ID `69d31f33b3071b25dbcc5656-mnqxvtt3` and the new version ID `69d31f33b3071b25dbcc5656`. If there are any other `.env` variables or files where the app Client ID is specified, please update them as well!
2. **Billing Status Updates**: The `GET /api/credits.php?action=status` endpoint has been upgraded. It now returns a `stats` object containing `sent_today`, `credits_used_today`, and `credits_used_month` directly from the database calculation. You no longer need to calculate this dynamically on the client-side!
3. **Free Trial Handling**: Trial sms charges are now logged as `type=deduction` but with `amount=0`. Your UI logic should naturally be able to check for this `amount=0` condition to print things like "-1 free trial".
