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
