<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class LegacyPhpBridgeService
{
    public function call(string $legacyScriptPath, string $method, array $query = [], string $rawBody = '', array $headers = []): array
    {
        $bridgeScript = base_path('bootstrap/legacy_bridge.php');
        if ($headers === [] && function_exists('request')) {
            $headers = request()->headers->all();
        }

        $queryJson = json_encode($query);
        $headersJson = json_encode($headers);

        $env = array_merge(
            is_array(getenv()) ? getenv() : [],
            $_ENV ?? [],
            $_SERVER ?? []
        );

        $process = new Process([
            PHP_BINARY,
            $bridgeScript,
            $legacyScriptPath,
            strtoupper($method),
            $queryJson === false ? '{}' : $queryJson,
            $headersJson === false ? '{}' : $headersJson,
        ], base_path('..'), $env);

        $process->setInput($rawBody);
        $process->run();

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        $statusCode = 200;
        if (preg_match('/__BRIDGE_STATUS__(\d{3})/', $stderr, $matches) === 1) {
            $statusCode = (int) $matches[1];
        } elseif ($process->getExitCode() !== 0) {
            $statusCode = 500;
        }

        return [
            'status' => $statusCode,
            'body' => $stdout,
        ];
    }
}
