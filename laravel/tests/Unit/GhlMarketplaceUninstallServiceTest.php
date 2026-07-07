<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../api/services/GhlMarketplaceUninstallService.php';

class GhlMarketplaceUninstallServiceTest extends TestCase
{
    public function test_uninstall_uses_location_endpoint_body_and_required_version(): void
    {
        $capture = new class {
            public array $request = [];

            public function request(string $method, string $path, ?string $body, string $version): array
            {
                $this->request = compact('method', 'path', 'body', 'version');
                return ['status' => 200, 'body' => '{"success":true}'];
            }
        };
        $service = new \GhlMarketplaceUninstallService(
            static fn($db, string $locationId, ?string $tokenRegistryId) => $capture
        );

        $result = $service->uninstall(
            null,
            'DisposableLocation123',
            'MarketplaceApp123',
            'DisposableLocation123',
            'Approved test cleanup'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('DELETE', $capture->request['method']);
        $this->assertSame('/marketplace/app/MarketplaceApp123/installations', $capture->request['path']);
        $this->assertSame('2023-02-21', $capture->request['version']);
        $this->assertSame([
            'locationId' => 'DisposableLocation123',
            'reason' => 'Approved test cleanup',
        ], json_decode($capture->request['body'], true));
    }

    public function test_protected_location_is_rejected_before_creating_client(): void
    {
        $factoryCalled = false;
        $service = new \GhlMarketplaceUninstallService(
            static function () use (&$factoryCalled) {
                $factoryCalled = true;
                throw new \RuntimeException('must not be called');
            }
        );
        $locationId = array_key_first(\CleanupSafety::PROTECTED_LOCATIONS);

        $result = $service->uninstall(null, $locationId, 'MarketplaceApp123');

        $this->assertFalse($result['success']);
        $this->assertSame('protected_location', $result['classification']);
        $this->assertFalse($factoryCalled);
    }

    public function test_failed_success_body_is_not_accepted(): void
    {
        $client = new class {
            public function request(string $method, string $path, ?string $body, string $version): array
            {
                return ['status' => 200, 'body' => '{"success":false}'];
            }
        };
        $service = new \GhlMarketplaceUninstallService(static fn() => $client);

        $result = $service->uninstall(null, 'DisposableLocation123', 'MarketplaceApp123');

        $this->assertFalse($result['success']);
        $this->assertSame('unexpected_response', $result['classification']);
    }
}
