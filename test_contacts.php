<?php
require __DIR__ . '/api/webhook/firestore_client.php';
$db = get_firestore();
$locationId = 'ugBqfQsPtGijLjrmLdmA';
$doc = $db->collection('ghl_tokens')->document($locationId)->snapshot();
if (!$doc->exists()) {
    echo json_encode(["error" => "Token not found"]);
    exit;
}
$data = $doc->data();
$token = $data['access_token'];
$expires = $data['expires_at'];
echo "Token found. Expires at: " . date('Y-m-d H:i:s', $expires) . "\n";

$url = 'https://services.leadconnectorhq.com/contacts/?locationId=' . $locationId;
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json',
    'Version: 2021-07-28'
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP Code: $httpCode\n";
echo "Response: " . substr($resp, 0, 300) . "\n";
