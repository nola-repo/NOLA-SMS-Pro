<?php

/**
 * Verify whether NOLA will allow SMS / ghl_provider for a location (after backfill or uninstall).
 *
 * Usage:
 *   php tmp/check_location_sms_gate.php LOCATION_ID
 */

require __DIR__ . '/../api/webhook/firestore_client.php';
require __DIR__ . '/../api/install_helpers.php';

$locationId = trim($argv[1] ?? '');
if ($locationId === '') {
    fwrite(STDERR, "Usage: php tmp/check_location_sms_gate.php LOCATION_ID\n");
    exit(1);
}

$db = get_firestore();
$gate = install_location_sms_gate($db, $locationId);

$snap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
$state = $snap->exists() ? ($snap->data()['install_state'] ?? '(none)') : '(no doc)';
$isLive = $snap->exists() ? json_encode($snap->data()['is_live'] ?? null) : 'n/a';
$toggle = $snap->exists() ? json_encode($snap->data()['toggle_enabled'] ?? null) : 'n/a';
$uninstalledAt = $snap->exists() && isset($snap->data()['uninstalled_at'])
    ? $snap->data()['uninstalled_at']->formatAsString()
    : '(not set)';

echo "location_id:     {$locationId}\n";
echo "install_state:   {$state}\n";
echo "is_live:         {$isLive}\n";
echo "toggle_enabled:  {$toggle}\n";
echo "uninstalled_at:  {$uninstalledAt}\n";
echo "sms_allowed:     " . (!empty($gate['allowed']) ? 'YES' : 'NO') . "\n";
echo "gate_code:       " . ($gate['code'] ?? '') . "\n";
echo "gate_reason:     " . ($gate['reason'] ?? '') . "\n";

exit(!empty($gate['allowed']) ? 0 : 2);
