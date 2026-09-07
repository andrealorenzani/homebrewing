<?php

declare(strict_types=1);

namespace App\Db;

/**
 * Small abstraction over the underlying database connection so
 * repositories and the migration runner can be unit-tested against a
 * fake/in-memory double (FakeDb) instead of a live MySQL/MariaDB server.
 */
interface DbInterface
{
    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array;

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array;

    /**
     * Runs a statement that doesn't return rows (DDL, INSERT/UPDATE/DELETE).
     *
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): void;

    public function lastInsertId(): string;

    public function inTransaction(): bool;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;
}
