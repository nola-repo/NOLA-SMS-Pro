<?php

namespace App\Http\Controllers;

use App\Services\LegacyPhpBridgeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthV2LegacyBridgeController extends Controller
{
    public function __construct(private readonly LegacyPhpBridgeService $bridge)
    {
    }

    public function register(Request $request): Response
    {
        return $this->forwardToLegacy(
            base_path('../api/auth/register.php'),
            'POST',
            [],
            (string) $request->getContent()
        );
    }

    public function registerFromInstall(Request $request): Response
    {
        return $this->forwardToLegacy(
            base_path('../api/auth/register_from_install.php'),
            'POST',
            [],
            (string) $request->getContent()
        );
    }

    public function verifyInstallToken(Request $request): Response
    {
        return $this->forwardToLegacy(
            base_path('../api/auth/verify_install_token.php'),
            'GET',
            ['token' => (string) $request->query('token', '')]
        );
    }

    public function ghlAutologin(Request $request): Response
    {
        return $this->forwardToLegacy(
            base_path('../api/auth/ghl_autologin.php'),
            $request->method(),
            $request->query->all(),
            (string) $request->getContent()
        );
    }

    public function locationBootstrap(Request $request): Response
    {
        return $this->forwardToLegacy(
            base_path('../api/location/bootstrap.php'),
            'GET',
            $request->query->all(),
            '',
            $this->bootstrapHeaders($request)
        );
    }

    private function forwardToLegacy(
        string $script,
        string $method,
        array $query = [],
        string $rawBody = '',
        array $headers = []
    ): Response
    {
        $result = $this->bridge->call($script, $method, $query, $rawBody, $headers);

        return response($result['body'], $result['status'])
            ->header('Content-Type', 'application/json');
    }

    private function bootstrapHeaders(Request $request): array
    {
        $headers = [];
        foreach ([
            'Authorization',
            'X-Authorization',
            'X-Auth-Token',
            'X-GHL-Location-ID',
            'X-GHL-LocationID',
            'X-Request-ID',
            'X-Correlation-ID',
            'Origin',
            'Accept',
        ] as $name) {
            $value = $request->header($name);
            if (is_string($value) && trim($value) !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
