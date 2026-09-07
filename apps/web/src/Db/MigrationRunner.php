<?php

declare(strict_types=1);

namespace App\Db;

/**
 * Tiny migration runner: discovers numbered `.sql` files under a
 * migrations directory, tracks which have been applied in the
 * `schema_migrations` table, and applies pending ones in order.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly DbInterface $db,
        private readonly string $migrationsPath,
    ) {
    }

    /**
     * Idempotently ensures the bookkeeping table exists. Safe to call
     * every run (`CREATE TABLE IF NOT EXISTS`); this is also, by design,
     * exactly what the `..._create_schema_migrations_table.sql` migration
     * file itself creates, so re-running it as a tracked migration is a
     * harmless no-op on tables that already exist.
     */
    public function ensureMigrationsTableExists(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS schema_migrations (' .
            'id INT UNSIGNED NOT NULL AUTO_INCREMENT, ' .
            'version VARCHAR(191) NOT NULL, ' .
            'applied_at DATETIME NOT NULL, ' .
            'PRIMARY KEY (id), ' .
            'UNIQUE KEY uniq_schema_migrations_version (version)' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
    }

    /**
     * @return list<string> versions already recorded as applied
     */
    public function appliedVersions(): array
    {
        $rows = $this->db->fetchAll('SELECT version FROM schema_migrations ORDER BY version ASC');

        return array_map(static fn (array $row): string => (string) $row['version'], $rows);
    }

    /**
     * @return list<string> versions on disk but not yet applied, in order
     */
    public function pendingMigrations(): array
    {
        $all = $this->discoverMigrations();
        $applied = $this->appliedVersions();

        return array_values(array_diff($all, $applied));
    }

    /**
     * Applies all pending migrations, in order, and records each as
     * applied. Returns the list of versions actually applied during this
     * call (empty if nothing was pending).
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTableExists();

        $applied = [];

        foreach ($this->pendingMigrations() as $version) {
            $sql = file_get_contents($this->migrationFilePath($version));

            if ($sql === false) {
                throw new \RuntimeException("Cannot read migration file for version: {$version}");
            }

            foreach ($this->splitStatements($sql) as $statement) {
                $this->db->execute($statement);
            }

            $this->db->execute(
                'INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)',
                [$version, gmdate('Y-m-d H:i:s')],
            );

            $applied[] = $version;
        }

        return $applied;
    }

    /**
     * @return list<string> migration versions (file basenames without extension) sorted ascending
     */
    private function discoverMigrations(): array
    {
        $files = glob(rtrim($this->migrationsPath, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        return array_map(static fn (string $file): string => basename($file, '.sql'), $files);
    }

    private function migrationFilePath(string $version): string
    {
        return rtrim($this->migrationsPath, '/') . '/' . $version . '.sql';
    }

    /**
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $sql = $this->stripLineComments($sql);
        $statements = array_map('trim', explode(';', $sql));

        return array_values(array_filter($statements, static fn (string $s): bool => $s !== ''));
    }

    /**
     * Strips `-- ...` line comments before statement splitting.
     *
     * Without this, a semicolon inside a comment (e.g. "owner has full
     * access; other users...") would be mistaken for a statement
     * terminator and corrupt the following DDL statement. Migration files
     * in this project never use `--` inside a quoted string literal, so a
     * simple "strip from `--` to end of line" pass is sufficient here.
     */
    private function stripLineComments(string $sql): string
    {
        $stripped = preg_replace('/--[^\n]*/', '', $sql);

        return $stripped ?? $sql;
    }
}
