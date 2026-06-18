<?php

require_once __DIR__ . '/SmsProviderInterface.php';

class SemaphoreProvider implements SmsProviderInterface
{
    private $defaultApiKey;
    private $apiUrl;

    public function __construct(array $config = [])
    {
        $this->defaultApiKey = $config['SEMAPHORE_API_KEY'] ?? '';
        $this->apiUrl = $config['SEMAPHORE_URL'] ?? 'https://api.semaphore.co/api/v4/messages';
    }

    private function getApiKey(?string $apiKey): string
    {
        return !empty($apiKey) ? $apiKey : $this->defaultApiKey;
    }

    public function sendSingle(string $number, string $message, string $senderId, ?string $apiKey = null): array
    {
        return $this->sendBulk([$number], $message, $senderId, $apiKey);
    }

    public function sendBulk(array $numbers, string $message, string $senderId, ?string $apiKey = null): array
    {
        $resolvedKey = $this->getApiKey($apiKey);
        $payload = [
            'apikey' => $resolvedKey,
            'number' => implode(',', $numbers),
            'message' => $message,
            'sendername' => $senderId
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \Exception("Semaphore cURL error: " . $curlError);
        }

        $decoded = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded)) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? 'Provider returned an invalid response';
            if (is_array($msg)) {
                $msg = json_encode($msg);
            }
            throw new \Exception("Semaphore send failed (HTTP {$httpCode}): " . $msg);
        }

        // Return standardized list
        $results = [];
        $isList = array_keys($decoded) === range(0, count($decoded) - 1);
        if ($isList) {
            foreach ($decoded as $row) {
                if (is_array($row) && isset($row['message_id'])) {
                    $results[] = [
                        'message_id' => (string)$row['message_id'],
                        'provider_reference_id' => (string)$row['message_id'],
                        'provider_message_id' => (string)$row['message_id'],
                        'status' => $this->normalizeStatus($row['status'] ?? 'queued'),
                        'recipient' => $row['number'] ?? '',
                        'provider_response' => $row,
                    ];
                }
            }
        } elseif (isset($decoded['message_id'])) {
            $results[] = [
                'message_id' => (string)$decoded['message_id'],
                'provider_reference_id' => (string)$decoded['message_id'],
                'provider_message_id' => (string)$decoded['message_id'],
                'status' => $this->normalizeStatus($decoded['status'] ?? 'queued'),
                'recipient' => $decoded['number'] ?? '',
                'provider_response' => $decoded,
            ];
        }

        if (empty($results)) {
            throw new \Exception("Semaphore response missing message_id");
        }

        return $results;
    }

    public function checkStatus(string $messageId, ?string $apiKey = null): array
    {
        $resolvedKey = $this->getApiKey($apiKey);
        $url = "https://api.semaphore.co/api/v4/messages/" . urlencode($messageId) . "?apikey=" . urlencode($resolvedKey);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 404) {
            return ['status' => 'not_found'];
        }

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return ['status' => 'error'];
        }

        $decoded = json_decode($response, true);
        $statusStr = '';
        if (is_array($decoded)) {
            if (isset($decoded[0]['status'])) {
                $statusStr = $decoded[0]['status'];
            } elseif (isset($decoded['status'])) {
                $statusStr = $decoded['status'];
            }
        }

        if ($statusStr) {
            return ['status' => $this->normalizeStatus($statusStr)];
        }

        return ['status' => 'sending'];
    }

    public function checkAccount(?string $apiKey = null): array
    {
        $resolvedKey = $this->getApiKey($apiKey);
        $url = "https://api.semaphore.co/api/v4/account?apikey=" . urlencode($resolvedKey);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return ['status' => 'inactive', 'credits' => 0];
        }

        $decoded = json_decode($response, true);
        if (is_array($decoded) && isset($decoded['credit_balance'])) {
            return [
                'status' => 'active',
                'credits' => (int)$decoded['credit_balance']
            ];
        }

        return ['status' => 'inactive', 'credits' => 0];
    }

    public function normalizeStatus(string $rawStatus): string
    {
        $l = strtolower(trim($rawStatus));
        if (in_array($l, ['queued', 'pending'])) {
            return 'queued';
        }
        if ($l === 'sending') {
            return 'sending';
        }
        if (in_array($l, ['sent', 'success', 'delivered'])) {
            return 'sent';
        }
        if (in_array($l, ['failed', 'expired', 'rejected', 'undelivered'])) {
            return 'failed';
        }
        return $l;
    }
}
