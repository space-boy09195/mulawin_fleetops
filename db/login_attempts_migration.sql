-- Login previously had no rate limiting at all — a failed attempt was
-- audit-logged but nothing blocked repeated tries, so the login form was
-- open to unlimited brute-force/credential-stuffing attempts. This table
-- backs a simple rolling-window throttle: too many failed attempts for
-- either a given username or a given IP within the window blocks further
-- tries until old attempts age out.
CREATE TABLE IF NOT EXISTS login_attempts (
  attempt_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier    VARCHAR(150) NOT NULL COMMENT 'Lowercased username that was attempted',
  ip_address    VARCHAR(45) NULL,
  attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempts_identifier (identifier, attempted_at),
  INDEX idx_login_attempts_ip (ip_address, attempted_at)
);
