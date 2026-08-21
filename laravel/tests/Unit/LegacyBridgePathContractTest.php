<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LegacyBridgePathContractTest extends TestCase
{
    private function rootPath(string $path = ''): string
    {
        $root = dirname(__DIR__, 3);

        if ($path === '') {
            return $root;
        }

        return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    public function test_laravel_legacy_bridge_targets_exist(): void
    {
        $controllers = glob($this->rootPath('laravel/app/Http/Controllers/*LegacyBridgeController.php'));
        $missing = [];

        foreach ($controllers as $controller) {
            $source = file_get_contents($controller);
            preg_match_all("/base_path\\('\\.\\.\\/([^']+)'\\)/", $source, $matches);

            foreach ($matches[1] as $target) {
                if (!file_exists($this->rootPath($target))) {
                    $missing[] = str_replace($this->rootPath() . DIRECTORY_SEPARATOR, '', $controller) . ' -> ' . $target;
                }
            }
        }

        $this->assertSame([], $missing);
    }
}
