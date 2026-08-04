<?php

namespace Tests\Feature\Bridge;

use App\Services\LegacyPhpBridgeService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyBridgeHeaderForwardingTest extends TestCase
{
    #[DataProvider('bridgeRoutes')]
    public function test_legacy_bridge_routes_forward_auth_and_location_headers(string $route, string $scriptSuffix): void
    {
        $this->mock(LegacyPhpBridgeService::class, function (Mockery\MockInterface $mock) use ($scriptSuffix) {
            $mock->shouldReceive('call')
                ->withArgs(function ($actualScript, $actualMethod, $actualQuery, $actualRawBody, $actualHeaders) use ($scriptSuffix) {
                    return str_ends_with($actualScript, $scriptSuffix)
                        && $actualMethod === 'GET'
                        && (($actualHeaders['authorization'][0] ?? null) === 'Bearer fake_token')
                        && (($actualHeaders['x-ghl-location-id'][0] ?? null) === 'loc_123');
                })
                ->once()
                ->andReturn([
                    'status' => 200,
                    'body' => json_encode(['success' => true]),
                ]);
        });

        $response = $this->withHeaders([
            'Authorization' => 'Bearer fake_token',
            'X-GHL-Location-ID' => 'loc_123',
        ])->getJson($route);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public static function bridgeRoutes(): array
    {
        return [
            'product' => ['/api/v2/credits?location_id=loc_123', 'api/credits.php'],
            'billing' => ['/api/v2/billing/transactions?location_id=loc_123', 'api/billing/transactions.php'],
            'admin' => ['/api/v2/admin_health?location_id=loc_123', 'api/admin_health.php'],
            'ghl' => ['/api/v2/ghl-contacts?location_id=loc_123', 'api/ghl_contacts.php'],
        ];
    }
}
