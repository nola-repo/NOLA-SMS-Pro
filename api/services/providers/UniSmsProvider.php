<?php

require_once __DIR__ . '/SmsProviderInterface.php';

class UniSmsProvider implements SmsProviderInterface
{
    private $defaultApiKey;
    private $defaultSenderId;
    private $endpoint;
    private $timeoutSeconds;

    // Retry configuration
    private const MAX_ATTEMPTS    = 3;
    private const BASE_DELAY_MS   = 500;  // 500 ms after attempt 1
    private const MAX_DELAY_MS    = 3000; // cap at 3 s

    public function __construct(array $config = [])
    {
        $this->defaultApiKey    = $config['UNISMS_API_KEY']        ?? '';
        $this->defaultSenderId  = $config['UNISMS_SENDER_ID']      ?? '';
        $this->endpoint         = $config['UNISMS_ENDPOINT']        ?? 'https://unismsapi.com/api';
        $this->timeoutSeconds   = (int)($config['UNISMS_TIMEOUT_SECONDS'] ?? 15);
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

    /**
     * Compute retry delay with exponential backoff + jitter.
     * attempt 1 → ~500 ms, attempt 2 → ~1 000 ms, capped at MAX_DELAY_MS.
     */
    private function retryDelayMs(int $attempt): int
    {
        $base  = self::BASE_DELAY_MS * (2 ** ($attempt - 1));          // 500, 1000, 2000 …
        $jitter = random_int(0, (int)($base * 0.3));                    // ±30 % jitter
        return min($base + $jitter, self::MAX_DELAY_MS);
    }

    /**
     * Execute a cURL request to the UniSMS API with automatic retry on
     * transient failures (connection errors and HTTP 5xx responses).
     *
     * @param  string      $path    API path, e.g. 'sms' or 'account'
     * @param  string      $method  'GET' or 'POST'
     * @param  mixed       $payload JSON-serialisable body for POST requests
     * @param  string      $apiKey  UniSMS API key
     * @return array{code:int, body:array, raw:string}
     * @throws \Exception  Only when all retry attempts are exhausted (connection-level error)
     */
    private function executeRequest(string $path, string $method, $payload, string $apiKey): array
    {
        $url = rtrim($this->endpoint, '/') . '/' . ltrim($path, '/');

        $headers = [
            "Content-Type: application/json",
            "Accept: application/json",
        ];

        $lastCurlError = '';
        $lastHttpCode  = 0;
        $lastResponse  = false;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {

            $ch = curl_init($url);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);   // slightly more generous than 5 s
            curl_setopt($ch, CURLOPT_TIMEOUT, max(3, $this->timeoutSeconds));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_USERPWD, $apiKey . ":");
            // Follow redirects (some hosting environments have HTTPS redirect)
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            } else {
                curl_setopt($ch, CURLOPT_HTTPGET, true);
            }

            $lastResponse  = curl_exec($ch);
            $lastHttpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastCurlError = curl_error($ch);
            curl_close($ch);

            // --- Success ---
            if ($lastResponse !== false && $lastHttpCode >= 200 && $lastHttpCode < 300) {
                break;
            }

            // --- Is the error transient? ---
            $isConnectionError = ($lastResponse === false);
            $isServerError     = ($lastHttpCode >= 500 && $lastHttpCode < 600);
            $isTransient       = $isConnectionError || $isServerError;

            if ($isTransient && $attempt < self::MAX_ATTEMPTS) {
                $delayMs = $this->retryDelayMs($attempt);
                error_log(sprintf(
                    '[UniSmsProvider] Attempt %d/%d failed — %s. Retrying in %d ms…',
                    $attempt,
                    self::MAX_ATTEMPTS,
                    $isConnectionError
                        ? 'cURL error: ' . $lastCurlError
                        : 'HTTP ' . $lastHttpCode,
                    $delayMs
                ));
                usleep($delayMs * 1000);
                continue;
            }

            // Non-transient error (4xx) or last attempt — fall through
            break;
        }

        if ($lastResponse === false) {
            throw new \Exception("UniSMS cURL error: " . $lastCurlError);
        }

        $decoded = json_decode($lastResponse, true);
        return [
            'code' => $lastHttpCode,
            'body' => is_array($decoded) ? $decoded : [],
            'raw'  => (string)$lastResponse,
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
        $resolvedKey  = $this->getApiKey($apiKey);
        $formattedNum = $this->formatNumber($number);

        // Fail-safe emoji sanitization: UniSMS API rejects non-GSM-7/emoji characters with HTTP 422/500
        $gsm7Basic     = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,\\-./:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        $gsm7Extension = "^{}\\\[]~|€";
        $gsm7All       = $gsm7Basic . $gsm7Extension;

        $cleaned = '';
        $len     = mb_strlen($message, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($message, $i, 1, 'UTF-8');
            if (mb_strpos($gsm7All, $char, 0, 'UTF-8') !== false
                || $char === "\n" || $char === "\r" || $char === "\t"
            ) {
                $cleaned .= $char;
            }
        }
        $message = trim(preg_replace('/[^\S\n]+/', ' ', $cleaned));

        $payload = [
            'recipient' => $formattedNum,
            'content'   => $message,
            'sender_id' => !empty($senderId) ? $senderId : $this->defaultSenderId,
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

        $body  = $this->messageBody($res['body']);
        $refId = $body['reference_id'] ?? $body['id'] ?? null;
        if (!$refId) {
            throw new \Exception("UniSMS response missing reference_id");
        }

        return [
            'message_id'             => (string)$refId,
            'provider_reference_id'  => (string)$refId,
            'provider_message_id'    => (string)$refId,
            'status'                 => $this->normalizeStatus($body['status'] ?? 'pending'),
            'recipient'              => $number,
            'provider_response'      => $body,
        ];
    }

    public function sendBulk(array $numbers, string $message, string $senderId, ?string $apiKey = null): array
    {
        // To preserve direct 1-to-1 Firestore document mapping, we send individual messages.
        // This ensures every recipient has their own unique reference_id and we can monitor statuses correctly.
        $results = [];
        foreach ($numbers as $number) {
            try {
                $res       = $this->sendSingle($number, $message, $senderId, $apiKey);
                $results[] = $res;
            } catch (\Exception $e) {
                $providerHttpStatus = null;
                if (preg_match('/HTTP\s+(\d{3})/i', $e->getMessage(), $m)) {
                    $providerHttpStatus = (int)$m[1];
                }
                // Return failed status for this number so it logs correctly
                $results[] = [
                    'message_id'            => 'failed_' . bin2hex(random_bytes(4)),
                    'provider_reference_id' => null,
                    'provider_message_id'   => null,
                    'provider_http_status'  => $providerHttpStatus,
                    'status'                => 'failed',
                    'recipient'             => $number,
                    'error'                 => $e->getMessage(),
                    'provider_response'     => [
                        'status' => 'failed',
                        'error'  => $e->getMessage(),
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

            $body      = $this->messageBody($res['body']);
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

            $body    = $res['body'];
            $status  = $body['status']      ?? 'active';
            $credits = $body['sms_credits'] ?? 0;

            return [
                'status'     => $status,
                'credits'    => (int)$credits,
                'email'      => $body['email']      ?? null,
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
