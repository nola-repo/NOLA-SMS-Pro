<?php

namespace Tests\Feature\Bridge;

use App\Services\LegacyPhpBridgeService;
use Mockery;
use Tests\TestCase;

class AdminBridgeTest extends TestCase
{
    public function test_admin_auth_does_not_put_password_in_query_payload(): void
    {
        $this->mock(LegacyPhpBridgeService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('call')
                ->withArgs(function ($actualScript, $actualMethod, $actualQuery, $actualRawBody) {
                    return str_ends_with($actualScript, 'api/admin_auth.php')
                        && $actualMethod === 'POST'
                        && $actualQuery === []
                        && str_contains($actualRawBody, '"email":"admin@example.com"')
                        && str_contains($actualRawBody, '"password":"secret123"');
                })
                ->once()
                ->andReturn([
                    'status' => 200,
                    'body' => json_encode(['status' => 'success']),
                ]);
        });

        $response = $this->postJson('/api/v2/admin_auth', [
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);
    }

    public function test_admin_list_agency_users_forwards_to_legacy_script(): void
    {
        $this->mock(LegacyPhpBridgeService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('call')
                ->withArgs(function ($actualScript, $actualMethod, $actualQuery, $actualRawBody) {
                    return str_ends_with($actualScript, 'api/admin_list_agency_users.php')
                        && $actualMethod === 'GET'
                        && $actualQuery === []
                        && $actualRawBody === '[]';
                })
                ->once()
                ->andReturn([
                    'status' => 200,
                    'body' => json_encode(['status' => 'success', 'data' => [], 'total' => 0]),
                ]);
        });

        $response = $this->getJson('/api/v2/admin_list_agency_users');

        $response->assertStatus(200)
            ->assertJson(['status' => 'success', 'data' => [], 'total' => 0]);
    }

    public function test_admin_health_forwards_to_legacy_script(): void
    {
        $this->mock(LegacyPhpBridgeService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('call')
                ->withArgs(function ($actualScript, $actualMethod, $actualQuery, $actualRawBody) {
                    return str_ends_with($actualScript, 'api/admin_health.php')
                        && $actualMethod === 'GET'
                        && $actualQuery === []
                        && $actualRawBody === '[]';
                })
                ->once()
                ->andReturn([
                    'status' => 200,
                    'body' => json_encode(['status' => 'success', 'data' => ['database_connected' => true]]),
                ]);
        });

        $response = $this->getJson('/api/v2/admin_health');

        $response->assertStatus(200)
            ->assertJson(['status' => 'success', 'data' => ['database_connected' => true]]);
    }
}
