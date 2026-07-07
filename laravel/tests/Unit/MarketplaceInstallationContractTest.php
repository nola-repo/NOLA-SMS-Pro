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
}
