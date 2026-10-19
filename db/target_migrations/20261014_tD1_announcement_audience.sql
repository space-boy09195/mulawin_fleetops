-- ============================================================
-- T-D1: announcement audience, priority and pin (DRAFT FOR REVIEW -
-- DECISION-GATED)
-- File: db/target_migrations/20261014_tD1_announcement_audience.sql
-- Option B phase: B4. GATED ON DECISION D8 (keep the Phase 1 audience
-- feature, priority and pinning).
--
-- STATUS: NOT EXECUTED and NOT APPROVED FOR EXECUTION.
--
-- TARGET DATABASE: the Option B target only. Do NOT apply to the legacy
--   database (these columns already exist there). No USE statement.
--
-- PURPOSE:
--   includes/layout.php, pages/announcements.php and
--   ajax/announcement_handler.php filter announcements by audience
--   (Phase 1: announcementAudienceForRoleKey / currentAnnouncementAudience),
--   sort by is_pinned and priority. The target announcements table has
--   title, content, start_date, end_date and is_active only.
--   Column renames (body -> content, starts_at/ends_at -> start_date/
--   end_date) are handled in B4 CODE, not here.
--
-- VALUES: identical to the legacy definitions, so B4 code keeps its
--   constants: audience 'all','maintenance','accounting','operations'
--   (default 'all'); priority 'high','medium','low' (default 'medium');
--   is_pinned 0/1 (default 0).
--
-- DEPENDENCIES: target schema baseline (announcements).
-- COMPATIBILITY: MariaDB 10.4 / MySQL 8 syntax. Hostinger NOT verified.
-- NOTE: SQL examples in comments have no statement terminators on purpose.
--
-- READ-ONLY PRE-CHECKS (expected result in brackets):
--   a) SELECT DATABASE()                                  -- [the target db]
--   b) SELECT COLUMN_NAME FROM information_schema.COLUMNS
--       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'
--         AND COLUMN_NAME IN ('audience','priority','is_pinned') -- [no rows]
--
-- BACKFILL: none. Existing rows (if any) get audience 'all', priority
--   'medium', not pinned - the same as a newly created announcement.
--
-- TRANSACTIONS: ALTER TABLE commits implicitly; a rerun fails with a
--   duplicate-column error and changes nothing.
--
-- ROLLBACK (only with explicit approval, before B4 code uses the columns):
--     ALTER TABLE announcements DROP INDEX idx_announcements_audience,
--       DROP INDEX idx_announcements_pinned, DROP COLUMN audience,
--       DROP COLUMN priority, DROP COLUMN is_pinned
--   Data loss: audience targeting, priority and pin state of every
--   announcement (non-Admin users would then see every announcement).
-- ============================================================

ALTER TABLE announcements
    ADD COLUMN priority ENUM('high','medium','low') NOT NULL DEFAULT 'medium' AFTER content,
    ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER priority,
    ADD COLUMN audience ENUM('all','maintenance','accounting','operations') NOT NULL DEFAULT 'all' AFTER is_pinned,
    ADD KEY idx_announcements_pinned (is_pinned, created_at),
    ADD KEY idx_announcements_audience (audience, is_active);
