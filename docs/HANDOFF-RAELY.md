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

## ✅ The Final Architecture (The Fix)
We reverted the database to store the **true, raw statuses** ("Pending" / "Queued") directly from Semaphore. This keeps the backend synchronizer perfectly functional.

To stop the frontend from flickering, we added a **presentation layer mapping**:
We intercept the "Pending" and "Queued" statuses and automatically map them to **"Sent"** before they reach the React components.

### Where exactly is this mapping?

**1. The Backend APIs (Instantly active)**
To guarantee this works without forcing everyone to hard-refresh their browsers, I added the mapping directly into two APIs that feeds the UI arrays:
- `api/messages.php` (for the Message Composer / Timeline)
- `api/webhook/fetch_bulk_messages.php` (for the Sidebar)

Both APIs now run a `$mapStatus()` function that converts `"Pending"` or `"Queued"` strings to `"Sent"` inside their JSON responses.

**2. The React Hooks (Failsafe)**
I also added the mapping as a failsafe into your React hooks:
- `frontend/src/hooks/useMessages.ts`
- `frontend/src/hooks/useConversationMessages.ts`

```typescript
// Look for this in your hooks:
let mappedStatus = (log.status || 'sent').toLowerCase();

// Optimistically show 'sent' (green) for messages that have reached Semaphore (pending delivery)
// This stops the UI from flickering back to gray 'pending' while the backend syncs
if (mappedStatus === 'pending' || mappedStatus === 'queued') {
    mappedStatus = 'sent';
}
```

## 📝 Rules Moving Forward
1. **Never** change the PHP files to save `"Sent"` into Firestore in place of "Pending". Keep saving exactly what Semaphore gives us so `StatusSync.php` can run properly. 
2. If you add any new React Views or API endpoints that fetch statuses from Firestore, **you must ensure the UI treats "Pending" from the backend as "Sent" visually**. 
3. The UI should only show `sending` (optimistic loading spinner), `sent` (green check), `delivered` (double green check), and `failed` (red cross). There should be no UI elements rendering the literal string "Pending".

**4. 🚨 Warning for Bulk Campaigns**
Raely, please ensure that **any new hooks or components you write for Bulk Messaging** also apply this identical logic! If you render a list of sent Bulk Messages or loop through bulk campaign recipients, and they say `"Pending"` or `"Queued"`, your React component must specifically map them to `"sent"` for the UI so the entire campaign looks Green instantly.
