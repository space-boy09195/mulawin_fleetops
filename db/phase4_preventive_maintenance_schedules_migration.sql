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
