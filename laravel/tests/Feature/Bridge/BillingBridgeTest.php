<?php

namespace Tests\Feature\Bridge;

use Tests\TestCase;

class BillingBridgeTest extends TestCase
{
    public function test_transactions_forwards_to_legacy_script(): void
    {
        $expectedResponse = ['success' => true, 'transactions' => []];

        $this->mockLegacyBridge(
            script: 'api/billing/transactions.php',
            status: 200,
            body: $expectedResponse
        );

        $response = $this->withHeaders([
            'Authorization' => 'Bearer fake_token'
        ])->getJson('/api/v2/billing/transactions');

        $response->assertStatus(200)
                 ->assertJson($expectedResponse);
    }

    public function test_report_forwards_to_legacy_script(): void
    {
        $expectedResponse = ['success' => true, 'transactions' => []];

        $this->mockLegacyBridge(
            script: 'api/billing/report.php',
            status: 200,
            body: $expectedResponse
        );

        $response = $this->withHeaders([
            'Authorization' => 'Bearer fake_token'
        ])->getJson('/api/v2/billing/report?format=json&location_id=loc_123');

        $response->assertStatus(200)
                 ->assertJson($expectedResponse);
    }
}
