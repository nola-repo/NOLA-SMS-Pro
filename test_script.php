<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/api/webhook/firestore_client.php';
$db = get_firestore();
$results = $db->collection('ghl_tokens')->where('companyId', '=', '0OYXPGWM9ep2I37dgxAo')->documents();
$count = 0;
foreach($results as $res) {
    print_r($res->data());
    $count++;
}
echo "Total documents with this companyId: " . $count . "\n";
