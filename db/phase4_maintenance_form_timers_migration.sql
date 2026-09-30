CREATE TABLE maintenance_form_timers (
  timer_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  form_type ENUM('checklist', 'inspection_general', 'inspection_departure', 'inspection_return') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  PRIMARY KEY (timer_id),
  UNIQUE KEY uq_maintenance_form_timer_token (token_hash),
  INDEX idx_maintenance_form_timer_cleanup (expires_at, consumed_at),
  INDEX idx_maintenance_form_timer_owner (user_id, form_type, target_id, started_at),
  CONSTRAINT fk_maintenance_form_timer_user
    FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
