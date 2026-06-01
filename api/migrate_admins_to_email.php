<?php
/**
 * CLI migration: move admins documents from username IDs to lowercase email IDs.
 *
 * Dry run:
 *   php api/migrate_admins_to_email.php
 *
 * Apply:
 *   php api/migrate_admins_to_email.php --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/webhook/firestore_client.php';

$apply = in_array('--apply', $argv, true);
$overwrite = in_array('--overwrite', $argv, true);
$db = get_firestore();

$migrated = 0;
$skipped = 0;
$conflicts = 0;
$deleted = 0;

echo $apply ? "Applying admins email-ID migration...\n" : "Dry run for admins email-ID migration...\n";

try {
    $docs = $db->collection('admins')->documents();

    foreach ($docs as $doc) {
        if (!$doc->exists()) {
            continue;
        }

        $sourceId = $doc->id();
        $data = $doc->data();
        $email = strtolower(trim((string)($data['email'] ?? '')));

        if ($email === '') {
            $skipped++;
            echo "SKIP {$sourceId}: missing email field\n";
            continue;
        }

        $newData = $data;
        $newData['email'] = $email;
        unset($newData['username']);

        if ($sourceId === $email) {
            $migrated++;
            echo "OK {$sourceId}: already uses email document ID\n";
            if ($apply) {
                $db->collection('admins')->document($email)->set($newData);
            }
            continue;
        }

        $targetRef = $db->collection('admins')->document($email);
        $targetSnap = $targetRef->snapshot();
        if ($targetSnap->exists() && !$overwrite) {
            $conflicts++;
            echo "CONFLICT {$sourceId}: target {$email} already exists; use --overwrite to replace it\n";
            continue;
        }

        $migrated++;
        echo "MIGRATE {$sourceId} -> {$email}\n";

        if ($apply) {
            $targetRef->set($newData);
            $db->collection('admins')->document($sourceId)->delete();
            $deleted++;
        }
    }

    echo "Done. migrated={$migrated} skipped={$skipped} conflicts={$conflicts} deleted={$deleted}\n";
    if (!$apply) {
        echo "Run with --apply to write changes. Add --overwrite only after reviewing conflicts.\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
