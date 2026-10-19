-- ============================================================
-- T-T1: trips.second_driver_id (DRAFT FOR REVIEW - DECISION-GATED)
-- File: db/target_migrations/20261014_tT1_trip_second_driver.sql
-- Option B phase: B3b. GATED ON DECISION D3 (keep the second driver).
--   If D3 decides to drop the second-driver feature, do NOT apply this file.
--
-- STATUS: NOT EXECUTED and NOT APPROVED FOR EXECUTION.
--
-- TARGET DATABASE: the Option B target only (mulawin_fleetops_test for
--   testing). Do NOT apply to the legacy database (legacy trips has no
--   driver columns; the second driver is on legacy dispatch_requests).
--   No USE statement.
--
-- PURPOSE:
--   The legacy dispatch flow assigns an optional second driver (about 25
--   code references), and Phase 4A final approval re-checks its licence.
--   The target trips table has driver_id and helper_id only.
--
-- SCOPE: one ALTER TABLE trips adding second_driver_id INT UNSIGNED NULL
--   (same type as employees.employee_id), an index and a foreign key that
--   mirrors fk_trips_driver (ON UPDATE CASCADE ON DELETE SET NULL).
--
-- NOT ENFORCED HERE: "second driver differs from driver". MySQL 8 forbids a
--   CHECK constraint on a column used in a foreign key referential action
--   (ON DELETE SET NULL), so this rule stays in application code (B3b
--   submit and final-approval checks).
--
-- DEPENDENCIES: target schema baseline (trips, employees).
-- COMPATIBILITY: MariaDB 10.4 / MySQL 8 syntax. Hostinger NOT verified.
-- NOTE: SQL examples in comments have no statement terminators on purpose.
--
-- READ-ONLY PRE-CHECKS (expected result in brackets):
--   a) SELECT DATABASE()                                  -- [the target db]
--   b) SELECT COLUMN_NAME FROM information_schema.COLUMNS
--       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trips'
--         AND COLUMN_NAME = 'second_driver_id'             -- [no rows]
--   c) SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
--       WHERE CONSTRAINT_SCHEMA = DATABASE()
--         AND CONSTRAINT_NAME = 'fk_trips_second_driver'   -- [no rows]
--
-- TRANSACTIONS: ALTER TABLE commits implicitly. A rerun fails with a
--   duplicate-column error and changes nothing.
--
-- ROLLBACK (only with explicit approval, and only before B3b code that
--   reads or writes the column is deployed):
--     ALTER TABLE trips DROP FOREIGN KEY fk_trips_second_driver,
--       DROP INDEX idx_trip_second_driver, DROP COLUMN second_driver_id
--   Data loss: every recorded second-driver assignment is deleted.
-- ============================================================

ALTER TABLE trips
    ADD COLUMN second_driver_id INT UNSIGNED NULL AFTER driver_id,
    ADD KEY idx_trip_second_driver (second_driver_id),
    ADD CONSTRAINT fk_trips_second_driver FOREIGN KEY (second_driver_id)
        REFERENCES employees (employee_id)
        ON UPDATE CASCADE ON DELETE SET NULL;
