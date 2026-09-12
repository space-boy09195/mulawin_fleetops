ALTER TABLE trucks
  ADD COLUMN image_path VARCHAR(500) NULL AFTER capacity_tons,
  ADD COLUMN image_front_path VARCHAR(500) NULL AFTER image_path,
  ADD COLUMN image_side_path VARCHAR(500) NULL AFTER image_front_path,
  ADD COLUMN image_rear_path VARCHAR(500) NULL AFTER image_side_path,
  ADD COLUMN image_top_path VARCHAR(500) NULL AFTER image_rear_path;

UPDATE trucks
   SET image_front_path = COALESCE(image_front_path, image_path)
 WHERE image_path IS NOT NULL AND image_path <> '';
