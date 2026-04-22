<?php
/**
 * debug_failures.php — Inspect the 10 most recent messages in Firestore.
 */

require __DIR__ . '/api/webhook/firestore_client.php';

$db = get_firestore();

echo "--- RECENT MESSAGES ---\n";
$messages = $db->collection('messages')
    ->orderBy('date_created', 'DESC')
    ->limit(10)
    ->documents();

foreach ($messages as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    
    $id = $doc->id();
    $status = $data['status'] ?? 'unknown';
    $sender = $data['sender_id'] ?? 'unknown';
    $number = $data['number'] ?? 'unknown';
    $message = substr($data['message'] ?? '', 0, 30) . '...';
    $error = $data['error_message'] ?? $data['response'] ?? 'None';
    $loc = $data['location_id'] ?? 'unknown';
    $date = $data['date_created'] instanceof \Google\Cloud\Core\Timestamp 
            ? $data['date_created']->get()->format('Y-m-d H:i:s') : 'N/A';

    echo "[{$date}] ID: {$id} | Loc: {$loc} | Sender: {$sender} | Status: {$status} | Phone: {$number}\n";
    echo "      Msg: {$message}\n";
    echo "      Error/Resp: " . (is_array($error) ? json_encode($error) : $error) . "\n\n";
}

echo "--- INTEGRATION CONFIG (NOLA CRM) ---\n";
// Attempt to find NOLA CRM subaccount
$ints = $db->collection('integrations')->where('approved_sender_id', '==', 'NOLACRM')->documents();
foreach($ints as $int) {
    if ($int->exists()) {
        $d = $int->data();
        echo "Doc ID: " . $int->id() . "\n";
        echo "Approved Sender: " . ($d['approved_sender_id'] ?? 'None') . "\n";
        echo "Custom API Key: " . (isset($d['semaphore_api_key']) ? 'YES ('.substr($d['semaphore_api_key'], 0, 4).'...)' : 'NO') . "\n";
        echo "Credit Balance: " . ($d['credit_balance'] ?? 0) . "\n";
        echo "Free Usage: " . ($d['free_usage_count'] ?? 0) . " / " . ($d['free_credits_total'] ?? 10) . "\n";
    }
}
