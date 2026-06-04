<?php

$url = 'http://127.0.0.1:8999/api/webhook/send_sms.php';

$payload = [
    'location' => [
        'id' => 'fake_attacker_location'
    ],
    'customData' => [
        'number' => '09708129927',
        'message' => 'Hi Test! I am trying to steal free credits!',
        'location_id' => 'fake_attacker_location',
        'is_system_notification' => 'true',
        'sendername' => 'NOLASMSPro'
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-Webhook-Secret: test_secret',
    'X-NOLA-SMS-Mock: true'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response:\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n";
