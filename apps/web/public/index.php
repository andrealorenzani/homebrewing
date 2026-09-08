<?php

declare(strict_types=1);

// Front controller: every request is routed through here (configure the
// webserver's document root at apps/web/public). On Apache/shared hosting,
// the committed .htaccess in this directory funnels every request that
// isn't a real file/directory through this script (requires mod_rewrite
// and AllowOverride to be enabled — see the README's deployment section).
// PHP's built-in development server (`php -S`) needs no such file: it has
// its own built-in fallback-to-script behavior and ignores .htaccess
// entirely.

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
