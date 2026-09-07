<?php

declare(strict_types=1);

namespace Tests\Unit\Db;

use App\Config\Config;
use App\Db\PdoDb;
use PDO;
use Tests\TestCase;

/**
 * PdoDb just delegates to PDO, so its CRUD/transaction behavior is
 * exercised here against a SQLite in-memory connection (dialect-agnostic
 * wrapper logic); MySQL-dialect DSN construction is tested separately via
 * buildDsn() without opening a real connection.
 */
final class PdoDbTest extends TestCase
{
    private function makeDb(): PdoDb
    {
        $pdo = new PDO('sqlite::memory:');
        $db = new PdoDb($pdo);
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        return $db;
    }

    public function testBuildDsnBuildsMysqlDsnFromConfig(): void
    {
        $config = Config::fromArray([
            'DB_HOST' => 'db.example.com',
            'DB_PORT' => '3307',
            'DB_NAME' => 'homebrewing_dev',
            'DB_CHARSET' => 'utf8mb4',
        ]);

        $dsn = PdoDb::buildDsn($config);

        $this->assertSame('mysql:host=db.example.com;port=3307;dbname=homebrewing_dev;charset=utf8mb4', $dsn);
    }

    public function testBuildDsnUsesDefaultsWhenMissing(): void
    {
        $dsn = PdoDb::buildDsn(Config::fromArray([]));

        $this->assertSame('mysql:host=localhost;port=3306;dbname=;charset=utf8mb4', $dsn);
    }

    public function testFromConfigAttemptsAMysqlConnectionAndSurfacesFailure(): void
    {
        // No live MySQL/MariaDB is reachable in this unit-test environment
        // (that's provisioned in a later task) — this proves fromConfig()
        // actually wires buildDsn() into a real PDO connection attempt
        // (mysql driver, given credentials) rather than exercising a live
        // server, which integration tests will cover once one exists.
        $config = Config::fromArray([
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_NAME' => 'homebrewing_test',
            'DB_USER' => 'nobody',
            'DB_PASS' => 'nopass',
        ]);

        $this->expectException(\PDOException::class);
        PdoDb::fromConfig($config);
    }

    public function testExecuteAndFetchAllRoundTrip(): void
    {
        $db = $this->makeDb();

        $db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);
        $db->execute('INSERT INTO users (name) VALUES (?)', ['Bob']);

        $rows = $db->fetchAll('SELECT name FROM users ORDER BY name ASC');

        $this->assertSame([['name' => 'Alice'], ['name' => 'Bob']], $rows);
    }

    public function testFetchOneReturnsSingleRowOrNull(): void
    {
        $db = $this->makeDb();
        $db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);

        $this->assertSame(['id' => 1, 'name' => 'Alice'], $db->fetchOne('SELECT * FROM users WHERE id = ?', [1]));
        $this->assertNull($db->fetchOne('SELECT * FROM users WHERE id = ?', [999]));
    }

    public function testLastInsertIdIncrements(): void
    {
        $db = $this->makeDb();
        $db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);

        $this->assertSame('1', $db->lastInsertId());

        $db->execute('INSERT INTO users (name) VALUES (?)', ['Bob']);
        $this->assertSame('2', $db->lastInsertId());
    }

    public function testTransactionsCommit(): void
    {
        $db = $this->makeDb();

        $this->assertFalse($db->inTransaction());

        $db->beginTransaction();
        $this->assertTrue($db->inTransaction());
        $db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);
        $db->commit();

        $this->assertFalse($db->inTransaction());
        $this->assertCount(1, $db->fetchAll('SELECT * FROM users'));
    }

    public function testTransactionsRollBack(): void
    {
        $db = $this->makeDb();

        $db->beginTransaction();
        $db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);
        $db->rollBack();

        $this->assertFalse($db->inTransaction());
        $this->assertCount(0, $db->fetchAll('SELECT * FROM users'));
    }
}
