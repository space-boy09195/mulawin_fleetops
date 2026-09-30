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
