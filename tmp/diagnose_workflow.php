<?php
require_once __DIR__ . '/../api/webhook/firestore_client.php';
$db = get_firestore();

$locationId = 'ugBqfQsPtGijLjrmLdmA';

echo "--- RECENT SMS LOGS FOR LOCATION {$locationId} ---\n";
$logs = $db->collection('sms_logs')
    ->where('location_id', '==', $locationId)
    ->orderBy('created_at', 'DESC')
    ->limit(30)
    ->documents();

foreach ($logs as $l) {
    if (!$l->exists()) continue;
    $d = $l->data();
    $id = $l->id();
    $status = $d['status'] ?? $d['provider_status'] ?? 'unknown';
    $phone = $d['number'] ?? 'unknown';
    $msg = substr($d['message'] ?? '', 0, 50) . '...';
    $err = $d['provider_error'] ?? $d['error'] ?? 'None';
    $direction = $d['direction'] ?? 'unknown';
    $ghl_message_id = $d['ghl_message_id'] ?? 'None';
    $date = $d['created_at'] instanceof \Google\Cloud\Core\Timestamp 
            ? $d['created_at']->get()->format('Y-m-d H:i:s') 
            : ($d['date_created'] instanceof \Google\Cloud\Core\Timestamp 
               ? $d['date_created']->get()->format('Y-m-d H:i:s') : 'N/A');
    echo "[{$date}] ID: {$id} | Phone: {$phone} | Dir: {$direction} | Status: {$status} | GHL Msg ID: {$ghl_message_id}\n";
    echo "      Msg: {$msg}\n";
    echo "      Error: " . (is_array($err) ? json_encode($err) : $err) . "\n";
}
