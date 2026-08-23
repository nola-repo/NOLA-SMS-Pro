<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HtaccessContractTest extends TestCase
{
    private function rootFile(string $path): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    public function test_rewrite_rules_keep_required_arguments_separated(): void
    {
        $source = file_get_contents($this->rootFile('.htaccess'));
        $lines = preg_split('/\R/', $source);

        foreach ($lines as $lineNumber => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_starts_with($trimmed, 'RewriteRule ')) {
                continue;
            }

            $parts = preg_split('/(?<!\\\\)\s+/', $trimmed);

            $this->assertGreaterThanOrEqual(
                3,
                count($parts),
                'Malformed RewriteRule at .htaccess:' . ($lineNumber + 1)
            );

            if (isset($parts[3])) {
                $this->assertMatchesRegularExpression(
                    '/^\[[^\]\s]+\]$/',
                    $parts[3],
                    'Malformed RewriteRule flags at .htaccess:' . ($lineNumber + 1)
                );
            }
        }
    }

    public function test_notification_settings_legacy_route_targets_moved_script(): void
    {
        $source = file_get_contents($this->rootFile('.htaccess'));

        $this->assertStringContainsString(
            'RewriteRule ^api/notification-settings(\.php)?/?$ /api/notifications/notification-settings.php [NC,L,QSA]',
            $source
        );
    }

    public function test_legacy_php_urls_for_moved_scripts_are_still_supported(): void
    {
        $source = file_get_contents($this->rootFile('.htaccess'));

        foreach ([
            'RewriteRule ^api/account(\.php)?/?$              /api/account/account.php',
            'RewriteRule ^api/admin_sender_requests(\.php)?/?$   /api/admin/admin_sender_requests.php',
            'RewriteRule ^api/ghl[-_]contacts(\.php)?/?$ /api/ghl/ghl_contacts.php',
            'RewriteRule ^api/tickets(\.php)?/?$              /api/tickets/tickets.php',
        ] as $rule) {
            $this->assertStringContainsString($rule, $source);
        }
    }

    public function test_api_trailing_slashes_are_not_externally_redirected(): void
    {
        $source = file_get_contents($this->rootFile('.htaccess'));
        $lines = preg_split('/\R/', $source);

        foreach ($lines as $lineNumber => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_starts_with($trimmed, 'RewriteRule ')) {
                continue;
            }

            $this->assertFalse(
                str_contains($trimmed, '^(api/.+)/$') && preg_match('/\[[^\]]*\bR(?:=301)?\b/i', $trimmed) === 1,
                'API trailing slash redirect can leak http://:8080 behind Cloud Run at .htaccess:' . ($lineNumber + 1)
            );
        }
    }
}
