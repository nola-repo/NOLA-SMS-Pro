<?php

namespace App\Http\Controllers;

use App\Services\LegacyPhpBridgeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdminV2LegacyBridgeController extends Controller
{
    public function __construct(private readonly LegacyPhpBridgeService $bridge)
    {
    }

    public function auth(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/admin_auth.php'), $request->method(), $request->query->all(), (string) $request->getContent());
    }

    public function agencies(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/admin_agencies.php'), $request->method(), $request->query->all(), (string) $request->getContent());
    }

    public function users(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/admin_users.php'), $request->method(), $request->query->all(), (string) $request->getContent());
    }

    public function settings(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/admin_settings.php'), $request->method(), $request->query->all(), (string) $request->getContent());
    }

    public function senderRequests(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/admin_sender_requests.php'), $request->method(), $request->query->all(), (string) $request->getContent());
    }

    public function forgotPassword(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/admin_forgot_password.php'), $request->method(), $request->query->all(), (string) $request->getContent());
    }

    public function seedAdmin(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/seed_admin.php'), $request->method(), $request->query->all(), (string) $request->getContent());
    }

    private function forwardToLegacy(string $script, string $method, array $query = [], string $rawBody = ''): Response
    {
        $result = $this->bridge->call($script, $method, $query, $rawBody);

        return response($result['body'], $result['status'])
            ->header('Content-Type', 'application/json');
    }
}
