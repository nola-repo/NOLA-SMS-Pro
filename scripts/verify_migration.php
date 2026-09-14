<?php
require_once __DIR__ . '/../api/webhook/firestore_client.php';

echo "=== Firestore Migration Verification ===\n";

$bossKeyFull = trim((string)(getenv('SEMAPHORE_GLOBAL_API_KEY') ?: ''));
if ($bossKeyFull === '') {
    echo "ERROR: SEMAPHORE_GLOBAL_API_KEY is not set. Refusing to run.\n";
    exit(1);
}

$globalKeyMasked = substr($bossKeyFull, 0, 4) . '...' . substr($bossKeyFull, -4);

try {
    $db = get_firestore();
    $integrationsSnap = $db->collection('integrations')->documents();

    $count = 0;
    $matched = 0;
    $custom = 0;
    $empty = 0;

    foreach ($integrationsSnap as $doc) {
        if (!$doc->exists()) {
            continue;
        }

        $data = $doc->data();
        $approvedSenderId = trim((string)($data['approved_sender_id'] ?? ''));

        if ($approvedSenderId === '') {
            continue;
        }

        $count++;
        $nolaProKey = (string)($data['nola_pro_api_key'] ?? '');
        $semaphoreKey = (string)($data['semaphore_api_key'] ?? '');
        $providerPref = $data['provider_preference'] ?? 'NULL';

        $nolaState = 'Empty';
        if ($nolaProKey === $bossKeyFull) {
            $nolaState = "MATCHES GLOBAL KEY ($globalKeyMasked)";
            $matched++;
        } elseif ($nolaProKey !== '') {
            $nolaState = 'Custom Key Set';
            $custom++;
        } else {
            $empty++;
        }

        $semState = 'Empty';
        if ($semaphoreKey === $bossKeyFull) {
            $semState = "MATCHES GLOBAL KEY ($globalKeyMasked)";
        } elseif ($semaphoreKey !== '') {
            $semState = 'Custom Key Set';
        }

        echo "\n-------------------------------------------------\n";
        echo "Location ID:  " . $doc->id() . "\n";
        echo "Sender ID:    " . $approvedSenderId . "\n";
        echo "ProviderPref: " . $providerPref . "\n";
        echo "Nola Pro Key: " . $nolaState . "\n";
        echo "SemaphoreKey: " . $semState . "\n";
    }

    echo "\n-------------------------------------------------\n";
    echo "Total Approved Senders Found: {$count}\n";
    echo "Matching global key: {$matched}\n";
    echo "Custom key still set: {$custom}\n";
    echo "Empty nola_pro_api_key: {$empty}\n";
} catch (\Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
