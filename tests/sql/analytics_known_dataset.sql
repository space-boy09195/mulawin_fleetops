-- ============================================================
-- tests/sql/analytics_known_dataset.sql
-- Known-dataset check of the analytics / dashboard definitions.
--
-- STATUS: PREPARED, NOT EXECUTED. Run only against the isolated test
-- database `mulawin_fleetops_test`, after the owner has approved it and
-- verified the backup (see docs/testing/database_setup_checklist.md).
-- NEVER run against `mulawin_fleetops`.
--
-- Safety: everything happens inside one transaction that ends with
-- ROLLBACK, so no row is kept. Rows use the far-past window March 2001 and
-- "ZZ-TEST" names so they cannot mix with imported data. It creates no user:
-- it borrows the lowest existing users.user_id for NOT NULL "created_by"
-- columns (the test database must contain at least one user; if not, the
-- inserts fail and nothing is changed).
--
-- Expected results (computed by hand from the rows below; every check row
-- must say PASS):
--   delivered trips 3, measurable 2, on-time 1, late 1, on-time rate 50.0
--   fleet utilization: 2 distinct trucks on non-cancelled trips
--   revenue billed 15000.00 (the Draft billing is excluded)
--   collected 9000.00, collection rate 60.0
--   outstanding 6000.00 (billing 1, overdue: due 2001-03-20)
--   maintenance cost 3000.00 (the Cancelled record is excluded)
--   operating expenses 1200.00 (the Maintenance-type expense row excluded)
--   recorded margin 10800.00 = 15000 - (3000 + 1200 + payroll 0 + trip pay 0)
-- The SQL below mirrors includes/metrics.php, includes/finance_records.php
-- and includes/dashboard_metrics.php; update it if those definitions change.
-- ============================================================

USE mulawin_fleetops_test;

START TRANSACTION;

SET @u := (SELECT MIN(user_id) FROM users);
SET @from := '2001-03-01 00:00:00';
SET @to   := '2001-04-01 00:00:00';

INSERT INTO clients (client_name, status) VALUES ('ZZ-TEST Analytics Client', 'Active');
SET @client := LAST_INSERT_ID();

INSERT INTO trucks (plate_number, truck_type, status) VALUES ('ZZTEST-A', 'Wing Van', 'Available');
SET @truck_a := LAST_INSERT_ID();
INSERT INTO trucks (plate_number, truck_type, status) VALUES ('ZZTEST-B', 'Wing Van', 'Available');
SET @truck_b := LAST_INSERT_ID();

-- T1 on time, T2 late, T3 delivered without scheduled arrival (not
-- measurable), T4 cancelled.
INSERT INTO trips (trip_number, trip_type, client_id, origin, destination, scheduled_departure, scheduled_arrival,
                   truck_id, status, departure_time, arrival_time, completion_time)
VALUES ('ZZTEST-T1', 'Wing Van', @client, 'A', 'B', '2001-03-05 06:00:00', '2001-03-05 12:00:00',
        @truck_a, 'Completed', '2001-03-05 06:10:00', '2001-03-05 11:00:00', '2001-03-05 15:00:00');
SET @t1 := LAST_INSERT_ID();
INSERT INTO trips (trip_number, trip_type, client_id, origin, destination, scheduled_departure, scheduled_arrival,
                   truck_id, status, departure_time, arrival_time)
VALUES ('ZZTEST-T2', 'Wing Van', @client, 'A', 'B', '2001-03-06 06:00:00', '2001-03-06 12:00:00',
        @truck_a, 'Delivered', '2001-03-06 06:05:00', '2001-03-06 13:00:00');
SET @t2 := LAST_INSERT_ID();
INSERT INTO trips (trip_number, trip_type, client_id, origin, destination, scheduled_departure, scheduled_arrival,
                   truck_id, status, departure_time, completion_time)
VALUES ('ZZTEST-T3', 'Wing Van', @client, 'A', 'B', '2001-03-07 06:00:00', NULL,
        @truck_b, 'Completed', '2001-03-07 06:00:00', '2001-03-07 18:00:00');
SET @t3 := LAST_INSERT_ID();
INSERT INTO trips (trip_number, trip_type, client_id, origin, destination, scheduled_departure, truck_id, status)
VALUES ('ZZTEST-T4', 'Wing Van', @client, 'A', 'B', '2001-03-08 06:00:00', @truck_b, 'Cancelled');

INSERT INTO billings (invoice_number, trip_id, client_id, billing_date, due_date, subtotal, total_amount, status, created_by)
VALUES ('ZZTEST-INV-1', @t1, @client, '2001-03-10', '2001-03-20', 10000, 10000, 'Issued', @u);
SET @b1 := LAST_INSERT_ID();
INSERT INTO billings (invoice_number, trip_id, client_id, billing_date, due_date, subtotal, total_amount, status, created_by)
VALUES ('ZZTEST-INV-2', @t2, @client, '2001-03-11', '2001-03-25', 5000, 5000, 'Paid', @u);
SET @b2 := LAST_INSERT_ID();
INSERT INTO billings (invoice_number, trip_id, client_id, billing_date, subtotal, total_amount, status, created_by)
VALUES ('ZZTEST-INV-3', @t3, @client, '2001-03-12', 7000, 7000, 'Draft', @u);

INSERT INTO collections (billing_id, client_id, amount, payment_date, payment_method, received_by)
VALUES (@b1, @client, 4000, '2001-03-15', 'Cash', @u),
       (@b2, @client, 5000, '2001-03-12', 'Bank Transfer', @u);

INSERT INTO maintenance_records (truck_id, maintenance_type, description, actual_completion_date, status, total_cost)
VALUES (@truck_a, 'Repair', 'ZZTEST repair', '2001-03-08', 'Completed', 2500),
       (@truck_a, 'Repair', 'ZZTEST cancelled', '2001-03-09', 'Cancelled', 9999),
       (@truck_b, 'Preventive', 'ZZTEST pms', NULL, 'Scheduled', 500);
UPDATE maintenance_records SET scheduled_date = '2001-03-25'
WHERE description = 'ZZTEST pms' AND truck_id = @truck_b;

INSERT INTO expense_records (expense_type, reference_type, reference_id, description, amount, expense_date, recorded_by)
VALUES ('Fuel', 'trip', @t1, 'ZZTEST fuel', 1200, '2001-03-05', @u),
       ('Maintenance', NULL, NULL, 'ZZTEST maintenance expense row', 800, '2001-03-08', @u);

-- ── Checks (same definitions as the application) ─────────────────────────
SELECT 'delivered / measurable / on-time / late' AS metric, x.actual, '3/2/1/1' AS expected,
       IF(x.actual = '3/2/1/1', 'PASS', 'FAIL') AS result
FROM (
    SELECT CONCAT(
               SUM(t.status IN ('Delivered','Returned','Completed')), '/',
               SUM(t.status IN ('Delivered','Returned','Completed') AND t.scheduled_arrival IS NOT NULL
                   AND COALESCE(t.arrival_time, t.completion_time) IS NOT NULL), '/',
               SUM(t.status IN ('Delivered','Returned','Completed') AND t.scheduled_arrival IS NOT NULL
                   AND COALESCE(t.arrival_time, t.completion_time) IS NOT NULL
                   AND COALESCE(t.arrival_time, t.completion_time) <= t.scheduled_arrival), '/',
               SUM(t.status IN ('Delivered','Returned','Completed') AND t.scheduled_arrival IS NOT NULL
                   AND COALESCE(t.arrival_time, t.completion_time) IS NOT NULL
                   AND COALESCE(t.arrival_time, t.completion_time) > t.scheduled_arrival)) AS actual
    FROM trips t
    WHERE t.trip_number LIKE 'ZZTEST-%' AND t.scheduled_departure >= @from AND t.scheduled_departure < @to
) x;

SELECT 'utilized trucks' AS metric, COUNT(DISTINCT t.truck_id) AS actual, 2 AS expected,
       IF(COUNT(DISTINCT t.truck_id) = 2, 'PASS', 'FAIL') AS result
FROM trips t
WHERE t.trip_number LIKE 'ZZTEST-%' AND t.truck_id IS NOT NULL
  AND t.status NOT IN ('Cancelled', 'Pending Approval')
  AND t.scheduled_departure >= @from AND t.scheduled_departure < @to;

SELECT 'revenue billed' AS metric, SUM(b.total_amount) AS actual, 15000.00 AS expected,
       IF(SUM(b.total_amount) = 15000.00, 'PASS', 'FAIL') AS result
FROM billings b
WHERE b.invoice_number LIKE 'ZZTEST-%' AND b.status IN ('Issued','Partially Paid','Paid','Overdue')
  AND b.billing_date >= @from AND b.billing_date < @to;

SELECT 'collected / collection rate' AS metric,
       CONCAT(SUM(c.amount), ' / ', ROUND(SUM(c.amount) / 15000 * 100, 1)) AS actual,
       '9000.00 / 60.0' AS expected,
       IF(SUM(c.amount) = 9000.00 AND ROUND(SUM(c.amount) / 15000 * 100, 1) = 60.0, 'PASS', 'FAIL') AS result
FROM collections c JOIN billings b ON b.billing_id = c.billing_id
WHERE b.invoice_number LIKE 'ZZTEST-%' AND c.payment_date >= @from AND c.payment_date < @to;

SELECT 'outstanding (overdue)' AS metric, SUM(x.balance) AS actual, 6000.00 AS expected,
       IF(SUM(x.balance) = 6000.00 AND SUM(x.overdue) = 1, 'PASS', 'FAIL') AS result
FROM (
    SELECT b.total_amount - (SELECT COALESCE(SUM(cpay.amount), 0) FROM collections cpay WHERE cpay.billing_id = b.billing_id) AS balance,
           (b.due_date IS NOT NULL AND b.due_date < CURDATE()) AS overdue
    FROM billings b
    WHERE b.invoice_number LIKE 'ZZTEST-%' AND b.status IN ('Issued', 'Partially Paid', 'Overdue')
) x WHERE x.balance > 0;

SELECT 'maintenance cost' AS metric, SUM(mr.total_cost) AS actual, 3000.00 AS expected,
       IF(SUM(mr.total_cost) = 3000.00, 'PASS', 'FAIL') AS result
FROM maintenance_records mr
WHERE mr.description LIKE 'ZZTEST%' AND mr.status <> 'Cancelled'
  AND COALESCE(mr.actual_completion_date, mr.start_date, mr.scheduled_date, DATE(mr.created_at)) >= @from
  AND COALESCE(mr.actual_completion_date, mr.start_date, mr.scheduled_date, DATE(mr.created_at)) < @to;

SELECT 'operating expenses' AS metric, SUM(er.amount) AS actual, 1200.00 AS expected,
       IF(SUM(er.amount) = 1200.00, 'PASS', 'FAIL') AS result
FROM expense_records er
WHERE er.description LIKE 'ZZTEST%' AND er.expense_type IN ('Fuel','Toll','Driver Allowance','Operating','Other')
  AND er.expense_date >= @from AND er.expense_date < @to;

SELECT 'recorded margin' AS metric, 15000.00 - (3000.00 + 1200.00 + 0 + 0) AS expected_formula,
       'compare with the Analytics page for March 2001 if the window is selectable' AS note;

ROLLBACK;
-- After ROLLBACK no ZZTEST row remains; verify with:
--   SELECT COUNT(*) FROM trips WHERE trip_number LIKE 'ZZTEST-%';   -- expect 0
