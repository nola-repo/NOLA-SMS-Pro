<?php

namespace Tests\Feature\Bridge;

use Tests\TestCase;

class AgencyBridgeTest extends TestCase
{
    public function test_locations_forwards_to_legacy_script(): void
    {
        $expectedResponse = ['success' => true, 'locations' => []];

        $this->mockLegacyBridge(
            script: 'api/agency/locations.php',
            status: 200,
            body: $expectedResponse
        );

        $response = $this->withHeaders([
            'Authorization' => 'Bearer fake_token'
        ])->getJson('/api/v2/agency/locations');

        $response->assertStatus(200)
                 ->assertJson($expectedResponse);
    }

    public function test_toggle_subaccount_forwards_to_legacy_script(): void
    {
        $expectedResponse = ['success' => true, 'message' => 'Toggled'];

        $this->mockLegacyBridge(
            script: 'api/agency/toggle_subaccount.php',
            status: 200,
            body: $expectedResponse
        );

        $response = $this->withHeaders([
            'Authorization' => 'Bearer fake_token'
        ])->postJson('/api/v2/agency/toggle_subaccount', [
            'location_id' => 'loc_123',
            'action' => 'pause'
        ]);

        $response->assertStatus(200)
                 ->assertJson($expectedResponse);
    }
}
