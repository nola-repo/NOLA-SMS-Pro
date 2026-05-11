<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Mocks the LegacyPhpBridgeService to expect a call and return a specific response.
     *
     * @param string $script The expected script path suffix (e.g., 'api/auth/login.php')
     * @param int $status The HTTP status code to return
     * @param array|string $body The body to return (array will be JSON encoded)
     */
    protected function mockLegacyBridge(string $script, int $status = 200, array|string $body = []): void
    {
        $this->mock(\App\Services\LegacyPhpBridgeService::class, function (\Mockery\MockInterface $mock) use ($script, $status, $body) {
            $mock->shouldReceive('call')
                ->withArgs(function ($actualScript, $actualMethod, $actualQuery, $actualRawBody) use ($script) {
                    return str_ends_with($actualScript, $script);
                })
                ->once()
                ->andReturn([
                    'status' => $status,
                    'body' => is_array($body) ? json_encode($body) : $body,
                ]);
        });
    }
}
