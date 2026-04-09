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
