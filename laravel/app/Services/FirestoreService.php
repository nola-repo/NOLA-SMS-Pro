<?php

namespace App\Services;

use Google\Cloud\Firestore\FirestoreClient;

class FirestoreService
{
    private ?FirestoreClient $db = null;

    public function db(): FirestoreClient
    {
        if ($this->db === null) {
            $this->db = new FirestoreClient([
                'projectId' => env('FIREBASE_PROJECT_ID', 'nola-sms-pro'),
            ]);
        }

        return $this->db;
    }
}
