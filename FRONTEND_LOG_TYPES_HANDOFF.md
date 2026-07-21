# Frontend Handoff: Distinct Log Types for Logs Explorer

**Target Repository**: `nola-repo/nola-sms-pro-frontend`  
**Deploy Trigger**: `nola-sms-pro-user-deploy` (`app.nolasmspro.com`)

---

## Context & Goal

Previously, all outbound SMS log entries in the **Logs Explorer** displayed `SMS` under the **`TYPE`** column regardless of their trigger origin.

To improve monitoring and traceability, the system now distinguishes messages by trigger source:
- **`Send PH SMS`**: Triggered via GoHighLevel Workflow Custom Actions (`/api/webhook/send_sms.php`).
- **`Conversation Provider`**: Sent via GoHighLevel Conversation View native chat (`/api/webhook/ghl_provider.php`).
- **`Inbound SMS`**: Incoming replies from contacts (`/api/webhook/receive_sms.php`).

---

## Backend API Contract Update

Backend endpoints (`/api/webhook/fetch_logs.php` and `/api/messages.php` / `/api/v2/messages`) now return a resolved `type` field on each log item in the response JSON array:

```json
{
  "id": "msg_123456",
  "direction": "outbound",
  "type": "Send PH SMS",
  "source": "send_sms",
  "message": "SMS sent to 09938905125 | Hi, This is Send PH SMS workflow action...",
  "status": "Sent",
  "date_created": "2026-07-21T14:38:01+08:00"
}
```

---

## Required Frontend Implementation

In the frontend repository (`nola-repo/nola-sms-pro-frontend`), locate the component rendering the **Logs Explorer** table (e.g. `LogsExplorer.tsx`, `Settings.tsx`, or `Dashboard.tsx`).

### 1. Log Type Helper Utility

Add a helper function to ensure robust display for both fresh API payloads and cached/historical items:

```ts
export function getDisplayLogType(log: {
  type?: string;
  source?: string;
  summary?: string;
  message?: string;
  direction?: string;
}): string {
  // 1. If backend explicitly provided a non-generic type, use it
  if (log.type && log.type !== 'SMS') {
    return log.type;
  }

  // 2. Fallback resolution for historical / unmapped items
  const source = log.source || '';
  const content = log.summary || log.message || '';

  if (source === 'send_sms' || content.includes('Send PH SMS')) {
    return 'Send PH SMS';
  }
  if (source === 'ghl_provider') {
    return 'Conversation Provider';
  }
  if (source === 'inbound' || log.direction === 'inbound') {
    return 'Inbound SMS';
  }

  return log.type || 'SMS';
}
```

### 2. Table Column Cell Rendering

Update the `TYPE` table column cell in the table body:

```tsx
<td className="px-4 py-3 font-semibold text-[#37352f] dark:text-[#ececf1]">
  {getDisplayLogType(log)}
</td>
```

### 3. Log Type Filter Options (If Applicable)

If the Logs Explorer UI features a dropdown filter for **TYPE**, update the selectable filter options to include:

- `All Types`
- `Send PH SMS`
- `Conversation Provider`
- `Inbound SMS`
- `SMS`

---

## QA Checklist

- [ ] Trigger a HighLevel Workflow containing the **Send PH SMS** action $\rightarrow$ Verify **Logs Explorer** displays **`Send PH SMS`** under `TYPE`.
- [ ] Send a message from GHL's interactive chat box (**NOLA SMS PRO - Default Provider**) $\rightarrow$ Verify **Logs Explorer** displays **`Conversation Provider`** under `TYPE`.
- [ ] Reply to an SMS from a mobile phone $\rightarrow$ Verify **Logs Explorer** displays **`Inbound SMS`** under `TYPE`.
- [ ] Check historical logs $\rightarrow$ Confirm old log entries render with proper type labels without missing data.
