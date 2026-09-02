# Backend Handoff: Notification SMS False Success + Empty Compose

Target file:

`C:\Users\User\nola-sms-pro-backend\api\webhook\send_sms.php`

## Problem

Notification SMS can currently show as successful, create a Sidebar conversation, but not appear in Compose and not be received.

Root causes:

1. `NOLASMSPro` notification sends can still use the subaccount custom Semaphore API key. If that key does not own the `NOLASMSPro` sender, the gateway may not deliver even though the app logs the attempt.
2. The webhook treats any 2xx Semaphore HTTP response as success, even when the decoded response contains no `message_id`.
3. The Sidebar `conversations` document is created whenever `$all_results` is non-empty, even if none of those results are actual sent message rows. Compose reads from `messages`, so it stays empty when no message row was saved.

## Required Fixes

### 1. Route `NOLASMSPro` notifications through the master gateway

In the external API key branch, replace this block:

```php
if ($isSystemNotification) {
    $sender = 'NOLASMSPro';
    error_log("[send_sms] Result: System notification override on external API path. Forcing sender to '{$sender}'.");
} elseif (strcasecmp((string)$requestedSender, 'NOLASMSPro') === 0) {
    $sender = 'NOLASMSPro';
    error_log("[send_sms] Result: Explicit NOLASMSPro sender request on external API path. Using '{$sender}' instead of approved_sender_id.");
}
```

with:

```php
if ($isSystemNotification) {
    $usingCustomSender = false;
    $activeApiKey = $SEMAPHORE_API_KEY;
    $sender = 'NOLASMSPro';
    error_log("[send_sms] Result: System notification override on external API path. Routing via master gateway with sender '{$sender}'.");
} elseif (strcasecmp((string)$requestedSender, 'NOLASMSPro') === 0) {
    $usingCustomSender = false;
    $activeApiKey = $SEMAPHORE_API_KEY;
    $sender = 'NOLASMSPro';
    error_log("[send_sms] Result: Explicit NOLASMSPro sender request on external API path. Routing via master gateway instead of approved_sender_id.");
}
```

### 2. Track gateway errors and only collect real message rows

Near the Semaphore send loop, change:

```php
$all_results = [];
$total_status = 200;
```

to:

```php
$all_results = [];
$gateway_errors = [];
$total_status = 200;
```

Inside the loop, replace the response parsing section:

```php
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
// Treat any 2xx response as success. Some gateway/API versions return 201/202.
if ($status < 200 || $status >= 300) {
    $total_status = $status;
}
$result = json_decode($response, true);
log_sms("SEMAPHORE_RESPONSE_CHUNK", $result);

if (is_array($result)) {
    $all_results = array_merge($all_results, $result);
}
```

with:

```php
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($response === false) {
    $gateway_errors[] = 'Semaphore cURL error: ' . curl_error($ch);
    $status = 0;
}

// Treat any 2xx response as success only after response body validation below.
if ($status < 200 || $status >= 300) {
    $total_status = $status;
}

$result = $response !== false ? json_decode($response, true) : null;
log_sms("SEMAPHORE_RESPONSE_CHUNK", $result);

if (is_array($result)) {
    $isList = array_keys($result) === range(0, count($result) - 1);

    if ($isList) {
        foreach ($result as $row) {
            if (is_array($row) && !empty($row['message_id'])) {
                $all_results[] = $row;
            } elseif (is_array($row)) {
                $gateway_errors[] = $row['message'] ?? $row['error'] ?? 'Semaphore row missing message_id';
            }
        }
    } elseif (!empty($result['message_id'])) {
        $all_results[] = $result;
    } else {
        $gateway_errors[] = $result['message'] ?? $result['error'] ?? 'Semaphore response missing message_id';
        if ($status >= 200 && $status < 300) {
            $total_status = 502;
        }
    }
} else {
    $gateway_errors[] = 'Semaphore response was not valid JSON';
    if ($status >= 200 && $status < 300) {
        $total_status = 502;
    }
}

curl_close($ch);
```

### 3. Only save messages/conversations when message IDs exist

Before the Firestore save block, add:

```php
$saved_message_ids = [];
$message_results = array_values(array_filter($all_results, function ($msg) {
    return is_array($msg) && !empty($msg['message_id']);
}));
```

Then change:

```php
if (!empty($all_results)) {
```

to:

```php
if (!empty($message_results)) {
```

Inside that block, remove the old local `$saved_message_ids = [];` line, and change:

```php
foreach ($all_results as $msg) {
    if (!isset($msg['message_id']))
        continue;
```

to:

```php
foreach ($message_results as $msg) {
```

This prevents a Sidebar conversation from being created unless a matching `messages/{messageId}` document is also saved for Compose.

### 4. Return failure when no gateway message was accepted

Replace:

```php
$gatewayAccepted = ($total_status >= 200 && $total_status < 300);
$ghlStatus = $gatewayAccepted ? "success" : "error";
```

with:

```php
$failedResultCount = 0;
foreach (($message_results ?? []) as $msg) {
    $rawStatus = strtolower((string)($msg['status'] ?? ''));
    if (in_array($rawStatus, ['failed', 'expired', 'rejected', 'undelivered'], true)) {
        $failedResultCount++;
    }
}

$hasSavedMessages = !empty($saved_message_ids);
$allSavedMessagesFailed = $hasSavedMessages && $failedResultCount >= count($saved_message_ids);
$gatewayAccepted = (
    $total_status >= 200 &&
    $total_status < 300 &&
    $hasSavedMessages &&
    empty($gateway_errors) &&
    !$allSavedMessagesFailed
);

if (!$gatewayAccepted) {
    http_response_code($total_status >= 400 ? $total_status : 502);
}

$ghlStatus = $gatewayAccepted ? "success" : "error";
```

Then update the response body so `"event_details" => ["Status" => ...]` uses the real status:

```php
"Status" => $gatewayAccepted ? "Success" : "Failed",
```

and add this to `debug_info`:

```php
"gateway_errors" => $gateway_errors,
```

## Expected Result

After this backend change:

- A notification SMS only returns success if Semaphore returns at least one real `message_id`.
- Sidebar only shows notification conversations that have matching message documents.
- Compose can load the notification message because `messages.conversation_id` and `conversations.id` are created together.
- `NOLASMSPro` notification sends use the master gateway, preventing the custom subaccount sender from silently breaking delivery.

## Frontend Already Done

Frontend safeguards were applied:

- `user/src/api/sms.ts` now treats a response with no gateway `message_id` as failure.
- `user/src/components/Composer.tsx` now falls back to the outbound log if Sidebar has a conversation but the main message query is empty.

