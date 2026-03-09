# SMS Bulk Group Chat Backend Implementation Guide

To enable the group chat view for bulk campaigns on the frontend, the backend `send_sms.php` must be modified to accept and store a `batch_id` for each message.

## 1. Requirement: Store `batch_id` in Firestore

When sending bulk messages, the frontend generates a unique `batch_id` and passes it to the backend for each recipient. 

### Changes in `send_sms.php`
- Capture the `batch_id` from the POST request.
- Include `batch_id` in the data object saved to Firestore.

#### Recommended Code Snippet:
```php
// Existing code capturing inputs
$number  = $_POST['number'] ?? '';
$message = $_POST['message'] ?? '';
$sender  = $_POST['sender']  ?? '';
$batch_id = $_POST['batch_id'] ?? null; // [NEW] Capture batch_id

// ... existing sending logic ...

// Update Firestore logging
$firestore_logger = [
    'timestamp'  => time(),
    'numbers'    => [$number],
    'message'    => $message,
    'sender'     => $sender,
    'status'     => $result['success'] ? 'sent' : 'error',
    'message_id' => $result['message_id'] ?? 'N/A',
    'batch_id'   => $batch_id // [NEW] Save batch_id
];
// ... rest of firestore save logic ...
```

## 2. Requirement: Filter by `batch_id` in `fetch_logs.php`

The frontend now calls the messages API with a `batch_id` query parameter to retrieve all messages in a campaign.

### Changes in `fetch_logs.php` (or equivalent)
- Check for `batch_id` in GET parameters.
- If present, filter the Firestore query to only return documents matching that `batch_id`.

#### Recommended Logic:
```php
$batch_id = $_GET['batch_id'] ?? null;

if ($batch_id) {
    // Return only messages for this campaign
    $query = $db->collection('sms_logs')
               ->where('batch_id', '==', $batch_id)
               ->orderBy('timestamp', 'DESC');
} else {
    // Existing logic (filter by phone number or return recent)
    // ...
}
```

## 3. Implementation Checklist
- [ ] Update `send_sms.php` to accept `batch_id` (already being sent by frontend).
- [ ] Ensure `batch_id` is a top-level field in the Firestore document for efficient querying.
- [ ] Verify that the GET `/api/messages?batch_id=xyz` correctly filters and returns historical data.
