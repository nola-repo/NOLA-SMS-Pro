<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MarketplaceInstallationContractTest extends TestCase
{
    private function rootFile(string $path): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    public function test_agency_provisioning_uses_installed_locations_not_all_company_locations(): void
    {
        $source = file_get_contents($this->rootFile('api/agency/install_provision.php'));

        $this->assertStringContainsString('/oauth/installedLocations', $source);
        $this->assertStringContainsString("'isInstalled' => 'true'", $source);
        $this->assertStringContainsString('No installed locations were returned by oauth/installedLocations.', $source);
        $this->assertStringNotContainsString('/locations/search?companyId=', $source);
    }

    public function test_reinstall_links_match_lean_marketplace_scope_set(): void
    {
        $callback = file_get_contents($this->rootFile('ghl_callback.php'));
        $debug = file_get_contents($this->rootFile('oauth_debug.php'));
        $combined = $callback . "\n" . $debug;

        foreach ([
            'contacts.readonly',
            'contacts.write',
            'conversations.readonly',
            'conversations.write',
            'conversations/message.readonly',
            'conversations/message.write',
            'locations.readonly',
            'locations/customFields.readonly',
            'oauth.readonly',
            'oauth.write',
        ] as $scope) {
            $this->assertStringContainsString($scope, $combined);
        }

        foreach ([
            'workflows.readonly',
            'locations/tags.readonly',
            'locations/tags.write',
            'locations/customValues.readonly',
            'locations/customValues.write',
        ] as $scope) {
            $this->assertStringNotContainsString($scope, $combined);
        }
    }

    public function test_support_ticket_submission_dispatches_admin_and_workflow_notifications(): void
    {
        $tickets = file_get_contents($this->rootFile('api/tickets.php'));
        $notifications = file_get_contents($this->rootFile('api/services/NotificationService.php'));

        $this->assertStringContainsString('NotificationService::createAdminNotification', $tickets);
        $this->assertStringContainsString('NotificationService::notifySupportTicketSubmitted', $tickets);
        $this->assertStringContainsString('public static function notifySupportTicketSubmitted', $notifications);
        $this->assertStringContainsString('NOLA_ALERT_SUPPORT_TICKET_TAG', $notifications);
        $this->assertStringContainsString('nola-support-ticket-alert', $notifications);
    }

    public function test_registration_blocks_second_complete_user_for_same_subaccount(): void
    {
        $source = file_get_contents($this->rootFile('api/auth/register_from_install.php'));

        $this->assertStringContainsString('function register_from_install_find_user_for_location', $source);
        $this->assertStringContainsString("['active_location_id', \$locationId]", $source);
        $this->assertStringContainsString("['location_id', \$locationId]", $source);
        $this->assertStringContainsString("['ghl_token_ref', 'ghl_tokens/' . \$locationId]", $source);
        $this->assertStringContainsString('register_from_install_user_is_incomplete', $source);
        $this->assertStringContainsString('register_from_install_location_conflict((string)$locationId);', $source);
    }

    public function test_registration_activates_install_before_issuing_jwt(): void
    {
        $source = file_get_contents($this->rootFile('api/auth/register_from_install.php'));

        $activation = strpos($source, 'install_finalize_registered_location_fast($db, (string)$locationId');
        $jwt = strpos($source, "'sub' => \$newUserId", $activation);
        $response = strpos($source, "'message' => 'Account ready.'", $activation);

        $this->assertNotFalse($activation);
        $this->assertNotFalse($jwt);
        $this->assertNotFalse($response);
        $this->assertLessThan($jwt, $activation);
        $this->assertLessThan($response, $activation);
        $this->assertStringContainsString("'active' => false", $source);
        $this->assertStringContainsString("'activation_state' => 'pending'", $source);
    }

    public function test_pending_install_reconciliation_is_dry_run_by_default(): void
    {
        $source = file_get_contents($this->rootFile('scripts/reconcile_pending_installs.php'));

        $this->assertStringContainsString("pending_reconcile_arg('apply', false)", $source);
        $this->assertStringContainsString('WOULD_EXPIRE', $source);
        $this->assertStringContainsString('INSTALL_STATE_ONBOARDING_EXPIRED', $source);
        $this->assertStringContainsString("'access_token' => null", $source);
        $this->assertStringContainsString("'refresh_token' => null", $source);
    }
}
