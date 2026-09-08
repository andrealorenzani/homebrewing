<?php

declare(strict_types=1);

namespace App\Db;

/**
 * Thrown when the `schema_migrations` bookkeeping table already exists but
 * has an incompatible structure (e.g. missing the `version` column).
 *
 * `MigrationRunner::ensureMigrationsTableExists()` uses `CREATE TABLE IF NOT
 * EXISTS`, which silently no-ops against a same-named-but-differently-shaped
 * table left behind by another application sharing the same database. This
 * exception surfaces that situation loudly instead of letting a cryptic
 * `\PDOException` ("Unknown column 'version'...") leak out. No auto-fix is
 * attempted: this app requires a dedicated database, and the foreign table
 * is left untouched.
 */
final class IncompatibleMigrationsTableException extends \RuntimeException
{
}
