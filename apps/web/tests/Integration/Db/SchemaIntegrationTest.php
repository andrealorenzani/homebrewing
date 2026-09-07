<?php

declare(strict_types=1);

namespace Tests\Integration\Db;

use Tests\Integration\IntegrationTestCase;

/**
 * Verifies the six domain tables + schema_migrations exist with the
 * expected `is_public` visibility columns, against the real Dockerized
 * MySQL test database (task V4) after `bin/migrate.php` has been run
 * against it from empty. This deliberately exercises live MySQL (via
 * App\Db\PdoDb + a real PDO connection) rather than the FakeDb double
 * used by V3's unit tests, to catch real-dialect issues FakeDb can't.
 */
final class SchemaIntegrationTest extends IntegrationTestCase
{
    private const EXPECTED_TABLES = [
        'batch_log_entries',
        'batches',
        'recipe_ingredients',
        'recipes',
        'schema_migrations',
        'users',
    ];

    private const EXPECTED_MIGRATIONS = [
        '0001_create_schema_migrations_table',
        '0002_create_users_table',
        '0003_create_recipes_table',
        '0004_create_recipe_ingredients_table',
        '0005_create_batches_table',
        '0006_create_batch_log_entries_table',
    ];

    public function testAllExpectedTablesExist(): void
    {
        $rows = self::db()->fetchAll('SHOW TABLES');
        $tables = array_map(static fn (array $row): string => (string) array_values($row)[0], $rows);
        sort($tables);

        $this->assertSame(self::EXPECTED_TABLES, $tables);
    }

    public function testRecipesHasNotNullBooleanIsPublicColumnDefaultingToFalse(): void
    {
        $this->assertIsPublicColumnShape('recipes');
    }

    public function testBatchesHasNotNullBooleanIsPublicColumnDefaultingToFalse(): void
    {
        $this->assertIsPublicColumnShape('batches');
    }

    public function testSchemaMigrationsTableRecordsAllSixAppliedMigrations(): void
    {
        $rows = self::db()->fetchAll('SELECT version FROM schema_migrations ORDER BY version ASC');
        $versions = array_map(static fn (array $row): string => (string) $row['version'], $rows);

        $this->assertSame(self::EXPECTED_MIGRATIONS, $versions);
    }

    private function assertIsPublicColumnShape(string $table): void
    {
        $column = self::db()->fetchOne(
            'SELECT IS_NULLABLE, DATA_TYPE, COLUMN_DEFAULT FROM information_schema.COLUMNS ' .
            'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'is_public'],
        );

        $this->assertNotNull($column, "Expected column {$table}.is_public to exist");
        $this->assertSame('NO', $column['IS_NULLABLE'], "{$table}.is_public should be NOT NULL");
        // MySQL 8.0 has no distinct BOOLEAN storage type; BOOLEAN/BOOL columns
        // report as tinyint(1) under the hood — this is the correct/expected shape.
        $this->assertSame('tinyint', $column['DATA_TYPE'], "{$table}.is_public should be a tinyint (BOOLEAN)");
        $this->assertSame('0', $column['COLUMN_DEFAULT'], "{$table}.is_public should default to 0 (private)");
    }
}
