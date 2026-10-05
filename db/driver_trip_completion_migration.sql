-- ===== BEGIN SOURCE: driver_trip_completion_migration.sql =====
-- Driver completion reports are separate from the official trip status.
-- A driver can report completion; an authorized post-trip recorder still
-- performs the official Completed transition through the existing workflow.

CREATE TABLE IF NOT EXISTS trip_completion_reports (
  report_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  trip_id INT UNSIGNED NOT NULL,
  dispatch_id INT UNSIGNED NOT NULL,
  reported_by INT UNSIGNED NOT NULL COMMENT 'Employee who reported completion',
  status ENUM('Pending', 'Acknowledged', 'Rejected') NOT NULL DEFAULT 'Pending',
  driver_note VARCHAR(500) NULL,
  reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  reviewer_note VARCHAR(500) NULL,
  PRIMARY KEY (report_id),
  KEY idx_trip_completion_trip_status (trip_id, status),
  KEY idx_trip_completion_status (status, reported_at),
  KEY idx_trip_completion_dispatch (dispatch_id),
  CONSTRAINT fk_trip_completion_trip
    FOREIGN KEY (trip_id) REFERENCES trips (trip_id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_completion_dispatch
    FOREIGN KEY (dispatch_id) REFERENCES dispatch_requests (dispatch_id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_completion_employee
    FOREIGN KEY (reported_by) REFERENCES employees (employee_id),
  CONSTRAINT fk_trip_completion_reviewer
    FOREIGN KEY (reviewed_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== END SOURCE: driver_trip_completion_migration.sql =====
