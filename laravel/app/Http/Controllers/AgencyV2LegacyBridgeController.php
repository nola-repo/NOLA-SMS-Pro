<?php

namespace App\Http\Controllers;

use App\Services\LegacyPhpBridgeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AgencyV2LegacyBridgeController extends Controller
{
    public function __construct(private readonly LegacyPhpBridgeService $bridge)
    {
    }

    public function provision(Request $request): Response
    {
        return $this->forwardToLegacy(
            base_path('../api/agency/install_provision.php'),
            $request->method(),
            $request->all(),
            (string) $request->getContent()
        );
    }

    public function status(Request $request): Response
    {
        return $this->forwardToLegacy(
            base_path('../api/agency/install_status.php'),
            'GET',
            $request->all()
        );
    }

    public function locations(Request $request): Response
    {
        return $this->forwardToLegacy(
            base_path('../api/agency/locations.php'),
            'GET',
            $request->all()
        );
    }

    public function checkInstalls(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/agency/check_installs.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function getAllActive(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/agency/get_all_active.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function getSubaccounts(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/agency/get_subaccounts.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function profile(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/agency/profile.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function ghlAgencyOauth(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/agency/ghl_agency_oauth.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function ghlAutologin(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/agency/ghl_autologin.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function ghlSsoDecrypt(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/agency/ghl_sso_decrypt.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function linkCompany(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/agency/link_company.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function resetAttemptCount(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/agency/reset_attempt_count.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function setRateLimit(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/agency/set_rate_limit.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function syncLocations(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/agency/sync_locations.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function toggleSubaccount(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/agency/toggle_subaccount.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    public function updateSubaccount(Request $request): Response
    {
        return $this->forwardToLegacy(base_path('../api/agency/update_subaccount.php'), $request->method(), $request->all(), (string) $request->getContent());
    }

    private function forwardToLegacy(string $script, string $method, array $query = [], string $rawBody = ''): Response
    {
        $result = $this->bridge->call($script, $method, $query, $rawBody, request()->headers->all());

        return response($result['body'], $result['status'])
            ->header('Content-Type', 'application/json');
    }
}
