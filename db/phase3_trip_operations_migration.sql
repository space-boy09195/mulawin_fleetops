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
