<?php
// Bootstrap Laravel while preserving the incoming /api/v2/* request path.
define('LARAVEL_START', microtime(true));

require __DIR__ . '/../../laravel/vendor/autoload.php';

$app = require_once __DIR__ . '/../../laravel/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
)->send();

$kernel->terminate($request, $response);
