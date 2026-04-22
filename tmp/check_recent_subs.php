<?php
require_once __DIR__ . '/../api/webhook/firestore_client.php';
$db = get_firestore();

echo "Scanning 'integrations' collection for recent updates...\n";
$col = $db->collection('integrations');
$query = $col->orderBy('updated_at', 'DESC')->limit(5);
$documents = $query->documents();

foreach ($documents as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    echo "ID: " . $doc->id() . "\n";
    echo "  Location Name: " . ($data['location_name'] ?? 'N/A') . "\n";
    echo "  Credit Balance: " . ($data['credit_balance'] ?? 0) . "\n";
    echo "  Free Usage: " . ($data['free_usage_count'] ?? 0) . "/" . ($data['free_credits_total'] ?? 10) . "\n";
    $updated = $data['updated_at'] ?? 'N/A';
    if ($updated instanceof \Google\Cloud\Core\Timestamp) {
        echo "  Updated At: " . $updated->get()->format('Y-m-d H:i:s') . "\n";
    } else {
        echo "  Updated At: " . $updated . "\n";
    }
    echo "  API Key Present: " . (isset($data['nola_pro_api_key']) ? 'Yes' : 'No') . "\n";
    if (isset($data['nola_pro_api_key'])) {
        echo "  API Key matches system: " . ($data['nola_pro_api_key'] === '8089fc9919bc05855ae0d354011f8e4b' ? 'Yes' : 'No') . "\n";
    }
    echo "--------------------\n";
}
