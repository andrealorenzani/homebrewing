<?php

declare(strict_types=1);

namespace Tests\Unit\Db;

use App\Db\FakeDb;
use Tests\TestCase;

final class FakeDbTest extends TestCase
{
    public function testExecuteInsertAppendsRowWithParams(): void
    {
        $db = new FakeDb();

        $db->execute('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', ['0001_x', '2026-01-01 00:00:00']);

        $this->assertSame(
            [['id' => 1, 'version' => '0001_x', 'applied_at' => '2026-01-01 00:00:00']],
            $db->getTable('schema_migrations'),
        );
        $this->assertSame('1', $db->lastInsertId());
    }

    public function testExecuteInsertWithoutParamsUsesLiteralValues(): void
    {
        $db = new FakeDb();

        $db->execute("INSERT INTO users (username, email) VALUES ('alice', 'alice@example.com')");

        $this->assertSame(
            [['id' => 1, 'username' => 'alice', 'email' => 'alice@example.com']],
            $db->getTable('users'),
        );
    }

    public function testExecuteInsertAssignsIncrementingIdsPerTable(): void
    {
        $db = new FakeDb();

        $db->execute('INSERT INTO users (username) VALUES (?)', ['alice']);
        $db->execute('INSERT INTO users (username) VALUES (?)', ['bob']);
        $db->execute('INSERT INTO recipes (name) VALUES (?)', ['Mead']);

        $this->assertSame(1, $db->getTable('users')[0]['id']);
        $this->assertSame(2, $db->getTable('users')[1]['id']);
        $this->assertSame(1, $db->getTable('recipes')[0]['id']);
    }

    public function testExecuteInsertRespectsExplicitIdColumn(): void
    {
        $db = new FakeDb();

        $db->execute('INSERT INTO users (id, username) VALUES (?, ?)', [42, 'alice']);

        $this->assertSame(['id' => 42, 'username' => 'alice'], $db->getTable('users')[0]);
        $this->assertSame('42', $db->lastInsertId());
    }

    public function testFetchAllFiltersBySingleWhereCondition(): void
    {
        $db = new FakeDb();
        $db->seedTable('users', [
            ['id' => 1, 'username' => 'alice'],
            ['id' => 2, 'username' => 'bob'],
        ]);

        $rows = $db->fetchAll('SELECT * FROM users WHERE username = ?', ['bob']);

        $this->assertSame([['id' => 2, 'username' => 'bob']], $rows);
    }

    public function testFetchAllFiltersByMultipleAndedWhereConditions(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipes', [
            ['id' => 1, 'user_id' => 1, 'is_public' => 1],
            ['id' => 2, 'user_id' => 1, 'is_public' => 0],
            ['id' => 3, 'user_id' => 2, 'is_public' => 1],
        ]);

        $rows = $db->fetchAll('SELECT * FROM recipes WHERE user_id = ? AND is_public = ?', [1, 1]);

        $this->assertSame([['id' => 1, 'user_id' => 1, 'is_public' => 1]], $rows);
    }

    public function testFetchAllSupportsWhereCombinedWithOrderBy(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipe_ingredients', [
            ['id' => 1, 'recipe_id' => 1, 'sort_order' => 1],
            ['id' => 2, 'recipe_id' => 2, 'sort_order' => 0],
            ['id' => 3, 'recipe_id' => 1, 'sort_order' => 0],
        ]);

        $rows = $db->fetchAll(
            'SELECT * FROM recipe_ingredients WHERE recipe_id = ? ORDER BY sort_order ASC',
            [1],
        );

        $this->assertSame([3, 1], array_column($rows, 'id'));
    }

    public function testFetchAllSupportsWhereOrderByAndLimitTogether(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipes', [
            ['id' => 1, 'is_public' => 1, 'updated_at' => '2026-01-01'],
            ['id' => 2, 'is_public' => 0, 'updated_at' => '2026-01-05'],
            ['id' => 3, 'is_public' => 1, 'updated_at' => '2026-01-03'],
            ['id' => 4, 'is_public' => 1, 'updated_at' => '2026-01-02'],
        ]);

        $rows = $db->fetchAll(
            'SELECT * FROM recipes WHERE is_public = ? ORDER BY updated_at DESC LIMIT ?',
            [1, 2],
        );

        $this->assertSame([3, 4], array_column($rows, 'id'));
    }

    public function testFetchAllSupportsOrderByWithLimitAndNoWhere(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipes', [
            ['id' => 1, 'updated_at' => '2026-01-01'],
            ['id' => 2, 'updated_at' => '2026-01-03'],
            ['id' => 3, 'updated_at' => '2026-01-02'],
        ]);

        $rows = $db->fetchAll('SELECT * FROM recipes ORDER BY updated_at DESC LIMIT 1');

        $this->assertSame([2], array_column($rows, 'id'));
    }

    public function testFetchAllThrowsWhenWhereParamCountMismatches(): void
    {
        $db = new FakeDb();

        $this->expectException(\RuntimeException::class);
        $db->fetchAll('SELECT * FROM users WHERE username = ? AND id = ?', ['alice']);
    }

    public function testFetchAllThrowsForUnsupportedWhereShape(): void
    {
        $db = new FakeDb();

        $this->expectException(\RuntimeException::class);
        $db->fetchAll('SELECT * FROM users WHERE username = ? OR id = ?', ['alice', 1]);
    }

    public function testExecuteUpdateModifiesMatchingRowOnly(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipes', [
            ['id' => 1, 'name' => 'Old Name', 'is_public' => 0],
            ['id' => 2, 'name' => 'Other', 'is_public' => 0],
        ]);

        $db->execute('UPDATE recipes SET name = ?, is_public = ? WHERE id = ?', ['New Name', 1, 1]);

        $this->assertSame(
            [
                ['id' => 1, 'name' => 'New Name', 'is_public' => 1],
                ['id' => 2, 'name' => 'Other', 'is_public' => 0],
            ],
            $db->getTable('recipes'),
        );
    }

    public function testExecuteUpdateThrowsOnParamCountMismatch(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipes', [['id' => 1, 'name' => 'Old']]);

        $this->expectException(\RuntimeException::class);
        $db->execute('UPDATE recipes SET name = ? WHERE id = ?', ['New']);
    }

    public function testExecuteDeleteRemovesMatchingRowsOnly(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipe_ingredients', [
            ['id' => 1, 'recipe_id' => 1],
            ['id' => 2, 'recipe_id' => 2],
            ['id' => 3, 'recipe_id' => 1],
        ]);

        $db->execute('DELETE FROM recipe_ingredients WHERE recipe_id = ?', [1]);

        $this->assertSame([['id' => 2, 'recipe_id' => 2]], $db->getTable('recipe_ingredients'));
    }

    public function testExecuteDeleteWithoutWhereClearsTable(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipe_ingredients', [['id' => 1, 'recipe_id' => 1]]);

        $db->execute('DELETE FROM recipe_ingredients');

        $this->assertSame([], $db->getTable('recipe_ingredients'));
    }

    public function testExecuteDeleteThrowsForUnsupportedWhereShape(): void
    {
        $db = new FakeDb();
        $db->seedTable('users', [['id' => 1]]);

        $this->expectException(\RuntimeException::class);
        $db->execute('DELETE FROM users WHERE id > ?', [1]);
    }

    public function testExecuteOfNonMatchingStatementIsNoOp(): void
    {
        $db = new FakeDb();

        $db->execute('CREATE TABLE IF NOT EXISTS schema_migrations (id INT)');

        $this->assertSame([], $db->getTable('schema_migrations'));
        $this->assertCount(1, $db->getExecutedStatements());
    }

    public function testFetchAllReturnsSeededRows(): void
    {
        $db = new FakeDb();
        $db->seedTable('schema_migrations', [
            ['version' => '0002_x', 'applied_at' => '2026-01-02'],
            ['version' => '0001_x', 'applied_at' => '2026-01-01'],
        ]);

        $rows = $db->fetchAll('SELECT * FROM schema_migrations');

        $this->assertCount(2, $rows);
    }

    public function testFetchAllOrdersByGivenColumnAscending(): void
    {
        $db = new FakeDb();
        $db->seedTable('schema_migrations', [
            ['version' => '0002_x'],
            ['version' => '0001_x'],
        ]);

        $rows = $db->fetchAll('SELECT version FROM schema_migrations ORDER BY version ASC');

        $this->assertSame(['0001_x', '0002_x'], array_column($rows, 'version'));
    }

    public function testFetchAllOrdersByGivenColumnDescending(): void
    {
        $db = new FakeDb();
        $db->seedTable('schema_migrations', [
            ['version' => '0001_x'],
            ['version' => '0002_x'],
        ]);

        $rows = $db->fetchAll('SELECT version FROM schema_migrations ORDER BY version DESC');

        $this->assertSame(['0002_x', '0001_x'], array_column($rows, 'version'));
    }

    public function testFetchAllProjectsRequestedColumnsOnly(): void
    {
        $db = new FakeDb();
        $db->seedTable('users', [['id' => 1, 'username' => 'alice', 'email' => 'a@example.com']]);

        $rows = $db->fetchAll('SELECT username FROM users');

        $this->assertSame([['username' => 'alice']], $rows);
    }

    public function testFetchAllOnEmptyTableReturnsEmptyArray(): void
    {
        $db = new FakeDb();

        $this->assertSame([], $db->fetchAll('SELECT * FROM users'));
    }

    public function testFetchAllThrowsForUnsupportedQueryShape(): void
    {
        $db = new FakeDb();

        $this->expectException(\RuntimeException::class);
        $db->fetchAll('SELECT * FROM users WHERE id = 1');
    }

    public function testFetchOneReturnsFirstRowOrNull(): void
    {
        $db = new FakeDb();
        $db->seedTable('users', [['id' => 1], ['id' => 2]]);

        $this->assertSame(['id' => 1], $db->fetchOne('SELECT * FROM users'));
        $this->assertNull((new FakeDb())->fetchOne('SELECT * FROM users'));
    }

    public function testFetchAllThrowsWhenStatementIsNotASelect(): void
    {
        $db = new FakeDb();

        $this->expectException(\RuntimeException::class);
        $db->fetchAll('SHOW TABLES');
    }

    public function testFetchAllThrowsForUnsupportedTrailingClause(): void
    {
        $db = new FakeDb();

        $this->expectException(\RuntimeException::class);
        $db->fetchAll('SELECT * FROM users GROUP BY username');
    }

    public function testExecuteUpdateThrowsForUnsupportedSetClauseShape(): void
    {
        $db = new FakeDb();
        $db->seedTable('users', [['id' => 1, 'a' => 'x']]);

        $this->expectException(\RuntimeException::class);
        $db->execute('UPDATE users SET a = 1 WHERE id = ?', [1]);
    }

    public function testMatchesWhereReturnsFalseWhenRowIsMissingTheColumn(): void
    {
        $db = new FakeDb();
        $db->seedTable('users', [['id' => 1]]);

        $rows = $db->fetchAll('SELECT * FROM users WHERE username = ?', ['alice']);

        $this->assertSame([], $rows);
    }

    public function testTransactionMethodsTrackState(): void
    {
        $db = new FakeDb();

        $this->assertFalse($db->inTransaction());

        $db->beginTransaction();
        $this->assertTrue($db->inTransaction());

        $db->commit();
        $this->assertFalse($db->inTransaction());

        $db->beginTransaction();
        $db->rollBack();
        $this->assertFalse($db->inTransaction());
    }

    public function testGetExecutedStatementsRecordsSqlAndParams(): void
    {
        $db = new FakeDb();
        $db->execute('INSERT INTO t (a) VALUES (?)', ['x']);
        $db->fetchAll('SELECT * FROM t');

        $statements = $db->getExecutedStatements();

        $this->assertCount(2, $statements);
        $this->assertSame('INSERT INTO t (a) VALUES (?)', $statements[0]['sql']);
        $this->assertSame(['x'], $statements[0]['params']);
    }

    public function testGetTableOnUnknownTableReturnsEmptyArray(): void
    {
        $db = new FakeDb();

        $this->assertSame([], $db->getTable('never_seeded'));
    }
}
