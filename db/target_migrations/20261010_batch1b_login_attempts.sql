-- ============================================================
-- Batch 1B: login rate-limit support table (DRAFT FOR REVIEW)
-- File: db/target_migrations/20261010_batch1b_login_attempts.sql
--
-- TARGET DATABASE: mulawin_fleetops_new ONLY.
--   Do NOT apply to the current application database (mulawin_fleetops),
--   which already has its own login_attempts table.
--   This file deliberately lives outside db/*_migration.sql so the existing
--   "apply every db/*_migration.sql" setup step never picks it up.
--   It contains no USE statement: the operator must select the target
--   database explicitly before running it.
--
-- STATUS: reviewed draft. Saving this file is NOT approval to execute it.
--   Execute only after explicit approval, against a disposable scratch copy
--   of the target schema first, with a backup taken beforehand.
--
-- SCOPE: creates one NEW table, login_attempts (auth/login_handler.php).
--   It does not ALTER, DROP, TRUNCATE, INSERT, UPDATE or DELETE anything.
--   No foreign key to users: attempts for unknown usernames must be stored.
--
-- PREREQUISITES:
--   - InnoDB engine, database default charset utf8mb4 / utf8mb4_unicode_ci.
--   - Works on MySQL 8.x and MariaDB (only syntax supported by both is used).
--   - No dependency on other tables.
--
-- NOTE: SQL examples in these comments intentionally have no statement
--   terminators, so no tool that splits on terminators can turn a comment
--   into an executable statement.
--
-- READ-ONLY PRE-CHECKS (run first, expected result in brackets):
--   SELECT DATABASE()                           -- [mulawin_fleetops_new]
--   SHOW TABLES LIKE 'login_attempts'           -- [empty]
--
-- RECOVERY:
--   CREATE TABLE commits immediately and is not IF NOT EXISTS, so a rerun
--   fails loudly if the table already exists.
--   - If it fails: stop and investigate the error. Nothing else depends on
--     this statement.
--   - If the table already exists: compare SHOW CREATE TABLE login_attempts
--     with this file and report differences. Do not drop or alter it
--     without explicit approval.
--
-- ROLLBACK (only with explicit approval, and only while the table is
-- unused): DROP TABLE login_attempts
-- ============================================================

-- ------------------------------------------------------------
-- login_attempts
--   identifier: either the lowercased username (at most 80 characters,
--     capped in login_handler.php) or "\x01ip:" plus a 64-character SHA-256
--     hex digest (68 characters). utf8mb4_bin so lookups are exact and are
--     not merged by case- or accent-insensitive collation.
--   ip_address: NULL on username rows. On IP rows, a validated IPv4/IPv6
--     address (at most 45 characters).
--   attempted_at: never written by the application, so it must default
--     to the current time.
--   Indexes match the queries in login_handler.php:
--     idx_login_attempts_identifier - per-username count, clear on success
--     idx_login_attempts_ip         - per-IP count
--     idx_login_attempts_attempted  - expiry cleanup run on every login POST
-- ------------------------------------------------------------
CREATE TABLE login_attempts (
    attempt_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier   VARCHAR(100)    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    ip_address   VARCHAR(45)     CHARACTER SET ascii COLLATE ascii_bin NULL,
    attempted_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (attempt_id),
    KEY idx_login_attempts_identifier (identifier, attempted_at),
    KEY idx_login_attempts_ip (ip_address, attempted_at),
    KEY idx_login_attempts_attempted (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
