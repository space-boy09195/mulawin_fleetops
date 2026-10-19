-- ============================================================
-- T-M2: maintenance planning support (DRAFT FOR REVIEW - NEW IN B4)
-- File: db/target_migrations/20261014_tM2_maintenance_planning.sql
-- Option B phase: B4.
--
-- STATUS: NOT EXECUTED and NOT APPROVED FOR EXECUTION.
--   Saving this file is NOT approval to run it. Execute only after explicit
--   approval, on a disposable copy first, with a verified backup.
--
-- TARGET DATABASE: the Option B target only (mulawin_fleetops_test for
--   testing). Do NOT apply to the legacy database. No USE statement.
--
-- PURPOSE:
--   The legacy app has repair work orders and recurring preventive
--   maintenance (PM) schedules. The target keeps every maintenance job in
--   maintenance_records but has no recurring schedule and no work-order
--   fields. B4 code stores:
--     repair work order   -> maintenance_records (maintenance_type 'Repair',
--                            record_source 'work_order', status Reported /
--                            In Progress / Completed / Cancelled)
--     general inspection  -> maintenance_records (maintenance_type
--                            'Inspection', record_source 'inspection') +
--                            maintenance_findings
--     PM service done     -> maintenance_records (maintenance_type
--                            'Preventive', record_source 'pm_service')
--   This file adds what those need:
--   1. maintenance_records.record_source  which screen created the row, so
--      the maintenance page can list records, work orders and inspections
--      separately (default 'record' for every existing row)
--   2. maintenance_records.work_order_priority  Low/Normal/High/Urgent
--   3. maintenance_records.closure_notes, closed_at  work-order closure
--   4. maintenance_records.created_by     who opened the job (FK users)
--   5. maintenance_records.incident_id    trip incident fixed by the job
--   6. maintenance_records.vehicle_inspection_id  trip inspection that
--      led to the job
--   7. preventive_maintenance_schedules   recurring PM plan per truck
--      (interval in days, next due date, Active/Paused/Archived)
--
-- DEPENDENCIES: target schema baseline (maintenance_records, trucks, users,
--   trip_incidents, vehicle_inspections). T-M1 (next_due_date) is
--   independent; either order.
-- COMPATIBILITY: MariaDB 10.4 / MySQL 8 syntax. CHECK enforced on MySQL
--   8.0.16+ and MariaDB 10.2+. Hostinger NOT verified.
-- NOTE: SQL examples in comments have no statement terminators on purpose.
--
-- READ-ONLY PRE-CHECKS (expected result in brackets):
--   a) SELECT DATABASE()                                  -- [the target db]
--   b) SELECT VERSION()                                   -- [record it]
--   c) SELECT COLUMN_NAME FROM information_schema.COLUMNS
--       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_records'
--         AND COLUMN_NAME IN ('record_source','work_order_priority','closure_notes',
--                             'closed_at','created_by','incident_id','vehicle_inspection_id')
--                                                         -- [no rows]
--   d) SELECT TABLE_NAME FROM information_schema.TABLES
--       WHERE TABLE_SCHEMA = DATABASE()
--         AND TABLE_NAME = 'preventive_maintenance_schedules' -- [no rows]
--
-- BACKFILL: none on a fresh test database. For a production data migration:
--   legacy maintenance_records -> record_source 'record'; repair_work_orders
--   -> maintenance_records rows (record_source 'work_order', title ->
--   reported_issue, opened_at -> created_at, expected_completion_at ->
--   expected_completion_date, closed_at, closure_notes, priority);
--   preventive_maintenance_schedules copied column for column
--   (last_maintenance_record_id -> last_maintenance_id).
--
-- TRANSACTIONS: CREATE/ALTER commit implicitly; a rerun fails at the first
--   statement and changes nothing.
--
-- ROLLBACK (only with explicit approval, before B4 code relies on it):
--     DROP TABLE preventive_maintenance_schedules
--     ALTER TABLE maintenance_records
--       DROP FOREIGN KEY fk_maintenance_records_creator,
--       DROP FOREIGN KEY fk_maintenance_records_incident,
--       DROP FOREIGN KEY fk_maintenance_records_vehicle_inspection,
--       DROP INDEX idx_maintenance_records_source,
--       DROP INDEX idx_maintenance_records_creator,
--       DROP INDEX idx_maintenance_records_incident,
--       DROP INDEX idx_maintenance_records_vehicle_inspection,
--       DROP COLUMN vehicle_inspection_id, DROP COLUMN incident_id,
--       DROP COLUMN created_by, DROP COLUMN closed_at,
--       DROP COLUMN closure_notes, DROP COLUMN work_order_priority,
--       DROP COLUMN record_source
--   Data loss: PM schedules and the added work-order fields.
-- ============================================================

ALTER TABLE maintenance_records
    ADD COLUMN record_source ENUM('record','work_order','inspection','pm_service') NOT NULL DEFAULT 'record' AFTER maintenance_type,
    ADD COLUMN work_order_priority ENUM('Low','Normal','High','Urgent') NULL AFTER record_source,
    ADD COLUMN closure_notes VARCHAR(1000) NULL AFTER completed_by,
    ADD COLUMN closed_at DATETIME NULL AFTER closure_notes,
    ADD COLUMN created_by INT UNSIGNED NULL AFTER closed_at,
    ADD COLUMN incident_id BIGINT UNSIGNED NULL AFTER created_by,
    ADD COLUMN vehicle_inspection_id BIGINT UNSIGNED NULL AFTER incident_id,
    ADD KEY idx_maintenance_records_source (record_source, status),
    ADD KEY idx_maintenance_records_creator (created_by),
    ADD KEY idx_maintenance_records_incident (incident_id),
    ADD KEY idx_maintenance_records_vehicle_inspection (vehicle_inspection_id),
    ADD CONSTRAINT fk_maintenance_records_creator FOREIGN KEY (created_by)
        REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    ADD CONSTRAINT fk_maintenance_records_incident FOREIGN KEY (incident_id)
        REFERENCES trip_incidents (incident_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    ADD CONSTRAINT fk_maintenance_records_vehicle_inspection FOREIGN KEY (vehicle_inspection_id)
        REFERENCES vehicle_inspections (inspection_id)
        ON UPDATE CASCADE ON DELETE SET NULL;

CREATE TABLE preventive_maintenance_schedules (
    schedule_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    truck_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    service_name VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    interval_days SMALLINT UNSIGNED NOT NULL,
    next_due_date DATE NOT NULL,
    status ENUM('Active','Paused','Archived') NOT NULL DEFAULT 'Active',
    last_completed_at DATETIME NULL,
    last_maintenance_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (schedule_id),
    KEY idx_pm_schedules_truck (truck_id, status),
    KEY idx_pm_schedules_due (status, next_due_date),
    KEY idx_pm_schedules_creator (created_by),
    KEY idx_pm_schedules_last_record (last_maintenance_id),
    CONSTRAINT fk_pm_schedules_truck FOREIGN KEY (truck_id)
        REFERENCES trucks (truck_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_pm_schedules_creator FOREIGN KEY (created_by)
        REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_pm_schedules_last_record FOREIGN KEY (last_maintenance_id)
        REFERENCES maintenance_records (maintenance_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_pm_schedules_interval CHECK (interval_days BETWEEN 1 AND 3650)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
