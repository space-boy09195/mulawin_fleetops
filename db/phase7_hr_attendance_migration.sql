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
