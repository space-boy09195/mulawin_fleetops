-- Trip numbers (TRP-YYYY-NNNN) were previously generated with
-- SELECT COUNT(*) FROM trips WHERE YEAR(created_at) = ... then +1 in PHP —
-- a classic check-then-act race: two dispatch approvals processed at nearly
-- the same moment can read the same count and both try to insert the same
-- trip_number, which is UNIQUE, so the second one fails with an unhandled
-- database error. This table backs an atomic increment instead (MySQL's
-- INSERT ... ON DUPLICATE KEY UPDATE ... LAST_INSERT_ID() idiom), which is
-- safe under concurrent connections without needing an explicit lock.
CREATE TABLE IF NOT EXISTS trip_number_counters (
  year         INT UNSIGNED PRIMARY KEY,
  next_number  INT UNSIGNED NOT NULL DEFAULT 1
);
