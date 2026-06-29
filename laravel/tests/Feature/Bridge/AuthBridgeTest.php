<?php

namespace Tests\Feature\Bridge;

use Tests\TestCase;

class AuthBridgeTest extends TestCase
{
    public function test_register_forwards_to_legacy_script(): void
    {
        $expectedResponse = ['success' => true, 'message' => 'Registered'];

        $this->mockLegacyBridge(
            script: 'api/auth/register.php',
            status: 200,
            body: $expectedResponse
        );

        $this->withoutExceptionHandling();
        $response = $this->postJson('/api/v2/auth/register', [
            'email' => 'new@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
                 ->assertJson($expectedResponse);
    }

    public function test_register_from_install_forwards_to_legacy_script(): void
    {
        $expectedResponse = ['success' => true, 'message' => 'Installed'];

        $this->mockLegacyBridge(
            script: 'api/auth/register_from_install.php',
            status: 200,
            body: $expectedResponse
        );

        $response = $this->postJson('/api/v2/auth/register-from-install', [
            'email' => 'install@example.com'
        ]);

        $response->assertStatus(200)
                 ->assertJson($expectedResponse);
    }

    public function test_verify_install_token_forwards_to_legacy_script(): void
    {
        $expectedResponse = ['success' => true, 'valid' => true];

        $this->mockLegacyBridge(
            script: 'api/auth/verify_install_token.php',
            status: 200,
            body: $expectedResponse
        );

        $response = $this->getJson('/api/v2/auth/verify-install-token?token=123');

        $response->assertStatus(200)
                 ->assertJson($expectedResponse);
    }

    public function test_ghl_autologin_forwards_to_legacy_script(): void
    {
        $expectedResponse = [
            'token' => 'jwt',
            'role' => 'user',
            'location_id' => 'loc_123',
        ];

        $this->mockLegacyBridge(
            script: 'api/auth/ghl_autologin.php',
            status: 200,
            body: $expectedResponse
        );

        $response = $this->getJson('/api/v2/auth/ghl_autologin?location_id=loc_123');

        $response->assertStatus(200)
                 ->assertJson($expectedResponse);
    }

    public function test_auth_me_returns_machine_readable_missing_token_code(): void
    {
        $response = $this->getJson('/api/v2/auth/me');

        $response->assertStatus(401)
                 ->assertJson([
                     'code' => 'AUTH_TOKEN_MISSING',
                 ]);
    }

    public function test_location_bootstrap_forwards_to_unified_legacy_gate(): void
    {
        $expectedResponse = [
            'code' => 'LOCATION_READY',
            'location_id' => 'ugBqfQsPtGijLjrmLdmA',
            'contacts_can_load' => true,
            'next_action' => 'load_app',
        ];

        $this->mockLegacyBridge(
            script: 'api/location/bootstrap.php',
            status: 200,
            body: $expectedResponse
        );

        $response = $this->withHeader('Authorization', 'Bearer test-jwt')
            ->getJson('/api/v2/location/bootstrap?location_id=ugBqfQsPtGijLjrmLdmA');

        $response->assertStatus(200)
            ->assertJson($expectedResponse);
    }
}
