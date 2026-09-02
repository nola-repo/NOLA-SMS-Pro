# Backend Handoff — GHL "Create Conversation" Integration

**Date**: March 26, 2026  
**Priority**: High  
**Frontend Status**: Ready (pending backend deployment)

---

## Objective

Create a backend endpoint that creates a conversation on the GHL Dashboard via their API, and syncs it into the local Firestore `conversations` collection so it immediately appears in the SMS Pro sidebar.

---

## What the Frontend Expects

### Endpoint

```
POST /api/ghl-conversations
```

### Request Headers

| Header | Value | Source |
|---|---|---|
| `Content-Type` | `application/json` | Frontend sets this |
| `X-Webhook-Secret` | `f7RkQ2pL9zV3tX8cB1nS4yW6` | Vercel proxy adds this |
| `X-GHL-Location-ID` | `{locationId}` | Frontend passes from `getAccountSettings().ghlLocationId` |

### Request Body

```json
{
  "contactId": "abc123xyz",
  "contactName": "Francis Cortez"
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `contactId` | string | ✅ | GHL Contact ID (from `ghl_contacts.php` response) |
| `contactName` | string | ❌ | Display name for local conversation doc |

### Expected Success Response (200)

```json
{
  "success": true,
  "ghl_conversation_id": "conv_abc123",
  "local_conversation_id": "LOCATION_ID_conv_09171234567",
  "message": "Conversation created"
}
```

### Expected Error Responses

| Status | When | Body |
|---|---|---|
| 400 | Missing `contactId` | `{ "success": false, "error": "contactId is required" }` |
| 401 | Invalid webhook secret | `{ "status": "error", "message": "Unauthorized Access" }` |
| 404 | GHL integration not found | `{ "success": false, "error": "GHL integration not found" }` |
| 422 | GHL API rejected request | `{ "success": false, "error": "GHL API error", "ghl_status": 422, "ghl_error": "..." }` |
| 500 | Server error | `{ "success": false, "error": "...", "message": "..." }` |

---

## Implementation Guide

### Phase 1: New Service Class — `GhlClient.php`

**File**: `api/services/GhlClient.php`  
**Purpose**: Extract the reusable GHL API logic that's currently duplicated in `ghl_contacts.php`.

The following functions from `ghl_contacts.php` should be refactored into a reusable class:

| Function in `ghl_contacts.php` | New Method in `GhlClient` | Lines |
|---|---|---|
| `getGHLIntegration($db, $locationId)` | `__construct($db, $locationId)` | L20–55 |
| `refreshGHLToken($db, &$integration)` | `refreshToken()` | L60–128 |
| `executeGHLRequest(...)` | `request($method, $path, $body)` | L177–222 |
| Proactive token refresh logic | Part of `request()` | L145–163 |

```php
<?php
// api/services/GhlClient.php

class GhlClient {
    private $db;
    private $locationId;
    private $integration;
    private $apiUrl = 'https://services.leadconnectorhq.com';

    public function __construct($db, $locationId) {
        // Load integration from Firestore (same logic as getGHLIntegration)
        // Throw if not found
    }

    public function request($method, $path, $body = null, $apiVersion = '2021-07-28') {
        // 1. Proactive refresh if token expires within 5 minutes
        // 2. Build headers with Authorization, Version, Content-Type
        // 3. Execute cURL request
        // 4. If 401 on first attempt → refreshToken() → retry once
        // 5. Return ['status' => int, 'body' => string]
    }

    private function refreshToken() {
        // Same logic as refreshGHLToken() in ghl_contacts.php L60-128
    }
}
```

### Phase 1.5: Shadow Validation

Refactor **only the GET handler** in `ghl_contacts.php` (L246-278) to use `GhlClient`. Leave POST/PUT/DELETE using the old functions. Test that contacts still load correctly. This validates the client without risking writes.

### Phase 2: New Endpoint — `ghl-conversations.php`

**File**: `api/ghl-conversations.php`

```php
<?php
// api/ghl-conversations.php

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';
require __DIR__ . '/services/GhlClient.php';

validate_api_request();

$db = get_firestore();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$locId = get_ghl_location_id();

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$contactId = $input['contactId'] ?? null;
$contactName = $input['contactName'] ?? null;

if (!$contactId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'contactId is required']);
    exit;
}

if (!$locId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing location_id']);
    exit;
}

try {
    $client = new GhlClient($db, $locId);

    // 1. Create conversation on GHL
    $payload = json_encode([
        'locationId' => $locId,
        'contactId'  => $contactId,
    ]);

    // NOTE: Conversations endpoint uses API version 2021-04-15
    $resp = $client->request('POST', '/conversations/', $payload, '2021-04-15');
    $ghlData = json_decode($resp['body'], true);

    if ($resp['status'] >= 400) {
        http_response_code($resp['status']);
        echo json_encode([
            'success'    => false,
            'error'      => 'GHL API error',
            'ghl_status' => $resp['status'],
            'ghl_error'  => $ghlData['message'] ?? $resp['body'],
        ]);
        exit;
    }

    $ghlConvId = $ghlData['conversation']['id'] ?? $ghlData['id'] ?? null;

    // 2. Fetch the contact's phone number for the local conversation ID
    $contactResp = $client->request('GET', "/contacts/{$contactId}");
    $contactData = json_decode($contactResp['body'], true);
    $phone = $contactData['contact']['phone'] ?? '';

    // Normalize to 09XXXXXXXXX format for conversation ID
    $digits = preg_replace('/\D/', '', $phone);
    if (str_starts_with($digits, '63')) {
        $digits = '0' . substr($digits, 2);
    }

    // 3. Write to local Firestore conversations collection
    $now = new DateTimeImmutable();
    $localDocId = "{$locId}_conv_{$digits}";

    $db->collection('conversations')->document($localDocId)->set([
        'id'                   => $localDocId,
        'location_id'          => $locId,
        'type'                 => 'direct',
        'name'                 => $contactName ?: ($contactData['contact']['name'] ?? 'Contact'),
        'ghl_conversation_id'  => $ghlConvId,
        'ghl_contact_id'       => $contactId,
        'members'              => [$digits],
        'last_message'         => null,
        'last_message_at'      => new \Google\Cloud\Core\Timestamp($now),
        'updated_at'           => new \Google\Cloud\Core\Timestamp($now),
        'source'               => 'ghl_sync',
    ], ['merge' => true]);

    echo json_encode([
        'success'               => true,
        'ghl_conversation_id'   => $ghlConvId,
        'local_conversation_id' => $localDocId,
        'message'               => 'Conversation created',
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to create conversation',
        'message' => $e->getMessage(),
    ]);
}
```

### Phase 3: Route Registration

**File**: `.htaccess`  
Add after line 58 (the `ghl-contacts` rule):

```apache
RewriteRule ^api/ghl-conversations$ /api/ghl-conversations.php [L,QSA]
```

---

## GHL API Reference

### Create Conversation

```
POST https://services.leadconnectorhq.com/conversations/
```

**Headers**:
- `Authorization: Bearer {{ACCESS_TOKEN}}`
- `Version: 2021-04-15` (⚠️ different from contacts which uses `2021-07-28`)
- `Content-Type: application/json`

**Body**:
```json
{
  "locationId": "{{location_id}}",
  "contactId": "{{contact_id}}"
}
```

**Response** (200):
```json
{
  "conversation": {
    "id": "conv_abc123",
    "locationId": "...",
    "contactId": "...",
    "type": 1,
    "dateAdded": "2026-03-26T01:00:00.000Z",
    ...
  }
}
```

**Behavior**: Creates a conversation visible in the GHL Dashboard → Conversations tab under the "NOLA SMS Pro" channel.

> ⚠️ **Required OAuth Scope**: `conversations.write` — already included in the install URL in `ghl_callback.php` (line 447).

---

## GHL Marketplace Configuration (Francis)

### Custom Workflow Action

To allow GHL Workflows to trigger conversation creation automatically:

1. **In the GHL Marketplace Developer Portal** → Your App → Custom Actions
2. **Register a new action**:
   - Action Name: `Create NOLA SMS Pro Conversation`
   - Type: `Webhook`
   - Webhook URL: `https://smspro-api.nolacrm.io/webhook/ghl_create_conversation`
   - Method: `POST`
3. **Map input fields**:
   - `contactId` → from Workflow contact context (`{{contact.id}}`)
   - `locationId` → from Workflow location context (`{{location.id}}`)

This enables GHL automations like:
- "When Contact Tagged 'Hot Lead' → Create NOLA Conversation"
- "When Contact Created → Create NOLA Conversation"

The webhook endpoint (`api/webhook/ghl_create_conversation.php`) would reuse `GhlClient` and follow the same logic as `ghl-conversations.php`.

---

## Testing Checklist

- [ ] `POST /api/ghl-conversations` with valid `contactId` → 200 success
- [ ] Verify conversation appears in GHL Dashboard → Conversations tab
- [ ] Verify Firestore `conversations` collection has new doc with `ghl_conversation_id`
- [ ] Verify conversation appears in SMS Pro sidebar immediately
- [ ] Test with expired token → should auto-refresh and succeed
- [ ] Test with invalid `contactId` → should return error
- [ ] Test without `X-GHL-Location-ID` → should return 400

---

## Files to Create/Modify

| File | Action | Notes |
|---|---|---|
| `api/services/GhlClient.php` | **NEW** | Reusable GHL API client |
| `api/ghl-conversations.php` | **NEW** | Main endpoint |
| `api/ghl_contacts.php` | **MODIFY** | Shadow refactor GET only |
| `.htaccess` | **MODIFY** | Add routing rule |
| `api/webhook/ghl_create_conversation.php` | **NEW** (optional) | For GHL Workflows |

## Rollback

All changes are in new files. Rollback = delete `GhlClient.php` + `ghl-conversations.php` + revert the one `.htaccess` line.
