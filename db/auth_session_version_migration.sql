SET @add_auth_version = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'auth_version') = 0,
  'ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE add_auth_version_stmt FROM @add_auth_version;
EXECUTE add_auth_version_stmt;
DEALLOCATE PREPARE add_auth_version_stmt;
