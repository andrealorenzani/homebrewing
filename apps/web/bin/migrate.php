#!/usr/bin/env php
<?php

declare(strict_types=1);

// CLI migration runner. On shared hosting without a persistent shell, this
// can be invoked via a cron job (`php /path/to/apps/web/bin/migrate.php`)
// or a one-off call through the hosting control panel's PHP CLI runner.
//
// Usage:
//   php bin/migrate.php            # applies pending migrations against .env
//   APP_ENV=testing php bin/migrate.php   # applies against .env.testing

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Config;
use App\Db\MigrationRunner;
use App\Db\PdoDb;

$isTesting = getenv('APP_ENV') === 'testing';
$envFile = $isTesting ? __DIR__ . '/../.env.testing' : __DIR__ . '/../.env';

if (!is_file($envFile)) {
    fwrite(STDERR, "Config file not found: {$envFile}\n");
    fwrite(STDERR, "Copy .env.example to " . basename($envFile) . " and fill in real values first.\n");
    exit(1);
}

$config = Config::fromEnvFile($envFile);

try {
    $db = PdoDb::fromConfig($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database: {$e->getMessage()}\n");
    exit(1);
}

$runner = new MigrationRunner($db, __DIR__ . '/../db/migrations');
$applied = $runner->migrate();

if ($applied === []) {
    fwrite(STDOUT, "No pending migrations.\n");
    exit(0);
}

foreach ($applied as $version) {
    fwrite(STDOUT, "Applied: {$version}\n");
}

exit(0);
