<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/api/webhook/firestore_client.php';
$db = get_firestore();

$companyId = '0OYXPGWM9ep2I37dgxAo';
$doc = $db->collection('ghl_tokens')->document($companyId)->snapshot();

if (!$doc->exists()) {
    die("Company token not found in DB.\n");
}

$data = $doc->data();
$accessToken = $data['access_token'];

$ch = curl_init('https://services.leadconnectorhq.com/locations/search?companyId=' . $companyId);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken,
    'Version: 2021-07-28',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
