-- ============================================================
-- T-S1: application support tables with no target equivalent
-- (DRAFT FOR REVIEW)
-- File: db/target_migrations/20261014_tS1_support_tables.sql
-- Option B phases: B2 (deleted_records, automation_*, reminder_deliveries)
-- and B3b (trip_number_counters).
--
-- STATUS: NOT EXECUTED and NOT APPROVED FOR EXECUTION.
--   Saving this file is NOT approval to run it. Execute only after explicit
--   approval, on a disposable copy first, with a verified backup.
--
-- TARGET DATABASE: the Option B target only (mulawin_fleetops_test for
--   testing). Do NOT apply to the legacy database: every table below
--   already exists there and the CREATE TABLE statements fail.
--   No USE statement: select the database explicitly first.
--
-- PURPOSE:
--   These tables are operational infrastructure used by current code and
--   have no representation in db/reference/mulawin_fleetops_new.sql:
--     trip_number_counters  includes/trip_number.php (Phase 4C allocator)
--     deleted_records       includes/soft_delete.php, recycle bin
--     automation_settings   includes/automation_runner.php, automation page
--     automation_runs       includes/automation_runner.php
--     automation_deliveries automation_runner / expiry_reminders (dedupe)
--     reminder_deliveries   includes/expiry_reminders.php (dedupe)
--   Definitions are ported from the legacy schema with target key types
--   (users.user_id INT UNSIGNED). Constraint names do not collide with any
--   constraint in the target reference or the other drafts (checked by
--   scripts/schema_check.php --lint-drafts).
--
-- SCOPE: six CREATE TABLE statements (no IF NOT EXISTS, so an unexpected
--   existing table stops the run), then seed rows for automation_settings
--   (all automations disabled, as in legacy) and a trip_number_counters
--   backfill from existing trips (0 rows on a fresh test database).
--   No DROP, TRUNCATE, DELETE or UPDATE of existing rows.
--
-- CONCURRENCY / UNIQUENESS:
--   trip_number_counters PRIMARY KEY (`year`) - row lock serializes number
--     allocation (Phase 4C); trips.trip_number stays UNIQUE (target).
--   automation_deliveries UNIQUE (job_key, fingerprint, user_id) and
--   reminder_deliveries UNIQUE (recipient, entity, days, due date) keep the
--     INSERT IGNORE dedupe used by the code.
--
-- DEPENDENCIES: target schema baseline (users). Independent of the other
--   drafts. Must exist before B2/B3b code is deployed.
--
-- COMPATIBILITY: MariaDB 10.4 and MySQL 8 syntax (JSON is a LONGTEXT alias
--   with a JSON_VALID check on MariaDB). Hostinger NOT verified.
--
-- NOTE: SQL examples in comments have no statement terminators on purpose.
--
-- READ-ONLY PRE-CHECKS (expected result in brackets):
--   a) SELECT DATABASE()                                  -- [the target db]
--   b) SELECT TABLE_NAME FROM information_schema.TABLES
--       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
--        ('trip_number_counters','deleted_records','automation_settings',
--         'automation_runs','automation_deliveries','reminder_deliveries')
--                                                           -- [no rows]
--   c) SELECT trip_number FROM trips
--       WHERE trip_number NOT REGEXP '^TRP-[0-9]{4}-[0-9]+$' LIMIT 20
--      (rows are ignored by the counter backfill; review them)
--
-- TRANSACTIONS: each CREATE TABLE commits implicitly. If a later CREATE
--   fails, the earlier tables remain: check pre-check b again, then either
--   finish manually or roll back as below. The seed/backfill INSERTs run in
--   one transaction at the end.
--
-- ROLLBACK (only with explicit approval, only while the code that uses a
--   table is not deployed, and only if its data may be discarded):
--     DROP TABLE reminder_deliveries
--     DROP TABLE automation_deliveries
--     DROP TABLE automation_runs
--     DROP TABLE automation_settings
--     DROP TABLE deleted_records
--     DROP TABLE trip_number_counters
--   Limitations: dropping deleted_records destroys recycle-bin snapshots
--   (deleted rows can no longer be restored). Dropping trip_number_counters
--   after trips were created is safe for uniqueness (the allocator skips
--   numbers already used) but needs the backfill before reuse.
-- ============================================================

CREATE TABLE trip_number_counters (
    `year` INT UNSIGNED NOT NULL,
    next_number INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deleted_records (
    archive_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    original_table VARCHAR(100) NOT NULL,
    original_id BIGINT UNSIGNED NOT NULL,
    record_data JSON NOT NULL,
    deleted_by INT UNSIGNED NULL,
    deleted_by_name VARCHAR(150) NOT NULL,
    deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored_at DATETIME NULL,
    PRIMARY KEY (archive_id),
    KEY idx_deleted_records_status_date (restored_at, deleted_at),
    KEY idx_deleted_records_original (original_table, original_id),
    KEY idx_deleted_records_user (deleted_by),
    CONSTRAINT fk_deleted_records_user FOREIGN KEY (deleted_by) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE automation_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value VARCHAR(20) NOT NULL DEFAULT '0',
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    KEY idx_automation_settings_user (updated_by),
    CONSTRAINT fk_automation_settings_user FOREIGN KEY (updated_by) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE automation_runs (
    run_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_key VARCHAR(100) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
    items_processed INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    PRIMARY KEY (run_id),
    KEY idx_automation_runs_job (job_key, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE automation_deliveries (
    delivery_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_key VARCHAR(100) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (delivery_id),
    UNIQUE KEY uq_automation_delivery (job_key, fingerprint, user_id),
    KEY idx_automation_delivery_user (user_id),
    CONSTRAINT fk_automation_delivery_user FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reminder_deliveries (
    delivery_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_user_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    reminder_days SMALLINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (delivery_id),
    UNIQUE KEY uq_reminder_delivery (recipient_user_id, entity_type, entity_id, reminder_days, due_date),
    CONSTRAINT fk_reminder_deliveries_recipient FOREIGN KEY (recipient_user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

-- All automations start disabled, as in the legacy migration.
INSERT INTO automation_settings (setting_key, setting_value)
SELECT v.setting_key, '0'
FROM (
              SELECT 'automation_engine_enabled' AS setting_key
    UNION ALL SELECT 'pending_approval_reminders'
    UNION ALL SELECT 'expiry_reminders'
    UNION ALL SELECT 'maintenance_due_reminders'
    UNION ALL SELECT 'unpaid_billing_reminders'
    UNION ALL SELECT 'daily_analytics_summary'
    UNION ALL SELECT 'system_health_checks'
) v
WHERE NOT EXISTS (
    SELECT 1 FROM automation_settings s WHERE s.setting_key = v.setting_key
);

-- Counter backfill from existing TRP-YYYY-NNNN numbers. The year comes from
-- the trip number itself (not created_at), so it matches the allocator.
-- Nonconforming numbers are skipped (pre-check c).
INSERT INTO trip_number_counters (`year`, next_number)
SELECT CAST(SUBSTRING(trip_number, 5, 4) AS UNSIGNED) AS y,
       MAX(CAST(SUBSTRING(trip_number, 10) AS UNSIGNED)) + 1
FROM trips
WHERE trip_number REGEXP '^TRP-[0-9]{4}-[0-9]+$'
GROUP BY CAST(SUBSTRING(trip_number, 5, 4) AS UNSIGNED);

COMMIT;
