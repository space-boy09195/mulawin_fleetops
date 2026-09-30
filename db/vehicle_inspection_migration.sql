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
