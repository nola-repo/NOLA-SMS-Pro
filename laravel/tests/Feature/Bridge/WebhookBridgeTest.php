<?php

namespace Tests\Feature\Bridge;

use App\Services\LegacyPhpBridgeService;
use Mockery;
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

    public function test_receive_sms_unisms_forwards_headers_to_legacy_script(): void
    {
        $expectedResponse = ['status' => 'success', 'message' => 'Webhook processed'];

        $this->mock(LegacyPhpBridgeService::class, function (Mockery\MockInterface $mock) use ($expectedResponse) {
            $mock->shouldReceive('call')
                ->withArgs(function ($actualScript, $actualMethod, $actualQuery, $actualRawBody, $actualHeaders) {
                    return str_ends_with($actualScript, 'api/webhook/receive_sms_unisms.php')
                        && $actualMethod === 'POST'
                        && ($actualHeaders['x-webhook-secret'][0] ?? null) === 'fake_secret'
                        && str_contains($actualRawBody, 'message.sent');
                })
                ->once()
                ->andReturn([
                    'status' => 200,
                    'body' => json_encode($expectedResponse),
                ]);
        });

        $response = $this->withHeaders([
            'X-Webhook-Secret' => 'fake_secret',
        ])->postJson('/api/v2/webhook/receive_sms_unisms', [
            'event' => 'message.sent',
            'id' => 'msg_test',
        ]);

        $response->assertStatus(200)
            ->assertJson($expectedResponse);
    }
}
