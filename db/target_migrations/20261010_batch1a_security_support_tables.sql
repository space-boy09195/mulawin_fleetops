-- ============================================================
-- Batch 1A: security support tables (DRAFT FOR REVIEW)
-- File: db/target_migrations/20261010_batch1a_security_support_tables.sql
--
-- TARGET DATABASE: mulawin_fleetops_new ONLY.
--   Do NOT apply to the current application database (mulawin_fleetops).
--   This file deliberately lives outside db/*_migration.sql so the existing
--   "apply every db/*_migration.sql" setup step never picks it up.
--   It contains no USE statement: the operator must select the target
--   database explicitly before running it.
--
-- STATUS: reviewed draft. Saving this file is NOT approval to execute it.
--   Execute only after explicit approval, against a disposable scratch copy
--   of the target schema first, with a backup taken beforehand.
--
-- SCOPE: creates two NEW tables only:
--   1. app_settings          (includes/app_settings.php)
--   2. idempotency_requests  (includes/idempotency.php)
--   It does not ALTER, DROP, TRUNCATE, INSERT, UPDATE or DELETE anything.
--   login_attempts and password_reset_requests are intentionally excluded.
--
-- PREREQUISITES:
--   - The target schema (db/reference/mulawin_fleetops_new.sql) has already
--     been created, so table `users` exists with user_id INT UNSIGNED PK.
--   - InnoDB engine, database default charset utf8mb4 / utf8mb4_unicode_ci.
--   - Works on MySQL 8.x and MariaDB (only syntax supported by both is used).
--
-- NOTE: SQL examples in these comments intentionally have no statement
--   terminators, so no tool that splits on terminators can turn a comment
--   into an executable statement. Add the terminator yourself when typing
--   an example into a client.
--
-- READ-ONLY PRE-CHECKS (run first, expected result in brackets):
--   SELECT DATABASE()                           -- [mulawin_fleetops_new]
--   SHOW TABLES LIKE 'app_settings'             -- [empty]
--   SHOW TABLES LIKE 'idempotency_requests'     -- [empty]
--   SHOW COLUMNS FROM users LIKE 'user_id'      -- [int unsigned or
--                                               --  int(10) unsigned, PRI]
--
-- RECOVERY:
--   MySQL/MariaDB commit each CREATE TABLE immediately (DDL is not
--   transactional), and both statements are plain CREATE TABLE (not
--   IF NOT EXISTS), so a rerun fails loudly on any table that already exists.
--   - If the FIRST CREATE TABLE (app_settings) fails: stop and investigate
--     the error. Do not continue to the second statement blindly.
--   - If the first succeeds and the SECOND (idempotency_requests) fails:
--     inspect the existing table with SHOW CREATE TABLE app_settings and
--     confirm it matches this file exactly. Only after that check, and only
--     when separately authorized, run ONLY the missing CREATE TABLE
--     idempotency_requests statement.
--   - If an existing table does not match this file, stop and report it.
--   - Do not drop or alter any existing table without explicit approval.
--
-- ROLLBACK (only with explicit approval, and only while both tables are
-- unused): DROP TABLE idempotency_requests, then DROP TABLE app_settings
-- ============================================================

-- ------------------------------------------------------------
-- app_settings
--   PK setting_key: required by setAppSetting()'s
--     INSERT ... ON DUPLICATE KEY UPDATE.
--   setting_value: validated plain strings (longest known: 253-char
--     email domain), not JSON.
--   updated_by -> users.user_id, ON DELETE SET NULL: keep the setting
--     when the user who last changed it is removed.
-- ------------------------------------------------------------
CREATE TABLE app_settings (
    setting_key   VARCHAR(100) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    KEY idx_app_settings_updated_by (updated_by),
    CONSTRAINT fk_app_settings_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- idempotency_requests
--   UNIQUE (user_id, action_key, request_key): claimIdempotencyKey()
--     detects duplicates only through MySQL error 1062 on this key.
--   KEY expires_at: the expiry cleanup DELETE runs on every claim.
--   request_key: ASCII, case-sensitive (server accepts
--     ^[A-Za-z0-9._-]{16,80}$).
--   expires_at: written by the application as NOW() + 1 DAY.
--   user_id -> users.user_id, ON DELETE CASCADE: short-lived keys have
--     no value once the user is removed.
-- ------------------------------------------------------------
CREATE TABLE idempotency_requests (
    idempotency_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED    NOT NULL,
    action_key     VARCHAR(100)    NOT NULL,
    request_key    VARCHAR(80)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at     DATETIME        NOT NULL,
    PRIMARY KEY (idempotency_id),
    UNIQUE KEY uq_idempotency_user_action_key (user_id, action_key, request_key),
    KEY idx_idempotency_expires_at (expires_at),
    CONSTRAINT fk_idempotency_user FOREIGN KEY (user_id)
        REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
