<?php

// Mocking Firestore and Curl to verify retrieve_status.php logic

class MockDoc {
    public $data;
    public $id;
    public $ref;
    public function __construct($id, $data) { $this->id = $id; $this->data = $data; $this->ref = new MockRef($id); }
    public function exists() { return true; }
    public function data() { return $this->data; }
    public function reference() { return $this->ref; }
}

class MockRef {
    public $id;
    public function __construct($id) { $this->id = $id; }
    public function update($data) { echo "Updating document {$this->id} with: " . json_encode($data) . "\n"; }
    public function snapshot() { 
        if ($this->id === 'ghl_loc_custom') {
            return new MockSnap(true, ['nola_pro_api_key' => 'custom_key_123']);
        }
        return new MockSnap(false, []);
    }
}

class MockSnap {
    public $exists;
    public $data;
    public function __construct($e, $d) { $this->exists = $e; $this->data = $d; }
    public function exists() { return $this->exists; }
    public function data() { return $this->data; }
}

$apiKey = 'system_default_key';
$keyCache = [];

$testMessages = [
    new MockDoc('msg1', ['message_id' => 'sema_1', 'location_id' => 'loc_default']),
    new MockDoc('msg2', ['message_id' => 'sema_2', 'location_id' => 'loc_custom']),
];

foreach ($testMessages as $doc) {
    $data = $doc->data();
    $messageId = $data['message_id'];
    $locId = $data['location_id'];

    $activeKey = $apiKey;
    if ($locId) {
        if (isset($keyCache[$locId])) {
            $activeKey = $keyCache[$locId];
            echo "Used cache for $locId\n";
        } else {
            $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
            echo "Lookup API key for $intDocId\n";
            $mockRef = new MockRef($intDocId);
            $intSnap = $mockRef->snapshot();
            if ($intSnap->exists()) {
                $activeKey = $intSnap->data()['nola_pro_api_key'];
            }
            $keyCache[$locId] = $activeKey;
        }
    }

    echo "Fetching status for $messageId using key: $activeKey\n";
    // Simulate update
    $doc->reference()->update([['path' => 'status', 'value' => 'Delivered']]);
    echo "-------------------\n";
}
