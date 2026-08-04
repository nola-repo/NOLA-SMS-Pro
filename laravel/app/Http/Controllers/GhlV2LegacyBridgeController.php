<?php

namespace App\Http\Controllers;

use App\Services\LegacyPhpBridgeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GhlV2LegacyBridgeController extends Controller
{
    public function __construct(private readonly LegacyPhpBridgeService $bridge)
    {
    }

    public function oauthExchange(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/ghl/oauth_exchange.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function ghlOauth(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/ghl_oauth.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function ghlContacts(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/ghl_contacts.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function ghlConversations(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/ghl-conversations.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function whitelabel(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/public/whitelabel.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    private function forwardToLegacy(string $script, string $method, array $query = [], string $rawBody = ''): Response
    {
        $result = $this->bridge->call($script, $method, $query, $rawBody);

        return response($result['body'], $result['status'])
            ->header('Content-Type', 'application/json');
    }
}
