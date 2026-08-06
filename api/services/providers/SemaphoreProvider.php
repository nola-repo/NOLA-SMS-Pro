<?php

require_once __DIR__ . '/SmsProviderInterface.php';
require_once __DIR__ . '/../ConnectivityMonitor.php';

class SemaphoreProvider implements SmsProviderInterface
{
    private $defaultApiKey;
    private $apiUrl;

    // Retry configuration (shared by all methods)
    private const MAX_ATTEMPTS  = 3;
    private const BASE_DELAY_MS = 500;  // 500 ms after attempt 1
    private const MAX_DELAY_MS  = 3000; // cap at 3 s

    /**
     * Fire-and-forget diagnostics — runs in the same process but after the
     * exception is thrown, so it never blocks or delays the error response.
     * Saves an incident report to Firestore → connectivity_incidents.
     */
    private static function triggerDiagnostics(string $provider, string $error, string $context): void
    {
        try {
            ConnectivityMonitor::runAndSave($provider, $error, $context);
        } catch (\Throwable $e) {
            error_log('[SemaphoreProvider] ConnectivityMonitor failed: ' . $e->getMessage());
        }
    }

    public function __construct(array $config = [])
    {
        $this->defaultApiKey = $config['SEMAPHORE_API_KEY'] ?? '';
        $this->apiUrl        = $config['SEMAPHORE_URL'] ?? 'https://api.semaphore.co/api/v4/messages';
    }

    private function getApiKey(?string $apiKey): string
    {
        return !empty($apiKey) ? $apiKey : $this->defaultApiKey;
    }

    /**
     * Compute retry delay with exponential backoff + jitter.
     * attempt 1 → ~500 ms, attempt 2 → ~1 000 ms, capped at MAX_DELAY_MS.
     */
    private function retryDelayMs(int $attempt): int
    {
        $base   = self::BASE_DELAY_MS * (2 ** ($attempt - 1));
        $jitter = random_int(0, (int)($base * 0.3));
        return min($base + $jitter, self::MAX_DELAY_MS);
    }

    /**
     * Execute a raw cURL call with automatic retry on transient failures.
     *
     * @param  string $url
     * @param  string $method   'GET' or 'POST'
     * @param  mixed  $payload  JSON body for POST (null for GET)
     * @param  array  $headers  HTTP headers
     * @param  int    $connectTimeout  cURL connect timeout in seconds
     * @param  int    $totalTimeout    cURL total timeout in seconds
     * @return array{response:string|false, httpCode:int, curlError:string}
     */
    private function curlWithRetry(
        string $url,
        string $method,
        $payload,
        array  $headers,
        int    $connectTimeout = 8,
        int    $totalTimeout   = 15
    ): array {
        $lastResponse  = false;
        $lastHttpCode  = 0;
        $lastCurlError = '';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $totalTimeout);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            // Capture response headers so we can read Retry-After on 429
            $responseHeaders = [];
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($header);
            });

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            }

            $lastResponse  = curl_exec($ch);
            $lastHttpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastCurlError = curl_error($ch);
            curl_close($ch);

            // Success
            if ($lastResponse !== false && $lastHttpCode >= 200 && $lastHttpCode < 300) {
                break;
            }

            $isConnectionError = ($lastResponse === false);
            $isServerError     = ($lastHttpCode >= 500 && $lastHttpCode < 600);
            $isRateLimit       = ($lastHttpCode === 429);
            $isTransient       = $isConnectionError || $isServerError || $isRateLimit;

            if ($isTransient && $attempt < self::MAX_ATTEMPTS) {
                if ($isRateLimit) {
                    // Respect Semaphore's Retry-After header; default to 10 s if not provided
                    $retryAfterSec = (int)($responseHeaders['retry-after'] ?? 10);
                    $retryAfterSec = max(1, min($retryAfterSec, 60)); // clamp 1–60 s
                    error_log(sprintf(
                        '[SemaphoreProvider] Attempt %d/%d — HTTP 429 Rate Limited. Waiting %d s (Retry-After)…',
                        $attempt, self::MAX_ATTEMPTS, $retryAfterSec
                    ));
                    sleep($retryAfterSec);
                } else {
                    $delayMs = $this->retryDelayMs($attempt);
                    error_log(sprintf(
                        '[SemaphoreProvider] Attempt %d/%d failed — %s. Retrying in %d ms…',
                        $attempt,
                        self::MAX_ATTEMPTS,
                        $isConnectionError
                            ? 'cURL error: ' . $lastCurlError
                            : 'HTTP ' . $lastHttpCode,
                        $delayMs
                    ));
                    usleep($delayMs * 1000);
                }
                continue;
            }

            // Non-transient (other 4xx) or last attempt — stop retrying
            break;
        }

        return [
            'response'  => $lastResponse,
            'httpCode'  => $lastHttpCode,
            'curlError' => $lastCurlError,
        ];
    }

    public function sendSingle(string $number, string $message, string $senderId, ?string $apiKey = null): array
    {
        return $this->sendBulk([$number], $message, $senderId, $apiKey);
    }

    public function sendBulk(array $numbers, string $message, string $senderId, ?string $apiKey = null): array
    {
        $resolvedKey = $this->getApiKey($apiKey);
        $payload = [
            'apikey'     => $resolvedKey,
            'number'     => implode(',', $numbers),
            'message'    => $message,
            'sendername' => $senderId,
        ];

        $result = $this->curlWithRetry(
            $this->apiUrl,
            'POST',
            $payload,
            ["Content-Type: application/json"]
        );

        if ($result['response'] === false) {
            // Final failure after all retries — auto-run diagnostics in background
            self::triggerDiagnostics('semaphore', 'cURL error: ' . $result['curlError'], 'send');
            throw new \Exception("Semaphore cURL error: " . $result['curlError']);
        }

        $httpCode = $result['httpCode'];
        $decoded  = json_decode($result['response'], true);

        if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded)) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? 'Semaphore HTTP ' . $httpCode;
            throw new \Exception("Semaphore send failed: " . $msg);
        }

        // Return standardized list
        $results = [];
        $isList  = array_keys($decoded) === range(0, count($decoded) - 1);
        if ($isList) {
            foreach ($decoded as $row) {
                if (is_array($row) && isset($row['message_id'])) {
                    $results[] = [
                        'message_id'            => (string)$row['message_id'],
                        'provider_reference_id' => (string)$row['message_id'],
                        'provider_message_id'   => (string)$row['message_id'],
                        'status'                => $this->normalizeStatus($row['status'] ?? 'queued'),
                        'recipient'             => $row['number'] ?? '',
                        'provider_response'     => $row,
                    ];
                }
            }
        } elseif (isset($decoded['message_id'])) {
            $results[] = [
                'message_id'            => (string)$decoded['message_id'],
                'provider_reference_id' => (string)$decoded['message_id'],
                'provider_message_id'   => (string)$decoded['message_id'],
                'status'                => $this->normalizeStatus($decoded['status'] ?? 'queued'),
                'recipient'             => $decoded['number'] ?? '',
                'provider_response'     => $decoded,
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

        $result   = $this->curlWithRetry($url, 'GET', null, [], 8, 10);
        $httpCode = $result['httpCode'];
        $response = $result['response'];

        if ($httpCode === 404) {
            return ['status' => 'not_found'];
        }

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return ['status' => 'error'];
        }

        $decoded   = json_decode($response, true);
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

        $result   = $this->curlWithRetry($url, 'GET', null, [], 8, 10);
        $httpCode = $result['httpCode'];
        $response = $result['response'];

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return ['status' => 'inactive', 'credits' => 0];
        }

        $decoded = json_decode($response, true);
        if (is_array($decoded) && isset($decoded['credit_balance'])) {
            return [
                'status'  => 'active',
                'credits' => (int)$decoded['credit_balance'],
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
