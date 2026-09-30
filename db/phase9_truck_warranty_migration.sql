ALTER TABLE trucks
  ADD COLUMN warranty_expiry DATE NULL AFTER insurance_expiry,
  ADD INDEX idx_trucks_warranty_expiry (warranty_expiry);
