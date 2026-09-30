ALTER TABLE parts_inventory
  ADD COLUMN IF NOT EXISTS warranty_expiry DATE NULL AFTER unit_cost,
  ADD INDEX IF NOT EXISTS idx_parts_warranty_expiry (warranty_expiry);
