<?php

declare(strict_types=1);

namespace App\Db;

/**
 * In-memory DbInterface double for unit tests.
 *
 * This is not a general SQL engine — it recognizes just enough of the
 * shapes real repositories/the migration runner actually emit:
 *   - `INSERT INTO t (...) VALUES (...)`
 *   - `SELECT ... FROM t [WHERE col = ? [AND col = ?]...] [ORDER BY col [ASC|DESC]] [LIMIT ?|n]`
 *   - `UPDATE t SET col = ? [, col = ?]... WHERE col = ? [AND col = ?]...`
 *   - `DELETE FROM t WHERE col = ? [AND col = ?]...` (or with no WHERE at all)
 * WHERE/SET conditions must use `?` placeholders (never literal values) —
 * this keeps the emulator simple and matches how every repository in this
 * codebase actually binds parameters. Any other statement (CREATE TABLE,
 * ALTER TABLE, etc. — i.e. real migration DDL) is a structural no-op.
 * Real SQL/dialect correctness is verified against a live MySQL/MariaDB
 * instance in integration tests, not here.
 *
 * Rows inserted without an explicit `id` column get one auto-assigned
 * (a per-table counter starting at 1), mirroring AUTO_INCREMENT, so
 * repository code can insert a row and immediately look it up by the id
 * returned from lastInsertId() — exactly like it would against real MySQL.
 */
final class FakeDb implements DbInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $tables = [];

    /** @var array<string, int> */
    private array $autoIncrement = [];

    /** @var list<array{sql: string, params: array<int|string, mixed>}> */
    private array $executedStatements = [];

    private int $lastInsertId = 0;

    private bool $inTransaction = false;

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->record($sql, $params);

        $trimmed = rtrim(trim($sql), ';');

        if (preg_match('/^SELECT\s+(.+?)\s+FROM\s+([a-zA-Z0-9_]+)(.*)$/is', $trimmed, $m) !== 1) {
            throw new \RuntimeException("FakeDb cannot emulate query: {$sql}");
        }

        $columnsPart = trim($m[1]);
        $table = $m[2];
        $rest = trim($m[3]);

        [$whereClause, $orderColumn, $orderDirection, $limitToken] = $this->parseSelectTail($rest, $sql);

        $limit = null;

        if ($limitToken === '?') {
            $limit = (int) array_pop($params);
        } elseif ($limitToken !== null) {
            $limit = (int) $limitToken;
        }

        $rows = $this->tables[$table] ?? [];

        if ($whereClause !== null) {
            $whereValues = $this->bindConditions($this->parseConditionColumns($whereClause, $sql), $params, $sql);
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => $this->matchesWhere($row, $whereValues),
            ));
        }

        if ($orderColumn !== null) {
            usort($rows, static function (array $a, array $b) use ($orderColumn, $orderDirection): int {
                $cmp = ($a[$orderColumn] ?? null) <=> ($b[$orderColumn] ?? null);

                return $orderDirection === 'DESC' ? -$cmp : $cmp;
            });
        }

        if ($limit !== null) {
            $rows = array_slice($rows, 0, $limit);
        }

        if ($columnsPart !== '*') {
            $columns = array_map('trim', explode(',', $columnsPart));
            $rows = array_map(
                static fn (array $row): array => array_intersect_key($row, array_flip($columns)),
                $rows,
            );
        }

        return array_values($rows);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($sql, $params);

        return $rows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): void
    {
        $this->record($sql, $params);

        $trimmed = rtrim(trim($sql), ';');

        if (preg_match(
            '/^INSERT\s+INTO\s+([a-zA-Z0-9_]+)\s*\(([^)]+)\)\s+VALUES\s*\(([^)]+)\)$/i',
            $trimmed,
            $m,
        ) === 1) {
            $this->executeInsert($m[1], $m[2], $m[3], $params);

            return;
        }

        if (preg_match('/^UPDATE\s+([a-zA-Z0-9_]+)\s+SET\s+(.+?)\s+WHERE\s+(.+)$/is', $trimmed, $m) === 1) {
            $this->executeUpdate($m[1], $m[2], $m[3], $params, $sql);

            return;
        }

        if (preg_match('/^DELETE\s+FROM\s+([a-zA-Z0-9_]+)(?:\s+WHERE\s+(.+))?$/is', $trimmed, $m) === 1) {
            $this->executeDelete($m[1], $m[2] ?? null, $params, $sql);

            return;
        }

        // Any other statement (CREATE TABLE, ALTER TABLE, etc.) is a
        // structural no-op for this in-memory double — see class docblock.
    }

    public function lastInsertId(): string
    {
        return (string) $this->lastInsertId;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function beginTransaction(): void
    {
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->inTransaction = false;
    }

    public function rollBack(): void
    {
        $this->inTransaction = false;
    }

    // --- Test-only helpers (not part of DbInterface) ---

    /**
     * @return list<array{sql: string, params: array<int|string, mixed>}>
     */
    public function getExecutedStatements(): array
    {
        return $this->executedStatements;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTable(string $name): array
    {
        return $this->tables[$name] ?? [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function seedTable(string $name, array $rows): void
    {
        $this->tables[$name] = $rows;
    }

    private function record(string $sql, array $params): void
    {
        $this->executedStatements[] = ['sql' => $sql, 'params' => $params];
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function executeInsert(string $table, string $columnList, string $valueList, array $params): void
    {
        $columns = array_map('trim', explode(',', $columnList));

        if ($params !== []) {
            $values = array_values($params);
        } else {
            $values = array_map(
                static fn (string $v): string => trim($v, " '\""),
                explode(',', $valueList),
            );
        }

        $row = array_combine($columns, $values);

        if (array_key_exists('id', $row)) {
            $newId = $row['id'];
        } else {
            $this->autoIncrement[$table] = ($this->autoIncrement[$table] ?? 0) + 1;
            $newId = $this->autoIncrement[$table];
            $row = ['id' => $newId] + $row;
        }

        $this->tables[$table][] = $row;
        $this->lastInsertId = (int) $newId;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function executeUpdate(string $table, string $setClause, string $whereClause, array $params, string $originalSql): void
    {
        $setColumns = $this->parseSetColumns($setClause, $originalSql);
        $whereColumns = $this->parseConditionColumns($whereClause, $originalSql);

        if (count($setColumns) + count($whereColumns) !== count($params)) {
            throw new \RuntimeException("FakeDb: parameter count mismatch for statement: {$originalSql}");
        }

        $setValues = array_combine($setColumns, array_slice($params, 0, count($setColumns)));
        $whereValues = array_combine($whereColumns, array_slice($params, count($setColumns)));

        foreach ($this->tables[$table] ?? [] as $i => $row) {
            if ($this->matchesWhere($row, $whereValues)) {
                $this->tables[$table][$i] = array_merge($row, $setValues);
            }
        }
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function executeDelete(string $table, ?string $whereClause, array $params, string $originalSql): void
    {
        if ($whereClause === null) {
            $this->tables[$table] = [];

            return;
        }

        $whereValues = $this->bindConditions($this->parseConditionColumns($whereClause, $originalSql), $params, $originalSql);

        $this->tables[$table] = array_values(array_filter(
            $this->tables[$table] ?? [],
            fn (array $row): bool => !$this->matchesWhere($row, $whereValues),
        ));
    }

    /**
     * Parses the part of a SELECT statement after `FROM table`: an
     * optional `WHERE ...` clause, an optional `ORDER BY col [ASC|DESC]`,
     * and an optional `LIMIT ?|n`.
     *
     * @return array{0: string|null, 1: string|null, 2: string, 3: string|null}
     */
    private function parseSelectTail(string $rest, string $originalSql): array
    {
        if ($rest === '') {
            return [null, null, 'ASC', null];
        }

        if (preg_match(
            '/^WHERE\s+(.+?)(?:\s+ORDER\s+BY\s+([a-zA-Z0-9_]+)(?:\s+(ASC|DESC))?)?(?:\s+LIMIT\s+(\?|\d+))?$/is',
            $rest,
            $m,
        ) === 1) {
            return [trim($m[1]), $m[2] ?? null, strtoupper($m[3] ?? 'ASC'), $m[4] ?? null];
        }

        if (preg_match('/^ORDER\s+BY\s+([a-zA-Z0-9_]+)(?:\s+(ASC|DESC))?(?:\s+LIMIT\s+(\?|\d+))?$/i', $rest, $m) === 1) {
            return [null, $m[1], strtoupper($m[2] ?? 'ASC'), $m[3] ?? null];
        }

        if (preg_match('/^LIMIT\s+(\?|\d+)$/i', $rest, $m) === 1) {
            return [null, null, 'ASC', $m[1]];
        }

        throw new \RuntimeException("FakeDb cannot emulate query: {$originalSql}");
    }

    /**
     * Parses an AND-joined list of `col = ?` conditions into an ordered
     * list of column names. Anything else (literal values, OR, other
     * operators) is unsupported and throws.
     *
     * @return list<string>
     */
    private function parseConditionColumns(string $clause, string $originalSql): array
    {
        $conditions = preg_split('/\s+AND\s+/i', trim($clause)) ?: [];
        $columns = [];

        foreach ($conditions as $condition) {
            if (preg_match('/^([a-zA-Z0-9_]+)\s*=\s*\?$/', trim($condition), $m) !== 1) {
                throw new \RuntimeException("FakeDb cannot emulate condition in statement: {$originalSql}");
            }

            $columns[] = $m[1];
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function parseSetColumns(string $setClause, string $originalSql): array
    {
        $assignments = explode(',', $setClause);
        $columns = [];

        foreach ($assignments as $assignment) {
            if (preg_match('/^\s*([a-zA-Z0-9_]+)\s*=\s*\?\s*$/', $assignment, $m) !== 1) {
                throw new \RuntimeException("FakeDb cannot emulate SET clause in statement: {$originalSql}");
            }

            $columns[] = $m[1];
        }

        return $columns;
    }

    /**
     * @param list<string> $columns
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>
     */
    private function bindConditions(array $columns, array $params, string $originalSql): array
    {
        if (count($columns) !== count($params)) {
            throw new \RuntimeException("FakeDb: parameter count mismatch for statement: {$originalSql}");
        }

        return array_combine($columns, array_values($params));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $whereValues
     */
    private function matchesWhere(array $row, array $whereValues): bool
    {
        foreach ($whereValues as $column => $value) {
            if (!array_key_exists($column, $row)) {
                return false;
            }

            if ((string) $row[$column] !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}
