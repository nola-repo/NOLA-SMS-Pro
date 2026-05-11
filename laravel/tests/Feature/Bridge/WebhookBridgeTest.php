<?php

namespace Tests\Feature\Bridge;

use Tests\TestCase;

class WebhookBridgeTest extends TestCase
{
    public function test_send_sms_forwards_to_legacy_script(): void
    {
        $expectedResponse = ['success' => true, 'message' => 'SMS Sent'];

        $this->mockLegacyBridge(
            script: 'api/webhook/send_sms.php',
            status: 200,
            body: $expectedResponse
        );

        $response = $this->withHeaders([
            'X-Webhook-Secret' => 'fake_secret'
        ])->postJson('/api/v2/webhook/send_sms', [
            'to' => '+1234567890',
            'body' => 'Test message'
        ]);

        $response->assertStatus(200)
                 ->assertJson($expectedResponse);
    }
}
