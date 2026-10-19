-- ============================================================
-- T-I1: trip incident resolution columns (DRAFT FOR REVIEW - DECISION-GATED)
-- File: db/target_migrations/20261014_tI1_trip_incident_resolution.sql
-- Option B phase: B3c. GATED ON DECISION D10.
--
-- STATUS: NOT EXECUTED and NOT APPROVED FOR EXECUTION.
--
-- TARGET DATABASE: the Option B target only. Do NOT apply to the legacy
--   database (no trip_incidents table there). No USE statement.
--
-- PURPOSE:
--   Current code resolves an incident atomically with
--     UPDATE ... SET resolution_notes = ?, resolved_at = NOW()
--     WHERE incident_id = ? AND resolved_at IS NULL
--   (ajax/log_incident.php, ajax/maintenance_handler.php; Phase E2-B fix).
--   The target trip_incidents table already has action_taken TEXT, which B3c
--   code will use for the resolution notes. It lacks the resolution state,
--   which this file adds: resolved_at (NULL = open) and resolved_by.
--   The conditional "AND resolved_at IS NULL" update keeps resolution
--   single-winner under concurrency without any extra constraint.
--
-- DEPENDENCIES: target schema baseline (trip_incidents, users).
-- COMPATIBILITY: MariaDB 10.4 / MySQL 8 syntax. Hostinger NOT verified.
-- NOTE: SQL examples in comments have no statement terminators on purpose.
--
-- READ-ONLY PRE-CHECKS (expected result in brackets):
--   a) SELECT DATABASE()                                  -- [the target db]
--   b) SELECT COLUMN_NAME FROM information_schema.COLUMNS
--       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_incidents'
--         AND COLUMN_NAME IN ('resolved_at','resolved_by') -- [no rows]
--   c) SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
--       WHERE CONSTRAINT_SCHEMA = DATABASE()
--         AND CONSTRAINT_NAME = 'fk_trip_incidents_resolver' -- [no rows]
--
-- BACKFILL: none on a fresh test database. For a production data
--   migration, legacy incidents.resolved_at maps to resolved_at and
--   incidents.resolution_notes to action_taken; legacy has no resolver.
--
-- TRANSACTIONS: ALTER TABLE commits implicitly; a rerun fails with a
--   duplicate-column error and changes nothing.
--
-- ROLLBACK (only with explicit approval, before B3c code uses the columns):
--     ALTER TABLE trip_incidents DROP FOREIGN KEY fk_trip_incidents_resolver,
--       DROP INDEX idx_trip_incidents_resolved, DROP COLUMN resolved_by,
--       DROP COLUMN resolved_at
--   Data loss: the resolved/open state of every incident (action_taken
--   text survives, but open and resolved incidents become indistinguishable).
-- ============================================================

ALTER TABLE trip_incidents
    ADD COLUMN resolved_at DATETIME NULL AFTER action_taken,
    ADD COLUMN resolved_by INT UNSIGNED NULL AFTER resolved_at,
    ADD KEY idx_trip_incidents_resolved (resolved_at),
    ADD KEY idx_trip_incidents_resolver (resolved_by),
    ADD CONSTRAINT fk_trip_incidents_resolver FOREIGN KEY (resolved_by)
        REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL;
