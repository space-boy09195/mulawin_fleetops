-- ============================================================
-- T-P1: trip_pay_records uniqueness and recorder (DRAFT FOR REVIEW)
-- File: db/target_migrations/20261014_tP1_trip_pay_records_guard.sql
-- Option B phase: B5a. Related decision: D6b (how the legacy paid_date maps
-- to the target trip-pay status / payroll flow - NOT decided by this file).
--
-- STATUS: NOT EXECUTED and NOT APPROVED FOR EXECUTION.
--
-- TARGET DATABASE: the Option B target only. Do NOT apply to the legacy
--   database (no trip_pay_records there; legacy uses trip_pay).
--   No USE statement.
--
-- PURPOSE:
--   ajax/trip_pay_handler.php upserts one wage row per trip and crew member
--   (legacy UNIQUE uq_trip_pay_trip_employee + ON DUPLICATE KEY UPDATE).
--   The target trip_pay_records table has NO unique key, so concurrent saves
--   could create duplicate wage rows and double-pay. This file adds:
--     UNIQUE uq_trip_pay_record (trip_id, employee_id, pay_type)
--     recorded_by INT UNSIGNED NULL (FK users) - who logged the wage
--     notes VARCHAR(255) NULL                     - the handler's notes field
--   Assignment and role checks (driver vs helper) stay in application code.
--
-- KNOWN LIMITATION: a Cancelled row still occupies its (trip, employee,
--   pay_type) slot. B5a code must re-activate/update the existing row
--   instead of inserting a new one (same upsert pattern as today).
--
-- DEPENDENCIES: target schema baseline (trip_pay_records, users).
-- COMPATIBILITY: MariaDB 10.4 / MySQL 8 syntax. Hostinger NOT verified.
-- NOTE: SQL examples in comments have no statement terminators on purpose.
--
-- READ-ONLY PRE-CHECKS (expected result in brackets):
--   a) SELECT DATABASE()                                  -- [the target db]
--   b) Duplicates that would block the UNIQUE index - ANY ROW MEANS STOP:
--        SELECT trip_id, employee_id, pay_type, COUNT(*) FROM trip_pay_records
--         GROUP BY trip_id, employee_id, pay_type HAVING COUNT(*) > 1
--   c) SELECT COLUMN_NAME FROM information_schema.COLUMNS
--       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_pay_records'
--         AND COLUMN_NAME IN ('recorded_by','notes')      -- [no rows]
--
-- TRANSACTIONS: ALTER TABLE commits implicitly; a rerun fails with a
--   duplicate-column error and changes nothing.
--
-- ROLLBACK (only with explicit approval, before B5a code relies on it):
--     ALTER TABLE trip_pay_records DROP FOREIGN KEY fk_trip_pay_records_recorder,
--       DROP INDEX uq_trip_pay_record, DROP INDEX idx_trip_pay_records_recorder,
--       DROP COLUMN notes, DROP COLUMN recorded_by
--   Limitation: without uq_trip_pay_record, concurrent saves can again
--   create duplicate wage rows. Data loss: recorder and notes values.
-- ============================================================

ALTER TABLE trip_pay_records
    ADD COLUMN recorded_by INT UNSIGNED NULL AFTER status,
    ADD COLUMN notes VARCHAR(255) NULL AFTER recorded_by,
    ADD UNIQUE KEY uq_trip_pay_record (trip_id, employee_id, pay_type),
    ADD KEY idx_trip_pay_records_recorder (recorded_by),
    ADD CONSTRAINT fk_trip_pay_records_recorder FOREIGN KEY (recorded_by)
        REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL;
