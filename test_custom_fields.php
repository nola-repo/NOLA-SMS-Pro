<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/api/webhook/firestore_client.php';
require_once __DIR__ . '/api/services/GhlClient.php';

$loc = $_GET['loc'] ?? 'ugBqfQsPtGijLjrmLdmA';

try {
    $db = get_firestore();
    $client = new \GhlClient($db, $loc, $loc);
    
    echo "<h3>Token Info for $loc</h3>";
    $integration = $client->getIntegration();
    echo "<pre>";
    echo "Access Token: " . substr($integration['access_token'] ?? '', 0, 20) . "...\n";
    echo "Scope: " . ($integration['scope'] ?? 'MISSING') . "\n";
    echo "</pre>";

    echo "<h3>Testing /locations/$loc/customFields</h3>";
    $resp = $client->request('GET', "/locations/{$loc}/customFields");
    echo "<pre>";
    print_r($resp);
    echo "</pre>";

    echo "<h3>Testing with model=all</h3>";
    $resp2 = $client->request('GET', "/locations/{$loc}/customFields?model=all");
    echo "<pre>";
    print_r($resp2);
    echo "</pre>";

} catch (Exception $e) {
    echo "Exception: " . $e->getMessage();
}
