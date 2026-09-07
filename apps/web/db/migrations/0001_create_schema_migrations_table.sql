-- Bookkeeping table tracking which migrations have been applied.
-- Note: MigrationRunner::ensureMigrationsTableExists() also issues this
-- exact CREATE TABLE IF NOT EXISTS before computing pending migrations, so
-- that the runner can determine pending/applied state on a totally empty
-- database. Re-applying this file as a tracked migration is therefore a
-- harmless no-op once the table exists.
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    version VARCHAR(191) NOT NULL,
    applied_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_schema_migrations_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
