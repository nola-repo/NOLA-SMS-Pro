# Backend Handoff: Unified Admin Activity Logs

## The Issue
Currently, the `?action=logs` endpoint only fetches from the `messages` collection. However, the Admin Dashboard "Platform Activity" feed needs to be a **unified timeline** of all major events across the platform.

Right now, the frontend is receiving `sender_id_requests` when it calls `fetch(ADMIN_API)` without an action, leading to UI bugs. We need a proper `?action=logs` endpoint that combines multiple collections.

## The Goal
Update the `?action=logs` GET handler in `api/admin_sender_requests.php` to fetch the latest 50 events combined from **three** collections:
1. `messages` (SMS sent/failed)
2. `credit_purchases` (or whichever collection stores credit top-ups/transactions)
3. `sender_id_requests` (Pending, Approved, Rejected sender IDs)

## The Required JSON Schema
To allow the frontend to gracefully render these different types of events in a single scrolling feed, **every returned object must include a `type` property**, and timestamps must be normalized to `timestamp`.

### Example Output Structure:

```json
{
  "status": "success",
  "data": [
    {
      "type": "message",
      "id": "msg_123",
      "timestamp": "2023-10-25T14:30:00Z",
      "location_id": "loc_abc123",
      "number": "+1234567890",
      "message": "Hello world",
      "status": "sent",
      "sendername": "NOLACRM"
    },
    {
      "type": "credit_purchase",
      "id": "purchase_456",
      "timestamp": "2023-10-25T13:15:00Z",
      "location_id": "loc_xyz789",
      "amount": 5000,
      "amount_paid": 50.00,
      "status": "completed"
    },
    {
      "type": "sender_request",
      "id": "req_789",
      "timestamp": "2023-10-25T10:00:00Z",
      "location_id": "loc_def456",
      "requested_id": "MYBRAND",
      "status": "pending"
    }
  ]
}
```

## Implementation Guide (PHP)

You will need to fetch from the collections, map the data to normalize the `type` and `timestamp` fields, merge the arrays, sort them in PHP by timestamp descending, and then slice the top 50.

```php
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'logs') {
    $unifiedLogs = [];

    // 1. Fetch recent messages
    $messages = $db->collection('messages')->orderBy('date_created', 'DESC')->limit(30)->documents();
    foreach ($messages as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $ts = isset($data['date_created']) && $data['date_created'] instanceof \Google\Cloud\Core\Timestamp 
                  ? $data['date_created']->get()->format('c') : null;
            
            $unifiedLogs[] = array_merge($data, [
                'id' => $doc->id(),
                'type' => 'message',
                'timestamp' => $ts
            ]);
        }
    }

    // 2. Fetch sender requests
    $requests = $db->collection('sender_id_requests')->orderBy('created_at', 'DESC')->limit(20)->documents();
    foreach ($requests as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $ts = isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp 
                  ? $data['created_at']->get()->format('c') : null;
            
            $unifiedLogs[] = array_merge($data, [
                'id' => $doc->id(),
                'type' => 'sender_request',
                'timestamp' => $ts
            ]);
        }
    }

    // 3. Fetch credit purchases (adjust collection name as needed)
    $purchases = $db->collection('credit_purchases')->orderBy('created_at', 'DESC')->limit(20)->documents();
    foreach ($purchases as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $ts = isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp 
                  ? $data['created_at']->get()->format('c') : null;
            
            $unifiedLogs[] = array_merge($data, [
                'id' => $doc->id(),
                'type' => 'credit_purchase',
                'timestamp' => $ts
            ]);
        }
    }

    // Sort combined array by timestamp descending
    usort($unifiedLogs, function($a, $b) {
        $timeA = strtotime($a['timestamp'] ?? '1970-01-01');
        $timeB = strtotime($b['timestamp'] ?? '1970-01-01');
        return $timeB - $timeA;
    });

    // Return the top 50
    $finalLogs = array_slice($unifiedLogs, 0, 50);

    echo json_encode(['status' => 'success', 'data' => $finalLogs]);
    exit;
}
```

The frontend has already been updated to expect this `{ type, timestamp, ... }` format.
