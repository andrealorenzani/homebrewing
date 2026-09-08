<?php

declare(strict_types=1);

namespace Tests\Unit\Db;

use App\Db\DbInterface;
use App\Db\FakeDb;
use App\Db\IncompatibleMigrationsTableException;
use App\Db\MigrationRunner;
use App\Db\PdoDb;
use PDO;
use Tests\TestCase;

final class MigrationRunnerTest extends TestCase
{
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->migrationsDir = sys_get_temp_dir() . '/migrations_test_' . uniqid('', true);
        mkdir($this->migrationsDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsDir . '/*.sql') ?: [] as $path) {
            is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
        }
        @rmdir($this->migrationsDir);
    }

    private function writeMigration(string $name, string $sql): void
    {
        file_put_contents($this->migrationsDir . '/' . $name . '.sql', $sql);
    }

    public function testEnsureMigrationsTableExistsIssuesCreateStatement(): void
    {
        $db = new FakeDb();
        $runner = new MigrationRunner($db, $this->migrationsDir);

        $runner->ensureMigrationsTableExists();

        $statements = $db->getExecutedStatements();
        $this->assertCount(1, $statements);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS schema_migrations', $statements[0]['sql']);
    }

    public function testAppliedVersionsReflectsSeededRows(): void
    {
        $db = new FakeDb();
        $db->seedTable('schema_migrations', [
            ['version' => '0002_b', 'applied_at' => '2026-01-02'],
            ['version' => '0001_a', 'applied_at' => '2026-01-01'],
        ]);
        $runner = new MigrationRunner($db, $this->migrationsDir);

        $this->assertSame(['0001_a', '0002_b'], $runner->appliedVersions());
    }

    public function testAppliedVersionsEmptyWhenTableEmpty(): void
    {
        $db = new FakeDb();
        $runner = new MigrationRunner($db, $this->migrationsDir);

        $this->assertSame([], $runner->appliedVersions());
    }

    public function testPendingMigrationsExcludesAlreadyAppliedAndOrdersByFilename(): void
    {
        $this->writeMigration('0002_create_recipes_table', 'CREATE TABLE recipes (id INT);');
        $this->writeMigration('0001_create_users_table', 'CREATE TABLE users (id INT);');
        $this->writeMigration('0003_create_batches_table', 'CREATE TABLE batches (id INT);');

        $db = new FakeDb();
        $db->seedTable('schema_migrations', [['version' => '0001_create_users_table']]);
        $runner = new MigrationRunner($db, $this->migrationsDir);

        $this->assertSame(
            ['0002_create_recipes_table', '0003_create_batches_table'],
            $runner->pendingMigrations(),
        );
    }

    public function testPendingMigrationsEmptyWhenNoFiles(): void
    {
        $runner = new MigrationRunner(new FakeDb(), $this->migrationsDir);

        $this->assertSame([], $runner->pendingMigrations());
    }

    public function testMigrateAppliesPendingMigrationsInOrderAndRecordsThem(): void
    {
        $this->writeMigration('0002_create_recipes_table', 'CREATE TABLE recipes (id INT);');
        $this->writeMigration('0001_create_users_table', 'CREATE TABLE users (id INT);');

        $db = new FakeDb();
        $runner = new MigrationRunner($db, $this->migrationsDir);

        $applied = $runner->migrate();

        $this->assertSame(['0001_create_users_table', '0002_create_recipes_table'], $applied);
        $this->assertSame(['0001_create_users_table', '0002_create_recipes_table'], $runner->appliedVersions());
    }

    public function testMigrateIsIdempotentOnSecondRun(): void
    {
        $this->writeMigration('0001_create_users_table', 'CREATE TABLE users (id INT);');

        $db = new FakeDb();
        $runner = new MigrationRunner($db, $this->migrationsDir);

        $firstRun = $runner->migrate();
        $secondRun = $runner->migrate();

        $this->assertSame(['0001_create_users_table'], $firstRun);
        $this->assertSame([], $secondRun);
    }

    public function testMigrateSplitsMultiStatementFilesOnSemicolons(): void
    {
        $this->writeMigration(
            '0001_multi',
            "CREATE TABLE a (id INT);\nCREATE TABLE b (id INT);\n",
        );

        $db = new FakeDb();
        $runner = new MigrationRunner($db, $this->migrationsDir);
        $runner->migrate();

        $ddlStatements = array_filter(
            $db->getExecutedStatements(),
            static fn (array $s): bool => str_starts_with($s['sql'], 'CREATE TABLE a') || str_starts_with($s['sql'], 'CREATE TABLE b'),
        );

        $this->assertCount(2, $ddlStatements);
    }

    public function testMigrateThrowsWhenMigrationFileUnreadable(): void
    {
        // glob('*.sql') matches broken symlinks too (the directory entry
        // exists even though its target doesn't), so a dangling symlink
        // reliably makes file_get_contents() return false regardless of
        // the OS user's file permissions (which root inside a container
        // would otherwise bypass for a plain chmod-000 file).
        symlink(
            $this->migrationsDir . '/does-not-exist-target',
            $this->migrationsDir . '/0001_broken_symlink.sql',
        );

        $db = new FakeDb();
        $runner = new MigrationRunner($db, $this->migrationsDir);

        $this->expectException(\RuntimeException::class);
        @$runner->migrate();
    }

    public function testMigrateStripsLineCommentsContainingSemicolonsBeforeSplitting(): void
    {
        // Regression test: a `--` comment line containing a semicolon
        // (e.g. "owner has full access; other users...") must not be
        // mistaken for a statement terminator and split a single CREATE
        // TABLE statement in half. Discovered when V4 ran migrations
        // against real MySQL: db/migrations/0003_create_recipes_table.sql
        // has exactly this shape in its header comment.
        $this->writeMigration(
            '0001_commented',
            "-- some comment; with a semicolon inside it\n" .
            "-- another line; also with one\n" .
            "CREATE TABLE recipes (id INT);\n",
        );

        $db = new FakeDb();
        $runner = new MigrationRunner($db, $this->migrationsDir);
        $runner->migrate();

        $ddlStatements = array_filter(
            $db->getExecutedStatements(),
            static fn (array $s): bool => str_starts_with(trim($s['sql']), 'CREATE TABLE recipes'),
        );

        $this->assertCount(1, $ddlStatements);
        $this->assertSame('CREATE TABLE recipes (id INT)', trim(reset($ddlStatements)['sql']));
    }

    public function testRealMigrationFilesApplyCleanlyAgainstFakeDb(): void
    {
        $realMigrationsDir = dirname(__DIR__, 3) . '/db/migrations';
        $db = new FakeDb();
        $runner = new MigrationRunner($db, $realMigrationsDir);

        $applied = $runner->migrate();

        $this->assertSame([
            '0001_create_schema_migrations_table',
            '0002_create_users_table',
            '0003_create_recipes_table',
            '0004_create_recipe_ingredients_table',
            '0005_create_batches_table',
            '0006_create_batch_log_entries_table',
        ], $applied);

        // Running again against the now-populated schema_migrations table
        // (seeded via the migration's own INSERT statements) is a no-op.
        $this->assertSame([], $runner->migrate());
    }

    public function testAppliedVersionsThrowsIncompatibleMigrationsTableExceptionOnIncompatibleTable(): void
    {
        // Regression test for a real production incident: a pre-existing
        // `schema_migrations` table from another application, sharing the
        // same database, that has an incompatible structure (here, missing
        // the `version` column entirely) makes MySQL raise "Unknown column
        // 'version' in 'field list'". Reproduced here with a real SQLite
        // in-memory PdoDb so the underlying exception is a genuine
        // \PDOException, not a stand-in, matching PdoDbTest.php's
        // precedent for real-PDO-exception-shaped tests.
        $pdo = new PDO('sqlite::memory:');
        $db = new PdoDb($pdo);
        $db->execute('CREATE TABLE schema_migrations (id INTEGER PRIMARY KEY, name TEXT)');
        $runner = new MigrationRunner($db, $this->migrationsDir);

        try {
            $runner->appliedVersions();
            $this->fail('Expected IncompatibleMigrationsTableException to be thrown.');
        } catch (IncompatibleMigrationsTableException $e) {
            $this->assertStringContainsString('dedicated database', $e->getMessage());
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    public function testAppliedVersionsWrapsAnyPdoExceptionRegardlessOfDbImplementation(): void
    {
        // Secondary, isolation-focused test against a pure DbInterface
        // double (precedented in RouterTest.php's anonymous-class doubles)
        // rather than SQLite specifics, so the wrapping behavior is proven
        // independently of any particular driver's error message wording.
        $runner = new MigrationRunner($this->makeDbThrowingOnFetchAll(), $this->migrationsDir);

        try {
            $runner->appliedVersions();
            $this->fail('Expected IncompatibleMigrationsTableException to be thrown.');
        } catch (IncompatibleMigrationsTableException $e) {
            $this->assertStringContainsString('incompatible structure', $e->getMessage());
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    public function testMigrateThrowsIncompatibleMigrationsTableExceptionOnIncompatibleTable(): void
    {
        // migrate() calls ensureMigrationsTableExists() first (a no-op on
        // this double's execute()), then funnels through
        // pendingMigrations() -> appliedVersions(), where the failure
        // surfaces. Uses the DbInterface double rather than SQLite because
        // ensureMigrationsTableExists()'s MySQL-specific DDL (AUTO_INCREMENT,
        // ENGINE=InnoDB) isn't valid SQLite syntax and would fail there
        // regardless of the schema_migrations table's pre-existing shape.
        $runner = new MigrationRunner($this->makeDbThrowingOnFetchAll(), $this->migrationsDir);

        $this->expectException(IncompatibleMigrationsTableException::class);
        $runner->migrate();
    }

    private function makeDbThrowingOnFetchAll(): DbInterface
    {
        return new class implements DbInterface {
            public function fetchAll(string $sql, array $params = []): array
            {
                throw new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'version' in 'field list'");
            }

            public function fetchOne(string $sql, array $params = []): ?array
            {
                return null;
            }

            public function execute(string $sql, array $params = []): void
            {
            }

            public function lastInsertId(): string
            {
                return '0';
            }

            public function inTransaction(): bool
            {
                return false;
            }

            public function beginTransaction(): void
            {
            }

            public function commit(): void
            {
            }

            public function rollBack(): void
            {
            }
        };
    }
}
