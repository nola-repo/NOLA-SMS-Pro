<?php
/**
 * tmp_check_credits.php — Debug: show exactly where credits are stored for a location.
 * Run: php tmp_check_credits.php
 * DELETE after use.
 */

require __DIR__ . '/api/webhook/firestore_client.php';

$locationId = 'Is3CjRqD4xzqonUZIOEo'; // ← the location from the workflow

$db = get_firestore();

$docIdWithPrefix    = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
$docIdWithoutPrefix = $locationId;

echo "=== Checking integrations collection ===\n";

$snapWith = $db->collection('integrations')->document($docIdWithPrefix)->snapshot();
echo "\n[1] integrations/{$docIdWithPrefix}:\n";
if ($snapWith->exists()) {
    $d = $snapWith->data();
    echo "  credit_balance   : " . ($d['credit_balance'] ?? 'NOT SET') . "\n";
    echo "  free_usage_count : " . ($d['free_usage_count'] ?? 'NOT SET') . "\n";
    echo "  free_credits_total: " . ($d['free_credits_total'] ?? 'NOT SET') . "\n";
    echo "  location_name    : " . ($d['location_name'] ?? 'NOT SET') . "\n";
} else {
    echo "  *** DOCUMENT DOES NOT EXIST ***\n";
}

$snapWithout = $db->collection('integrations')->document($docIdWithoutPrefix)->snapshot();
echo "\n[2] integrations/{$docIdWithoutPrefix}:\n";
if ($snapWithout->exists()) {
    $d = $snapWithout->data();
    echo "  credit_balance   : " . ($d['credit_balance'] ?? 'NOT SET') . "\n";
    echo "  free_usage_count : " . ($d['free_usage_count'] ?? 'NOT SET') . "\n";
    echo "  free_credits_total: " . ($d['free_credits_total'] ?? 'NOT SET') . "\n";
    echo "  location_name    : " . ($d['location_name'] ?? 'NOT SET') . "\n";
} else {
    echo "  *** DOCUMENT DOES NOT EXIST ***\n";
}

echo "\n=== Checking ghl_tokens collection ===\n";
$tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
echo "\n[3] ghl_tokens/{$locationId}:\n";
if ($tokenSnap->exists()) {
    $d = $tokenSnap->data();
    echo "  userType    : " . ($d['userType'] ?? 'NOT SET') . "\n";
    echo "  appType     : " . ($d['appType'] ?? 'NOT SET') . "\n";
    echo "  is_live     : " . (isset($d['is_live']) ? ($d['is_live'] ? 'true' : 'false') : 'NOT SET') . "\n";
    echo "  toggle_enabled: " . (isset($d['toggle_enabled']) ? ($d['toggle_enabled'] ? 'true' : 'false') : 'NOT SET') . "\n";
} else {
    echo "  *** DOCUMENT DOES NOT EXIST ***\n";
}

echo "\n=== CreditManager::get_balance() result ===\n";
require_once __DIR__ . '/api/services/CreditManager.php';
$cm = new CreditManager();
$balance = $cm->get_balance($locationId);
echo "get_balance('{$locationId}') = {$balance}\n";

echo "\nDone.\n";
