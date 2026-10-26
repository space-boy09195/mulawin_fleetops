-- ============================================================
-- tests/integration/seed_synthetic.sql
-- Synthetic fixtures for tests/integration/run.php.
--
-- NOT RUN YET. Running it needs separate approval of the setup stage
-- (tests/integration/README.md). Never run it against the working database
-- (mulawin_fleetops_test) or any database that holds real records.
--
-- Prerequisites, all done in the approved setup stage with the SETUP
-- account (never the application-test account):
--   1. An empty disposable database named mulawin_fleetops_e2e[_suffix],
--      holding the current schema and ONLY the configuration rows of
--      roles, permissions, role_permissions, approval_workflows and
--      approval_workflow_steps.
--   2. The random marker row in app_settings (setting_key 'e2e_marker',
--      64 hex characters). The marker is generated per setup and is
--      deliberately NOT in this file.
--   3. The session variable @e2e_password_hash set to a bcrypt hash of the
--      synthetic users' password, chosen at setup, e.g.
--        mysql --init-command="SET @e2e_password_hash='<hash>'" ...
--      No password or hash is stored in this file.
--
-- Run with the mysql client WITHOUT --force, so the first error stops it.
-- The guard below stops before any change unless the current database
-- matches the disposable pattern, the marker row exists, the hash is set,
-- and the fixture tables are empty. Everything after the guard runs in one
-- transaction; an error leaves nothing behind (the client disconnects and
-- the open transaction is rolled back).
--
-- This file contains no DROP, TRUNCATE, ALTER, GRANT or DELETE. It inserts
-- clearly fictional rows only: usernames start with e2e_, e-mail addresses
-- use the reserved example.invalid domain, names start with E2E.
--
-- Roles are looked up by roles.system_key, so role IDs may differ from any
-- other database.
-- ============================================================

DELIMITER //
BEGIN NOT ATOMIC
    IF DATABASE() IS NULL OR DATABASE() = 'mulawin_fleetops_test'
       OR DATABASE() NOT REGEXP '^mulawin_fleetops_e2e(_[a-z0-9]+)?$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refused: not a disposable mulawin_fleetops_e2e database.';
    END IF;
    IF (SELECT COUNT(*) FROM app_settings
        WHERE setting_key = 'e2e_marker' AND setting_value REGEXP '^[0-9a-f]{64}$') <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refused: setup marker row is missing.';
    END IF;
    IF @e2e_password_hash IS NULL
       OR @e2e_password_hash NOT REGEXP '^\\$2y\\$1[0-9]\\$[./A-Za-z0-9]{53}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refused: @e2e_password_hash is not a bcrypt hash.';
    END IF;
    IF EXISTS (SELECT 1 FROM users) OR EXISTS (SELECT 1 FROM employees)
       OR EXISTS (SELECT 1 FROM clients) OR EXISTS (SELECT 1 FROM trips)
       OR EXISTS (SELECT 1 FROM suppliers) OR EXISTS (SELECT 1 FROM parts) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refused: fixture tables are not empty.';
    END IF;
    IF (SELECT COUNT(*) FROM roles
        WHERE is_active = 1
          AND system_key IN ('admin', 'finance', 'car_carrier_dispatcher', 'purchasing_officer')) <> 4 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refused: required roles are missing or inactive.';
    END IF;
END//
DELIMITER ;

START TRANSACTION;

SET @role_admin      = (SELECT role_id FROM roles WHERE system_key = 'admin');
SET @role_finance    = (SELECT role_id FROM roles WHERE system_key = 'finance');
SET @role_dispatcher = (SELECT role_id FROM roles WHERE system_key = 'car_carrier_dispatcher');
SET @role_purchasing = (SELECT role_id FROM roles WHERE system_key = 'purchasing_officer');

-- ---- Employees and users -----------------------------------
INSERT INTO employees (first_name, last_name, employment_status, position)
VALUES ('E2E', 'Admin', 'Active', 'E2E Test Account');
SET @emp_admin = LAST_INSERT_ID();
INSERT INTO users (employee_id, username, email, password_hash, role_id, is_active)
VALUES (@emp_admin, 'e2e_admin', 'e2e_admin@example.invalid', @e2e_password_hash, @role_admin, 1);
SET @user_admin = LAST_INSERT_ID();

-- Inactive Admin: must never receive approval notifications.
INSERT INTO employees (first_name, last_name, employment_status, position)
VALUES ('E2E', 'Inactive Admin', 'Inactive', 'E2E Test Account');
SET @emp_admin_inactive = LAST_INSERT_ID();
INSERT INTO users (employee_id, username, email, password_hash, role_id, is_active)
VALUES (@emp_admin_inactive, 'e2e_admin_inactive', 'e2e_admin_inactive@example.invalid', @e2e_password_hash, @role_admin, 0);

INSERT INTO employees (first_name, last_name, employment_status, position)
VALUES ('E2E', 'Finance', 'Active', 'E2E Test Account');
SET @emp_finance = LAST_INSERT_ID();
INSERT INTO users (employee_id, username, email, password_hash, role_id, is_active)
VALUES (@emp_finance, 'e2e_finance', 'e2e_finance@example.invalid', @e2e_password_hash, @role_finance, 1);

INSERT INTO employees (first_name, last_name, employment_status, position)
VALUES ('E2E', 'Dispatcher', 'Active', 'E2E Test Account');
SET @emp_dispatcher = LAST_INSERT_ID();
INSERT INTO users (employee_id, username, email, password_hash, role_id, is_active)
VALUES (@emp_dispatcher, 'e2e_dispatcher', 'e2e_dispatcher@example.invalid', @e2e_password_hash, @role_dispatcher, 1);
SET @user_dispatcher = LAST_INSERT_ID();

INSERT INTO employees (first_name, last_name, employment_status, position)
VALUES ('E2E', 'Purchasing', 'Active', 'E2E Test Account');
SET @emp_purchasing = LAST_INSERT_ID();
INSERT INTO users (employee_id, username, email, password_hash, role_id, is_active)
VALUES (@emp_purchasing, 'e2e_purchasing', 'e2e_purchasing@example.invalid', @e2e_password_hash, @role_purchasing, 1);

-- One open-ended Car Carrier Day assignment (includes/dispatcher_scope.php).
INSERT INTO dispatcher_assignments (employee_id, dispatcher_role_type, shift, start_date, end_date, is_active)
VALUES (@emp_dispatcher, 'CAR_CARRIER', 'Day', '2020-01-01', NULL, 1);

-- ---- Trip for the dispatcher's Trip Allowance request -------
INSERT INTO clients (client_name, status)
VALUES ('E2E Fictional Client', 'Active');
SET @client = LAST_INSERT_ID();

INSERT INTO routes (route_name, origin, destination, is_active)
VALUES ('E2E Fictional Route', 'E2E Origin Yard', 'E2E Destination Yard', 1);
SET @route = LAST_INSERT_ID();

-- Assigned, submitted by the dispatcher, inside the dispatcher's scope:
-- the state ajax/finance_handler.php requires for a Trip Allowance request.
INSERT INTO trips (trip_number, trip_type, client_id, route_id, origin, destination,
                   scheduled_departure, dispatcher_id, shift, status)
VALUES ('E2E-TRIP-0001', 'Car Carrier', @client, @route, 'E2E Origin Yard', 'E2E Destination Yard',
        TIMESTAMP(CURDATE() + INTERVAL 1 DAY, '08:00:00'), @emp_dispatcher, 'Day', 'Assigned');
SET @trip = LAST_INSERT_ID();

INSERT INTO dispatch_requests (trip_id, submitted_by, status, reviewed_by, reviewed_at)
VALUES (@trip, @user_dispatcher, 'Approved', @user_admin, NOW());

-- ---- Supplier and part for the purchase order ---------------
INSERT INTO suppliers (supplier_name, status)
VALUES ('E2E Fictional Supplier', 'Active');
SET @supplier = LAST_INSERT_ID();

INSERT INTO parts (part_number, part_name, unit, unit_cost, supplier_id, is_active)
VALUES ('E2E-PART-001', 'E2E Fictional Part', 'pcs', 100.00, @supplier, 1);

COMMIT;
