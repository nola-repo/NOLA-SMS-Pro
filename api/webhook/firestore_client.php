<?php

require __DIR__ . '/../../vendor/autoload.php';

use Google\Cloud\Firestore\FirestoreClient;

function get_firestore()
{
    static $db = null;

    if ($db === null) {
        $db = new FirestoreClient([
            'projectId' => 'nola-sms-pro'
        ]);
    }

    return $db;
}
