-- ============================================================
-- T-E1: employee compatibility columns (DRAFT FOR REVIEW)
-- File: db/target_migrations/20261014_tE1_employee_compat_columns.sql
-- Option B phase: B1 (identity). Approved design: Option B decision 2
-- (ordinary columns maintained by application code, NOT generated columns).
--
-- STATUS: NOT EXECUTED and NOT APPROVED FOR EXECUTION.
--   Saving this file is NOT approval to run it. Execute only after explicit
--   approval, on a disposable copy first, with a verified backup.
--
-- TARGET DATABASE: the Option B target only (mulawin_fleetops_test for
--   testing, built from db/reference/mulawin_fleetops_new.sql). Do NOT
--   apply to the legacy database (mulawin_fleetops): there employees
--   already has full_name and is_active and this file fails.
--   No USE statement: select the database explicitly first.
--
-- PURPOSE:
--   About 21 application files read employees.full_name and 6 read
--   employees.is_active. The target table has first_name / middle_name /
--   last_name / suffix and employment_status instead. This file adds the two
--   legacy columns as ORDINARY columns so those readers keep working.
--
-- SCOPE:
--   1. ALTER TABLE employees ADD full_name VARCHAR(270) NULL (after suffix)
--      and is_active TINYINT(1) NOT NULL DEFAULT 1 (after employment_status),
--      plus index idx_employees_is_active.
--   2. Backfill both columns from the target columns (UPDATE). On a freshly
--      created test database the table is empty and the UPDATEs change 0 rows.
--   No DROP, TRUNCATE, DELETE or INSERT.
--
-- MAINTENANCE CONTRACT (application code, phase B1 - NOT part of this file):
--   Every INSERT/UPDATE of employees name parts or employment_status must
--   also write full_name and is_active using exactly these rules:
--     full_name = the non-empty trimmed parts first, middle, last, suffix,
--                 joined by single spaces (same rule as the backfill below)
--     is_active = 1 when employment_status = 'Active', otherwise 0
--   Writers known today: ajax/users_handler.php, auth/seed_users.php.
--   Drift check (read-only, run after B1 tests; expected: no rows):
--     SELECT employee_id FROM employees
--      WHERE NOT (full_name <=> NULLIF(TRIM(CONCAT_WS(' ',
--              NULLIF(TRIM(first_name),''), NULLIF(TRIM(middle_name),''),
--              NULLIF(TRIM(last_name),''), NULLIF(TRIM(suffix),''))), ''))
--         OR is_active <> (employment_status = 'Active')
--
-- SIZE: 80 + 80 + 80 + 20 characters plus 3 separators = 263, so
--   VARCHAR(270) cannot truncate a backfilled name.
--
-- DEPENDENCIES: target schema baseline only. Independent of 1A, 1B, 7M,
--   S-36 (S-36 also alters employees but different columns; either order).
--
-- COMPATIBILITY: MariaDB 10.4 (local XAMPP) and MySQL 8 syntax. No
--   ADD COLUMN IF NOT EXISTS (MySQL 8 lacks it). Hostinger NOT verified.
--
-- NOTE: SQL examples in these comments have no statement terminators on
--   purpose, so no tool that splits on terminators can execute a comment.
--
-- READ-ONLY PRE-CHECKS (expected result in brackets):
--   a) SELECT DATABASE()                                  -- [the target db]
--   b) SELECT VERSION()                                   -- [record it]
--   c) SELECT COLUMN_NAME FROM information_schema.COLUMNS
--       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
--         AND COLUMN_NAME IN ('full_name','is_active')     -- [no rows]
--   d) SELECT INDEX_NAME FROM information_schema.STATISTICS
--       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
--         AND INDEX_NAME = 'idx_employees_is_active'       -- [no rows]
--   e) SELECT COUNT(*) FROM employees                     -- [record it]
--
-- TRANSACTIONS: ALTER TABLE commits implicitly; it cannot be rolled back by
--   a surrounding transaction. The UPDATEs run afterwards in their own
--   transaction (START TRANSACTION ... COMMIT below). A rerun fails at the
--   ALTER with a duplicate-column error and changes nothing.
--
-- ROLLBACK (only with explicit approval, and only before B1 code that reads
--   or writes these columns is deployed - afterwards login and employee
--   pages fail):
--     ALTER TABLE employees DROP INDEX idx_employees_is_active,
--       DROP COLUMN is_active, DROP COLUMN full_name
--   Data loss: none of the dropped values are original data (both columns
--   are derived from other columns), so the backfill can recreate them.
-- ============================================================

ALTER TABLE employees
    ADD COLUMN full_name VARCHAR(270) NULL AFTER suffix,
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER employment_status,
    ADD KEY idx_employees_is_active (is_active);

START TRANSACTION;

UPDATE employees
   SET full_name = NULLIF(TRIM(CONCAT_WS(' ',
           NULLIF(TRIM(first_name), ''),
           NULLIF(TRIM(middle_name), ''),
           NULLIF(TRIM(last_name), ''),
           NULLIF(TRIM(suffix), '')
       )), '');

UPDATE employees
   SET is_active = IF(employment_status = 'Active', 1, 0);

COMMIT;
