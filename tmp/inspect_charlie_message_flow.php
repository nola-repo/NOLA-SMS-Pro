<?php
require_once __DIR__ . '/../api/webhook/firestore_client.php';

$db = get_firestore();
$locationId = 'ugBqfQsPtGijLjrmLdmA';
$phone = '09175062203';

function printable($value) {
    if ($value instanceof \Google\Cloud\Core\Timestamp) return $value->get()->format(DATE_ATOM);
    return $value;
}

foreach (['sms_logs', 'messages'] as $collection) {
    echo "\n=== {$collection} ===\n";
    $docs = $db->collection($collection)
        ->where('location_id', '=', $locationId)
        ->orderBy('date_created', 'DESC')
        ->limit(100)
        ->documents();
    foreach ($docs as $doc) {
        if (!$doc->exists()) continue;
        $d = $doc->data();
        $number = (string)($d['number'] ?? (($d['numbers'][0] ?? '')));
        $body = (string)($d['message'] ?? '');
        if ($number !== $phone && stripos($body, 'Charlie Cardines') === false) continue;
        $out = ['doc_id' => $doc->id()];
        foreach ([
            'status', 'provider_status', 'origin', 'source', 'direction', 'number',
            'message_id', 'provider_reference_id', 'provider_message_id', 'ghl_message_id',
            'batch_id', 'credits_used', 'provider', 'provider_error', 'error_reason',
            'billing_reference_id', 'billing_rollback_status', 'ghl_sync_success',
            'ghl_sync_http_status', 'date_created', 'created_at', 'updated_at'
        ] as $field) {
            if (array_key_exists($field, $d)) $out[$field] = printable($d[$field]);
        }
        $out['message'] = $body;
        if (isset($d['provider_response']['message']['fail_reason'])) {
            $out['fail_reason'] = $d['provider_response']['message']['fail_reason'];
        }
        echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
    }
}

echo "\n=== recent credit transactions ===\n";
$txs = $db->collection('credit_transactions')
    ->where('account_id', '=', 'ghl_' . $locationId)
    ->orderBy('created_at', 'DESC')
    ->limit(30)
    ->documents();
foreach ($txs as $tx) {
    if (!$tx->exists()) continue;
    $d = $tx->data();
    $text = (string)($d['description'] ?? '') . ' ' . (string)($d['to_number'] ?? '') . ' ' . (string)($d['message_body'] ?? '');
    $meta = is_array($d['metadata'] ?? null) ? $d['metadata'] : [];
    $text .= ' ' . (string)($meta['to_number'] ?? '') . ' ' . (string)($meta['message_body'] ?? '');
    if (stripos($text, $phone) === false && stripos($text, 'Charlie Cardines') === false) continue;
    $out = ['doc_id' => $tx->id()];
    foreach (['type','amount','balance_after','reference_id','description','provider','created_at'] as $field) {
        if (array_key_exists($field, $d)) $out[$field] = printable($d[$field]);
    }
    echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
}
