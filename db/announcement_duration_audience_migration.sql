-- Add announcement severity, scheduling, and department targeting.
-- Existing announcements retain their original creation date and remain
-- active indefinitely until an end date is supplied.
USE mulawin_fleetops;

ALTER TABLE announcements
  ADD COLUMN priority ENUM('high','medium','low')
    NOT NULL DEFAULT 'medium' AFTER body,
  ADD COLUMN audience ENUM('all','maintenance','accounting','operations')
    NOT NULL DEFAULT 'all' AFTER is_pinned,
  ADD COLUMN starts_at DATETIME NULL AFTER audience,
  ADD COLUMN ends_at DATETIME NULL AFTER starts_at,
  ADD INDEX idx_announcements_active (starts_at, ends_at, audience);

UPDATE announcements
   SET starts_at = created_at
 WHERE starts_at IS NULL;
