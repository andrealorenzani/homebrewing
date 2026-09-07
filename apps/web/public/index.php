<?php

declare(strict_types=1);

// Front controller: every request is routed through here (configure the
// webserver's document root at apps/web/public and, for Apache/shared
// hosting without pretty-URL rewriting configured yet, rely on the
// PHP built-in server or a future .htaccess rewrite to funnel requests
// here).

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Config;
use App\Http\Request;
use App\Kernel;

$envFile = __DIR__ . '/../.env';

if (!is_file($envFile)) {
    // Local/dev convenience fallback so the app boots even before a real
    // .env has been created; .env.example never contains real secrets.
    $envFile = __DIR__ . '/../.env.example';
}

$config = Config::fromEnvFile($envFile);
$kernel = new Kernel($config);
$request = Request::fromGlobals();
$response = $kernel->handle($request);
$response->send();
