<?php
require 'c:/Users/niceo/public_html/api/webhook/firestore_client.php';

$db = get_firestore();

$collections = ['messages', 'sms_logs', 'conversations', 'contacts', 'inbound_messages', 'integrations'];

foreach ($collections as $col) {
    try {
        $docs = $db->collection($col)->limit(5)->documents();
        $count = 0;
        echo "Collection: $col\n";
        foreach ($docs as $doc) {
            $count++;
            echo "  ID: " . $doc->id() . "\n";
            // echo "  Data: " . json_encode($doc->data(), JSON_PRETTY_PRINT) . "\n";
        }
        if ($count === 0) {
            echo "  No documents found.\n";
        }
        echo "\n";
    } catch (Exception $e) {
        echo "Error querying $col: " . $e->getMessage() . "\n\n";
    }
}
