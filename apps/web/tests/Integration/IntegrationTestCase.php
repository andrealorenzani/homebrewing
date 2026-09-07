<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Config;
use App\Db\PdoDb;
use Tests\TestCase;

/**
 * Base class for integration tests that need a real database connection.
 *
 * Connects to the Dockerized `mysql:8.0` test database configured via
 * `.env.testing` (task V4, docs/plans/0002-v1.0.1-release.md:
 * `docker-compose.yml` + `docker/mysql-init/01-init.sql`). If the database
 * is unreachable (e.g. the `homebrewing-mysql` container isn't running),
 * tests are skipped with a clear, actionable message pointing at the fix,
 * instead of failing with a raw PDOException stack trace.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?PdoDb $db = null;

    protected static function db(): PdoDb
    {
        if (self::$db === null) {
            self::$db = self::connect();
        }

        return self::$db;
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            self::db();
        } catch (\Throwable $e) {
            self::markTestSkipped(
                'Could not connect to the integration test database (homebrewing_test). ' .
                'Bring it up with: cd apps/web && docker compose up -d ' .
                '(see docker-compose.yml, task V4). Underlying error: ' . $e->getMessage(),
            );
        }
    }

    private static function connect(): PdoDb
    {
        $envFile = dirname(__DIR__, 2) . '/.env.testing';

        if (!is_file($envFile)) {
            throw new \RuntimeException(
                ".env.testing not found at {$envFile}. Copy .env.example to .env.testing and " .
                'point it at the Dockerized test database (see docker-compose.yml, task V4).',
            );
        }

        $config = Config::fromEnvFile($envFile);

        return PdoDb::fromConfig($config);
    }
}
