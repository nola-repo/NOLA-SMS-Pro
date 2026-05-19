<?php

/**
 * One-off CLI: mark a sub-account as uninstalled (blocks ghl_provider / send_sms).
 *
 * Usage:
 *   php tmp/mark_location_uninstalled.php LOCATION_ID [company_id]
 */

require __DIR__ . '/../api/webhook/firestore_client.php';
require __DIR__ . '/../api/install_helpers.php';

$locationId = $argv[1] ?? '';
$companyId = $argv[2] ?? null;

if ($locationId === '') {
    fwrite(STDERR, "Usage: php tmp/mark_location_uninstalled.php LOCATION_ID [company_id]\n");
    exit(1);
}

$db = get_firestore();
$ok = install_mark_location_uninstalled($db, $locationId, 'manual_cli', $companyId);

echo $ok
    ? "Marked {$locationId} as UNINSTALLED\n"
    : "No ghl_tokens doc for {$locationId}\n";

exit($ok ? 0 : 1);
