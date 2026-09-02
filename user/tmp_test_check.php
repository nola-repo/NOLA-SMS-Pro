<?php
$messageId = '1234567'; // fake ID
$apiKey = '8089fc9919bc05855ae0d354011f8e4b'; // from config.php

$url = "https://api.semaphore.co/api/v4/messages/{$messageId}?apikey={$apiKey}";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $resp\n";
