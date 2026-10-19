-- ============================================================
-- T-C1: client_bookings fields for Operations Head dispatch instructions
-- (DRAFT FOR REVIEW - NEW IN THE B3 IMPLEMENTATION)
-- File: db/target_migrations/20261014_tC1_client_booking_dispatch_fields.sql
-- Option B phase: B3a.
--
-- STATUS: NOT EXECUTED and NOT APPROVED FOR EXECUTION.
--   Saving this file is NOT approval to run it. Execute only after explicit
--   approval, on a disposable copy first, with a verified backup.
--
-- TARGET DATABASE: the Option B target only (mulawin_fleetops_test for
--   testing). Do NOT apply to the legacy database (mulawin_fleetops has no
--   client_bookings table). No USE statement: select the database first.
--
-- PURPOSE:
--   The legacy dispatch_instructions table (Operations Head -> Dispatcher
--   queue) has no target table. The B3 code stores each instruction as a
--   target client_bookings row (client, origin, destination, truck type,
--   requested date, quantity, notes, status Pending/Confirmed/Cancelled) and
--   links the encoded trip through the existing trips.booking_id.
--   client_bookings cannot hold four facts the dispatcher queue depends on;
--   this file adds them as nullable columns:
--     route_id             approved route the trip must use (FK routes)
--     scheduled_departure  instructed departure date AND time
--     shift                Day / Night (dispatcher shift scope)
--     created_by           Operations Head user who issued it (FK users)
--   Status mapping used by the code: legacy Open = Pending,
--   Encoded = Confirmed (a trip exists), Cancelled = Cancelled.
--   The legacy instruction "batch" grouping is not kept (no target table);
--   batch entries become individual bookings.
--
-- SCOPE: one ALTER TABLE on client_bookings. No DROP, TRUNCATE, INSERT,
--   UPDATE or DELETE.
--
-- DEPENDENCIES: target schema baseline (client_bookings, routes, users).
-- COMPATIBILITY: MariaDB 10.4 / MySQL 8 syntax (no ADD COLUMN IF NOT
--   EXISTS). Hostinger NOT verified.
-- NOTE: SQL examples in comments have no statement terminators on purpose.
--
-- READ-ONLY PRE-CHECKS (expected result in brackets):
--   a) SELECT DATABASE()                                  -- [the target db]
--   b) SELECT VERSION()                                   -- [record it]
--   c) SELECT COLUMN_NAME FROM information_schema.COLUMNS
--       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_bookings'
--         AND COLUMN_NAME IN ('route_id','scheduled_departure','shift','created_by')
--                                                         -- [no rows]
--   d) SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
--       WHERE CONSTRAINT_SCHEMA = DATABASE()
--         AND CONSTRAINT_NAME IN ('fk_bookings_route','fk_bookings_creator')
--                                                         -- [no rows]
--
-- BACKFILL: none on a fresh test database. For a production data
--   migration, each legacy dispatch_instructions row maps to one booking:
--   client_id, route_id, scheduled_at -> scheduled_departure (and DATE() ->
--   requested_date and booking_date), shift, unit_count -> quantity
--   (integer), instruction_notes -> notes, created_by, status as above,
--   origin/destination copied from the route.
--
-- TRANSACTIONS: ALTER TABLE commits implicitly; a rerun fails with a
--   duplicate-column error and changes nothing.
--
-- ROLLBACK (only with explicit approval, before B3 code that uses these
--   columns is deployed - afterwards the dispatcher queue fails):
--     ALTER TABLE client_bookings DROP FOREIGN KEY fk_bookings_route,
--       DROP FOREIGN KEY fk_bookings_creator,
--       DROP INDEX idx_bookings_queue, DROP INDEX idx_bookings_route,
--       DROP INDEX idx_bookings_creator,
--       DROP COLUMN created_by, DROP COLUMN shift,
--       DROP COLUMN scheduled_departure, DROP COLUMN route_id
--   Data loss: the four values of every booking.
-- ============================================================

ALTER TABLE client_bookings
    ADD COLUMN route_id INT UNSIGNED NULL AFTER client_id,
    ADD COLUMN scheduled_departure DATETIME NULL AFTER requested_date,
    ADD COLUMN shift ENUM('Day','Night') NULL AFTER scheduled_departure,
    ADD COLUMN created_by INT UNSIGNED NULL AFTER notes,
    ADD KEY idx_bookings_queue (status, shift, scheduled_departure),
    ADD KEY idx_bookings_route (route_id),
    ADD KEY idx_bookings_creator (created_by),
    ADD CONSTRAINT fk_bookings_route FOREIGN KEY (route_id)
        REFERENCES routes (route_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    ADD CONSTRAINT fk_bookings_creator FOREIGN KEY (created_by)
        REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL;
