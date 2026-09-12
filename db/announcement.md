CREATE TABLE IF NOT EXISTS announcements (
  announcement_id INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  created_by      INT UNSIGNED  NOT NULL,
  title           VARCHAR(200)  NOT NULL,
  body            TEXT          NOT NULL,
  priority        ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  is_pinned       TINYINT(1)    NOT NULL DEFAULT 0,
  audience        ENUM('all','maintenance','accounting','operations') NOT NULL DEFAULT 'all',
  starts_at       DATETIME      NULL,
  ends_at         DATETIME      NULL,
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (announcement_id),
  INDEX idx_announcements_active (starts_at, ends_at, audience),
  INDEX idx_announcements_pinned (is_pinned, created_at),
  CONSTRAINT fk_announcements_creator FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;