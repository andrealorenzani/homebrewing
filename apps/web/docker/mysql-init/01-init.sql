-- Runs once, automatically, the first time the homebrewing-mysql container's
-- data volume is created (mysql:8.0 entrypoint convention: any *.sql file
-- under /docker-entrypoint-initdb.d/ is executed on first boot only).
--
-- MYSQL_DATABASE / MYSQL_USER / MYSQL_PASSWORD env vars (see
-- ../../docker-compose.yml) already create `homebrewing_dev` and the
-- `homebrewing` user with full privileges on it. This script adds the
-- second database (`homebrewing_test`, used by the integration test suite
-- and future V5-V9 rounds) and grants the same user full privileges there
-- too.
CREATE DATABASE IF NOT EXISTS homebrewing_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON homebrewing_dev.* TO 'homebrewing'@'%';
GRANT ALL PRIVILEGES ON homebrewing_test.* TO 'homebrewing'@'%';
FLUSH PRIVILEGES;
