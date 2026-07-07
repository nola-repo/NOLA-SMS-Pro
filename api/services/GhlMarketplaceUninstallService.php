<?php

require_once __DIR__ . '/CleanupSafety.php';
require_once __DIR__ . '/GhlClient.php';

/**
 * Location-scoped Marketplace uninstall with safe response classification.
 */
final class GhlMarketplaceUninstallService
{
    /** @var callable */
    private $clientFactory;

    public function __construct(?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? static fn($db, string $locationId, ?string $tokenRegistryId = null) =>
            new GhlClient($db, $locationId, $tokenRegistryId);
    }

    public function uninstall(
        $db,
        string $locationId,
        string $appId,
        ?string $tokenRegistryId = null,
        string $reason = 'Removing unused NOLA SMS Pro test installation'
    ): array {
        $locationId = trim($locationId);
        $appId = trim($appId);
        $requestId = 'cleanup_uninstall_' . bin2hex(random_bytes(8));

        if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $locationId)) {
            return $this->result(false, 'invalid_location', 0, $requestId);
        }
        if (CleanupSafety::isProtectedLocation($locationId)) {
            return $this->result(false, 'protected_location', 0, $requestId);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{8,160}$/', $appId)) {
            return $this->result(false, 'invalid_app_id', 0, $requestId);
        }

        try {
            $client = ($this->clientFactory)($db, $locationId, $tokenRegistryId);
            $response = $client->request(
                'DELETE',
                '/marketplace/app/' . rawurlencode($appId) . '/installations',
                json_encode([
                    'locationId' => $locationId,
                    'reason' => trim($reason) !== '' ? trim($reason) : 'Removing unused NOLA SMS Pro test installation',
                ], JSON_UNESCAPED_SLASHES),
                '2023-02-21'
            );
        } catch (Throwable $e) {
            error_log('[cleanup_uninstall] request_id=' . $requestId . ' class=client_error location=' . $locationId);
            return $this->result(false, 'client_error', 0, $requestId);
        }

        $status = (int)($response['status'] ?? 0);
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        $body = is_array($decoded) ? $decoded : [];
        $success = $status >= 200 && $status < 300 && ($body['success'] ?? true) === true;
        $classification = $success ? 'uninstalled' : $this->classifyFailure($status, $body);

        error_log('[cleanup_uninstall] ' . json_encode([
            'request_id' => $requestId,
            'location_id' => $locationId,
            'http_status' => $status,
            'classification' => $classification,
        ]));

        return $this->result($success, $classification, $status, $requestId);
    }

    private function classifyFailure(int $status, array $body): string
    {
        if ($status === 401 || $status === 403) {
            return !empty($body['requires_reconnect']) ? 'reconnect_required' : 'unauthorized';
        }
        if ($status === 404) {
            return 'not_found_unverified';
        }
        if ($status === 400 || $status === 422) {
            return 'request_rejected';
        }
        if ($status === 429) {
            return 'rate_limited';
        }
        if ($status === 0 || $status >= 500) {
            return 'transient_failure';
        }
        return 'unexpected_response';
    }

    private function result(bool $success, string $classification, int $status, string $requestId): array
    {
        return [
            'success' => $success,
            'classification' => $classification,
            'http_status' => $status,
            'request_id' => $requestId,
            'occurred_at' => gmdate(DATE_ATOM),
        ];
    }
}
