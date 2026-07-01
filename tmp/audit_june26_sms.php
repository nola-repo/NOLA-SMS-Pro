<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/webhook/firestore_client.php';

$db = get_firestore();
$locationId = 'ugBqfQsPtGijLjrmLdmA';
$tz = new DateTimeZone('Asia/Manila');
$startLocal = new DateTimeImmutable('2026-06-26 00:00:00', $tz);
$endLocal = new DateTimeImmutable('2026-06-27 00:00:00', $tz);
$startTs = new \Google\Cloud\Core\Timestamp(DateTime::createFromImmutable($startLocal));
$endTs = new \Google\Cloud\Core\Timestamp(DateTime::createFromImmutable($endLocal));

function audit_local_time($value, DateTimeZone $tz): ?string {
    return $value instanceof \Google\Cloud\Core\Timestamp
        ? $value->get()->setTimezone($tz)->format('Y-m-d h:i:s A')
        : null;
}

function audit_nested(array $data, string $path) {
    $value = $data;
    foreach (explode('.', $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return null;
        $value = $value[$part];
    }
    return $value;
}

function audit_failure_reason(array $data): ?string {
    foreach ([
        'provider_error', 'error_reason', 'error_message',
        'provider_response.message.fail_reason', 'provider_response.fail_reason',
        'provider_response.error', 'provider_response.message',
    ] as $path) {
        $value = audit_nested($data, $path);
        if (is_scalar($value) && trim((string)$value) !== '') return trim((string)$value);
    }
    return null;
}

$rows = [];
$references = [];
$docs = $db->collection('sms_logs')
    ->where('location_id', '=', $locationId)
    ->where('date_created', '>=', $startTs)
    ->where('date_created', '<', $endTs)
    ->orderBy('date_created', 'DESC')
    ->limit(500)
    ->documents();

foreach ($docs as $doc) {
    if (!$doc->exists()) continue;
    $d = $doc->data();
    $ref = (string)($d['provider_reference_id'] ?? $d['provider_message_id'] ?? '');
    if (str_starts_with($ref, 'msg_')) $references[$ref] = true;
    $providerResponse = is_array($d['provider_response'] ?? null) ? $d['provider_response'] : [];
    $rows[] = [
        'id' => $doc->id(),
        'time_pht' => audit_local_time($d['date_created'] ?? null, $tz),
        'updated_pht' => audit_local_time($d['updated_at'] ?? null, $tz),
        'status' => $d['status'] ?? null,
        'provider' => $d['provider'] ?? null,
        'provider_status' => $d['provider_status'] ?? null,
        'provider_event' => $providerResponse['event'] ?? null,
        'failure_reason' => audit_failure_reason($d),
        'number' => $d['number'] ?? ($d['numbers'][0] ?? null),
        'sender_id' => $d['sender_id'] ?? null,
        'message' => $d['message'] ?? null,
        'provider_reference_id' => $ref ?: null,
        'ghl_message_id' => $d['ghl_message_id'] ?? null,
        'credits_used' => $d['credits_used'] ?? null,
        'source' => $d['source'] ?? $d['origin'] ?? null,
    ];
}

echo "=== SMS LOGS — JUNE 26 PHT ===\n";
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

echo "=== CREDIT TRANSACTIONS — JUNE 26 PHT ===\n";
$transactions = [];
$txDocs = $db->collection('credit_transactions')
    ->where('account_id', '=', 'ghl_' . $locationId)
    ->where('created_at', '>=', $startTs)
    ->where('created_at', '<', $endTs)
    ->orderBy('created_at', 'DESC')
    ->limit(500)
    ->documents();
foreach ($txDocs as $doc) {
    if (!$doc->exists()) continue;
    $d = $doc->data();
    $meta = is_array($d['metadata'] ?? null) ? $d['metadata'] : [];
    $transactions[] = [
        'id' => $doc->id(),
        'time_pht' => audit_local_time($d['created_at'] ?? null, $tz),
        'type' => $d['type'] ?? null,
        'amount' => $d['amount'] ?? null,
        'balance_after' => $d['balance_after'] ?? null,
        'reference_id' => $d['reference_id'] ?? null,
        'description' => $d['description'] ?? null,
        'provider' => $d['provider'] ?? null,
        'to_number' => $meta['to_number'] ?? $d['to_number'] ?? null,
        'message_body' => $meta['message_body'] ?? $d['message_body'] ?? null,
    ];
}
echo json_encode($transactions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

echo "=== CURRENT UNISMS STATUS FOR JUNE 26 REFERENCES ===\n";
$integration = $db->collection('integrations')->document('ghl_' . $locationId)->snapshot();
$apiKey = $integration->exists() ? (string)($integration->data()['unisms_api_key'] ?? '') : '';
if ($apiKey === '') {
    echo "UniSMS API key unavailable\n";
    exit;
}
foreach (array_keys($references) as $ref) {
    $ch = curl_init('https://unismsapi.com/api/sms/' . rawurlencode($ref));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERPWD => $apiKey . ':',
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $body = json_decode((string)$raw, true);
    $msg = is_array($body['message'] ?? null) ? $body['message'] : $body;
    if (isset($msg[0]) && is_array($msg[0])) $msg = $msg[0];
    $safe = ['reference_id'=>$ref, 'http_status'=>$http, 'curl_error'=>$curlError ?: null];
    foreach (['status','recipient','sender_id','fail_reason','created','sent_at','delivered_at','updated_at'] as $field) {
        if (is_array($msg) && array_key_exists($field, $msg)) $safe[$field] = $msg[$field];
    }
    echo json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}
