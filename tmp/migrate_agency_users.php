<?php
/**
 * One-time migration: users(role=agency) -> agency_users
 *
 * Usage:
 *   /tmp/migrate_agency_users.php?secret=...&dry_run=1
 *   /tmp/migrate_agency_users.php?secret=...&dry_run=0
 */

require_once __DIR__ . '/../api/webhook/firestore_client.php';

$expectedSecret = getenv('WEBHOOK_SECRET');
$providedSecret = (string)($_GET['secret'] ?? '');
if ($expectedSecret === false || trim((string)$expectedSecret) === '' || !hash_equals((string)$expectedSecret, $providedSecret)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$dryRun = ((string)($_GET['dry_run'] ?? '1')) !== '0';
header('Content-Type: application/json');

try {
    $db = get_firestore();
    $agencyUsers = $db->collection('users')->where('role', '=', 'agency')->documents();

    $total = 0;
    $migrated = 0;
    $skipped = 0;
    $errors = [];

    foreach ($agencyUsers as $doc) {
        if (!$doc->exists()) {
            continue;
        }
        $total++;
        $d = $doc->data();
        $email = strtolower(trim((string)($d['email'] ?? '')));
        if ($email === '') {
            $skipped++;
            continue;
        }

        $existing = $db->collection('agency_users')->where('email', '=', $email)->limit(1)->documents();
        $exists = false;
        foreach ($existing as $s) {
            if ($s->exists()) {
                $exists = true;
                break;
            }
        }
        if ($exists) {
            $skipped++;
            continue;
        }

        if ($dryRun) {
            $migrated++;
            continue;
        }

        try {
            $newRef = $db->collection('agency_users')->newDocument();
            $newRef->set([
                'name' => $d['name'] ?? '',
                'firstName' => $d['firstName'] ?? '',
                'lastName' => $d['lastName'] ?? '',
                'email' => $email,
                'phone' => $d['phone'] ?? '',
                'password_hash' => $d['password_hash'] ?? '',
                'role' => 'agency',
                'active' => isset($d['active']) ? (bool)$d['active'] : true,
                'source' => $d['source'] ?? 'migrated_from_users',
                'company_id' => $d['company_id'] ?? null,
                'company_name' => $d['company_name'] ?? null,
                'created_at' => $d['created_at'] ?? new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
                'updated_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
                'legacy_user_id' => $doc->id(),
                'migrated_from_users' => true,
            ], ['merge' => true]);

            $doc->reference()->set([
                'migrated_to_agency_users' => true,
                'updated_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
            ], ['merge' => true]);
            $migrated++;
        } catch (Exception $e) {
            $errors[] = 'Failed for users/' . $doc->id();
        }
    }

    echo json_encode([
        'ok' => true,
        'dry_run' => $dryRun,
        'total_agency_users_in_users' => $total,
        'migrated_or_would_migrate' => $migrated,
        'skipped' => $skipped,
        'errors' => $errors,
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Migration failed']);
}
