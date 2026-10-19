-- ============================================================
-- T-T2: trip_completion_reports support table
-- (DRAFT FOR REVIEW - DECISION-GATED)
-- File: db/target_migrations/20261014_tT2_trip_completion_reports.sql
-- Option B phase: B3c. GATED ON DECISIONS D4 / D5.
--   The plan's recommendation for D5 is "trips.status 'For Review' +
--   workflow_events + a unique guard". This file is that unique guard in
--   its smallest form: a support table whose generated column allows at
--   most one Pending report per trip. If D5 chooses a design without a
--   support table, do NOT apply this file.
--   D4 (the 12-step workflow state) is recommended to be DERIVED from target
--   records and needs no table; no table is drafted for it.
--
-- STATUS: NOT EXECUTED and NOT APPROVED FOR EXECUTION.
--
-- TARGET DATABASE: the Option B target only. Do NOT apply to the legacy
--   database (a legacy trip_completion_reports already exists there with a
--   different shape). No USE statement.
--
-- PURPOSE:
--   Drivers report completion (ajax/trip_completion_handler.php); an
--   authorized recorder later completes the trip. The code currently relies
--   on a locked read to refuse a second active report. Here the database
--   also guarantees it: active_trip_id is trip_id while status = 'Pending'
--   and NULL otherwise, with a UNIQUE index.
--   Legacy column dispatch_id is not carried over: in the target the trip
--   is the root entity (dispatch_requests.trip_id is 1:1).
--
-- DEPENDENCIES: target schema baseline (trips, employees, users).
-- COMPATIBILITY: STORED generated column + UNIQUE index on MySQL 8 and
--   MariaDB 10.4. Hostinger NOT verified.
-- NOTE: SQL examples in comments have no statement terminators on purpose.
--
-- READ-ONLY PRE-CHECKS (expected result in brackets):
--   a) SELECT DATABASE()                                  -- [the target db]
--   b) SELECT TABLE_NAME FROM information_schema.TABLES
--       WHERE TABLE_SCHEMA = DATABASE()
--         AND TABLE_NAME = 'trip_completion_reports'       -- [no rows]
--
-- TRANSACTIONS: CREATE TABLE commits implicitly; a rerun fails and changes
--   nothing.
--
-- ROLLBACK (only with explicit approval, before B3c code uses the table):
--     DROP TABLE trip_completion_reports
--   Data loss: all driver completion reports.
-- ============================================================

CREATE TABLE trip_completion_reports (
    report_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id BIGINT UNSIGNED NOT NULL,
    reported_by INT UNSIGNED NOT NULL COMMENT 'employees.employee_id of the reporting driver',
    status ENUM('Pending','Acknowledged','Rejected') NOT NULL DEFAULT 'Pending',
    driver_note VARCHAR(500) NULL,
    reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    reviewer_note VARCHAR(500) NULL,
    active_trip_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN status = 'Pending' THEN trip_id ELSE NULL END) STORED,
    PRIMARY KEY (report_id),
    UNIQUE KEY uq_trip_completion_active (active_trip_id),
    KEY idx_trip_completion_trip_status (trip_id, status),
    KEY idx_trip_completion_status (status, reported_at),
    KEY idx_trip_completion_reporter (reported_by),
    KEY idx_trip_completion_reviewer (reviewed_by),
    CONSTRAINT fk_trip_completion_reports_trip FOREIGN KEY (trip_id) REFERENCES trips (trip_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_trip_completion_reports_employee FOREIGN KEY (reported_by) REFERENCES employees (employee_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_trip_completion_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
