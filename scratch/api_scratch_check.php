<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Google\Cloud\Firestore\FirestoreClient;

putenv('GOOGLE_APPLICATION_CREDENTIALS=' . __DIR__ . '/../../firebase_credentials.json');

$db = new FirestoreClient([
    'projectId' => 'nolasms-6abfb',
]);

// 1. Check for recent company tokens
echo "--- Recent Company Tokens ---\n";
$companyDocs = $db->collection('ghl_tokens')
    ->where('userType', '==', 'Company')
    ->orderBy('updated_at', 'DESC')
    ->limit(3)
    ->documents();

foreach ($companyDocs as $doc) {
    if ($doc->exists()) {
        $data = $doc->data();
        echo "ID: " . $doc->id() . "\n";
        echo "Company ID: " . ($data['companyId'] ?? 'N/A') . "\n";
        echo "Updated At: " . ($data['updated_at'] ? $data['updated_at']->get()->format('Y-m-d H:i:s') : 'N/A') . "\n";
        echo "Raw GHL payload: " . json_encode($data['raw'] ?? []) . "\n";
        echo "---------------------------\n";
    }
}

// 2. Check for recent location tokens
echo "\n--- Recent Location Tokens ---\n";
$locDocs = $db->collection('ghl_tokens')
    ->where('userType', '==', 'Location')
    ->orderBy('updated_at', 'DESC')
    ->limit(5)
    ->documents();

foreach ($locDocs as $doc) {
    if ($doc->exists()) {
        $data = $doc->data();
        echo "ID: " . $doc->id() . " (Location Name: " . ($data['location_name'] ?? 'N/A') . ")\n";
        echo "Company ID: " . ($data['companyId'] ?? 'N/A') . "\n";
        echo "Provisioned From Bulk: " . (!empty($data['provisioned_from_bulk']) ? 'Yes' : 'No') . "\n";
        echo "Updated At: " . ($data['updated_at'] ? $data['updated_at']->get()->format('Y-m-d H:i:s') : 'N/A') . "\n";
        echo "---------------------------\n";
    }
}
