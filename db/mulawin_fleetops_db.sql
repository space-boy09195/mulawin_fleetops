-- Mulawin FleetOps complete schema and migration bundle.
-- FRESH INSTALL ONLY: import into a newly created, empty MySQL database.
-- Do not import over an existing database; base CREATE statements are not idempotent.
-- Select the target database in phpMyAdmin before importing this file.
-- This builds schema and migration data only; it does not create login accounts.
-- Create application accounts through auth/seed_users.php after PHP setup.

-- ===== BEGIN SOURCE: Mulawin_DB-Phase 1 =====
-- ============================================================
-- MULAWIN FLEETOPS — Phase 1 (Revised): Database Schema
-- ERP-Based Fleet Management and Financial Monitoring System
-- RP Mulawin Trucking Services
-- ------------------------------------------------------------
-- Charset : utf8mb4 | Collation: utf8mb4_unicode_ci
-- Engine  : InnoDB   | Normalized to 3NF
-- Import into an already selected database.
-- Revised : Drivers/helpers separated from system users,
--           parts movement history added, billings decoupled
--           from single document, trucks enriched for ops,
--           checklist linked to dispatch, employees table added
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- TABLE 1: roles
-- Stores the four RBAC roles.
-- NOTE: permissions are enforced in PHP (config/app.php),
--       not stored as JSON here — cleaner and easier to change.
-- ============================================================
CREATE TABLE roles (
  role_id    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  role_name  VARCHAR(50)   NOT NULL UNIQUE,
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (role_id, role_name) VALUES
  (1, 'Head Management'),
  (2, 'Dispatcher'),
  (3, 'Maintenance'),
  (4, 'Accounting');

-- ============================================================
-- TABLE 2: users
-- System login accounts. Only staff who need to log in
-- have a record here. Drivers/helpers are in `employees`.
-- ============================================================
CREATE TABLE users (
  user_id       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  role_id       INT UNSIGNED  NOT NULL,
  username      VARCHAR(50)   NOT NULL UNIQUE,
  full_name     VARCHAR(100)  NOT NULL,
  email         VARCHAR(100)  NOT NULL UNIQUE,
  password_hash VARCHAR(255)  NOT NULL COMMENT 'bcrypt hash via password_hash()',
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  auth_version  INT UNSIGNED  NOT NULL DEFAULT 1,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  INDEX idx_users_role   (role_id),
  INDEX idx_users_active (is_active),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 3: employees
-- All company personnel — drivers, helpers, mechanics, etc.
-- Separated from `users` because most field staff do NOT
-- have system logins. A user CAN be linked to an employee
-- record (e.g. a dispatcher who is also tracked as staff),
-- but it is optional.
-- ============================================================
CREATE TABLE employees (
  employee_id     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED  NULL     COMMENT 'Set if this employee also has a system login',
  employee_code   VARCHAR(30)   NOT NULL UNIQUE COMMENT 'e.g. EMP-0001',
  full_name       VARCHAR(100)  NOT NULL,
  position        VARCHAR(80)   NOT NULL COMMENT 'e.g. Driver, Helper, Mechanic',
  contact_number  VARCHAR(20)   NULL,
  address         TEXT          NULL,
  license_number  VARCHAR(50)   NULL     COMMENT 'Drivers only — NULL for helpers/mechanics',
  license_expiry  DATE          NULL     COMMENT 'Drivers only',
  license_type    VARCHAR(20)   NULL     COMMENT 'e.g. Professional, Restriction 1-2-3',
  is_active       TINYINT(1)    NOT NULL DEFAULT 1,
  date_hired      DATE          NULL,
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (employee_id),
  INDEX idx_employees_user     (user_id),
  INDEX idx_employees_position (position),
  INDEX idx_employees_active   (is_active),
  CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 4: trucks
-- Fleet registry. Enriched with body type, capacity, fuel,
-- and chassis/engine numbers for proper fleet management.
-- ============================================================
CREATE TABLE trucks (
  truck_id       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  plate_number   VARCHAR(20)   NOT NULL UNIQUE,
  chassis_number VARCHAR(50)   NULL UNIQUE,
  engine_number  VARCHAR(50)   NULL,
  brand          VARCHAR(80)   NOT NULL,
  model          VARCHAR(100)  NOT NULL,
  year_model     YEAR          NOT NULL,
  body_type      VARCHAR(50)   NULL COMMENT 'e.g. Closed Van, Flatbed, Reefer',
  fuel_type      ENUM(
                   'Diesel',
                   'Gasoline',
                   'LPG',
                   'Electric'
                 )             NOT NULL DEFAULT 'Diesel',
  capacity_tons  DECIMAL(6,2)  NULL COMMENT 'Maximum load in metric tons',
  fuel_efficiency_km_per_liter DECIMAL(6,2) NOT NULL DEFAULT 4.00 COMMENT 'Expected distance per liter',
  status         ENUM(
                   'Available',
                   'Deployed',
                   'Under Maintenance',
                   'Inactive'
                 )             NOT NULL DEFAULT 'Available',
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (truck_id),
  INDEX idx_trucks_status (status),
  INDEX idx_trucks_plate  (plate_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 5: routes
-- Named origin-destination pairs for dispatch.
-- ============================================================
CREATE TABLE routes (
  route_id     INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  route_name   VARCHAR(100)   NOT NULL,
  origin       VARCHAR(150)   NOT NULL,
  destination  VARCHAR(150)   NOT NULL,
  distance_km  DECIMAL(8,2)   NULL,
  is_active    TINYINT(1)     NOT NULL DEFAULT 1,
  created_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (route_id),
  INDEX idx_routes_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 6: dispatch_requests
-- Workflow: Dispatcher submits → Head Management approves/rejects.
-- Driver and helper now reference `employees`, not `drivers`,
-- because helpers are not necessarily licensed drivers.
-- ============================================================
CREATE TABLE dispatch_requests (
  dispatch_id   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  truck_id      INT UNSIGNED  NOT NULL,
  driver_id     INT UNSIGNED  NOT NULL COMMENT 'References employees.employee_id',
  helper_id     INT UNSIGNED  NULL     COMMENT 'References employees.employee_id — optional',
  route_id      INT UNSIGNED  NOT NULL,
  requested_by  INT UNSIGNED  NOT NULL COMMENT 'References users.user_id (Dispatcher)',
  approved_by   INT UNSIGNED  NULL     COMMENT 'References users.user_id (Head Management)',
  scheduled_at  DATETIME      NULL     COMMENT 'Planned departure date and time',
  status        ENUM(
                  'Pending',
                  'Approved',
                  'Rejected'
                )             NOT NULL DEFAULT 'Pending',
  remarks       TEXT          NULL     COMMENT 'Approval/rejection notes from Head Management',
  requested_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at   TIMESTAMP     NULL,
  PRIMARY KEY (dispatch_id),
  INDEX idx_dispatch_truck        (truck_id),
  INDEX idx_dispatch_driver       (driver_id),
  INDEX idx_dispatch_status       (status),
  INDEX idx_dispatch_requested_by (requested_by),
  INDEX idx_dispatch_scheduled    (scheduled_at),
  CONSTRAINT fk_dispatch_truck     FOREIGN KEY (truck_id)    REFERENCES trucks    (truck_id),
  CONSTRAINT fk_dispatch_driver    FOREIGN KEY (driver_id)   REFERENCES employees (employee_id),
  CONSTRAINT fk_dispatch_helper    FOREIGN KEY (helper_id)   REFERENCES employees (employee_id),
  CONSTRAINT fk_dispatch_route     FOREIGN KEY (route_id)    REFERENCES routes    (route_id),
  CONSTRAINT fk_dispatch_requested FOREIGN KEY (requested_by) REFERENCES users   (user_id),
  CONSTRAINT fk_dispatch_approved  FOREIGN KEY (approved_by)  REFERENCES users   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 7: trips
-- Created automatically when a dispatch_request is Approved.
-- One dispatch → one trip. Tracks the lifecycle from loading
-- through delivery. is_late is set by a scheduled check or
-- on every trip status update.
-- ============================================================
CREATE TABLE trips (
  trip_id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  dispatch_id      INT UNSIGNED  NOT NULL UNIQUE COMMENT 'One trip per approved dispatch',
  trip_number      VARCHAR(30)   NOT NULL UNIQUE COMMENT 'Format: TRP-YYYY-NNNN',
  status           ENUM(
                     'Loading',
                     'In Transit',
                     'Unloading',
                     'Completed',
                     'Cancelled'
                   )             NOT NULL DEFAULT 'Loading',
  cargo_description TEXT         NULL     COMMENT 'What is being hauled',
  cargo_weight_tons DECIMAL(6,2) NULL COMMENT 'Actual cargo weight for fuel analysis',
  expected_arrival DATETIME      NULL,
  actual_arrival   DATETIME      NULL,
  is_late          TINYINT(1)    NOT NULL DEFAULT 0,
  created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (trip_id),
  INDEX idx_trips_status   (status),
  INDEX idx_trips_number   (trip_number),
  INDEX idx_trips_dispatch (dispatch_id),
  CONSTRAINT fk_trips_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatch_requests (dispatch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 8: trip_updates
-- Append-only log of every status change on a trip.
-- `status` mirrors the trip's own status ENUM so each row
-- is a timestamped snapshot. location_note captures where
-- the truck is at the time of the update.
-- ============================================================
CREATE TABLE trip_updates (
  update_id     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  trip_id       INT UNSIGNED  NOT NULL,
  updated_by    INT UNSIGNED  NOT NULL COMMENT 'References users.user_id',
  status        ENUM(
                  'Loading',
                  'In Transit',
                  'Unloading',
                  'Completed',
                  'Cancelled'
                )             NOT NULL,
  location_note VARCHAR(255)  NULL COMMENT 'Where the truck is at this update',
  notes         TEXT          NULL,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (update_id),
  INDEX idx_trip_updates_trip    (trip_id),
  INDEX idx_trip_updates_time    (updated_at),
  CONSTRAINT fk_trip_updates_trip    FOREIGN KEY (trip_id)    REFERENCES trips (trip_id),
  CONSTRAINT fk_trip_updates_updater FOREIGN KEY (updated_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 9: incidents
-- Breakdowns, cargo damage, and delays linked to a trip.
-- resolution_notes and resolved_at allow the dispatcher to
-- close out an incident once handled.
-- ============================================================
CREATE TABLE incidents (
  incident_id      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  trip_id          INT UNSIGNED  NOT NULL,
  reported_by      INT UNSIGNED  NOT NULL COMMENT 'References users.user_id',
  incident_type    ENUM(
                     'Vehicle Breakdown',
                     'Item Damage',
                     'Delay',
                     'Other'
                   )             NOT NULL,
  description      TEXT          NOT NULL,
  resolution_notes TEXT          NULL,
  resolved_at      DATETIME      NULL,
  reported_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (incident_id),
  INDEX idx_incidents_trip (trip_id),
  CONSTRAINT fk_incidents_trip     FOREIGN KEY (trip_id)    REFERENCES trips (trip_id),
  CONSTRAINT fk_incidents_reporter FOREIGN KEY (reported_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 10: maintenance_checklists
-- Pre-trip checklist submitted by Maintenance personnel.
-- Linked to dispatch_id (not just trip_id) so it can be
-- submitted before the trip is created (before dispatch is
-- approved). trip_id filled in once trip is created.
-- ============================================================
CREATE TABLE maintenance_checklists (
  checklist_id   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  dispatch_id    INT UNSIGNED  NOT NULL COMMENT 'The dispatch this checklist covers',
  truck_id       INT UNSIGNED  NOT NULL,
  submitted_by   INT UNSIGNED  NOT NULL COMMENT 'References users.user_id (Maintenance role)',
  trip_id        INT UNSIGNED  NULL     COMMENT 'Filled once trip is created from the dispatch',
  -- Checklist items
  lights_ok      TINYINT(1)    NOT NULL DEFAULT 0,
  tires_ok       TINYINT(1)    NOT NULL DEFAULT 0,
  tools_ok       TINYINT(1)    NOT NULL DEFAULT 0,
  medical_kit_ok TINYINT(1)    NOT NULL DEFAULT 0,
  license_ok     TINYINT(1)    NOT NULL DEFAULT 0,
  or_cr_ok       TINYINT(1)    NOT NULL DEFAULT 0,
  waybill_ok     TINYINT(1)    NOT NULL DEFAULT 0,
  fuel_po_ok     TINYINT(1)    NOT NULL DEFAULT 0,
  -- Auto-computed: Passed only if ALL items are 1
  result         ENUM('Passed','Failed') NOT NULL DEFAULT 'Failed',
  notes          TEXT          NULL,
  submitted_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (checklist_id),
  INDEX idx_checklist_dispatch (dispatch_id),
  INDEX idx_checklist_truck    (truck_id),
  INDEX idx_checklist_trip     (trip_id),
  CONSTRAINT fk_checklist_dispatch  FOREIGN KEY (dispatch_id)  REFERENCES dispatch_requests (dispatch_id),
  CONSTRAINT fk_checklist_truck     FOREIGN KEY (truck_id)     REFERENCES trucks (truck_id),
  CONSTRAINT fk_checklist_submitter FOREIGN KEY (submitted_by) REFERENCES users  (user_id),
  CONSTRAINT fk_checklist_trip      FOREIGN KEY (trip_id)      REFERENCES trips  (trip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 11: maintenance_records
-- Every repair, preventive check, and inspection per truck.
-- ============================================================
CREATE TABLE maintenance_records (
  record_id        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  truck_id         INT UNSIGNED  NOT NULL,
  performed_by     INT UNSIGNED  NOT NULL COMMENT 'References users.user_id (Maintenance role)',
  incident_id      INT UNSIGNED  NULL     COMMENT 'Link if this record resolves an incident',
  inspection_id    INT UNSIGNED  NULL     COMMENT 'Vehicle inspection linked to this record',
  maintenance_type ENUM(
                     'Preventive',
                     'Corrective',
                     'Inspection'
                   )             NOT NULL,
  truck_status     ENUM(
                     'Operational',
                     'Scheduled Maintenance',
                     'Under Repair'
                   )             NOT NULL DEFAULT 'Under Repair',
  description      TEXT          NOT NULL,
  cost             DECIMAL(12,2) NULL,
  date_performed   DATE          NOT NULL,
  next_due_date    DATE          NULL,
  created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (record_id),
  INDEX idx_maint_truck    (truck_id),
  INDEX idx_maint_date     (date_performed),
  INDEX idx_maint_incident (incident_id),
  INDEX idx_maint_inspection (inspection_id),
  CONSTRAINT fk_maint_truck     FOREIGN KEY (truck_id)    REFERENCES trucks    (truck_id),
  CONSTRAINT fk_maint_performer FOREIGN KEY (performed_by) REFERENCES users    (user_id),
  CONSTRAINT fk_maint_incident  FOREIGN KEY (incident_id) REFERENCES incidents (incident_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 12: vehicle_inspections
-- Part-level findings from an interactive vehicle inspection.
-- ============================================================
CREATE TABLE vehicle_inspections (
  inspection_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  truck_id      INT UNSIGNED NOT NULL,
  inspected_by  INT UNSIGNED NOT NULL,
  inspection_date DATE NOT NULL,
  notes         TEXT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (inspection_id),
  INDEX idx_vehicle_inspections_truck (truck_id),
  INDEX idx_vehicle_inspections_date (inspection_date),
  CONSTRAINT fk_vehicle_inspections_truck FOREIGN KEY (truck_id) REFERENCES trucks (truck_id),
  CONSTRAINT fk_vehicle_inspections_user FOREIGN KEY (inspected_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicle_inspection_findings (
  finding_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  inspection_id INT UNSIGNED NOT NULL,
  view_name     ENUM('Front','Side','Rear','Top') NOT NULL,
  part_name     VARCHAR(80) NOT NULL,
  `condition`   ENUM('Good','Needs Attention','Damaged','Missing','Leaking','Worn','Not Checked') NOT NULL DEFAULT 'Not Checked',
  notes         VARCHAR(255) NULL,
  PRIMARY KEY (finding_id),
  UNIQUE KEY uq_inspection_part (inspection_id, view_name, part_name),
  CONSTRAINT fk_inspection_findings_inspection FOREIGN KEY (inspection_id) REFERENCES vehicle_inspections (inspection_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 12: parts_inventory
-- Spare parts stock. `created_at` added so you can see when
-- a part was first entered. Movements tracked in parts_movements.
-- ============================================================
CREATE TABLE parts_inventory (
  part_id       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  part_number   VARCHAR(50)   NULL UNIQUE  COMMENT 'Manufacturer or internal part number',
  part_name     VARCHAR(150)  NOT NULL,
  category      VARCHAR(100)  NOT NULL     COMMENT 'e.g. Engine, Tires, Brakes, Electrical',
  unit          VARCHAR(30)   NOT NULL DEFAULT 'pcs' COMMENT 'e.g. pcs, liters, meters',
  quantity      INT UNSIGNED  NOT NULL DEFAULT 0,
  reorder_level INT UNSIGNED  NOT NULL DEFAULT 5,
  unit_cost     DECIMAL(10,2) NULL,
  supplier      VARCHAR(150)  NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (part_id),
  INDEX idx_parts_category (category),
  INDEX idx_parts_stock    (quantity, reorder_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 13: parts_movements
-- Every stock-in and stock-out event for a part.
-- This makes the current `quantity` in parts_inventory auditable
-- and allows the system to show usage history per maintenance job.
-- ============================================================
CREATE TABLE parts_movements (
  movement_id      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  part_id          INT UNSIGNED  NOT NULL,
  maintenance_id   INT UNSIGNED  NULL     COMMENT 'Set if used in a maintenance job',
  recorded_by      INT UNSIGNED  NOT NULL COMMENT 'References users.user_id',
  movement_type    ENUM(
                     'Stock In',
                     'Stock Out',
                     'Adjustment'
                   )             NOT NULL,
  quantity         INT           NOT NULL COMMENT 'Positive = in, negative = out, signed',
  unit_cost        DECIMAL(10,2) NULL     COMMENT 'Cost at time of movement (for Stock In)',
  reference_number VARCHAR(80)   NULL     COMMENT 'PO number, receipt number, etc.',
  notes            TEXT          NULL,
  moved_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (movement_id),
  INDEX idx_movements_part        (part_id),
  INDEX idx_movements_maintenance (maintenance_id),
  INDEX idx_movements_time        (moved_at),
  CONSTRAINT fk_movements_part       FOREIGN KEY (part_id)       REFERENCES parts_inventory   (part_id),
  CONSTRAINT fk_movements_maint      FOREIGN KEY (maintenance_id) REFERENCES maintenance_records (record_id) ON DELETE SET NULL,
  CONSTRAINT fk_movements_recorder   FOREIGN KEY (recorded_by)   REFERENCES users             (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 14: documents
-- Digital repository for all uploaded files.
-- `description` added for searchability.
-- Billing documents now live here; billings just references
-- the trip (not a single document_id) for flexibility.
-- ============================================================
CREATE TABLE documents (
  document_id  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  uploaded_by  INT UNSIGNED    NOT NULL,
  trip_id      INT UNSIGNED    NULL     COMMENT 'Set if document belongs to a specific trip',
  doc_type     ENUM(
                 'OR/CR',
                 'Delivery Receipt',
                 'Waybill',
                 'Maintenance Record',
                 'Billing Record',
                 'Company Document',
                 'Other'
               )               NOT NULL,
  file_name    VARCHAR(255)    NOT NULL COMMENT 'Original filename as uploaded',
  stored_name  VARCHAR(255)    NOT NULL COMMENT 'Renamed filename on disk (UUID-based)',
  file_path    VARCHAR(500)    NOT NULL COMMENT 'Relative path inside /uploads/',
  file_size    BIGINT UNSIGNED NOT NULL COMMENT 'Size in bytes',
  mime_type    VARCHAR(100)    NULL     COMMENT 'e.g. application/pdf, image/jpeg',
  description  VARCHAR(255)    NULL     COMMENT 'Short user-supplied description for search',
  uploaded_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (document_id),
  INDEX idx_documents_uploader (uploaded_by),
  INDEX idx_documents_trip     (trip_id),
  INDEX idx_documents_type     (doc_type),
  CONSTRAINT fk_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES users (user_id),
  CONSTRAINT fk_documents_trip     FOREIGN KEY (trip_id)     REFERENCES trips (trip_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 15: billings
-- Billing statements generated by Accounting.
-- Linked to a trip only (not a single document), because a
-- billing may reference multiple DRs and Waybills.
-- billing_documents junction table below handles that.
-- ============================================================
CREATE TABLE billings (
  billing_id     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  trip_id        INT UNSIGNED  NOT NULL,
  created_by     INT UNSIGNED  NOT NULL COMMENT 'References users.user_id (Accounting role)',
  billing_number VARCHAR(30)   NOT NULL UNIQUE COMMENT 'Format: BIL-YYYY-NNNN',
  client_name    VARCHAR(150)  NULL     COMMENT 'Bill-to name if different from default',
  amount         DECIMAL(14,2) NOT NULL,
  due_date       DATE          NOT NULL,
  status         ENUM(
                   'Unpaid',
                   'Partial',
                   'Paid'
                 )             NOT NULL DEFAULT 'Unpaid',
  notes          TEXT          NULL,
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (billing_id),
  INDEX idx_billings_trip   (trip_id),
  INDEX idx_billings_status (status),
  INDEX idx_billings_due    (due_date),
  CONSTRAINT fk_billings_trip    FOREIGN KEY (trip_id)    REFERENCES trips (trip_id),
  CONSTRAINT fk_billings_creator FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 16: trip_expenses
-- Operating expenses recorded against a completed or active trip. Driver
-- Allowance is a reimbursement expense, separate from Trip Pay wages, and
-- may coexist with Trip Pay for the same trip and crew member.
-- Fuel rows use quantity as liters for anomaly detection.
-- ============================================================
CREATE TABLE trip_expenses (
  expense_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  trip_id       INT UNSIGNED NOT NULL,
  recorded_by   INT UNSIGNED NOT NULL,
  expense_type  ENUM('Fuel','Toll','Driver Allowance','Other') NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  quantity      DECIMAL(10,2) NULL COMMENT 'Fuel quantity in liters when expense_type is Fuel',
  expense_date  DATE NOT NULL,
  notes         VARCHAR(255) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (expense_id),
  INDEX idx_trip_expenses_trip (trip_id),
  INDEX idx_trip_expenses_type (expense_type),
  INDEX idx_trip_expenses_date (expense_date),
  CONSTRAINT fk_trip_expenses_trip FOREIGN KEY (trip_id) REFERENCES trips (trip_id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_expenses_recorder FOREIGN KEY (recorded_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 17: billing_documents
-- Junction table: one billing can reference many documents
-- (e.g. 3 Delivery Receipts + 1 Waybill for one billing).
-- ============================================================
CREATE TABLE billing_documents (
  billing_id   INT UNSIGNED NOT NULL,
  document_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (billing_id, document_id),
  CONSTRAINT fk_billing_docs_billing  FOREIGN KEY (billing_id)  REFERENCES billings   (billing_id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_docs_document FOREIGN KEY (document_id) REFERENCES documents  (document_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 18: collections
-- Payment records per billing. Supports partial payments.
-- ============================================================
CREATE TABLE collections (
  collection_id INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  billing_id    INT UNSIGNED  NOT NULL,
  recorded_by   INT UNSIGNED  NOT NULL COMMENT 'References users.user_id (Accounting role)',
  amount_paid   DECIMAL(14,2) NOT NULL,
  payment_date  DATE          NOT NULL,
  payment_mode  VARCHAR(50)   NOT NULL COMMENT 'Cash, Check, Bank Transfer, GCash, etc.',
  reference_no  VARCHAR(100)  NULL     COMMENT 'Check no., transaction ID, etc.',
  remarks       TEXT          NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (collection_id),
  INDEX idx_collections_billing (billing_id),
  INDEX idx_collections_date    (payment_date),
  CONSTRAINT fk_collections_billing  FOREIGN KEY (billing_id) REFERENCES billings (billing_id),
  CONSTRAINT fk_collections_recorder FOREIGN KEY (recorded_by) REFERENCES users   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 18: audit_logs
-- Immutable trail of every user action. user_id is nullable
-- so system-generated actions (cron jobs, triggers) can also
-- be recorded. ON DELETE SET NULL preserves the log if a user
-- account is deleted.
-- ============================================================
CREATE TABLE audit_logs (
  log_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED    NULL     COMMENT 'NULL for system/automated actions',
  action     VARCHAR(100)    NOT NULL COMMENT 'LOGIN, LOGOUT, CREATE, UPDATE, DELETE, EXPORT…',
  table_name VARCHAR(100)    NOT NULL COMMENT 'Affected table',
  record_id  INT UNSIGNED    NULL     COMMENT 'PK of the affected row',
  old_value  JSON            NULL     COMMENT 'Snapshot before change (UPDATE/DELETE)',
  new_value  JSON            NULL     COMMENT 'Snapshot after change (CREATE/UPDATE)',
  ip_address VARCHAR(45)     NULL     COMMENT 'IPv4 or IPv6',
  logged_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  INDEX idx_audit_user   (user_id),
  INDEX idx_audit_table  (table_name),
  INDEX idx_audit_action (action),
  INDEX idx_audit_logged (logged_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- VIEWS
-- ============================================================

-- v_fleet_status
-- Quick count of trucks grouped by status for the dashboard.
CREATE OR REPLACE VIEW v_fleet_status AS
SELECT
  status,
  COUNT(*) AS truck_count
FROM trucks
GROUP BY status;

-- v_active_trips
-- Running trips with full context: truck, driver, route, late flag.
CREATE OR REPLACE VIEW v_active_trips AS
SELECT
  t.trip_id,
  t.trip_number,
  t.status           AS trip_status,
  t.expected_arrival,
  t.is_late,
  tr.plate_number,
  tr.brand,
  tr.model,
  e_drv.full_name    AS driver_name,
  e_hlp.full_name    AS helper_name,
  r.origin,
  r.destination,
  r.distance_km
FROM trips t
JOIN dispatch_requests dr  ON t.dispatch_id   = dr.dispatch_id
JOIN trucks tr              ON dr.truck_id     = tr.truck_id
JOIN employees e_drv        ON dr.driver_id    = e_drv.employee_id
LEFT JOIN employees e_hlp   ON dr.helper_id    = e_hlp.employee_id
JOIN routes r               ON dr.route_id     = r.route_id
WHERE t.status NOT IN ('Completed', 'Cancelled');

-- v_low_stock_parts
-- Parts at or below reorder level for maintenance alerts.
CREATE OR REPLACE VIEW v_low_stock_parts AS
SELECT
  part_id,
  part_name,
  category,
  unit,
  quantity,
  reorder_level,
  (reorder_level - quantity) AS shortage,
  supplier
FROM parts_inventory
WHERE quantity <= reorder_level;

-- v_billing_summary
-- Per-billing: billed amount, total collected, outstanding balance.
CREATE OR REPLACE VIEW v_billing_summary AS
SELECT
  b.billing_id,
  b.billing_number,
  b.client_name,
  t.trip_number,
  b.amount                                       AS billed_amount,
  b.due_date,
  b.status,
  COALESCE(SUM(c.amount_paid), 0)                AS total_collected,
  (b.amount - COALESCE(SUM(c.amount_paid), 0))   AS balance
FROM billings b
JOIN trips t ON b.trip_id = t.trip_id
LEFT JOIN collections c ON b.billing_id = c.billing_id
GROUP BY b.billing_id;

-- v_license_expiry_alerts
-- Drivers whose license expires within the next 60 days.
CREATE OR REPLACE VIEW v_license_expiry_alerts AS
SELECT
  employee_id,
  employee_code,
  full_name,
  license_number,
  license_expiry,
  DATEDIFF(license_expiry, CURDATE()) AS days_until_expiry
FROM employees
WHERE license_number IS NOT NULL
  AND license_expiry IS NOT NULL
  AND license_expiry <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
  AND is_active = 1
ORDER BY license_expiry ASC;
-- ===== END SOURCE: Mulawin_DB-Phase 1 =====

-- ===== BEGIN SOURCE: clients_migration.sql =====
CREATE TABLE clients (
  client_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_name VARCHAR(150) NOT NULL,
  contact_person VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (client_id),
  UNIQUE KEY uq_clients_name (client_name),
  KEY idx_clients_active (is_active),
  CONSTRAINT fk_clients_created_by FOREIGN KEY (created_by)
    REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== END SOURCE: clients_migration.sql =====

-- ===== BEGIN SOURCE: phase1_foundations_migration.sql =====
CREATE TABLE IF NOT EXISTS permissions (
  permission_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  permission_key VARCHAR(100) NOT NULL,
  module_name VARCHAR(50) NOT NULL,
  action_name VARCHAR(50) NOT NULL,
  description VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (permission_id),
  UNIQUE KEY uq_permissions_key (permission_key),
  INDEX idx_permissions_module (module_name, action_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id)
    REFERENCES roles (role_id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id)
    REFERENCES permissions (permission_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(100) NOT NULL,
  setting_value TEXT NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_app_settings_updater FOREIGN KEY (updated_by)
    REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_role_steps (
  request_type VARCHAR(50) NOT NULL,
  step_order SMALLINT UNSIGNED NOT NULL,
  approver_role_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (request_type, step_order),
  INDEX idx_approval_role_steps_role (approver_role_id),
  CONSTRAINT fk_approval_role_steps_role FOREIGN KEY (approver_role_id)
    REFERENCES roles (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_request_types (
  request_type VARCHAR(50) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (request_type),
  INDEX idx_approval_request_types_active (is_active, display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_requests (
  approval_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_type VARCHAR(50) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending',
  current_step SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (approval_id),
  INDEX idx_approval_entity (entity_type, entity_id, request_type),
  INDEX idx_approval_status (status, requested_at),
  CONSTRAINT fk_approval_requests_requester FOREIGN KEY (requested_by)
    REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_steps (
  approval_step_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  approval_id BIGINT UNSIGNED NOT NULL,
  step_order SMALLINT UNSIGNED NOT NULL,
  approver_role_id INT UNSIGNED NOT NULL,
  assigned_to INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending',
  decided_by INT UNSIGNED NULL,
  decision_comment TEXT NULL,
  decided_at DATETIME NULL,
  PRIMARY KEY (approval_step_id),
  UNIQUE KEY uq_approval_steps_order (approval_id, step_order),
  INDEX idx_approval_steps_inbox (approver_role_id, status, assigned_to),
  CONSTRAINT fk_approval_steps_request FOREIGN KEY (approval_id)
    REFERENCES approval_requests (approval_id) ON DELETE CASCADE,
  CONSTRAINT fk_approval_steps_role FOREIGN KEY (approver_role_id)
    REFERENCES roles (role_id),
  CONSTRAINT fk_approval_steps_assignee FOREIGN KEY (assigned_to)
    REFERENCES users (user_id) ON DELETE SET NULL,
  CONSTRAINT fk_approval_steps_decider FOREIGN KEY (decided_by)
    REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_history (
  history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  approval_id BIGINT UNSIGNED NOT NULL,
  approval_step_id BIGINT UNSIGNED NULL,
  actor_user_id INT UNSIGNED NULL,
  action VARCHAR(30) NOT NULL,
  comment TEXT NULL,
  old_status VARCHAR(20) NULL,
  new_status VARCHAR(20) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (history_id),
  INDEX idx_approval_history_request (approval_id, created_at),
  CONSTRAINT fk_approval_history_request FOREIGN KEY (approval_id)
    REFERENCES approval_requests (approval_id) ON DELETE CASCADE,
  CONSTRAINT fk_approval_history_step FOREIGN KEY (approval_step_id)
    REFERENCES approval_steps (approval_step_id) ON DELETE SET NULL,
  CONSTRAINT fk_approval_history_actor FOREIGN KEY (actor_user_id)
    REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
  attachment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  linked_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attachment_id),
  INDEX idx_attachments_entity (entity_type, entity_id, category),
  CONSTRAINT fk_attachments_linker FOREIGN KEY (linked_by)
    REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachment_versions (
  version_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attachment_id BIGINT UNSIGNED NOT NULL,
  document_id INT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version_id),
  UNIQUE KEY uq_attachment_version_number (attachment_id, version_number),
  UNIQUE KEY uq_attachment_document (attachment_id, document_id),
  INDEX idx_attachment_versions_document (document_id),
  CONSTRAINT fk_attachment_versions_attachment FOREIGN KEY (attachment_id)
    REFERENCES attachments (attachment_id) ON DELETE CASCADE,
  CONSTRAINT fk_attachment_versions_uploader FOREIGN KEY (uploaded_by)
    REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS idempotency_requests (
  idempotency_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  action_key VARCHAR(100) NOT NULL,
  request_key VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (idempotency_id),
  UNIQUE KEY uq_idempotency_user_action_key (user_id, action_key, request_key),
  INDEX idx_idempotency_expiry (expires_at),
  CONSTRAINT fk_idempotency_user FOREIGN KEY (user_id)
    REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminder_deliveries (
  delivery_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipient_user_id INT UNSIGNED NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  reminder_days SMALLINT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (delivery_id),
  UNIQUE KEY uq_reminder_delivery (recipient_user_id, entity_type, entity_id, reminder_days, due_date),
  CONSTRAINT fk_reminder_deliveries_recipient FOREIGN KEY (recipient_user_id)
    REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('legacy.role.1', 'legacy', 'head_management', 'Access granted to legacy Head Management surfaces'),
  ('legacy.role.2', 'legacy', 'dispatcher', 'Access granted to legacy Dispatcher surfaces'),
  ('legacy.role.3', 'legacy', 'maintenance', 'Access granted to legacy Maintenance surfaces'),
  ('legacy.role.4', 'legacy', 'accounting', 'Access granted to legacy Accounting surfaces'),
  ('roles.manage', 'admin', 'manage_roles', 'Create and manage system roles'),
  ('permissions.manage', 'admin', 'manage_permissions', 'Manage role permission grants'),
  ('approvals.manage', 'approvals', 'manage_chains', 'Configure approver roles and approval order'),
  ('users.view', 'users', 'view', 'View user and employee accounts'),
  ('users.manage', 'users', 'manage', 'Create, edit, and deactivate user accounts'),
  ('fleet.view', 'fleet', 'view', 'View fleet records and availability'),
  ('fleet.manage', 'fleet', 'manage', 'Create and update fleet records'),
  ('fleet.status.update', 'fleet', 'update_status', 'Update vehicle availability and status'),
  ('employees.view', 'employees', 'view', 'View employee and crew records'),
  ('employees.manage', 'employees', 'manage', 'Manage employee and crew records'),
  ('clients.view', 'clients', 'view', 'View client records'),
  ('clients.manage', 'clients', 'manage', 'Manage client records'),
  ('routes.view', 'routes', 'view', 'View routes and locations'),
  ('routes.manage', 'routes', 'manage', 'Manage routes and locations'),
  ('trips.view', 'trips', 'view', 'View trips and dispatch requests'),
  ('trips.create', 'trips', 'create', 'Create trip or dispatch requests'),
  ('trips.update', 'trips', 'update', 'Update trip information and progress'),
  ('trips.assign', 'trips', 'assign', 'Assign trucks and crew to trips'),
  ('trips.approve', 'trips', 'approve', 'Approve trip requests'),
  ('dispatch.clear', 'dispatch', 'clear', 'Grant dispatch clearance'),
  ('trips.complete', 'trips', 'complete', 'Submit completed trip records'),
  ('approvals.review', 'approvals', 'review', 'Review assigned approval requests'),
  ('maintenance.view', 'maintenance', 'view', 'View maintenance history and jobs'),
  ('maintenance.manage', 'maintenance', 'manage', 'Create and update maintenance records'),
  ('parts.view', 'inventory', 'view', 'View inventory and stock movements'),
  ('parts.manage', 'inventory', 'manage', 'Manage inventory stock and adjustments'),
  ('billing.view', 'billing', 'view', 'View billing and collection records'),
  ('billing.manage', 'billing', 'manage', 'Manage billing and collection records'),
  ('finance.view', 'finance', 'view', 'View finance records and reports'),
  ('finance.manage', 'finance', 'manage', 'Manage finance records'),
  ('payroll.view', 'payroll', 'view', 'View payroll records'),
  ('payroll.manage', 'payroll', 'manage', 'Prepare and manage payroll'),
  ('documents.view', 'documents', 'view', 'View authorized documents'),
  ('documents.upload', 'documents', 'upload', 'Upload documents'),
  ('documents.download', 'documents', 'download', 'Download authorized documents'),
  ('documents.manage', 'documents', 'manage', 'Manage document records'),
  ('reports.view', 'reports', 'view', 'View reports'),
  ('reports.export', 'reports', 'export', 'Export report data'),
  ('audit.view', 'audit', 'view', 'View audit history'),
  ('messages.view', 'messages', 'view', 'View authorized conversations'),
  ('messages.send', 'messages', 'send', 'Send authorized messages');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_id = 1
;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'legacy.role.2', 'trips.view', 'trips.create', 'trips.update', 'trips.assign',
  'trips.complete', 'fleet.view', 'employees.view', 'clients.view', 'routes.view',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'reports.export'
)
WHERE r.role_id = 2;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'legacy.role.3', 'fleet.view', 'fleet.status.update', 'maintenance.view',
  'maintenance.manage', 'parts.view', 'parts.manage', 'documents.view',
  'documents.upload', 'documents.download', 'reports.view'
)
WHERE r.role_id = 3;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'legacy.role.4', 'clients.view', 'billing.view', 'billing.manage',
  'finance.view', 'finance.manage', 'payroll.view', 'payroll.manage',
  'documents.view', 'documents.upload', 'documents.download', 'documents.manage', 'reports.view',
  'reports.export'
)
WHERE r.role_id = 4;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
  ('reminder_thresholds_days', '60,30,15,7'),
  ('timezone', 'Asia/Manila');

INSERT IGNORE INTO approval_request_types (request_type, display_name) VALUES
  ('dispatch', 'Dispatch request'),
  ('trip_request', 'Trip request'),
  ('purchase_request', 'Purchase request'),
  ('fund_request', 'Fund request'),
  ('voucher', 'Payment voucher'),
  ('payroll', 'Payroll');

INSERT IGNORE INTO approval_role_steps (request_type, step_order, approver_role_id)
SELECT request_type, 1, 1
FROM (
  SELECT 'dispatch' AS request_type
  UNION ALL SELECT 'trip_request'
  UNION ALL SELECT 'purchase_request'
  UNION ALL SELECT 'fund_request'
  UNION ALL SELECT 'voucher'
  UNION ALL SELECT 'payroll'
) AS default_steps
WHERE EXISTS (SELECT 1 FROM roles WHERE role_id = 1);
-- ===== END SOURCE: phase1_foundations_migration.sql =====

-- ===== BEGIN SOURCE: phase2_master_data_migration.sql =====
ALTER TABLE trucks
  ADD COLUMN unit_number VARCHAR(30) NULL,
  ADD COLUMN truck_type VARCHAR(50) NULL,
  ADD COLUMN mv_file_number VARCHAR(50) NULL,
  ADD COLUMN registration_expiry DATE NULL,
  ADD COLUMN insurance_provider VARCHAR(150) NULL,
  ADD COLUMN insurance_policy_number VARCHAR(80) NULL,
  ADD COLUMN insurance_expiry DATE NULL,
  ADD UNIQUE KEY uq_trucks_unit_number (unit_number),
  ADD INDEX idx_trucks_registration_expiry (registration_expiry),
  ADD INDEX idx_trucks_insurance_expiry (insurance_expiry);

ALTER TABLE employees
  ADD COLUMN employment_type ENUM('Employee', 'Contractor') NOT NULL DEFAULT 'Employee',
  ADD COLUMN contractor_company VARCHAR(150) NULL,
  ADD COLUMN date_resigned DATE NULL,
  ADD COLUMN resignation_reason VARCHAR(500) NULL,
  ADD INDEX idx_employees_employment_type (employment_type),
  ADD INDEX idx_employees_resigned (date_resigned);

ALTER TABLE clients
  ADD COLUMN client_type ENUM('Direct', 'Forwarder', 'End Client') NOT NULL DEFAULT 'Direct',
  ADD COLUMN parent_client_id INT UNSIGNED NULL,
  ADD INDEX idx_clients_parent (parent_client_id),
  ADD CONSTRAINT fk_clients_parent
    FOREIGN KEY (parent_client_id) REFERENCES clients (client_id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS client_locations (
  location_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NOT NULL,
  location_name VARCHAR(150) NOT NULL,
  location_type ENUM('Pickup', 'Delivery', 'Both') NOT NULL DEFAULT 'Both',
  address VARCHAR(500) NOT NULL,
  contact_person VARCHAR(150) NULL,
  contact_number VARCHAR(50) NULL,
  notes VARCHAR(1000) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (location_id),
  UNIQUE KEY uq_client_location_name (client_id, location_name),
  INDEX idx_client_locations_active (client_id, is_active),
  CONSTRAINT fk_client_locations_client
    FOREIGN KEY (client_id) REFERENCES clients (client_id) ON DELETE RESTRICT,
  CONSTRAINT fk_client_locations_creator
    FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_rates (
  rate_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NOT NULL,
  origin_location_id INT UNSIGNED NOT NULL,
  destination_location_id INT UNSIGNED NOT NULL,
  service_name VARCHAR(100) NOT NULL DEFAULT 'Standard',
  rate_basis ENUM('Per Trip', 'Per Ton', 'Per Kilometer', 'Per Unit') NOT NULL DEFAULT 'Per Trip',
  rate_amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'PHP',
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  notes VARCHAR(1000) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rate_id),
  INDEX idx_client_rates_lookup (client_id, origin_location_id, destination_location_id, effective_from, effective_to),
  INDEX idx_client_rates_active (client_id, is_active),
  CONSTRAINT fk_client_rates_client
    FOREIGN KEY (client_id) REFERENCES clients (client_id) ON DELETE RESTRICT,
  CONSTRAINT fk_client_rates_origin
    FOREIGN KEY (origin_location_id) REFERENCES client_locations (location_id) ON DELETE RESTRICT,
  CONSTRAINT fk_client_rates_destination
    FOREIGN KEY (destination_location_id) REFERENCES client_locations (location_id) ON DELETE RESTRICT,
  CONSTRAINT fk_client_rates_creator
    FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE RESTRICT,
  CONSTRAINT chk_client_rates_amount CHECK (rate_amount >= 0),
  CONSTRAINT chk_client_rates_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (setting_key, setting_value)
VALUES ('crew_module_label', 'Drivers & Helpers');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, permission_id
FROM permissions;
-- ===== END SOURCE: phase2_master_data_migration.sql =====

-- ===== BEGIN SOURCE: phase3_roles_migration.sql =====
UPDATE roles
SET role_name = 'Admin'
WHERE role_id = 1 AND role_name = 'Head Management';

INSERT INTO roles (role_name)
SELECT 'Operations Head'
WHERE NOT EXISTS (
  SELECT 1 FROM roles WHERE role_name = 'Operations Head'
);

INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('operations.dashboard.view', 'operations', 'view_dashboard', 'View the Operations Head dashboard'),
  ('routes.request', 'routes', 'request', 'Submit new route requests'),
  ('routes.approve', 'routes', 'approve', 'Approve or reject route requests'),
  ('incidents.manage', 'incidents', 'manage', 'Report and resolve trip incidents');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, permission_id
FROM permissions;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Operations Head'
  AND p.permission_key IN (
    'operations.dashboard.view',
    'trips.view',
    'trips.approve',
    'approvals.review',
    'fleet.view',
    'employees.view',
    'clients.view',
    'routes.view',
    'routes.manage',
    'routes.approve',
    'incidents.manage',
    'reports.export'
  );

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Dispatcher'
  AND p.permission_key IN (
    'legacy.role.2',
    'trips.view',
    'trips.create',
    'trips.update',
    'trips.assign',
    'trips.complete',
    'fleet.view',
    'employees.view',
    'clients.view',
    'routes.view',
    'routes.request',
    'incidents.manage',
    'documents.view',
    'documents.upload',
    'documents.download',
    'reports.view',
    'reports.export'
  );

UPDATE approval_role_steps ars
JOIN roles operation_role ON operation_role.role_name = 'Operations Head'
SET ars.approver_role_id = operation_role.role_id
WHERE ars.request_type = 'dispatch'
  AND ars.step_order = 1
  AND ars.approver_role_id = 1;
-- ===== END SOURCE: phase3_roles_migration.sql =====

-- ===== BEGIN SOURCE: phase3_dispatch_handoff_migration.sql =====
ALTER TABLE dispatch_requests
  ADD COLUMN shift ENUM('Day', 'Night') NOT NULL DEFAULT 'Day' AFTER scheduled_at,
  ADD COLUMN dispatch_instruction_id INT UNSIGNED NULL AFTER shift,
  ADD UNIQUE KEY uq_dispatch_instruction (dispatch_instruction_id),
  ADD INDEX idx_dispatch_shift_schedule (shift, scheduled_at);

ALTER TABLE trips
  ADD COLUMN shift ENUM('Day', 'Night') NOT NULL DEFAULT 'Day' AFTER status,
  ADD INDEX idx_trip_shift_status (shift, status);

CREATE TABLE dispatch_instructions (
  instruction_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  shift_date DATE NOT NULL,
  shift ENUM('Day', 'Night') NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  route_id INT UNSIGNED NOT NULL,
  scheduled_at DATETIME NOT NULL,
  unit_count DECIMAL(8,2) NULL,
  instruction_notes TEXT NULL,
  status ENUM('Open', 'Encoded', 'Cancelled') NOT NULL DEFAULT 'Open',
  created_by INT UNSIGNED NOT NULL,
  encoded_by INT UNSIGNED NULL,
  dispatch_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_at DATETIME NULL,
  PRIMARY KEY (instruction_id),
  UNIQUE KEY uq_dispatch_instruction_dispatch (dispatch_id),
  INDEX idx_dispatch_instruction_inbox (status, shift_date, shift, scheduled_at),
  CONSTRAINT fk_dispatch_instruction_client
    FOREIGN KEY (client_id) REFERENCES clients (client_id),
  CONSTRAINT fk_dispatch_instruction_route
    FOREIGN KEY (route_id) REFERENCES routes (route_id),
  CONSTRAINT fk_dispatch_instruction_creator
    FOREIGN KEY (created_by) REFERENCES users (user_id),
  CONSTRAINT fk_dispatch_instruction_encoder
    FOREIGN KEY (encoded_by) REFERENCES users (user_id) ON DELETE SET NULL,
  CONSTRAINT fk_dispatch_instruction_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_requests (dispatch_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE dispatch_requests
  ADD CONSTRAINT fk_dispatch_instruction
    FOREIGN KEY (dispatch_instruction_id)
    REFERENCES dispatch_instructions (instruction_id) ON DELETE SET NULL;

INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('dispatch.instructions.manage', 'dispatch', 'manage_instructions', 'Create and cancel shift dispatch instructions'),
  ('dispatch.instructions.encode', 'dispatch', 'encode_instructions', 'Encode Operations Head dispatch instructions');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, permission_id
FROM permissions;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Operations Head'
  AND p.permission_key = 'dispatch.instructions.manage';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Dispatcher'
  AND p.permission_key = 'dispatch.instructions.encode';
-- ===== END SOURCE: phase3_dispatch_handoff_migration.sql =====

-- ===== BEGIN SOURCE: dispatch_client_migration.sql =====
-- Add the client captured when a dispatcher creates a dispatch.
ALTER TABLE dispatch_requests
  ADD COLUMN client_name VARCHAR(150) NULL AFTER remarks;
-- ===== END SOURCE: dispatch_client_migration.sql =====

-- ===== BEGIN SOURCE: phase3_trip_operations_migration.sql =====
ALTER TABLE dispatch_requests
  ADD COLUMN client_id INT UNSIGNED NULL AFTER client_name,
  ADD COLUMN billing_client_id INT UNSIGNED NULL AFTER client_id,
  ADD COLUMN origin_location_id INT UNSIGNED NULL AFTER billing_client_id,
  ADD COLUMN destination_location_id INT UNSIGNED NULL AFTER origin_location_id,
  ADD COLUMN second_driver_id INT UNSIGNED NULL AFTER driver_id,
  ADD COLUMN client_rate_id INT UNSIGNED NULL AFTER destination_location_id,
  ADD COLUMN client_rate_amount DECIMAL(12,2) NULL AFTER client_rate_id,
  ADD COLUMN client_rate_currency CHAR(3) NULL AFTER client_rate_amount,
  ADD COLUMN client_rate_basis ENUM('Per Trip', 'Per Ton', 'Per Kilometer', 'Per Unit') NULL AFTER client_rate_currency,
  ADD COLUMN booking_reference VARCHAR(100) NULL AFTER client_name,
  ADD COLUMN waybill_reference VARCHAR(100) NULL AFTER booking_reference,
  ADD COLUMN unit_count DECIMAL(8,2) NULL AFTER waybill_reference,
  ADD COLUMN expected_arrival DATETIME NULL AFTER scheduled_at,
  ADD INDEX idx_dispatch_client_schedule (client_id, scheduled_at),
  ADD INDEX idx_dispatch_second_driver (second_driver_id),
  ADD CONSTRAINT fk_dispatch_client
    FOREIGN KEY (client_id) REFERENCES clients (client_id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_dispatch_billing_client
    FOREIGN KEY (billing_client_id) REFERENCES clients (client_id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_dispatch_origin_location
    FOREIGN KEY (origin_location_id) REFERENCES client_locations (location_id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_dispatch_destination_location
    FOREIGN KEY (destination_location_id) REFERENCES client_locations (location_id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_dispatch_second_driver
    FOREIGN KEY (second_driver_id) REFERENCES employees (employee_id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_dispatch_client_rate
    FOREIGN KEY (client_rate_id) REFERENCES client_rates (rate_id) ON DELETE SET NULL;

UPDATE dispatch_requests dr
JOIN clients c ON c.client_name = dr.client_name
SET dr.client_id = c.client_id
WHERE dr.client_id IS NULL;

ALTER TABLE trips
  ADD COLUMN actual_departure_at DATETIME NULL AFTER expected_arrival;

CREATE INDEX idx_dispatch_truck_schedule_status
  ON dispatch_requests (truck_id, scheduled_at, status);

CREATE INDEX idx_dispatch_driver_schedule_status
  ON dispatch_requests (driver_id, scheduled_at, status);
-- ===== END SOURCE: phase3_trip_operations_migration.sql =====

-- ===== BEGIN SOURCE: phase3_dispatch_batches_migration.sql =====
CREATE TABLE dispatch_instruction_batches (
  batch_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_by INT UNSIGNED NOT NULL,
  instruction_count SMALLINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (batch_id),
  INDEX idx_dispatch_instruction_batch_created (created_at),
  CONSTRAINT fk_dispatch_instruction_batch_creator
    FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE dispatch_instructions
  ADD COLUMN batch_id INT UNSIGNED NULL AFTER instruction_id,
  ADD INDEX idx_dispatch_instruction_batch (batch_id, status),
  ADD CONSTRAINT fk_dispatch_instruction_batch
    FOREIGN KEY (batch_id) REFERENCES dispatch_instruction_batches (batch_id)
    ON DELETE SET NULL;
-- ===== END SOURCE: phase3_dispatch_batches_migration.sql =====

-- ===== BEGIN SOURCE: phase4_trip_inspections_migration.sql =====
ALTER TABLE vehicle_inspections
  ADD COLUMN trip_id INT UNSIGNED NULL AFTER truck_id,
  ADD COLUMN inspection_stage ENUM('General', 'Departure', 'Return') NOT NULL DEFAULT 'General'
    AFTER trip_id,
  ADD UNIQUE KEY uq_vehicle_inspection_trip_stage (trip_id, inspection_stage),
  ADD CONSTRAINT fk_vehicle_inspections_trip
    FOREIGN KEY (trip_id) REFERENCES trips (trip_id) ON DELETE SET NULL;
-- ===== END SOURCE: phase4_trip_inspections_migration.sql =====

-- ===== BEGIN SOURCE: phase4_maintenance_form_timers_migration.sql =====
CREATE TABLE maintenance_form_timers (
  timer_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  form_type ENUM('checklist', 'inspection_general', 'inspection_departure', 'inspection_return') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  PRIMARY KEY (timer_id),
  UNIQUE KEY uq_maintenance_form_timer_token (token_hash),
  INDEX idx_maintenance_form_timer_cleanup (expires_at, consumed_at),
  INDEX idx_maintenance_form_timer_owner (user_id, form_type, target_id, started_at),
  CONSTRAINT fk_maintenance_form_timer_user
    FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== END SOURCE: phase4_maintenance_form_timers_migration.sql =====

-- ===== BEGIN SOURCE: phase4_preventive_maintenance_schedules_migration.sql =====
CREATE TABLE preventive_maintenance_schedules (
  schedule_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  truck_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  service_name VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  interval_days SMALLINT UNSIGNED NOT NULL,
  next_due_date DATE NOT NULL,
  last_completed_at DATETIME NULL,
  last_maintenance_record_id INT UNSIGNED NULL,
  status ENUM('Active', 'Paused', 'Archived') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (schedule_id),
  INDEX idx_pm_schedule_truck_status_due (truck_id, status, next_due_date),
  INDEX idx_pm_schedule_status_due (status, next_due_date),
  CONSTRAINT fk_pm_schedule_truck
    FOREIGN KEY (truck_id) REFERENCES trucks (truck_id),
  CONSTRAINT fk_pm_schedule_creator
    FOREIGN KEY (created_by) REFERENCES users (user_id),
  CONSTRAINT fk_pm_schedule_last_record
    FOREIGN KEY (last_maintenance_record_id)
    REFERENCES maintenance_records (record_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== END SOURCE: phase4_preventive_maintenance_schedules_migration.sql =====

-- ===== BEGIN SOURCE: phase4_repair_work_orders_migration.sql =====
CREATE TABLE repair_work_orders (
  work_order_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  truck_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  priority ENUM('Low', 'Normal', 'High', 'Urgent') NOT NULL DEFAULT 'Normal',
  status ENUM('Open', 'In Progress', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Open',
  expected_completion_at DATETIME NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  closure_notes VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (work_order_id),
  INDEX idx_repair_work_order_truck_status (truck_id, status),
  INDEX idx_repair_work_order_status_eta (status, expected_completion_at),
  CONSTRAINT fk_repair_work_order_truck
    FOREIGN KEY (truck_id) REFERENCES trucks (truck_id),
  CONSTRAINT fk_repair_work_order_creator
    FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== END SOURCE: phase4_repair_work_orders_migration.sql =====

-- ===== BEGIN SOURCE: phase4_truck_status_history_migration.sql =====
CREATE TABLE truck_status_history (
  status_history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  truck_id INT UNSIGNED NOT NULL,
  previous_status ENUM('Available', 'Deployed', 'Under Maintenance', 'Inactive') NULL,
  new_status ENUM('Available', 'Deployed', 'Under Maintenance', 'Inactive') NOT NULL,
  reason VARCHAR(500) NOT NULL,
  changed_by INT UNSIGNED NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (status_history_id),
  INDEX idx_truck_status_history_truck_time (truck_id, changed_at, status_history_id),
  INDEX idx_truck_status_history_changed_by (changed_by),
  CONSTRAINT fk_truck_status_history_truck
    FOREIGN KEY (truck_id) REFERENCES trucks (truck_id) ON DELETE CASCADE,
  CONSTRAINT fk_truck_status_history_user
    FOREIGN KEY (changed_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO truck_status_history
    (truck_id, previous_status, new_status, reason, changed_by)
SELECT truck_id, NULL, status,
       'Status snapshot at history rollout; earlier changes are unavailable.',
       NULL
FROM trucks;
-- ===== END SOURCE: phase4_truck_status_history_migration.sql =====

-- ===== BEGIN SOURCE: vehicle_inspection_migration.sql =====
CREATE TABLE IF NOT EXISTS vehicle_inspections (
  inspection_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  truck_id INT UNSIGNED NOT NULL,
  inspected_by INT UNSIGNED NOT NULL,
  inspection_date DATE NOT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (inspection_id),
  INDEX idx_vehicle_inspections_truck (truck_id),
  INDEX idx_vehicle_inspections_date (inspection_date),
  CONSTRAINT fk_vehicle_inspections_truck FOREIGN KEY (truck_id) REFERENCES trucks (truck_id),
  CONSTRAINT fk_vehicle_inspections_user FOREIGN KEY (inspected_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_inspection_findings (
  finding_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  inspection_id INT UNSIGNED NOT NULL,
  view_name ENUM('Front','Side','Rear','Top') NOT NULL,
  part_name VARCHAR(80) NOT NULL,
  `condition` ENUM('Good','Needs Attention','Damaged','Missing','Leaking','Worn','Not Checked') NOT NULL DEFAULT 'Not Checked',
  notes VARCHAR(255) NULL,
  PRIMARY KEY (finding_id),
  UNIQUE KEY uq_inspection_part (inspection_id, view_name, part_name),
  CONSTRAINT fk_inspection_findings_inspection FOREIGN KEY (inspection_id) REFERENCES vehicle_inspections (inspection_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE vehicle_inspection_findings
  MODIFY view_name ENUM('Front','Side','Rear','Top') NOT NULL;

ALTER TABLE vehicle_inspection_findings
  MODIFY `condition` ENUM('Good','Needs Attention','Damaged','Missing','Leaking','Worn','Not Checked')
  NOT NULL DEFAULT 'Not Checked';

SET @add_maintenance_inspection_id = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_records'
      AND COLUMN_NAME = 'inspection_id') = 0,
  'ALTER TABLE maintenance_records ADD COLUMN inspection_id INT UNSIGNED NULL COMMENT ''Vehicle inspection linked to this record''',
  'SELECT 1'
);
PREPARE add_maintenance_inspection_id_stmt FROM @add_maintenance_inspection_id;
EXECUTE add_maintenance_inspection_id_stmt;
DEALLOCATE PREPARE add_maintenance_inspection_id_stmt;

SET @add_maintenance_inspection_index = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_records'
      AND INDEX_NAME = 'idx_maint_inspection') = 0,
  'CREATE INDEX idx_maint_inspection ON maintenance_records (inspection_id)',
  'SELECT 1'
);
PREPARE add_maintenance_inspection_index_stmt FROM @add_maintenance_inspection_index;
EXECUTE add_maintenance_inspection_index_stmt;
DEALLOCATE PREPARE add_maintenance_inspection_index_stmt;

SET @add_maintenance_inspection_fk = IF(
  (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_records'
      AND CONSTRAINT_NAME = 'fk_maint_inspection') = 0,
  'ALTER TABLE maintenance_records ADD CONSTRAINT fk_maint_inspection FOREIGN KEY (inspection_id) REFERENCES vehicle_inspections (inspection_id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE add_maintenance_inspection_fk_stmt FROM @add_maintenance_inspection_fk;
EXECUTE add_maintenance_inspection_fk_stmt;
DEALLOCATE PREPARE add_maintenance_inspection_fk_stmt;
-- ===== END SOURCE: vehicle_inspection_migration.sql =====

-- ===== BEGIN SOURCE: phase5_rbac_roles_migration.sql =====
INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('company.dashboard.view', 'company', 'view_dashboard', 'View the company-wide management dashboard'),
  ('dispatch.shift.day', 'dispatch', 'day_shift', 'Encode Day-shift dispatches'),
  ('dispatch.shift.night', 'dispatch', 'night_shift', 'Encode Night-shift dispatches'),
  ('dispatch.vehicle.car_carrier', 'dispatch', 'car_carrier', 'Encode Car Carrier dispatches'),
  ('dispatch.vehicle.container', 'dispatch', 'container', 'Encode Container dispatches'),
  ('dispatch.vehicle.wing_van', 'dispatch', 'wing_van', 'Encode Wing Van dispatches');

INSERT INTO roles (role_name)
SELECT requested.role_name
FROM (
  SELECT 'Management / Head' AS role_name
  UNION ALL SELECT 'Admin Officer'
  UNION ALL SELECT 'Car Carrier Dispatcher - Day'
  UNION ALL SELECT 'Car Carrier Dispatcher - Night'
  UNION ALL SELECT 'Container & Wing Van Dispatcher - Day'
  UNION ALL SELECT 'Container & Wing Van Dispatcher - Night'
  UNION ALL SELECT 'Purchasing Officer'
  UNION ALL SELECT 'Finance'
  UNION ALL SELECT 'Billing and Collection'
  UNION ALL SELECT 'Payroll'
) requested
WHERE NOT EXISTS (
  SELECT 1 FROM roles existing WHERE existing.role_name = requested.role_name
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, permission_id
FROM permissions;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'company.dashboard.view',
  'reports.view', 'reports.export',
  'trips.view', 'fleet.view', 'maintenance.view',
  'billing.view', 'finance.view', 'payroll.view',
  'employees.view', 'employees.manage', 'clients.view', 'routes.view',
  'documents.view', 'audit.view', 'users.view', 'users.manage'
)
WHERE r.role_name = 'Management / Head';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'trips.view', 'fleet.view',
  'employees.view', 'employees.manage',
  'clients.view', 'routes.view',
  'billing.view', 'finance.view',
  'documents.view', 'documents.upload', 'documents.download', 'documents.manage',
  'reports.view', 'reports.export', 'audit.view'
)
WHERE r.role_name = 'Admin Officer';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'operations.dashboard.view',
  'dispatch.instructions.manage',
  'trips.view', 'trips.approve', 'trips.assign', 'dispatch.clear',
  'approvals.review',
  'fleet.view', 'employees.view', 'clients.view', 'routes.view',
  'maintenance.view', 'incidents.manage', 'documents.view',
  'reports.view', 'reports.export'
)
WHERE r.role_name = 'Operations Head';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'dispatch.instructions.encode',
  'trips.view', 'trips.create', 'trips.update', 'trips.assign', 'trips.complete',
  'fleet.view', 'employees.view', 'clients.view', 'routes.view', 'routes.request',
  'incidents.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'dispatch.shift.day', 'dispatch.vehicle.car_carrier'
)
WHERE r.role_name = 'Car Carrier Dispatcher - Day';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'dispatch.instructions.encode',
  'trips.view', 'trips.create', 'trips.update', 'trips.assign', 'trips.complete',
  'fleet.view', 'employees.view', 'clients.view', 'routes.view', 'routes.request',
  'incidents.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'dispatch.shift.night', 'dispatch.vehicle.car_carrier'
)
WHERE r.role_name = 'Car Carrier Dispatcher - Night';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'dispatch.instructions.encode',
  'trips.view', 'trips.create', 'trips.update', 'trips.assign', 'trips.complete',
  'fleet.view', 'employees.view', 'clients.view', 'routes.view', 'routes.request',
  'incidents.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'dispatch.shift.day',
  'dispatch.vehicle.container', 'dispatch.vehicle.wing_van'
)
WHERE r.role_name = 'Container & Wing Van Dispatcher - Day';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'dispatch.instructions.encode',
  'trips.view', 'trips.create', 'trips.update', 'trips.assign', 'trips.complete',
  'fleet.view', 'employees.view', 'clients.view', 'routes.view', 'routes.request',
  'incidents.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'dispatch.shift.night',
  'dispatch.vehicle.container', 'dispatch.vehicle.wing_van'
)
WHERE r.role_name = 'Container & Wing Van Dispatcher - Night';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'fleet.view', 'maintenance.view',
  'parts.view', 'parts.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view'
)
WHERE r.role_name = 'Purchasing Officer';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'trips.view', 'billing.view', 'finance.view', 'finance.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'reports.export'
)
WHERE r.role_name = 'Finance';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'clients.view', 'clients.manage', 'trips.view',
  'billing.view', 'billing.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'reports.export'
)
WHERE r.role_name = 'Billing and Collection';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'employees.view', 'trips.view', 'payroll.view', 'payroll.manage',
  'documents.view', 'documents.upload', 'documents.download',
  'reports.view', 'reports.export'
)
WHERE r.role_name = 'Payroll';

UPDATE trucks
SET truck_type = CASE LOWER(TRIM(truck_type))
  WHEN 'car carrier' THEN 'Car Carrier'
  WHEN 'car-carrier' THEN 'Car Carrier'
  WHEN 'car_carrier' THEN 'Car Carrier'
  WHEN 'container' THEN 'Container'
  WHEN 'container truck' THEN 'Container'
  WHEN 'container trailer' THEN 'Container'
  WHEN 'wing van' THEN 'Wing Van'
  WHEN 'wing-van' THEN 'Wing Van'
  WHEN 'wingvan' THEN 'Wing Van'
  ELSE truck_type
END
WHERE LOWER(TRIM(truck_type)) IN (
  'car carrier', 'car-carrier', 'car_carrier',
  'container', 'container truck', 'container trailer',
  'wing van', 'wing-van', 'wingvan'
);
-- ===== END SOURCE: phase5_rbac_roles_migration.sql =====

-- ===== BEGIN SOURCE: phase5_purchasing_migration.sql =====
INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('purchasing.view', 'purchasing', 'view', 'View suppliers and purchase orders'),
  ('purchasing.manage', 'purchasing', 'manage', 'Create and receive purchase orders');

CREATE TABLE IF NOT EXISTS suppliers (
  supplier_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_name VARCHAR(150) NOT NULL,
  contact_person VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id),
  UNIQUE KEY uq_supplier_name (supplier_name),
  KEY idx_suppliers_active (is_active),
  CONSTRAINT fk_suppliers_created_by FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_headers (
  purchase_order_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_number VARCHAR(40) NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  approval_id BIGINT UNSIGNED NULL,
  status ENUM('Pending Approval','Approved','Ordered','Partially Received','Received','Cancelled')
    NOT NULL DEFAULT 'Pending Approval',
  ordered_at DATETIME NULL,
  expected_at DATE NULL,
  notes TEXT NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (purchase_order_id),
  UNIQUE KEY uq_purchase_order_headers_number (po_number),
  KEY idx_purchase_order_headers_status (status),
  KEY idx_purchase_order_headers_supplier (supplier_id),
  KEY idx_purchase_order_headers_approval (approval_id),
  CONSTRAINT fk_purchase_order_headers_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (supplier_id),
  CONSTRAINT fk_purchase_order_headers_requester FOREIGN KEY (requested_by) REFERENCES users (user_id),
  CONSTRAINT fk_purchase_order_headers_approval FOREIGN KEY (approval_id) REFERENCES approval_requests (approval_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_lines (
  purchase_order_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_order_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(14,2) NOT NULL,
  received_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (purchase_order_item_id),
  KEY idx_purchase_order_lines_order (purchase_order_id),
  KEY idx_purchase_order_lines_part (part_id),
  CONSTRAINT fk_purchase_order_lines_order FOREIGN KEY (purchase_order_id)
    REFERENCES purchase_order_headers (purchase_order_id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_order_lines_part FOREIGN KEY (part_id)
    REFERENCES parts_inventory (part_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN ('purchasing.view', 'purchasing.manage')
WHERE r.role_name IN ('Admin', 'Purchasing Officer');
-- ===== END SOURCE: phase5_purchasing_migration.sql =====

-- ===== BEGIN SOURCE: phase6_finance_migration.sql =====
INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('finance.funds.view', 'finance', 'view_funds', 'View fund requests and cash advances'),
  ('finance.funds.manage', 'finance', 'manage_funds', 'Create and manage fund requests and cash advances'),
  ('finance.disbursements.view', 'finance', 'view_disbursements', 'View disbursement records'),
  ('finance.disbursements.manage', 'finance', 'manage_disbursements', 'Record approved disbursements'),
  ('finance.ap.view', 'finance', 'view_payables', 'View accounts payable and payment vouchers'),
  ('finance.ap.manage', 'finance', 'manage_payables', 'Create accounts payable and payment vouchers');

CREATE TABLE IF NOT EXISTS fund_requests (
  fund_request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_number VARCHAR(40) NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  request_type ENUM('Trip Allowance','Cash Advance','Operating Fund','Other') NOT NULL,
  trip_id INT UNSIGNED NULL,
  purpose VARCHAR(255) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  approval_id BIGINT UNSIGNED NULL,
  status ENUM('Pending Approval','Approved','Rejected','Disbursed','Settled','Cancelled')
    NOT NULL DEFAULT 'Pending Approval',
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes TEXT NULL,
  PRIMARY KEY (fund_request_id),
  UNIQUE KEY uq_fund_requests_number (request_number),
  KEY idx_fund_requests_status (status),
  KEY idx_fund_requests_trip (trip_id),
  KEY idx_fund_requests_approval (approval_id),
  CONSTRAINT fk_fund_requests_requester FOREIGN KEY (requested_by) REFERENCES users (user_id),
  CONSTRAINT fk_fund_requests_trip FOREIGN KEY (trip_id) REFERENCES trips (trip_id),
  CONSTRAINT fk_fund_requests_approval FOREIGN KEY (approval_id) REFERENCES approval_requests (approval_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_disbursements (
  disbursement_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  disbursement_number VARCHAR(40) NOT NULL,
  fund_request_id INT UNSIGNED NULL,
  payee_name VARCHAR(150) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  payment_mode VARCHAR(50) NOT NULL,
  reference_no VARCHAR(100) NULL,
  disbursed_by INT UNSIGNED NOT NULL,
  disbursed_at DATE NOT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (disbursement_id),
  UNIQUE KEY uq_finance_disbursements_number (disbursement_number),
  KEY idx_finance_disbursements_request (fund_request_id),
  KEY idx_finance_disbursements_date (disbursed_at),
  CONSTRAINT fk_finance_disbursements_request FOREIGN KEY (fund_request_id) REFERENCES fund_requests (fund_request_id),
  CONSTRAINT fk_finance_disbursements_user FOREIGN KEY (disbursed_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts_payable (
  payable_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  payable_number VARCHAR(40) NOT NULL,
  supplier_name VARCHAR(150) NOT NULL,
  invoice_number VARCHAR(100) NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  due_date DATE NULL,
  status ENUM('Open','Partially Paid','Paid','Cancelled') NOT NULL DEFAULT 'Open',
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (payable_id),
  UNIQUE KEY uq_accounts_payable_number (payable_number),
  KEY idx_accounts_payable_status (status),
  KEY idx_accounts_payable_due_date (due_date),
  CONSTRAINT fk_accounts_payable_user FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_vouchers (
  voucher_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  voucher_number VARCHAR(40) NOT NULL,
  payable_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  approval_id BIGINT UNSIGNED NULL,
  status ENUM('Pending Approval','Approved','Paid','Rejected','Cancelled')
    NOT NULL DEFAULT 'Pending Approval',
  prepared_by INT UNSIGNED NOT NULL,
  prepared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes TEXT NULL,
  PRIMARY KEY (voucher_id),
  UNIQUE KEY uq_payment_vouchers_number (voucher_number),
  KEY idx_payment_vouchers_payable (payable_id),
  KEY idx_payment_vouchers_status (status),
  KEY idx_payment_vouchers_approval (approval_id),
  CONSTRAINT fk_payment_vouchers_payable FOREIGN KEY (payable_id) REFERENCES accounts_payable (payable_id),
  CONSTRAINT fk_payment_vouchers_approval FOREIGN KEY (approval_id) REFERENCES approval_requests (approval_id),
  CONSTRAINT fk_payment_vouchers_user FOREIGN KEY (prepared_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE finance_disbursements
  ADD COLUMN IF NOT EXISTS payment_voucher_id INT UNSIGNED NULL,
  ADD KEY IF NOT EXISTS idx_finance_disbursements_voucher (payment_voucher_id),
  ADD CONSTRAINT fk_finance_disbursements_voucher
    FOREIGN KEY (payment_voucher_id) REFERENCES payment_vouchers (voucher_id);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN (
  'finance.funds.view', 'finance.funds.manage',
  'finance.disbursements.view', 'finance.disbursements.manage',
  'finance.ap.view', 'finance.ap.manage'
)
WHERE r.role_name IN ('Admin', 'Finance');

INSERT IGNORE INTO approval_request_types (request_type, display_name)
VALUES ('fund_request', 'Fund Request');

INSERT IGNORE INTO approval_role_steps (request_type, step_order, approver_role_id)
SELECT 'fund_request', 1, r.role_id
FROM roles r
WHERE r.role_name = 'Management / Head';

UPDATE approval_role_steps ars
JOIN roles r ON r.role_name = 'Management / Head'
SET ars.approver_role_id = r.role_id
WHERE ars.request_type = 'fund_request' AND ars.step_order = 1;
-- ===== END SOURCE: phase6_finance_migration.sql =====

-- ===== BEGIN SOURCE: trip_costs_migration.sql =====
-- Apply this migration to an existing Mulawin FleetOps database.
SET @add_truck_efficiency = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trucks'
      AND COLUMN_NAME = 'fuel_efficiency_km_per_liter') = 0,
  'ALTER TABLE trucks ADD COLUMN fuel_efficiency_km_per_liter DECIMAL(6,2) NOT NULL DEFAULT 4.00 COMMENT ''Expected distance per liter''',
  'SELECT 1'
);
PREPARE add_truck_efficiency_stmt FROM @add_truck_efficiency;
EXECUTE add_truck_efficiency_stmt;
DEALLOCATE PREPARE add_truck_efficiency_stmt;

SET @add_cargo_weight = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trips'
      AND COLUMN_NAME = 'cargo_weight_tons') = 0,
  'ALTER TABLE trips ADD COLUMN cargo_weight_tons DECIMAL(6,2) NULL COMMENT ''Actual cargo weight for fuel analysis''',
  'SELECT 1'
);
PREPARE add_cargo_weight_stmt FROM @add_cargo_weight;
EXECUTE add_cargo_weight_stmt;
DEALLOCATE PREPARE add_cargo_weight_stmt;

-- Driver Allowance is a trip reimbursement expense, separate from Trip Pay
-- wages; both may be recorded for the same trip and crew member.
CREATE TABLE IF NOT EXISTS trip_expenses (
  expense_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  trip_id       INT UNSIGNED NOT NULL,
  recorded_by   INT UNSIGNED NOT NULL,
  expense_type  ENUM('Fuel','Toll','Driver Allowance','Other') NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  quantity      DECIMAL(10,2) NULL COMMENT 'Fuel quantity in liters when expense_type is Fuel',
  other_description VARCHAR(255) NULL COMMENT 'Description when expense_type is Other',
  expense_date  DATE NOT NULL,
  notes         VARCHAR(255) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (expense_id),
  INDEX idx_trip_expenses_trip (trip_id),
  INDEX idx_trip_expenses_type (expense_type),
  INDEX idx_trip_expenses_date (expense_date),
  CONSTRAINT fk_trip_expenses_trip FOREIGN KEY (trip_id) REFERENCES trips (trip_id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_expenses_recorder FOREIGN KEY (recorded_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @add_other_description = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_expenses'
      AND COLUMN_NAME = 'other_description') = 0,
  'ALTER TABLE trip_expenses ADD COLUMN other_description VARCHAR(255) NULL COMMENT ''Description when expense_type is Other''',
  'SELECT 1'
);
PREPARE add_other_description_stmt FROM @add_other_description;
EXECUTE add_other_description_stmt;
DEALLOCATE PREPARE add_other_description_stmt;
-- ===== END SOURCE: trip_costs_migration.sql =====

-- ===== BEGIN SOURCE: phase7_hr_attendance_migration.sql =====
INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('hr.attendance.view', 'hr', 'view_attendance', 'View employee attendance and timekeeping'),
  ('hr.attendance.manage', 'hr', 'manage_attendance', 'Record and update employee attendance'),
  ('hr.attendance.report', 'hr', 'attendance_report', 'View and export attendance reports');

CREATE TABLE IF NOT EXISTS employee_attendance (
  attendance_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,
  attendance_date DATE NOT NULL,
  status ENUM('Present','Absent','Leave','On Duty') NOT NULL DEFAULT 'Present',
  time_in TIME NULL,
  time_out TIME NULL,
  notes VARCHAR(500) NULL,
  overtime_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  overtime_reason VARCHAR(255) NULL,
  recorded_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (attendance_id),
  UNIQUE KEY uq_employee_attendance_day (employee_id, attendance_date),
  KEY idx_employee_attendance_date (attendance_date),
  CONSTRAINT fk_employee_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id),
  CONSTRAINT fk_employee_attendance_user FOREIGN KEY (recorded_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE employee_attendance
  ADD COLUMN IF NOT EXISTS overtime_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS overtime_reason VARCHAR(255) NULL;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_key IN ('hr.attendance.view', 'hr.attendance.manage', 'hr.attendance.report')
WHERE r.role_name IN ('Admin', 'Admin Officer');
-- ===== END SOURCE: phase7_hr_attendance_migration.sql =====

-- ===== BEGIN SOURCE: phase7_payroll_announcements_base_migration.sql =====
-- Phase 14 bootstrap fix: create the `payroll_records` and `announcements`
-- base tables. Both were referenced by later migrations/app code
-- (phase7_payroll_components_migration.sql, phase7_payroll_deduction_items_
-- migration.sql, announcement_duration_audience_migration.sql, and the
-- payroll/announcements pages/handlers) but neither table was ever actually
-- created by any migration file — `announcements`' schema only existed as
-- documentation in db/announcement.md, and `payroll_records` had no CREATE
-- statement anywhere. Pages already degrade gracefully (see pages/billing.php,
-- pages/analytics.php) when payroll_records is missing, which is how this
-- gap stayed invisible. Apply this file before phase7_payroll_components_
-- migration.sql / phase7_payroll_deduction_items_migration.sql and before
-- announcement_duration_audience_migration.sql. Safe to re-run (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS payroll_records (
  payroll_id        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  employee_id       INT UNSIGNED  NOT NULL,
  pay_period_start  DATE          NOT NULL,
  pay_period_end    DATE          NOT NULL,
  amount_paid       DECIMAL(14,2) NOT NULL,
  paid_date         DATE          NOT NULL,
  notes             VARCHAR(500)  NULL,
  recorded_by       INT UNSIGNED  NOT NULL,
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (payroll_id),
  KEY idx_payroll_records_employee (employee_id),
  KEY idx_payroll_records_paid_date (paid_date),
  CONSTRAINT fk_payroll_records_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id),
  CONSTRAINT fk_payroll_records_recorder FOREIGN KEY (recorded_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  announcement_id INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  created_by      INT UNSIGNED  NOT NULL,
  title           VARCHAR(200)  NOT NULL,
  body            TEXT          NOT NULL,
  is_pinned       TINYINT(1)    NOT NULL DEFAULT 0,
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (announcement_id),
  INDEX idx_announcements_pinned (is_pinned, created_at),
  CONSTRAINT fk_announcements_creator FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== END SOURCE: phase7_payroll_announcements_base_migration.sql =====

-- ===== BEGIN SOURCE: phase7_payroll_components_migration.sql =====
ALTER TABLE payroll_records
  ADD COLUMN IF NOT EXISTS base_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER pay_period_end,
  ADD COLUMN IF NOT EXISTS allowance_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER base_amount,
  ADD COLUMN IF NOT EXISTS deduction_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER allowance_amount;

UPDATE payroll_records
SET base_amount = amount_paid
WHERE base_amount = 0 AND allowance_amount = 0 AND deduction_amount = 0;
-- ===== END SOURCE: phase7_payroll_components_migration.sql =====

-- ===== BEGIN SOURCE: phase7_payroll_deduction_items_migration.sql =====
CREATE TABLE IF NOT EXISTS payroll_deductions (
  deduction_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  payroll_id INT UNSIGNED NOT NULL,
  deduction_name VARCHAR(100) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (deduction_id),
  KEY idx_payroll_deductions_payroll (payroll_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== END SOURCE: phase7_payroll_deduction_items_migration.sql =====

-- ===== BEGIN SOURCE: phase8_admin_officer_migration.sql =====
INSERT IGNORE INTO permissions (permission_key, module_name, action_name, description) VALUES
  ('hr.recruitment.view', 'hr', 'view_recruitment', 'View recruitment candidates'),
  ('hr.recruitment.manage', 'hr', 'manage_recruitment', 'Create and update recruitment candidates'),
  ('admin.supplies.view', 'admin', 'view_supplies', 'View office supplies inventory'),
  ('admin.supplies.manage', 'admin', 'manage_supplies', 'Manage office supplies inventory'),
  ('operations.reports.view', 'operations', 'view_reports', 'View filtered operations performance reports'),
  ('maintenance.reports.view', 'maintenance', 'view_reports', 'View maintenance downtime and cost reports');

CREATE TABLE IF NOT EXISTS recruitment_candidates (
  candidate_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  candidate_number VARCHAR(40) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  desired_position VARCHAR(100) NOT NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  applied_on DATE NOT NULL,
  source VARCHAR(100) NULL,
  status ENUM('New','Screening','Interview','Reference Check','Offer','Hired','Rejected','Withdrawn')
    NOT NULL DEFAULT 'New',
  notes TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (candidate_id),
  UNIQUE KEY uq_recruitment_candidate_number (candidate_number),
  KEY idx_recruitment_candidates_status (status, applied_on),
  CONSTRAINT fk_recruitment_candidates_creator FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_supply_items (
  item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_name VARCHAR(150) NOT NULL,
  unit VARCHAR(50) NOT NULL DEFAULT 'piece',
  quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (item_id),
  UNIQUE KEY uq_office_supply_items_name (item_name),
  CONSTRAINT fk_office_supply_items_creator FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_supply_movements (
  movement_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id INT UNSIGNED NOT NULL,
  movement_type ENUM('Stock In','Issue','Adjustment') NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  quantity_change DECIMAL(12,2) NOT NULL,
  reference_number VARCHAR(100) NULL,
  notes VARCHAR(500) NULL,
  recorded_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (movement_id),
  KEY idx_office_supply_movements_item (item_id, created_at),
  CONSTRAINT fk_office_supply_movements_item FOREIGN KEY (item_id) REFERENCES office_supply_items (item_id),
  CONSTRAINT fk_office_supply_movements_user FOREIGN KEY (recorded_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Admin'
  AND p.permission_key IN (
  'hr.recruitment.view', 'hr.recruitment.manage',
  'admin.supplies.view', 'admin.supplies.manage',
  'operations.reports.view', 'maintenance.reports.view'
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Admin Officer'
  AND p.permission_key IN (
  'hr.recruitment.view', 'hr.recruitment.manage',
  'admin.supplies.view', 'admin.supplies.manage'
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name IN ('Management / Head', 'Operations Head')
  AND p.permission_key = 'operations.reports.view';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name IN ('Management / Head', 'Operations Head', 'Maintenance')
  AND p.permission_key = 'maintenance.reports.view';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name = 'Management / Head'
  AND p.permission_key = 'approvals.review';
-- ===== END SOURCE: phase8_admin_officer_migration.sql =====

-- ===== BEGIN SOURCE: phase9_truck_warranty_migration.sql =====
ALTER TABLE trucks
  ADD COLUMN warranty_expiry DATE NULL AFTER insurance_expiry,
  ADD INDEX idx_trucks_warranty_expiry (warranty_expiry);
-- ===== END SOURCE: phase9_truck_warranty_migration.sql =====

-- ===== BEGIN SOURCE: truck_image_migration.sql =====
ALTER TABLE trucks
  ADD COLUMN image_path VARCHAR(500) NULL AFTER capacity_tons,
  ADD COLUMN image_front_path VARCHAR(500) NULL AFTER image_path,
  ADD COLUMN image_side_path VARCHAR(500) NULL AFTER image_front_path,
  ADD COLUMN image_rear_path VARCHAR(500) NULL AFTER image_side_path,
  ADD COLUMN image_top_path VARCHAR(500) NULL AFTER image_rear_path;

UPDATE trucks
   SET image_front_path = COALESCE(image_front_path, image_path)
 WHERE image_path IS NOT NULL AND image_path <> '';
-- ===== END SOURCE: truck_image_migration.sql =====

-- ===== BEGIN SOURCE: document_expiry_migration.sql =====
-- Run once if document expiry reminders are needed.
ALTER TABLE documents
  ADD COLUMN expiry_date DATE NULL AFTER description,
  ADD INDEX idx_documents_expiry (expiry_date);
-- ===== END SOURCE: document_expiry_migration.sql =====

-- ===== BEGIN SOURCE: trip_report_document_visibility_migration.sql =====
-- Trip-report attachments are operational documents. They are visible to
-- Head Management, Dispatchers, and Accounting, but not Maintenance.
ALTER TABLE documents
  ADD COLUMN visibility_scope ENUM('all', 'operations', 'maintenance', 'accounting')
    NOT NULL DEFAULT 'all'
    AFTER description,
  ADD INDEX idx_documents_visibility (visibility_scope);
-- ===== END SOURCE: trip_report_document_visibility_migration.sql =====

-- ===== BEGIN SOURCE: route_approval_and_notifications_migration.sql =====
ALTER TABLE routes
  ADD COLUMN approval_status ENUM('Pending', 'Approved', 'Rejected')
    NOT NULL DEFAULT 'Approved'
    AFTER is_active,
  ADD COLUMN requested_by INT UNSIGNED NULL AFTER approval_status,
  ADD COLUMN request_notes VARCHAR(500) NULL AFTER requested_by,
  ADD INDEX idx_routes_approval (approval_status),
  ADD CONSTRAINT fk_routes_requested_by
    FOREIGN KEY (requested_by) REFERENCES users (user_id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS notifications (
  notification_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  message VARCHAR(500) NOT NULL,
  link VARCHAR(255) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notification_id),
  INDEX idx_notifications_user (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== END SOURCE: route_approval_and_notifications_migration.sql =====

-- ===== BEGIN SOURCE: automation_settings_migration.sql =====
-- Automation control settings.
-- This creates controls only; no automated job is enabled or scheduled by this migration.
CREATE TABLE IF NOT EXISTS automation_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value VARCHAR(20) NOT NULL DEFAULT '0',
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_automation_settings_user
        FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

INSERT INTO automation_settings (setting_key, setting_value)
VALUES ('automation_engine_enabled', '0')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

CREATE TABLE IF NOT EXISTS automation_runs (
    run_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_key VARCHAR(100) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
    items_processed INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    PRIMARY KEY (run_id),
    INDEX idx_automation_runs_job (job_key, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_deliveries (
    delivery_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_key VARCHAR(100) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (delivery_id),
    UNIQUE KEY uq_automation_delivery (job_key, fingerprint, user_id),
    CONSTRAINT fk_automation_delivery_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO automation_settings (setting_key, setting_value)
VALUES
    ('pending_approval_reminders', '0'),
    ('expiry_reminders', '0'),
    ('maintenance_due_reminders', '0'),
    ('unpaid_billing_reminders', '0'),
    ('daily_analytics_summary', '0'),
    ('system_health_checks', '0')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
-- ===== END SOURCE: automation_settings_migration.sql =====

-- ===== BEGIN SOURCE: password_reset_requests_migration.sql =====
CREATE TABLE IF NOT EXISTS password_reset_requests (
  request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
  requested_ip VARCHAR(45) NULL,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_notes VARCHAR(500) NULL,
  PRIMARY KEY (request_id),
  INDEX idx_password_reset_status (status, requested_at),
  INDEX idx_password_reset_user (user_id, status),
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_password_reset_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== END SOURCE: password_reset_requests_migration.sql =====

-- ===== BEGIN SOURCE: auth_session_version_migration.sql =====
SET @add_auth_version = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'auth_version') = 0,
  'ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE add_auth_version_stmt FROM @add_auth_version;
EXECUTE add_auth_version_stmt;
DEALLOCATE PREPARE add_auth_version_stmt;
-- ===== END SOURCE: auth_session_version_migration.sql =====

-- ===== BEGIN SOURCE: system_hardening_migration.sql =====
CREATE TABLE IF NOT EXISTS trip_number_counters (
  `year` INT UNSIGNED NOT NULL,
  next_number INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO trip_number_counters (`year`, next_number)
SELECT YEAR(created_at), MAX(CAST(SUBSTRING_INDEX(trip_number, '-', -1) AS UNSIGNED)) + 1
FROM trips
WHERE trip_number LIKE 'TRP-%'
GROUP BY YEAR(created_at)
ON DUPLICATE KEY UPDATE next_number = GREATEST(next_number, VALUES(next_number));

CREATE TABLE IF NOT EXISTS login_attempts (
  attempt_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier VARCHAR(150) NOT NULL,
  ip_address VARCHAR(45) NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attempt_id),
  INDEX idx_login_attempts_lookup (identifier, ip_address, attempted_at),
  INDEX idx_login_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM login_attempts
WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY);
-- ===== END SOURCE: system_hardening_migration.sql =====

-- ===== BEGIN SOURCE: announcement_duration_audience_migration.sql =====
-- Add announcement severity, scheduling, and department targeting.
-- Existing announcements retain their original creation date and remain
-- active indefinitely until an end date is supplied.

SET @add_priority = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'
      AND COLUMN_NAME = 'priority') = 0,
  'ALTER TABLE announcements ADD COLUMN priority ENUM(''high'',''medium'',''low'') NOT NULL DEFAULT ''medium'' AFTER body',
  'SELECT 1'
);
PREPARE add_priority_stmt FROM @add_priority;
EXECUTE add_priority_stmt;
DEALLOCATE PREPARE add_priority_stmt;

SET @add_audience = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'
      AND COLUMN_NAME = 'audience') = 0,
  'ALTER TABLE announcements ADD COLUMN audience ENUM(''all'',''maintenance'',''accounting'',''operations'') NOT NULL DEFAULT ''all'' AFTER is_pinned',
  'SELECT 1'
);
PREPARE add_audience_stmt FROM @add_audience;
EXECUTE add_audience_stmt;
DEALLOCATE PREPARE add_audience_stmt;

SET @add_starts_at = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'
      AND COLUMN_NAME = 'starts_at') = 0,
  'ALTER TABLE announcements ADD COLUMN starts_at DATETIME NULL AFTER audience',
  'SELECT 1'
);
PREPARE add_starts_at_stmt FROM @add_starts_at;
EXECUTE add_starts_at_stmt;
DEALLOCATE PREPARE add_starts_at_stmt;

SET @add_ends_at = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'
      AND COLUMN_NAME = 'ends_at') = 0,
  'ALTER TABLE announcements ADD COLUMN ends_at DATETIME NULL AFTER starts_at',
  'SELECT 1'
);
PREPARE add_ends_at_stmt FROM @add_ends_at;
EXECUTE add_ends_at_stmt;
DEALLOCATE PREPARE add_ends_at_stmt;

SET @add_active_index = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'
      AND INDEX_NAME = 'idx_announcements_active') = 0,
  'CREATE INDEX idx_announcements_active ON announcements (starts_at, ends_at, audience)',
  'SELECT 1'
);
PREPARE add_active_index_stmt FROM @add_active_index;
EXECUTE add_active_index_stmt;
DEALLOCATE PREPARE add_active_index_stmt;

UPDATE announcements
   SET starts_at = created_at
 WHERE starts_at IS NULL;
-- ===== END SOURCE: announcement_duration_audience_migration.sql =====

-- ===== BEGIN SOURCE: phase10_trip_workflow_migration.sql =====
CREATE TABLE IF NOT EXISTS trip_workflow_state (
  dispatch_id INT UNSIGNED NOT NULL,
  trip_id INT UNSIGNED NULL,
  current_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
  assignment_confirmed_by INT UNSIGNED NULL,
  assignment_confirmed_at DATETIME NULL,
  documents_status ENUM('Prepared', 'Not Required') NULL,
  documents_notes TEXT NULL,
  allowance_status ENUM('Approved', 'Not Required') NULL,
  allowance_fund_request_id INT UNSIGNED NULL,
  allowance_notes TEXT NULL,
  prepared_by INT UNSIGNED NULL,
  prepared_at DATETIME NULL,
  clearance_by INT UNSIGNED NULL,
  clearance_at DATETIME NULL,
  clearance_notes TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (dispatch_id),
  UNIQUE KEY uq_trip_workflow_trip (trip_id),
  KEY idx_trip_workflow_step (current_step),
  CONSTRAINT fk_trip_workflow_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_requests (dispatch_id),
  CONSTRAINT fk_trip_workflow_trip
    FOREIGN KEY (trip_id) REFERENCES trips (trip_id),
  CONSTRAINT fk_trip_workflow_assignment_user
    FOREIGN KEY (assignment_confirmed_by) REFERENCES users (user_id),
  CONSTRAINT fk_trip_workflow_preparer
    FOREIGN KEY (prepared_by) REFERENCES users (user_id),
  CONSTRAINT fk_trip_workflow_clearance_user
    FOREIGN KEY (clearance_by) REFERENCES users (user_id),
  CONSTRAINT fk_trip_workflow_fund_request
    FOREIGN KEY (allowance_fund_request_id) REFERENCES fund_requests (fund_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_workflow_events (
  event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dispatch_id INT UNSIGNED NOT NULL,
  trip_id INT UNSIGNED NULL,
  step_number TINYINT UNSIGNED NOT NULL,
  event_key VARCHAR(100) NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  details TEXT NULL,
  event_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id),
  UNIQUE KEY uq_trip_workflow_event_key (dispatch_id, event_key),
  KEY idx_trip_workflow_events_trip (trip_id, step_number, event_at),
  CONSTRAINT fk_trip_workflow_event_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_requests (dispatch_id),
  CONSTRAINT fk_trip_workflow_event_trip
    FOREIGN KEY (trip_id) REFERENCES trips (trip_id),
  CONSTRAINT fk_trip_workflow_event_user
    FOREIGN KEY (actor_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_delivery_return_details (
  trip_id INT UNSIGNED NOT NULL,
  dispatch_id INT UNSIGNED NOT NULL,
  delivered_unit_count DECIMAL(10,2) NULL,
  delivery_receipt_number VARCHAR(100) NULL,
  shared_waybill_reference VARCHAR(100) NULL,
  co_load_reference VARCHAR(100) NULL,
  delivery_notes TEXT NOT NULL,
  return_location VARCHAR(255) NULL,
  return_notes TEXT NOT NULL,
  recorded_by INT UNSIGNED NOT NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (trip_id),
  KEY idx_trip_delivery_dispatch (dispatch_id),
  KEY idx_trip_delivery_waybill (shared_waybill_reference),
  KEY idx_trip_delivery_coload (co_load_reference),
  CONSTRAINT fk_trip_delivery_trip
    FOREIGN KEY (trip_id) REFERENCES trips (trip_id),
  CONSTRAINT fk_trip_delivery_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_requests (dispatch_id),
  CONSTRAINT fk_trip_delivery_user
    FOREIGN KEY (recorded_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== END SOURCE: phase10_trip_workflow_migration.sql =====

-- ===== BEGIN SOURCE: phase11_inventory_controls_migration.sql =====
ALTER TABLE parts_inventory
  ADD COLUMN IF NOT EXISTS warranty_expiry DATE NULL AFTER unit_cost,
  ADD INDEX IF NOT EXISTS idx_parts_warranty_expiry (warranty_expiry);
-- ===== END SOURCE: phase11_inventory_controls_migration.sql =====

-- ===== BEGIN SOURCE: phase13_billing_ar_migration.sql =====
-- Phase 13: Billing / AR completion
-- Normalizes the billing party against the clients table (kept alongside the
-- existing free-text client_name for backward compatibility / one-off
-- bill-to overrides) and adds optional invoice number/date fields so
-- Accounting can record a client's own invoice reference separately from
-- the internal billing_number.

ALTER TABLE billings
  ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL AFTER trip_id,
  ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(50) NULL AFTER billing_number,
  ADD COLUMN IF NOT EXISTS invoice_date DATE NULL AFTER invoice_number,
  ADD INDEX IF NOT EXISTS idx_billings_client (client_id),
  ADD UNIQUE KEY IF NOT EXISTS uq_billings_invoice_number (invoice_number),
  ADD CONSTRAINT fk_billings_client
    FOREIGN KEY (client_id) REFERENCES clients (client_id) ON DELETE SET NULL;

-- Best-effort backfill: link existing billings to their client record when
-- the free-text client_name exactly matches an existing active client.
UPDATE billings b
JOIN clients c ON c.client_name = b.client_name
SET b.client_id = c.client_id
WHERE b.client_id IS NULL AND b.client_name IS NOT NULL;
-- ===== END SOURCE: phase13_billing_ar_migration.sql =====

-- ===== BEGIN SOURCE: trip_pay_migration.sql =====
-- Per-trip wages for the Driver and Helper assigned to a trip.
-- Separate from trip_expenses (including Driver Allowance) and
-- payroll_records (period payroll); allowance and Trip Pay may coexist.

CREATE TABLE IF NOT EXISTS trip_pay (
  trip_pay_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  trip_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  crew_role ENUM('Driver', 'Helper') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  paid_date DATE NOT NULL,
  recorded_by INT UNSIGNED NOT NULL,
  notes VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (trip_pay_id),
  UNIQUE KEY uq_trip_pay_trip_employee (trip_id, employee_id),
  KEY idx_trip_pay_employee_date (employee_id, paid_date),
  KEY idx_trip_pay_paid_date (paid_date),
  CONSTRAINT fk_trip_pay_trip
    FOREIGN KEY (trip_id) REFERENCES trips (trip_id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_pay_employee
    FOREIGN KEY (employee_id) REFERENCES employees (employee_id),
  CONSTRAINT fk_trip_pay_recorder
    FOREIGN KEY (recorded_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== END SOURCE: trip_pay_migration.sql =====

-- ===== BEGIN SOURCE: recycle_bin_migration.sql =====
-- Archive table used by includes/soft_delete.php and the Recycle Bin page.
-- JSON snapshots retain deleted rows for restoration and audit history.

CREATE TABLE IF NOT EXISTS deleted_records (
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
  CONSTRAINT fk_deleted_records_user
    FOREIGN KEY (deleted_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== END SOURCE: recycle_bin_migration.sql =====

-- Restore FK checks, disabled by the original base schema during setup.
SET FOREIGN_KEY_CHECKS = 1;

-- CREATES the budgets table on the database.
CREATE TABLE budgets (
  budget_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category ENUM(
    'Revenue',
    'Maintenance',
    'Fuel',
    'Toll',
    'Driver Allowance',
    'Other',
    'Payroll'
  ) NOT NULL,
  period_month DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  set_by INT UNSIGNED NOT NULL,
  PRIMARY KEY (budget_id),
  UNIQUE KEY uq_budgets_category_period_month (category, period_month),
  KEY idx_budgets_period_month (period_month),
  KEY idx_budgets_set_by (set_by),
  CONSTRAINT fk_budgets_set_by
    FOREIGN KEY (set_by) REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;