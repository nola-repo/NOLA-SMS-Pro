<?php

namespace Tests\Feature\Bridge;

use Tests\TestCase;

class ProductBridgeTest extends TestCase
{
    public function test_tickets_forwards_to_legacy_script(): void
    {
        $expectedResponse = ['success' => true, 'data' => []];

        $this->mockLegacyBridge(
            script: 'api/tickets.php',
            status: 200,
            body: $expectedResponse
        );

        $response = $this->withHeaders([
            'Authorization' => 'Bearer fake_token',
            'X-GHL-Location-ID' => 'loc_123',
        ])->getJson('/api/v2/tickets?location_id=loc_123');

        $response->assertStatus(200)
                 ->assertJson($expectedResponse);
    }
}
