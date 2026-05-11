<?php

namespace App\Http\Controllers;

use App\Services\LegacyPhpBridgeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BillingV2LegacyBridgeController extends Controller
{
    public function __construct(private readonly LegacyPhpBridgeService $bridge)
    {
    }

    public function agencyWallet(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/billing/agency_wallet.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function subaccountWallet(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/billing/subaccount_wallet.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function transactions(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/billing/transactions.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function creditRequests(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/billing/credit_requests.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function autoRechargeCron(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/billing/auto_recharge_cron.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    private function forwardToLegacy(string $script, string $method, array $query = [], string $rawBody = ''): Response
    {
        $result = $this->bridge->call($script, $method, $query, $rawBody);

        return response($result['body'], $result['status'])
            ->header('Content-Type', 'application/json');
    }
}
