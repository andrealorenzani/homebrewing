<?php

declare(strict_types=1);

namespace App\Db;

use App\Config\Config;
use PDO;

/**
 * PDO-backed DbInterface implementation.
 *
 * Note: this class is dialect-agnostic (it just delegates to PDO), so it
 * is unit-testable against a SQLite in-memory PDO instance even where the
 * production `pdo_mysql` driver isn't available in the current PHP
 * environment. `buildDsn()` is separated out so the MySQL DSN construction
 * itself can be tested without opening a real connection.
 */
final class PdoDb implements DbInterface
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public static function fromConfig(Config $config): self
    {
        $pdo = new PDO(
            self::buildDsn($config),
            $config->get('DB_USER'),
            $config->get('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        return new self($pdo);
    }

    public static function buildDsn(Config $config): string
    {
        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config->get('DB_HOST', 'localhost'),
            $config->get('DB_PORT', '3306'),
            $config->get('DB_NAME', ''),
            $config->get('DB_CHARSET', 'utf8mb4'),
        );
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): void
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }
}
