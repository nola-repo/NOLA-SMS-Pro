<?php
require 'c:/Users/niceo/public_html/api/webhook/firestore_client.php';
$db = get_firestore();
$docs = $db->collection('integrations')->limit(2)->documents();
foreach ($docs as $doc) {
    if ($doc->exists()) {
        print_r($doc->data());
    }
}
