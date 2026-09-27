USE mulawin_fleetops;

CREATE TABLE IF NOT EXISTS trip_number_counters (
  `year` INT UNSIGNED NOT NULL,
  next_number INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO trip_number_counters (`year`, next_number)
SELECT YEAR(created_at), MAX(CAST(SUBSTRING_INDEX(trip_number, '-', -1) AS UNSIGNED)) + 1
FROM trips
WHERE trip_number LIKE 'TRP-%'
GROUP BY YEAR(created_at)
ON DUPLICATE KEY UPDATE next_number = GREATEST(next_number, VALUES(next_number));

CREATE TABLE IF NOT EXISTS login_attempts (
  attempt_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier VARCHAR(150) NOT NULL,
  ip_address VARCHAR(45) NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attempt_id),
  INDEX idx_login_attempts_lookup (identifier, ip_address, attempted_at),
  INDEX idx_login_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM login_attempts
WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY);
