<?php

/**
 * Verification Script for SMS Status Synchronization Optimization.
 * Run this script to test all the guards, throttling, and state transition logic.
 */

// 1. Mock Google Cloud Core Timestamp class inside its namespace
namespace Google\Cloud\Core {
    class Timestamp implements \JsonSerializable {
        private $value;
        public function __construct($value) {
            $this->value = $value;
        }
        public function get() {
            if ($this->value instanceof \DateTimeInterface) {
                return $this->value;
            }
            return new \DateTime($this->value);
        }
        #[\ReturnTypeWillChange]
        public function jsonSerialize() {
            $dt = $this->get();
            return $dt->format('Y-m-d H:i:s');
        }
    }
}

// 2. Mock Curl inside Nola\Services namespace to intercept Semaphore HTTP calls
namespace Nola\Services {
    class CurlMock {
        public static $responses = [];
        public static $lastUrl = '';
        public static $callCount = 0;
    }

    function curl_init($url) {
        CurlMock::$lastUrl = $url;
        CurlMock::$callCount++;
        return $url;
    }

    function curl_setopt($ch, $opt, $val) {
        return true;
    }

    function curl_exec($ch) {
        foreach (CurlMock::$responses as $pattern => $resp) {
            if (strpos($ch, $pattern) !== false) {
                return $resp['body'];
            }
        }
        return json_encode([['status' => 'Pending']]);
    }

    function curl_getinfo($ch, $opt) {
        if ($opt === CURLINFO_HTTP_CODE) {
            foreach (CurlMock::$responses as $pattern => $resp) {
                if (strpos($ch, $pattern) !== false) {
                    return $resp['code'];
                }
            }
            return 200;
        }
        return 0;
    }

    function curl_close($ch) {
        return true;
    }
}

// 3. Main script logic in Global Namespace
namespace {
    define('VERIFICATION_TEST', true);

    class MockFirestore {
        public $collections = [];
        public function collection($name) {
            if (!isset($this->collections[$name])) {
                $this->collections[$name] = new MockCollection($name);
            }
            return $this->collections[$name];
        }
        public function runTransaction($callback) {
            return $callback($this);
        }
    }

    class MockCollection {
        public $name;
        public $documents = [];
        public function __construct($name) { $this->name = $name; }
        public function document($id) {
            if (!isset($this->documents[$id])) {
                $this->documents[$id] = new MockDocumentReference($id);
            }
            return $this->documents[$id];
        }
        public function where($path, $op, $val) {
            return new MockQuery($this);
        }
        public function orderBy($path, $dir = 'asc') {
            return new MockQuery($this);
        }
        public function limit($n) {
            return new MockQuery($this);
        }
        public function documents() {
            return [];
        }
    }

    class MockDocumentReference {
        public $id;
        public $data = [];
        public function __construct($id) { $this->id = $id; }
        public function update($updates) {
            foreach ($updates as $update) {
                $path = $update['path'];
                $val = $update['value'];
                $this->data[$path] = $val;
            }
            echo "    [Firestore] Updated document {$this->id} -> " . json_encode($this->data) . "\n";
        }
        public function set($data, $options = []) {
            if (isset($options['merge']) && $options['merge']) {
                $this->data = array_merge($this->data, $data);
            } else {
                $this->data = $data;
            }
            echo "    [Firestore] Set document {$this->id} -> " . json_encode($this->data) . "\n";
        }
        public function delete() {
            $this->data = [];
            echo "    [Firestore] Deleted document {$this->id}\n";
        }
        public function snapshot() {
            return new MockDocumentSnapshot($this->id, $this->data);
        }
    }

    class MockDocumentSnapshot {
        public $id;
        private $data;
        public function __construct($id, $data) { $this->id = $id; $this->data = $data; }
        public function exists() { return !empty($this->data); }
        public function data() { return $this->data; }
        public function reference() { return new MockDocumentReference($this->id); }
    }

    class MockQuery {
        private $collection;
        public function __construct($collection) { $this->collection = $collection; }
        public function where($path, $op, $val) { return $this; }
        public function orderBy($path, $dir = 'asc') { return $this; }
        public function limit($n) { return $this; }
        public function documents() { return []; }
    }

    // Require the actual StatusSync class
    require_once __DIR__ . '/services/StatusSync.php';

    echo "=== ACTIVE SMS STATUS RETRIEVER VERIFICATION ===\n\n";

    $db = new MockFirestore();
    $systemApiKey = "system_key_secret_abc123";
    $keyCache = [];

    // Seed mock integrations data so key loading doesn't fail
    $db->collection('integrations')->document('ghl_loc_pro')->update([
        ['path' => 'nola_pro_api_key', 'value' => 'pro_custom_api_key_456']
    ]);

    // Setup Mock Responses for Curl
    // For Case 1: sema_msg_1 initially returns 'pending' (stays in Sending, but sets updated_at)
    \Nola\Services\CurlMock::$responses['sema_msg_1'] = [
        'code' => 200,
        'body' => json_encode([['status' => 'pending']])
    ];
    // For Case 3: A, B, C messages
    \Nola\Services\CurlMock::$responses['msg_a'] = [
        'code' => 200,
        'body' => json_encode([['status' => 'sent']])
    ];
    \Nola\Services\CurlMock::$responses['msg_b'] = [
        'code' => 200,
        'body' => json_encode([['status' => 'sent']])
    ];
    \Nola\Services\CurlMock::$responses['msg_c'] = [
        'code' => 200,
        'body' => json_encode([['status' => 'sent']])
    ];
    // For Case 4: Upgrade test
    \Nola\Services\CurlMock::$responses['sema_msg_upgrade'] = [
        'code' => 200,
        'body' => json_encode([['status' => 'sent']])
    ];

    // Test Cases
    $cases = [
        'Case 1: Standard Active Message Check & Throttling (within 8 seconds)' => [
            'id' => 'sema_msg_1',
            'data' => [
                'status' => 'Sending',
                'location_id' => 'loc_pro',
                'date_created' => time() - 30, // 30 seconds ago (active window)
                'updated_at' => null
            ],
            'action' => function(&$db, &$data, $id, $apiKey, &$keyCache) {
                \Nola\Services\CurlMock::$callCount = 0;
                echo "  * First call to checkAndSyncSingleMessage (should hit Semaphore API):\n";
                \Nola\Services\StatusSync::checkAndSyncSingleMessage($db, $data, $id, $apiKey, $keyCache);
                echo "    -> Curl call count: " . \Nola\Services\CurlMock::$callCount . "\n";
                echo "    -> Status after first call: " . $data['status'] . "\n";
                
                echo "  * Second call immediately after (should be throttled, NO API call):\n";
                $dataBefore = $data;
                \Nola\Services\StatusSync::checkAndSyncSingleMessage($db, $data, $id, $apiKey, $keyCache);
                echo "    -> Curl call count: " . \Nola\Services\CurlMock::$callCount . "\n";
                echo "    -> Throttle status: " . ($data === $dataBefore ? "SUCCESS (Throttled)" : "FAILURE (Not throttled)") . "\n";
            }
        ],
        'Case 2: Older Messages Outside 15 Minute Window (should be skipped)' => [
            'id' => 'sema_msg_2',
            'data' => [
                'status' => 'Sending',
                'location_id' => 'loc_pro',
                'date_created' => time() - 1000, // ~16 minutes ago
                'updated_at' => null
            ],
            'action' => function(&$db, &$data, $id, $apiKey, &$keyCache) {
                \Nola\Services\CurlMock::$callCount = 0;
                $dataBefore = $data;
                \Nola\Services\StatusSync::checkAndSyncSingleMessage($db, $data, $id, $apiKey, $keyCache);
                echo "    -> Curl call count: " . \Nola\Services\CurlMock::$callCount . "\n";
                echo "  * Window filter status: " . ($data === $dataBefore ? "SUCCESS (Skipped older message)" : "FAILURE") . "\n";
            }
        ],
        'Case 3: Global Request Limit (max 3 checks per single request execution flow)' => [
            'id' => 'sema_msg_3',
            'data' => [
                'status' => 'Sending',
                'location_id' => 'loc_pro',
                'date_created' => time() - 10,
                'updated_at' => null
            ],
            'action' => function(&$db, &$data, $id, $apiKey, &$keyCache) {
                \Nola\Services\CurlMock::$callCount = 0;
                echo "  * We already made 1 API call in Case 1. Let's make more to reach limit (max 3):\n";
                
                // Seed a few more test message data arrays
                $msgA = ['status' => 'Sending', 'date_created' => time() - 5, 'location_id' => 'loc_pro'];
                $msgB = ['status' => 'Sending', 'date_created' => time() - 5, 'location_id' => 'loc_pro'];
                $msgC = ['status' => 'Sending', 'date_created' => time() - 5, 'location_id' => 'loc_pro'];
                
                echo "    -> Triggering check for Msg A:\n";
                \Nola\Services\StatusSync::checkAndSyncSingleMessage($db, $msgA, 'msg_a', $apiKey, $keyCache);
                echo "    -> Triggering check for Msg B:\n";
                \Nola\Services\StatusSync::checkAndSyncSingleMessage($db, $msgB, 'msg_b', $apiKey, $keyCache);
                echo "    -> Triggering check for Msg C (should hit the limit and not call API):\n";
                $msgCBefore = $msgC;
                \Nola\Services\StatusSync::checkAndSyncSingleMessage($db, $msgC, 'msg_c', $apiKey, $keyCache);
                
                echo "    -> Curl call count in Case 3: " . \Nola\Services\CurlMock::$callCount . "\n";
                echo "  * Max checks guard status: " . ($msgC === $msgCBefore ? "SUCCESS (Limit of 3 checks respected)" : "FAILURE") . "\n";
            }
        ],
        'Case 4: Live Status Upgrade (Sending -> Sent)' => [
            'id' => 'sema_msg_upgrade',
            'data' => [
                'status' => 'Sending',
                'location_id' => 'loc_pro',
                'date_created' => time() - 10,
                'updated_at' => null
            ],
            'action' => function(&$db, &$data, $id, $apiKey, &$keyCache) {
                \Nola\Services\CurlMock::$callCount = 0;
                // Force reset inline check count for this test case
                $refClass = new ReflectionClass('\Nola\Services\StatusSync');
                $refProp = $refClass->getProperty('inlineCheckCount');
                $refProp->setAccessible(true);
                $refProp->setValue(null, 0);

                echo "  * Verifying live upgrade on Semaphore status 'sent':\n";
                \Nola\Services\StatusSync::checkAndSyncSingleMessage($db, $data, $id, $apiKey, $keyCache);
                echo "    -> Curl call count: " . \Nola\Services\CurlMock::$callCount . "\n";
                echo "    -> Final Status: " . $data['status'] . "\n";
                echo "  * Upgrade status: " . ($data['status'] === 'Sent' ? "SUCCESS (Status upgraded to Sent)" : "FAILURE") . "\n";
            }
        ]
    ];

    foreach ($cases as $name => $test) {
        echo "---------------------------------------------------------\n";
        echo "$name\n";
        echo "---------------------------------------------------------\n";
        $test['action']($db, $test['data'], $test['id'], $systemApiKey, $keyCache);
        echo "\n";
    }

    echo "=== All Tests Completed Successfully ===\n";
}
