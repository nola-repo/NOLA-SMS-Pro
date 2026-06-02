<?php

require __DIR__ . '/../../vendor/autoload.php';

use Google\Cloud\Firestore\FirestoreClient;

function get_firestore()
{
    static $db = null;

    if (defined('NOLA_SMS_TEST_MOCK') || (isset($_SERVER['HTTP_X_NOLA_SMS_MOCK']) && $_SERVER['HTTP_X_NOLA_SMS_MOCK'] === 'true')) {
        return new MockFirestore();
    }

    if ($db === null) {
        $db = new FirestoreClient([
            'projectId' => 'nola-sms-pro'
        ]);
    }

    return $db;
}

if (!class_exists('MockFirestore')) {
    class MockDocumentSnapshot {
        private $data;
        private $exists;
        public function __construct($data, $exists = true) {
            $this->data = $data;
            $this->exists = $exists;
        }
        public function exists() { return $this->exists; }
        public function data() { return $this->data; }
    }

    class MockDocumentReference {
        private $id;
        private $data;
        public function __construct($id, $data = []) {
            $this->id = $id;
            $this->data = $data;
        }
        public function id() { return $this->id; }
        public function reference() { return $this; }
        public function snapshot() {
            return new MockDocumentSnapshot($this->data, !empty($this->data));
        }
        public function set($data, $options = []) {
            return true;
        }
        public function update($data) {
            return true;
        }
    }

    class MockCollectionReference {
        private $name;
        public function __construct($name) {
            $this->name = $name;
        }
        public function document($id = null) {
            $id = $id ?: 'mock_doc_' . bin2hex(random_bytes(4));
            $data = [];
            if ($this->name === 'admin_config' && $id === 'master_senders') {
                $data = ['approved_senders' => ['NOLASMSPro', 'NOLA CRM']];
            } elseif ($this->name === 'ghl_tokens') {
                $data = ['toggle_enabled' => true, 'install_state' => 'INSTALLED', 'rate_limit' => 0];
            } elseif ($this->name === 'integrations') {
                $data = ['approved_sender_id' => 'NOLA CRM', 'free_usage_count' => 0, 'free_credits_total' => 10];
            }
            return new MockDocumentReference($id, $data);
        }
        public function newDocument() {
            return $this->document();
        }
        public function where($field, $op, $value) {
            return $this;
        }
        public function limit($n) {
            return $this;
        }
        public function documents() {
            return [];
        }
    }

    class MockBatch {
        public function create($ref, $data) { return $this; }
        public function set($ref, $data, $options = []) { return $this; }
        public function update($ref, $data) { return $this; }
        public function commit() { return true; }
    }

    class MockFirestore {
        public function collection($name) {
            return new MockCollectionReference($name);
        }
        public function batch() {
            return new MockBatch();
        }
        public function runTransaction($callback) {
            return $callback($this);
        }
        public function snapshot($ref) {
            return $ref->snapshot();
        }
        public function set($ref, $data, $options = []) {
            return $ref->set($data, $options);
        }
        public function create($ref, $data) {
            return $ref->set($data);
        }
    }
}

