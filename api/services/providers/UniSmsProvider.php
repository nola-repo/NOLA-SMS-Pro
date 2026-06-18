<?php

require_once __DIR__ . '/SmsProviderInterface.php';

class UniSmsProvider implements SmsProviderInterface
{
    private $defaultApiKey;
    private $defaultSenderId;
    private $endpoint;
    private $timeoutSeconds;

    public function __construct(array $config = [])
    {
        $this->defaultApiKey = $config['UNISMS_API_KEY'] ?? '';
        $this->defaultSenderId = $config['UNISMS_SENDER_ID'] ?? '';
        $this->endpoint = $config['UNISMS_ENDPOINT'] ?? 'https://unismsapi.com/api';
        $this->timeoutSeconds = (int)($config['UNISMS_TIMEOUT_SECONDS'] ?? 15);
    }

    private function getApiKey(?string $apiKey): string
    {
        $resolved = !empty($apiKey) ? $apiKey : $this->defaultApiKey;
        if (trim((string)$resolved) === '') {
            throw new \Exception('UniSMS API key is not configured');
        }
        return $resolved;
    }

    private function formatNumber(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);
        if (str_starts_with($digits, '09') && strlen($digits) === 11) {
            return '+63' . substr($digits, 1);
        }
        if (str_starts_with($digits, '639') && strlen($digits) === 12) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '+63' . $digits;
        }
        return '+' . $digits;
    }

    private function executeRequest(string $path, string $method, $payload, string $apiKey): array
    {
        $url = rtrim($this->endpoint, '/') . '/' . ltrim($path, '/');
        $ch = curl_init($url);

        $headers = [
            "Content-Type: application/json",
            "Accept: application/json"
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(3, $this->timeoutSeconds));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERPWD, $apiKey . ":");

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \Exception("UniSMS cURL error: " . $curlError);
        }

        $decoded = json_decode($response, true);
        return [
            'code' => $httpCode,
            'body' => is_array($decoded) ? $decoded : [],
            'raw' => (string)$response,
        ];
    }

    private function messageBody(array $body): array
    {
        if (isset($body['message']) && is_array($body['message'])) {
            return $body['message'];
        }
        return $body;
    }

    public function sendSingle(string $number, string $message, string $senderId, ?string $apiKey = null): array
    {
        $resolvedKey = $this->getApiKey($apiKey);
        $formattedNum = $this->formatNumber($number);

        $payload = [
            'recipient' => $formattedNum,
            'content' => $message,
            'sender_id' => !empty($senderId) ? $senderId : $this->defaultSenderId
        ];

        $res = $this->executeRequest('sms', 'POST', $payload, $resolvedKey);

        if ($res['code'] < 200 || $res['code'] >= 300) {
            $msg = $res['body']['message'] ?? $res['body']['error'] ?? 'UniSMS HTTP ' . $res['code'];
            if (is_array($msg)) {
                $msg = json_encode($msg);
            }
            if ($msg === 'UniSMS HTTP ' . $res['code'] && trim((string)$res['raw']) !== '') {
                $msg .= ': ' . substr((string)$res['raw'], 0, 300);
            }
            throw new \Exception("UniSMS send failed (HTTP {$res['code']}): " . $msg);
        }

        $body = $this->messageBody($res['body']);
        $refId = $body['reference_id'] ?? $body['id'] ?? null;
        if (!$refId) {
            throw new \Exception("UniSMS response missing reference_id");
        }

        return [
            'message_id' => (string)$refId,
            'provider_reference_id' => (string)$refId,
            'provider_message_id' => (string)$refId,
            'status' => $this->normalizeStatus($body['status'] ?? 'pending'),
            'recipient' => $number,
            'provider_response' => $body,
        ];
    }

    public function sendBulk(array $numbers, string $message, string $senderId, ?string $apiKey = null): array
    {
        // To preserve direct 1-to-1 Firestore document mapping, we send individual messages.
        // This ensures every recipient has their own unique reference_id and we can monitor statuses correctly.
        $results = [];
        foreach ($numbers as $number) {
            try {
                $res = $this->sendSingle($number, $message, $senderId, $apiKey);
                $results[] = $res;
            } catch (\Exception $e) {
                $providerHttpStatus = null;
                if (preg_match('/HTTP\s+(\d{3})/i', $e->getMessage(), $m)) {
                    $providerHttpStatus = (int)$m[1];
                }
                // Return failed status for this number so it logs correctly
                $results[] = [
                    'message_id' => 'failed_' . bin2hex(random_bytes(4)),
                    'provider_reference_id' => null,
                    'provider_message_id' => null,
                    'provider_http_status' => $providerHttpStatus,
                    'status' => 'failed',
                    'recipient' => $number,
                    'error' => $e->getMessage(),
                    'provider_response' => [
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ],
                ];
            }
        }
        return $results;
    }

    public function checkStatus(string $messageId, ?string $apiKey = null): array
    {
        $resolvedKey = $this->getApiKey($apiKey);
        try {
            $res = $this->executeRequest('sms/' . urlencode($messageId), 'GET', null, $resolvedKey);
            if ($res['code'] === 404) {
                return ['status' => 'not_found'];
            }
            if ($res['code'] < 200 || $res['code'] >= 300) {
                return ['status' => 'error'];
            }

            $body = $this->messageBody($res['body']);
            $statusStr = '';
            if (isset($body[0]['status'])) {
                $statusStr = $body[0]['status'];
            } elseif (isset($body['status'])) {
                $statusStr = $body['status'];
            }

            if ($statusStr) {
                return ['status' => $this->normalizeStatus($statusStr)];
            }
        } catch (\Exception $e) {
            return ['status' => 'error'];
        }

        return ['status' => 'sending'];
    }

    public function checkAccount(?string $apiKey = null): array
    {
        $resolvedKey = $this->getApiKey($apiKey);
        try {
            $res = $this->executeRequest('account', 'GET', null, $resolvedKey);
            if ($res['code'] < 200 || $res['code'] >= 300) {
                return ['status' => 'inactive', 'credits' => 0];
            }

            $body = $res['body'];
            $status = $body['status'] ?? 'active';
            $credits = $body['sms_credits'] ?? 0;

            return [
                'status' => $status,
                'credits' => (int)$credits,
                'email' => $body['email'] ?? null,
                'sid_tokens' => isset($body['sid_tokens']) ? (int)$body['sid_tokens'] : null,
            ];
        } catch (\Exception $e) {
            return ['status' => 'inactive', 'credits' => 0];
        }
    }

    public function normalizeStatus(string $rawStatus): string
    {
        $l = strtolower(trim($rawStatus));
        if (in_array($l, ['pending', 'queued'])) {
            return 'queued';
        }
        if (in_array($l, ['retrying', 'sending'])) {
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
