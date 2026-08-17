<?php
$url = 'https://sms-api-116662437564.asia-southeast1.run.app/api/credits.php?account_id=default';
$options = [
    'http' => [
        'header' => "X-Webhook-Secret: f7RkQ2pL9zV3tX8cB1nS4yW6\r\n"
    ]
];
$context = stream_context_create($options);
echo file_get_contents($url, false, $context);
