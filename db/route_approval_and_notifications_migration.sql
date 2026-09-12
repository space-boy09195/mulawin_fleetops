ALTER TABLE routes
  ADD COLUMN approval_status ENUM('Pending', 'Approved', 'Rejected')
    NOT NULL DEFAULT 'Approved'
    AFTER is_active,
  ADD COLUMN requested_by INT UNSIGNED NULL AFTER approval_status,
  ADD INDEX idx_routes_approval (approval_status),
  ADD CONSTRAINT fk_routes_requested_by
    FOREIGN KEY (requested_by) REFERENCES users (user_id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS notifications (
  notification_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  message VARCHAR(500) NOT NULL,
  link VARCHAR(255) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notification_id),
  INDEX idx_notifications_user (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
