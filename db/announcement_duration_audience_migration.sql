-- Add announcement severity, scheduling, and department targeting.
-- Existing announcements retain their original creation date and remain
-- active indefinitely until an end date is supplied.

SET @add_priority = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'
      AND COLUMN_NAME = 'priority') = 0,
  'ALTER TABLE announcements ADD COLUMN priority ENUM(''high'',''medium'',''low'') NOT NULL DEFAULT ''medium'' AFTER body',
  'SELECT 1'
);
PREPARE add_priority_stmt FROM @add_priority;
EXECUTE add_priority_stmt;
DEALLOCATE PREPARE add_priority_stmt;

SET @add_audience = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'
      AND COLUMN_NAME = 'audience') = 0,
  'ALTER TABLE announcements ADD COLUMN audience ENUM(''all'',''maintenance'',''accounting'',''operations'') NOT NULL DEFAULT ''all'' AFTER is_pinned',
  'SELECT 1'
);
PREPARE add_audience_stmt FROM @add_audience;
EXECUTE add_audience_stmt;
DEALLOCATE PREPARE add_audience_stmt;

SET @add_starts_at = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'
      AND COLUMN_NAME = 'starts_at') = 0,
  'ALTER TABLE announcements ADD COLUMN starts_at DATETIME NULL AFTER audience',
  'SELECT 1'
);
PREPARE add_starts_at_stmt FROM @add_starts_at;
EXECUTE add_starts_at_stmt;
DEALLOCATE PREPARE add_starts_at_stmt;

SET @add_ends_at = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'
      AND COLUMN_NAME = 'ends_at') = 0,
  'ALTER TABLE announcements ADD COLUMN ends_at DATETIME NULL AFTER starts_at',
  'SELECT 1'
);
PREPARE add_ends_at_stmt FROM @add_ends_at;
EXECUTE add_ends_at_stmt;
DEALLOCATE PREPARE add_ends_at_stmt;

SET @add_active_index = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'
      AND INDEX_NAME = 'idx_announcements_active') = 0,
  'CREATE INDEX idx_announcements_active ON announcements (starts_at, ends_at, audience)',
  'SELECT 1'
);
PREPARE add_active_index_stmt FROM @add_active_index;
EXECUTE add_active_index_stmt;
DEALLOCATE PREPARE add_active_index_stmt;

UPDATE announcements
   SET starts_at = created_at
 WHERE starts_at IS NULL;
