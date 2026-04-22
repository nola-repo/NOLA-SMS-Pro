<?php
require_once __DIR__ . '/../api/webhook/firestore_client.php';
$db = get_firestore();

$locId = 'ugBqfQsPtGijLjrmLdmA';
$docId = 'ghl_' . $locId;
$doc = $db->collection('integrations')->document($docId)->snapshot();

if ($doc->exists()) {
    $data = $doc->data();
    echo "Location: " . $locId . "\n";
    echo "Balance:  " . ($data['credit_balance'] ?? 0) . "\n";
    echo "Free:     " . ($data['free_usage_count'] ?? 0) . " / " . ($data['free_credits_total'] ?? 10) . "\n";
    echo "API Key:  " . ($data['nola_pro_api_key'] ?? 'None') . "\n";
} else {
    echo "Document $docId does NOT exist in integrations!\n";
}

// Check agency subaccounts
$subDoc = $db->collection('agency_subaccounts')->document($locId)->snapshot();
if ($subDoc->exists()) {
    echo "Agency Subaccount exists. Agency ID: " . ($subDoc->data()['agency_id'] ?? 'None') . "\n";
} else {
    echo "Agency Subaccount does NOT exist!\n";
}
