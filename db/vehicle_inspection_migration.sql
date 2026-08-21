USE mulawin_fleetops;

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
  condition ENUM('Good','Needs Attention','Damaged','Missing','Leaking','Worn','Not Checked') NOT NULL DEFAULT 'Not Checked',
  notes VARCHAR(255) NULL,
  PRIMARY KEY (finding_id),
  UNIQUE KEY uq_inspection_part (inspection_id, view_name, part_name),
  CONSTRAINT fk_inspection_findings_inspection FOREIGN KEY (inspection_id) REFERENCES vehicle_inspections (inspection_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE vehicle_inspection_findings
  MODIFY view_name ENUM('Front','Side','Rear','Top') NOT NULL;

ALTER TABLE vehicle_inspection_findings
  MODIFY condition ENUM('Good','Needs Attention','Damaged','Missing','Leaking','Worn','Not Checked')
  NOT NULL DEFAULT 'Not Checked';

ALTER TABLE maintenance_records
  ADD COLUMN inspection_id INT UNSIGNED NULL
  COMMENT 'Vehicle inspection linked to this record',
  ADD INDEX idx_maint_inspection (inspection_id),
  ADD CONSTRAINT fk_maint_inspection
    FOREIGN KEY (inspection_id) REFERENCES vehicle_inspections (inspection_id) ON DELETE SET NULL;
