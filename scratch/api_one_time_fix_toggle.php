<?php
/**
 * one_time_fix_toggle.php — Run ONCE to correct subaccounts
 * that were set to toggle_enabled = false by the old sync_locations.php bug.
 *
 * ⚠️  Only run after confirming with the agency owner that
 *     all existing OFF toggles should be reset to ON.
 *
 * Usage:  php one_time_fix_toggle.php
 */
require __DIR__ . '/webhook/firestore_client.php';
$db = get_firestore();

$results = $db->collection('agency_subaccounts')->documents();
$fixed = 0;

foreach ($results as $doc) {
    if (!$doc->exists()) continue;

    $data = $doc->data();
    if (($data['toggle_enabled'] ?? true) === false) {
        $locId = $doc->id();

        // Fix agency_subaccounts (UI layer)
        $doc->reference()->set(['toggle_enabled' => true], ['merge' => true]);

        // Fix ghl_tokens (enforcement layer)
        $tokenRef = $db->collection('ghl_tokens')->document($locId);
        if ($tokenRef->snapshot()->exists()) {
            $tokenRef->set(['toggle_enabled' => true], ['merge' => true]);
        }

        $fixed++;
        echo "Fixed: $locId\n";
    }
}

echo "Total fixed: $fixed\n";
