-- Apply this migration to an existing Mulawin FleetOps database.
USE mulawin_fleetops;

ALTER TABLE trucks
  ADD COLUMN fuel_efficiency_km_per_liter DECIMAL(6,2) NOT NULL DEFAULT 4.00
  COMMENT 'Expected distance per liter';

ALTER TABLE trips
  ADD COLUMN cargo_weight_tons DECIMAL(6,2) NULL
  COMMENT 'Actual cargo weight for fuel analysis';

CREATE TABLE IF NOT EXISTS trip_expenses (
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
