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

    private function forwardToLegacy(string $script, string $method, array $query = [], string $rawBody = ''): Response
    {
        $result = $this->bridge->call($script, $method, $query, $rawBody);

        return response($result['body'], $result['status'])
            ->header('Content-Type', 'application/json');
    }
}
