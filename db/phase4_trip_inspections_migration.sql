ALTER TABLE vehicle_inspections
  ADD COLUMN trip_id INT UNSIGNED NULL AFTER truck_id,
  ADD COLUMN inspection_stage ENUM('General', 'Departure', 'Return') NOT NULL DEFAULT 'General'
    AFTER trip_id,
  ADD UNIQUE KEY uq_vehicle_inspection_trip_stage (trip_id, inspection_stage),
  ADD CONSTRAINT fk_vehicle_inspections_trip
    FOREIGN KEY (trip_id) REFERENCES trips (trip_id) ON DELETE SET NULL;
