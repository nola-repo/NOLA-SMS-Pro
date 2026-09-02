# Backend Handoff: Sender ID Selection Not Respected

## The Bug
When a user has an approved custom Sender ID (e.g. `NOLACRM`) with a Semaphore API key stored in their `integrations` document, the backend **always forces** the approved sender ID — even when the user explicitly selects `NOLASMSPro` (system default) from the "From:" dropdown.

The frontend correctly passes the selected sender in the payload as `sendername`, but `send_sms.php` ignores it.

## Root Cause

In `api/webhook/send_sms.php`, around line 155:

```php
// ❌ CURRENT (broken): Ignores the user's dropdown selection
if ($approvedSenderId && $customApiKey) {
    $sender = $approvedSenderId;   // always uses NOLACRM
    $activeApiKey = $customApiKey;
}
```

## The Fix

Extract the user's requested `sendername` from the payload and only use the approved sender key if the user **actually chose the approved sender**.

**Replace the Sender ID Logic block with:**

```php
// Extract the sendername the frontend/user selected
$requestedSender = $customData['sendername'] ?? $payload['sendername'] ?? $data['sendername'] ?? null;

// Determine which sender to use:
if ($approvedSenderId && $customApiKey && $requestedSender === $approvedSenderId) {
    // ✅ User explicitly selected their approved custom sender → use custom key
    $sender = $approvedSenderId;
    $activeApiKey = $customApiKey;
} elseif ($approvedSenderId && $customApiKey && empty($requestedSender)) {
    // ✅ No sender specified (e.g. webhook call) → default to approved sender
    $sender = $approvedSenderId;
    $activeApiKey = $customApiKey;
} else {
    // ✅ User chose a system default sender (NOLASMSPro), or no approved sender exists
    // Check free usage limit before allowing
    if ($freeUsageCount + $num_recipients > $freeCreditsTotal) {
        http_response_code(403);
        echo json_encode([
            "status"  => "error",
            "message" => "Free message limit reached ($freeUsageCount/$freeCreditsTotal). Registration of a custom Sender ID and API Key is required.",
            "error"   => "free_credits_exhausted"
        ]);
        exit;
    }
    // Use system sender + system API key
    $sender = $requestedSender ?? ($SENDER_IDS[0] ?? "");
    if (!in_array($sender, $SENDER_IDS)) {
        $sender = $SENDER_IDS[0];
    }
    $activeApiKey = $SEMAPHORE_API_KEY;
}
```

## Important Note on Free Credit Tracking

The `free_usage_count` increment block (around line 191) already correctly gates on `!($approvedSenderId && $customApiKey)` — but after this fix it should gate on whether the **system API key was used**, not just whether the approved sender exists:

```php
// ✅ Only increment free usage when system key was used
if ($activeApiKey === $SEMAPHORE_API_KEY) {
    $intRef->set([
        'free_usage_count' => $freeUsageCount + $num_recipients,
        'updated_at'       => new \Google\Cloud\Core\Timestamp(new \DateTime()),
    ], ['merge' => true]);
}
```

## Why This Works
- When user picks `NOLACRM` → `$requestedSender === $approvedSenderId` → uses custom API key ✅
- When user picks `NOLASMSPro` → falls to else branch → uses system key, increments free counter ✅  
- When GHL webhook fires without a sendername → defaults to approved sender if available ✅
