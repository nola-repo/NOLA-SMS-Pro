<?php

/**
 * Backfill UNINSTALLED for sub-accounts that were removed in GHL before AppUninstall webhook handling.
 *
 * Usage:
 *   php tmp/mark_locations_uninstalled_bulk.php locId1 locId2 locId3
 *   php tmp/mark_locations_uninstalled_bulk.php --file=locations.txt
 *
 * locations.txt: one GHL location_id per line (# comments allowed)
 */

require __DIR__ . '/../api/webhook/firestore_client.php';
require __DIR__ . '/../api/install_helpers.php';

$ids = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $path = substr($arg, 7);
        if (!is_readable($path)) {
            fwrite(STDERR, "Cannot read file: {$path}\n");
            exit(1);
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $ids[] = $line;
        }
        continue;
    }
    $ids[] = trim($arg);
}

$ids = array_values(array_unique(array_filter($ids)));
if ($ids === []) {
    fwrite(STDERR, "Usage: php tmp/mark_locations_uninstalled_bulk.php LOC_ID [LOC_ID ...]\n");
    fwrite(STDERR, "   or: php tmp/mark_locations_uninstalled_bulk.php --file=locations.txt\n");
    exit(1);
}

$db = get_firestore();
$ok = 0;
$skip = 0;

foreach ($ids as $locationId) {
    if (install_mark_location_uninstalled($db, $locationId, 'manual_bulk_backfill')) {
        echo "OK   {$locationId}\n";
        $ok++;
    } else {
        echo "SKIP {$locationId} (no ghl_tokens doc)\n";
        $skip++;
    }
}

echo "\nDone: marked={$ok} skipped={$skip} total=" . count($ids) . "\n";
exit($ok > 0 ? 0 : 1);
