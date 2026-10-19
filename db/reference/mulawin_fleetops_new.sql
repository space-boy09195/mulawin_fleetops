-- ============================================================
-- MULAWIN FLEETOPS - TARGET DATABASE
-- Target architecture based on the approved System Requirements Plan
-- MySQL 8.x
--
-- IMPORTANT:
-- This is a NEW database. It does not modify the existing
-- mulawin_fleetops database.
--
-- Source-derived requirements:
-- - Role-based access and approvals
-- - Single-holder departmental roles; Driver/Helper may be multi-user
-- - Two dispatcher roles with Day/Night shift as assignment data
-- - Fleet, trips, dispatch, inspections, maintenance
-- - Purchasing/inventory, finance, billing/AR, payroll/timekeeping
-- - Documents, audit logs, notifications, recruitment, office supplies
-- - Backup/recovery is handled operationally, not as application tables
--
-- Design decisions (not explicitly specified by the source):
-- - Exact SQL data types, indexes, surrogate integer PKs, and some
--   controlled status values are implementation choices.
-- - The schema favors normalized records and auditability.
-- ============================================================

CREATE DATABASE IF NOT EXISTS mulawin_fleetops_new
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE mulawin_fleetops_new;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. RBAC / ACCESS
-- ============================================================

CREATE TABLE roles (
    role_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_single_holder TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_name (role_name)
) ENGINE=InnoDB;

CREATE TABLE permissions (
    permission_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(100) NOT NULL,
    permission_name VARCHAR(150) NOT NULL,
    module VARCHAR(60) NOT NULL,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_permissions_key (permission_key),
    KEY idx_permissions_module (module)
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_permission_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_role_permission (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(role_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(permission_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 2. EMPLOYEES / USERS
-- ============================================================

CREATE TABLE employees (
    employee_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    first_name VARCHAR(80) NOT NULL,
    middle_name VARCHAR(80) NULL,
    last_name VARCHAR(80) NOT NULL,
    suffix VARCHAR(20) NULL,
    employment_type ENUM('Employee','Contractor') NOT NULL DEFAULT 'Employee',
    employment_status ENUM('Active','Resigned','Inactive') NOT NULL DEFAULT 'Active',
    contact_number VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    address TEXT NULL,
    date_hired DATE NULL,
    date_resigned DATE NULL,
    position VARCHAR(100) NULL,
    license_number VARCHAR(100) NULL,
    license_expiry DATE NULL,
    availability_status ENUM('Available','Unavailable','On Trip','On Leave') NOT NULL DEFAULT 'Available',
    contractor_company VARCHAR(150) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_employee_name (last_name, first_name),
    KEY idx_employee_status (employment_status),
    KEY idx_employee_availability (availability_status),
    KEY idx_license_expiry (license_expiry)
) ENGINE=InnoDB;

CREATE TABLE users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NULL,
    username VARCHAR(80) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_employee (employee_id),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(role_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

ALTER TABLE employees
    ADD CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- ============================================================
-- 3. DISPATCHER ASSIGNMENTS
-- ============================================================

CREATE TABLE dispatcher_assignments (
    dispatcher_assignment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    dispatcher_role_type ENUM('CAR_CARRIER','CONTAINER_WING_VAN') NOT NULL,
    shift ENUM('Day','Night') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_dispatcher_employee (employee_id),
    KEY idx_dispatcher_scope (dispatcher_role_type, shift, is_active),
    CONSTRAINT fk_dispatcher_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- 4. ROUTES / CLIENTS / BOOKINGS / NOMINATIONS
-- ============================================================

CREATE TABLE routes (
    route_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    route_name VARCHAR(150) NOT NULL,
    origin VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    distance DECIMAL(10,2) NULL,
    estimated_duration_minutes INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_routes_active (is_active),
    CONSTRAINT fk_routes_creator FOREIGN KEY (created_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE clients (
    client_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_name VARCHAR(180) NOT NULL,
    contact_person VARCHAR(150) NULL,
    contact_number VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    address TEXT NULL,
    billing_address TEXT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_clients_name (client_name),
    KEY idx_clients_status (status)
) ENGINE=InnoDB;

CREATE TABLE client_locations (
    location_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    location_name VARCHAR(150) NOT NULL,
    address TEXT NOT NULL,
    contact_person VARCHAR(150) NULL,
    contact_number VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_client_locations_client (client_id),
    CONSTRAINT fk_client_locations_client FOREIGN KEY (client_id) REFERENCES clients(client_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE client_rates (
    rate_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    route_id INT UNSIGNED NULL,
    truck_type ENUM('Car Carrier','Container','Wing Van') NOT NULL,
    rate_type ENUM('Route','Distance','Fixed') NOT NULL DEFAULT 'Route',
    rate_amount DECIMAL(14,2) NOT NULL,
    effective_date DATE NOT NULL,
    end_date DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rates_client (client_id),
    KEY idx_rates_route (route_id),
    KEY idx_rates_dates (effective_date, end_date),
    CONSTRAINT fk_client_rates_client FOREIGN KEY (client_id) REFERENCES clients(client_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_client_rates_route FOREIGN KEY (route_id) REFERENCES routes(route_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_client_rates_amount CHECK (rate_amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE client_bookings (
    booking_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    booking_reference VARCHAR(100) NOT NULL,
    booking_date DATE NOT NULL,
    origin VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    truck_type ENUM('Car Carrier','Container','Wing Van') NOT NULL,
    requested_date DATE NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('Pending','Confirmed','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_booking_reference (booking_reference),
    KEY idx_bookings_client (client_id),
    CONSTRAINT fk_bookings_client FOREIGN KEY (client_id) REFERENCES clients(client_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE client_nominations (
    nomination_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    nomination_reference VARCHAR(100) NOT NULL,
    nomination_date DATE NOT NULL,
    origin VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    requested_date DATE NULL,
    status ENUM('Pending','Confirmed','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nomination_reference (nomination_reference),
    KEY idx_nominations_client (client_id),
    CONSTRAINT fk_nominations_client FOREIGN KEY (client_id) REFERENCES clients(client_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- 5. FLEET
-- ============================================================

CREATE TABLE trucks (
    truck_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_number VARCHAR(50) NULL,
    plate_number VARCHAR(30) NOT NULL,
    truck_type ENUM('Car Carrier','Container','Wing Van') NOT NULL,
    brand VARCHAR(80) NULL,
    model VARCHAR(80) NULL,
    vehicle_year YEAR NULL,
    chassis_number VARCHAR(100) NULL,
    engine_number VARCHAR(100) NULL,
    body_details TEXT NULL,
    capacity DECIMAL(12,2) NULL,
    fuel_type VARCHAR(40) NULL,
    status ENUM('Available','Assigned','On Trip','Under Maintenance','Unavailable') NOT NULL DEFAULT 'Available',
    availability_status ENUM('Available','Unavailable') NOT NULL DEFAULT 'Available',
    current_assignment_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_truck_unit (unit_number),
    UNIQUE KEY uq_truck_plate (plate_number),
    UNIQUE KEY uq_truck_chassis (chassis_number),
    UNIQUE KEY uq_truck_engine (engine_number),
    KEY idx_truck_type_status (truck_type, status),
    KEY idx_truck_availability (availability_status)
) ENGINE=InnoDB;

CREATE TABLE truck_documents (
    truck_document_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    truck_id INT UNSIGNED NOT NULL,
    document_type ENUM('OR','CR','Insurance','Other') NOT NULL,
    document_number VARCHAR(100) NULL,
    issue_date DATE NULL,
    expiry_date DATE NULL,
    file_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_truck_docs_truck (truck_id),
    KEY idx_truck_docs_expiry (expiry_date),
    CONSTRAINT fk_truck_docs_truck FOREIGN KEY (truck_id) REFERENCES trucks(truck_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- 6. TRIPS / ASSIGNMENTS / DISPATCH
-- ============================================================

CREATE TABLE trips (
    trip_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_number VARCHAR(60) NOT NULL,
    trip_type ENUM('Car Carrier','Container','Wing Van') NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    booking_id BIGINT UNSIGNED NULL,
    nomination_id BIGINT UNSIGNED NULL,
    route_id INT UNSIGNED NULL,
    origin VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    scheduled_departure DATETIME NOT NULL,
    scheduled_arrival DATETIME NULL,
    truck_id INT UNSIGNED NULL,
    driver_id INT UNSIGNED NULL,
    helper_id INT UNSIGNED NULL,
    dispatcher_id INT UNSIGNED NULL,
    shift ENUM('Day','Night') NULL,
    status ENUM('Pending Approval','Approved','Assigned','Ready for Dispatch','Dispatched','In Progress','Delivered','Returned','Completed','Cancelled','For Review') NOT NULL DEFAULT 'Pending Approval',
    departure_time DATETIME NULL,
    arrival_time DATETIME NULL,
    completion_time DATETIME NULL,
    return_time DATETIME NULL,
    completion_notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_trip_number (trip_number),
    KEY idx_trip_client (client_id),
    KEY idx_trip_date (scheduled_departure),
    KEY idx_trip_status (status),
    KEY idx_trip_truck (truck_id),
    KEY idx_trip_driver (driver_id),
    KEY idx_trip_dispatcher (dispatcher_id),
    KEY idx_trip_type_shift (trip_type, shift),
    CONSTRAINT fk_trips_client FOREIGN KEY (client_id) REFERENCES clients(client_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_trips_booking FOREIGN KEY (booking_id) REFERENCES client_bookings(booking_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_trips_nomination FOREIGN KEY (nomination_id) REFERENCES client_nominations(nomination_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_trips_route FOREIGN KEY (route_id) REFERENCES routes(route_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_trips_truck FOREIGN KEY (truck_id) REFERENCES trucks(truck_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_trips_driver FOREIGN KEY (driver_id) REFERENCES employees(employee_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_trips_helper FOREIGN KEY (helper_id) REFERENCES employees(employee_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_trips_dispatcher FOREIGN KEY (dispatcher_id) REFERENCES employees(employee_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_trip_dates CHECK (scheduled_arrival IS NULL OR scheduled_arrival >= scheduled_departure)
) ENGINE=InnoDB;

CREATE TABLE truck_assignments (
    truck_assignment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    truck_id INT UNSIGNED NOT NULL,
    trip_id BIGINT UNSIGNED NOT NULL,
    assigned_from DATETIME NOT NULL,
    assigned_until DATETIME NULL,
    assignment_status ENUM('Reserved','Assigned','Released','Cancelled') NOT NULL DEFAULT 'Reserved',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_truck_assignments_window (truck_id, assigned_from, assigned_until),
    KEY idx_truck_assignments_trip (trip_id),
    CONSTRAINT fk_truck_assignments_truck FOREIGN KEY (truck_id) REFERENCES trucks(truck_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_truck_assignments_trip FOREIGN KEY (trip_id) REFERENCES trips(trip_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE trip_updates (
    trip_update_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id BIGINT UNSIGNED NOT NULL,
    update_type ENUM('Departure','Loading','Unloading','In Transit','Delayed','Delivered','Return','Other') NOT NULL,
    description TEXT NULL,
    location_text VARCHAR(255) NULL,
    reported_by INT UNSIGNED NULL,
    reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_trip_updates_trip_date (trip_id, reported_at),
    CONSTRAINT fk_trip_updates_trip FOREIGN KEY (trip_id) REFERENCES trips(trip_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_trip_updates_user FOREIGN KEY (reported_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE trip_deliveries (
    delivery_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id BIGINT UNSIGNED NOT NULL,
    delivery_date DATE NOT NULL,
    delivery_time TIME NULL,
    received_by VARCHAR(150) NULL,
    delivery_notes TEXT NULL,
    waybill_number VARCHAR(100) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_delivery_trip (trip_id),
    KEY idx_delivery_waybill (waybill_number),
    CONSTRAINT fk_delivery_trip FOREIGN KEY (trip_id) REFERENCES trips(trip_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_delivery_creator FOREIGN KEY (created_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE trip_returns (
    return_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id BIGINT UNSIGNED NOT NULL,
    return_date DATE NOT NULL,
    return_time TIME NULL,
    return_condition TEXT NULL,
    return_notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_return_trip (trip_id),
    CONSTRAINT fk_return_trip FOREIGN KEY (trip_id) REFERENCES trips(trip_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_return_creator FOREIGN KEY (created_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE trip_incidents (
    incident_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id BIGINT UNSIGNED NOT NULL,
    truck_id INT UNSIGNED NULL,
    incident_type ENUM('Breakdown','Accident','Delay','Damage','Missing Equipment','Other') NOT NULL,
    description TEXT NOT NULL,
    incident_date DATETIME NOT NULL,
    severity ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Low',
    action_taken TEXT NULL,
    reported_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_incidents_trip (trip_id),
    KEY idx_incidents_date (incident_date),
    CONSTRAINT fk_incidents_trip FOREIGN KEY (trip_id) REFERENCES trips(trip_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_incidents_truck FOREIGN KEY (truck_id) REFERENCES trucks(truck_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_incidents_user FOREIGN KEY (reported_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE dispatch_requests (
    dispatch_request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id BIGINT UNSIGNED NOT NULL,
    submitted_by INT UNSIGNED NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_notes TEXT NULL,
    UNIQUE KEY uq_dispatch_request_trip (trip_id),
    KEY idx_dispatch_requests_status (status),
    CONSTRAINT fk_dispatch_request_trip FOREIGN KEY (trip_id) REFERENCES trips(trip_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_dispatch_request_submitter FOREIGN KEY (submitted_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_dispatch_request_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE dispatch_clearances (
    dispatch_clearance_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id BIGINT UNSIGNED NOT NULL,
    cleared_by INT UNSIGNED NOT NULL,
    cleared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('Cleared','Denied') NOT NULL,
    remarks TEXT NULL,
    UNIQUE KEY uq_dispatch_clearance_trip (trip_id),
    CONSTRAINT fk_dispatch_clearance_trip FOREIGN KEY (trip_id) REFERENCES trips(trip_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_dispatch_clearance_user FOREIGN KEY (cleared_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE workflow_events (
    workflow_event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(60) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    old_status VARCHAR(80) NULL,
    new_status VARCHAR(80) NULL,
    performed_by INT UNSIGNED NULL,
    remarks TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_workflow_entity (entity_type, entity_id),
    KEY idx_workflow_date (created_at),
    CONSTRAINT fk_workflow_user FOREIGN KEY (performed_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 7. INSPECTIONS
-- ============================================================

CREATE TABLE inspection_templates (
    template_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(150) NOT NULL,
    inspection_stage ENUM('Departure','Arrival') NOT NULL,
    minimum_duration_seconds INT UNSIGNED NOT NULL DEFAULT 900,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inspection_template (template_name, inspection_stage)
) ENGINE=InnoDB;

CREATE TABLE inspection_items (
    inspection_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    category VARCHAR(80) NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_inspection_items_template (template_id),
    CONSTRAINT fk_inspection_items_template FOREIGN KEY (template_id) REFERENCES inspection_templates(template_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE vehicle_inspections (
    inspection_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id BIGINT UNSIGNED NOT NULL,
    truck_id INT UNSIGNED NOT NULL,
    inspection_stage ENUM('Departure','Arrival') NOT NULL,
    inspected_by INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    duration_seconds INT UNSIGNED NULL,
    overall_result ENUM('Passed','Flagged','Failed') NULL,
    remarks TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_inspections_trip_stage (trip_id, inspection_stage),
    KEY idx_inspections_truck (truck_id),
    CONSTRAINT fk_inspections_trip FOREIGN KEY (trip_id) REFERENCES trips(trip_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_inspections_truck FOREIGN KEY (truck_id) REFERENCES trucks(truck_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_inspections_user FOREIGN KEY (inspected_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE inspection_findings (
    finding_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id BIGINT UNSIGNED NOT NULL,
    inspection_item_id INT UNSIGNED NOT NULL,
    result ENUM('Good','Flagged','N/A') NOT NULL,
    condition_description TEXT NULL,
    damage_description TEXT NULL,
    is_existing_damage TINYINT(1) NOT NULL DEFAULT 0,
    is_new_damage TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inspection_item (inspection_id, inspection_item_id),
    CONSTRAINT fk_findings_inspection FOREIGN KEY (inspection_id) REFERENCES vehicle_inspections(inspection_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_findings_item FOREIGN KEY (inspection_item_id) REFERENCES inspection_items(inspection_item_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE inspection_damage_comparisons (
    comparison_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    departure_finding_id BIGINT UNSIGNED NOT NULL,
    arrival_finding_id BIGINT UNSIGNED NOT NULL,
    comparison_result ENUM('No Change','Existing Damage','New Damage','Worsened','Resolved') NOT NULL,
    notes TEXT NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    UNIQUE KEY uq_damage_comparison_arrival (arrival_finding_id),
    CONSTRAINT fk_damage_departure FOREIGN KEY (departure_finding_id) REFERENCES inspection_findings(finding_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_damage_arrival FOREIGN KEY (arrival_finding_id) REFERENCES inspection_findings(finding_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_damage_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 8. PARTS / INVENTORY / MAINTENANCE / PURCHASING
-- ============================================================

CREATE TABLE suppliers (
    supplier_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(180) NOT NULL,
    contact_person VARCHAR(150) NULL,
    contact_number VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    address TEXT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_suppliers_name (supplier_name)
) ENGINE=InnoDB;

CREATE TABLE parts (
    part_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    part_number VARCHAR(80) NOT NULL,
    part_name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
    category VARCHAR(80) NULL,
    reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    warranty_period_days INT UNSIGNED NULL,
    supplier_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_part_number (part_number),
    KEY idx_parts_name (part_name),
    CONSTRAINT fk_parts_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_parts_cost CHECK (unit_cost >= 0),
    CONSTRAINT chk_parts_reorder CHECK (reorder_level >= 0)
) ENGINE=InnoDB;

CREATE TABLE inventory (
    inventory_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    part_id INT UNSIGNED NOT NULL,
    quantity_on_hand DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inventory_part (part_id),
    CONSTRAINT fk_inventory_part FOREIGN KEY (part_id) REFERENCES parts(part_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_inventory_qty CHECK (quantity_on_hand >= 0)
) ENGINE=InnoDB;

CREATE TABLE maintenance_records (
    maintenance_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    truck_id INT UNSIGNED NOT NULL,
    maintenance_type ENUM('Preventive','Repair','Inspection','Other') NOT NULL,
    reported_issue TEXT NULL,
    description TEXT NOT NULL,
    scheduled_date DATE NULL,
    start_date DATE NULL,
    expected_completion_date DATE NULL,
    actual_completion_date DATE NULL,
    status ENUM('Reported','Scheduled','In Progress','Waiting for Parts','Completed','Cancelled') NOT NULL DEFAULT 'Reported',
    expected_available_date DATE NULL,
    supplier_id INT UNSIGNED NULL,
    labor_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    parts_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    other_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    completed_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_maintenance_truck_status (truck_id, status),
    KEY idx_maintenance_expected (expected_available_date),
    CONSTRAINT fk_maintenance_truck FOREIGN KEY (truck_id) REFERENCES trucks(truck_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_maintenance_user FOREIGN KEY (completed_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_maintenance_costs CHECK (
        labor_cost >= 0 AND parts_cost >= 0 AND other_cost >= 0 AND total_cost >= 0
    )
) ENGINE=InnoDB;

CREATE TABLE maintenance_findings (
    maintenance_finding_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    maintenance_id BIGINT UNSIGNED NOT NULL,
    finding_type VARCHAR(80) NOT NULL,
    description TEXT NOT NULL,
    severity ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Low',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_maintenance_findings_maintenance FOREIGN KEY (maintenance_id) REFERENCES maintenance_records(maintenance_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE maintenance_parts (
    maintenance_part_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    maintenance_id BIGINT UNSIGNED NOT NULL,
    part_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    unit_cost DECIMAL(14,2) NOT NULL,
    total_cost DECIMAL(14,2) NOT NULL,
    issued_by INT UNSIGNED NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_maintenance_parts_maintenance (maintenance_id),
    KEY idx_maintenance_parts_part (part_id),
    CONSTRAINT fk_maintenance_parts_maintenance FOREIGN KEY (maintenance_id) REFERENCES maintenance_records(maintenance_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_maintenance_parts_part FOREIGN KEY (part_id) REFERENCES parts(part_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_parts_user FOREIGN KEY (issued_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_maintenance_parts_values CHECK (quantity > 0 AND unit_cost >= 0 AND total_cost >= 0)
) ENGINE=InnoDB;

CREATE TABLE inventory_transactions (
    inventory_transaction_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    part_id INT UNSIGNED NOT NULL,
    transaction_type ENUM('Stock In','Stock Out','Adjustment','Return') NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    unit_cost DECIMAL(14,2) NULL,
    reference_type VARCHAR(60) NULL,
    reference_id BIGINT UNSIGNED NULL,
    performed_by INT UNSIGNED NULL,
    transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    remarks TEXT NULL,
    KEY idx_inventory_tx_part_date (part_id, transaction_date),
    CONSTRAINT fk_inventory_tx_part FOREIGN KEY (part_id) REFERENCES parts(part_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_tx_user FOREIGN KEY (performed_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_inventory_tx_qty CHECK (quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE purchase_requests (
    purchase_request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(60) NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    department VARCHAR(100) NULL,
    purpose TEXT NOT NULL,
    total_amount DECIMAL(14,2) NOT NULL,
    status ENUM('Draft','Pending Approval','Approved','Rejected','Ordered','Completed','Cancelled') NOT NULL DEFAULT 'Draft',
    submitted_at DATETIME NULL,
    approved_at DATETIME NULL,
    approved_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_purchase_request_number (request_number),
    KEY idx_purchase_request_status (status),
    CONSTRAINT fk_purchase_request_user FOREIGN KEY (requested_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_purchase_request_approver FOREIGN KEY (approved_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_purchase_request_amount CHECK (total_amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE purchase_request_items (
    purchase_request_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_request_id BIGINT UNSIGNED NOT NULL,
    part_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    estimated_unit_cost DECIMAL(14,2) NOT NULL,
    estimated_total DECIMAL(14,2) NOT NULL,
    CONSTRAINT fk_purchase_request_items_request FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests(purchase_request_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_purchase_request_items_part FOREIGN KEY (part_id) REFERENCES parts(part_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_purchase_item_values CHECK (quantity > 0 AND estimated_unit_cost >= 0 AND estimated_total >= 0)
) ENGINE=InnoDB;

CREATE TABLE purchase_orders (
    purchase_order_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(60) NOT NULL,
    purchase_request_id BIGINT UNSIGNED NULL,
    supplier_id INT UNSIGNED NOT NULL,
    order_date DATE NOT NULL,
    expected_delivery_date DATE NULL,
    total_amount DECIMAL(14,2) NOT NULL,
    status ENUM('Draft','Issued','Partially Received','Received','Cancelled') NOT NULL DEFAULT 'Draft',
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_po_number (po_number),
    KEY idx_po_supplier (supplier_id),
    CONSTRAINT fk_po_request FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests(purchase_request_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_po_creator FOREIGN KEY (created_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_po_amount CHECK (total_amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE purchase_order_items (
    purchase_order_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id BIGINT UNSIGNED NOT NULL,
    part_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    unit_cost DECIMAL(14,2) NOT NULL,
    total_cost DECIMAL(14,2) NOT NULL,
    CONSTRAINT fk_po_items_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(purchase_order_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_po_items_part FOREIGN KEY (part_id) REFERENCES parts(part_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_po_item_values CHECK (quantity > 0 AND unit_cost >= 0 AND total_cost >= 0)
) ENGINE=InnoDB;

CREATE TABLE purchase_receipts (
    receipt_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id BIGINT UNSIGNED NOT NULL,
    receipt_number VARCHAR(100) NULL,
    received_date DATE NOT NULL,
    received_by INT UNSIGNED NOT NULL,
    notes TEXT NULL,
    file_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_receipts_po (purchase_order_id),
    CONSTRAINT fk_receipts_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(purchase_order_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_receipts_user FOREIGN KEY (received_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- 9. FINANCE
-- ============================================================

CREATE TABLE fund_requests (
    fund_request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(60) NOT NULL,
    trip_id BIGINT UNSIGNED NULL,
    requested_by INT UNSIGNED NOT NULL,
    request_type ENUM('Trip Allowance','Cash Advance','Operating Fund','Other') NOT NULL,
    purpose TEXT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    status ENUM('Draft','Pending Approval','Approved','Rejected','Processing','Disbursed','Settled','Cancelled') NOT NULL DEFAULT 'Draft',
    submitted_at DATETIME NULL,
    processed_at DATETIME NULL,
    processed_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fund_request_number (request_number),
    KEY idx_fund_request_status (status),
    CONSTRAINT fk_fund_request_trip FOREIGN KEY (trip_id) REFERENCES trips(trip_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_fund_request_user FOREIGN KEY (requested_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_fund_request_processor FOREIGN KEY (processed_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_fund_request_amount CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE cash_advance_liquidations (
    liquidation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fund_request_id BIGINT UNSIGNED NOT NULL,
    submitted_by INT UNSIGNED NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_expenses DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount_returned DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount_due DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('Draft','Submitted','Reviewed','Settled','Rejected') NOT NULL DEFAULT 'Draft',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    notes TEXT NULL,
    UNIQUE KEY uq_liquidation_fund_request (fund_request_id),
    CONSTRAINT fk_liquidation_fund FOREIGN KEY (fund_request_id) REFERENCES fund_requests(fund_request_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_liquidation_submitter FOREIGN KEY (submitted_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_liquidation_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_liquidation_values CHECK (total_expenses >= 0 AND amount_returned >= 0 AND amount_due >= 0)
) ENGINE=InnoDB;

CREATE TABLE expense_records (
    expense_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_type ENUM('Fuel','Payroll','Maintenance','Parts','Operating','Other') NOT NULL,
    reference_type VARCHAR(60) NULL,
    reference_id BIGINT UNSIGNED NULL,
    description TEXT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    expense_date DATE NOT NULL,
    recorded_by INT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_expenses_date_type (expense_date, expense_type),
    CONSTRAINT fk_expense_user FOREIGN KEY (recorded_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_expense_amount CHECK (amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE accounts_payable (
    ap_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NULL,
    reference_number VARCHAR(100) NULL,
    description TEXT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    due_date DATE NULL,
    status ENUM('Open','Partially Paid','Paid','Overdue','Cancelled') NOT NULL DEFAULT 'Open',
    related_type VARCHAR(60) NULL,
    related_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ap_status_due (status, due_date),
    CONSTRAINT fk_ap_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_ap_amount CHECK (amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE payment_vouchers (
    voucher_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    voucher_number VARCHAR(60) NOT NULL,
    payee VARCHAR(180) NOT NULL,
    purpose TEXT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    reference_type VARCHAR(60) NULL,
    reference_id BIGINT UNSIGNED NULL,
    status ENUM('Draft','Pending Approval','Approved','Rejected','Paid','Cancelled') NOT NULL DEFAULT 'Draft',
    prepared_by INT UNSIGNED NOT NULL,
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_voucher_number (voucher_number),
    KEY idx_voucher_status (status),
    CONSTRAINT fk_voucher_preparer FOREIGN KEY (prepared_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_voucher_approver FOREIGN KEY (approved_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_voucher_amount CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE disbursements (
    disbursement_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_type VARCHAR(60) NOT NULL,
    reference_id BIGINT UNSIGNED NOT NULL,
    payment_method ENUM('Cash','Check','Bank Transfer','Other') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_date DATE NOT NULL,
    check_number VARCHAR(100) NULL,
    transaction_reference VARCHAR(150) NULL,
    processed_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_disbursement_date (payment_date),
    CONSTRAINT fk_disbursement_user FOREIGN KEY (processed_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_disbursement_amount CHECK (amount > 0)
) ENGINE=InnoDB;

-- ============================================================
-- 10. BILLING / COLLECTION
-- ============================================================

CREATE TABLE billings (
    billing_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(80) NOT NULL,
    trip_id BIGINT UNSIGNED NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    billing_date DATE NOT NULL,
    due_date DATE NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('Draft','Issued','Partially Paid','Paid','Overdue','Cancelled') NOT NULL DEFAULT 'Draft',
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoice_number (invoice_number),
    UNIQUE KEY uq_billing_trip (trip_id),
    KEY idx_billing_client_date (client_id, billing_date),
    KEY idx_billing_status (status),
    CONSTRAINT fk_billing_trip FOREIGN KEY (trip_id) REFERENCES trips(trip_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_billing_client FOREIGN KEY (client_id) REFERENCES clients(client_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_billing_creator FOREIGN KEY (created_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_billing_amounts CHECK (subtotal >= 0 AND tax_amount >= 0 AND total_amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE billing_items (
    billing_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    billing_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
    unit_rate DECIMAL(14,2) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    CONSTRAINT fk_billing_items_billing FOREIGN KEY (billing_id) REFERENCES billings(billing_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_billing_item_values CHECK (quantity > 0 AND unit_rate >= 0 AND amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE collections (
    collection_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    billing_id BIGINT UNSIGNED NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('Cash','Check','Bank Transfer','Other') NOT NULL,
    reference_number VARCHAR(150) NULL,
    received_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_collections_date (payment_date),
    KEY idx_collections_client (client_id),
    CONSTRAINT fk_collections_billing FOREIGN KEY (billing_id) REFERENCES billings(billing_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_collections_client FOREIGN KEY (client_id) REFERENCES clients(client_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_collections_user FOREIGN KEY (received_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_collection_amount CHECK (amount > 0)
) ENGINE=InnoDB;

-- ============================================================
-- 11. PAYROLL / TIMEKEEPING
-- ============================================================

CREATE TABLE payroll_periods (
    payroll_period_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    status ENUM('Draft','Prepared','Pending Approval','Approved','Disbursed','Closed') NOT NULL DEFAULT 'Draft',
    prepared_by INT UNSIGNED NULL,
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payroll_period (period_start, period_end),
    CONSTRAINT fk_payroll_period_preparer FOREIGN KEY (prepared_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_payroll_period_approver FOREIGN KEY (approved_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_payroll_period_dates CHECK (period_end >= period_start)
) ENGINE=InnoDB;

CREATE TABLE payroll_records (
    payroll_record_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_period_id BIGINT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    base_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    trip_pay DECIMAL(14,2) NOT NULL DEFAULT 0,
    allowance_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    deduction_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('Draft','Included','Approved','Disbursed') NOT NULL DEFAULT 'Draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payroll_employee_period (payroll_period_id, employee_id),
    CONSTRAINT fk_payroll_record_period FOREIGN KEY (payroll_period_id) REFERENCES payroll_periods(payroll_period_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_payroll_record_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_payroll_amounts CHECK (
        base_amount >= 0 AND trip_pay >= 0 AND allowance_amount >= 0 AND deduction_amount >= 0 AND net_amount >= 0
    )
) ENGINE=InnoDB;

CREATE TABLE trip_pay_records (
    trip_pay_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id BIGINT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    waybill_reference VARCHAR(100) NULL,
    pay_type ENUM('Driver Trip Pay','Helper Trip Pay') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    status ENUM('Draft','Ready','Included in Payroll','Paid','Cancelled') NOT NULL DEFAULT 'Draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_trip_pay_trip (trip_id),
    KEY idx_trip_pay_employee (employee_id),
    CONSTRAINT fk_trip_pay_trip FOREIGN KEY (trip_id) REFERENCES trips(trip_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_trip_pay_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_trip_pay_amount CHECK (amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE payroll_deductions (
    deduction_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_record_id BIGINT UNSIGNED NOT NULL,
    deduction_type VARCHAR(100) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    description TEXT NULL,
    file_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_deduction_payroll FOREIGN KEY (payroll_record_id) REFERENCES payroll_records(payroll_record_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_deduction_amount CHECK (amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE payslips (
    payslip_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_record_id BIGINT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payslip_payroll_record (payroll_record_id),
    CONSTRAINT fk_payslip_payroll FOREIGN KEY (payroll_record_id) REFERENCES payroll_records(payroll_record_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payroll_disbursements (
    payroll_disbursement_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_period_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('Cash','Check','Bank Transfer','Other') NOT NULL,
    reference_number VARCHAR(150) NULL,
    processed_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payroll_disbursement_date (payment_date),
    CONSTRAINT fk_payroll_disbursement_period FOREIGN KEY (payroll_period_id) REFERENCES payroll_periods(payroll_period_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_payroll_disbursement_user FOREIGN KEY (processed_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_payroll_disbursement_amount CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE attendance_records (
    attendance_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    time_in DATETIME NULL,
    time_out DATETIME NULL,
    status ENUM('Present','Absent','Late','Half Day','Leave') NOT NULL,
    remarks TEXT NULL,
    recorded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_employee_date (employee_id, attendance_date),
    KEY idx_attendance_date (attendance_date),
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_user FOREIGN KEY (recorded_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_attendance_times CHECK (time_out IS NULL OR time_in IS NULL OR time_out >= time_in)
) ENGINE=InnoDB;

-- ============================================================
-- 12. DOCUMENTS
-- ============================================================

CREATE TABLE files (
    file_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    checksum CHAR(64) NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_files_checksum (checksum),
    CONSTRAINT fk_files_uploader FOREIGN KEY (uploaded_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE documents (
    document_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_id BIGINT UNSIGNED NOT NULL,
    document_category VARCHAR(100) NOT NULL,
    description TEXT NULL,
    issue_date DATE NULL,
    expiry_date DATE NULL,
    visibility_scope VARCHAR(100) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_documents_expiry (expiry_date),
    KEY idx_documents_category (document_category),
    CONSTRAINT fk_documents_file FOREIGN KEY (file_id) REFERENCES files(file_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_documents_creator FOREIGN KEY (created_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE document_links (
    document_link_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_document_link (document_id, entity_type, entity_id),
    KEY idx_document_entity (entity_type, entity_id),
    CONSTRAINT fk_document_links_document FOREIGN KEY (document_id) REFERENCES documents(document_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 13. APPROVAL ENGINE
-- ============================================================

CREATE TABLE approval_workflows (
    workflow_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_key VARCHAR(80) NOT NULL,
    workflow_name VARCHAR(150) NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workflow_key (workflow_key)
) ENGINE=InnoDB;

CREATE TABLE approval_requests (
    approval_request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    status ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    KEY idx_approval_entity (entity_type, entity_id),
    KEY idx_approval_status (status),
    CONSTRAINT fk_approval_request_workflow FOREIGN KEY (workflow_id) REFERENCES approval_workflows(workflow_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_approval_request_user FOREIGN KEY (requested_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE approval_steps (
    approval_step_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    approval_request_id BIGINT UNSIGNED NOT NULL,
    step_number INT UNSIGNED NOT NULL,
    approver_role_id INT UNSIGNED NOT NULL,
    status ENUM('Pending','Approved','Rejected','Skipped') NOT NULL DEFAULT 'Pending',
    acted_by INT UNSIGNED NULL,
    acted_at DATETIME NULL,
    remarks TEXT NULL,
    UNIQUE KEY uq_approval_step (approval_request_id, step_number),
    KEY idx_approval_step_role (approver_role_id),
    CONSTRAINT fk_approval_step_request FOREIGN KEY (approval_request_id) REFERENCES approval_requests(approval_request_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_approval_step_role FOREIGN KEY (approver_role_id) REFERENCES roles(role_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_approval_step_actor FOREIGN KEY (acted_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE approval_history (
    approval_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    approval_request_id BIGINT UNSIGNED NOT NULL,
    approval_step_id BIGINT UNSIGNED NULL,
    action ENUM('Submitted','Approved','Rejected','Cancelled','Overridden') NOT NULL,
    acted_by INT UNSIGNED NOT NULL,
    acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    remarks TEXT NULL,
    KEY idx_approval_history_request (approval_request_id, acted_at),
    CONSTRAINT fk_approval_history_request FOREIGN KEY (approval_request_id) REFERENCES approval_requests(approval_request_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_approval_history_step FOREIGN KEY (approval_step_id) REFERENCES approval_steps(approval_step_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_approval_history_user FOREIGN KEY (acted_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- 14. AUDIT / NOTIFICATIONS / ADMIN RECORDS
-- ============================================================

CREATE TABLE audit_logs (
    audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(60) NULL,
    entity_id BIGINT UNSIGNED NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_user_date (user_id, created_at),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_action_date (action, created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE notifications (
    notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    notification_type VARCHAR(80) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    entity_type VARCHAR(60) NULL,
    entity_id BIGINT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    KEY idx_notifications_user_read (user_id, is_read, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE recruitment_records (
    recruitment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    applicant_name VARCHAR(180) NOT NULL,
    position VARCHAR(100) NOT NULL,
    contact_number VARCHAR(50) NULL,
    application_date DATE NULL,
    status ENUM('Applicant','Screening','Interview','Accepted','Rejected','Withdrawn') NOT NULL DEFAULT 'Applicant',
    notes TEXT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_recruitment_status (status),
    CONSTRAINT fk_recruitment_creator FOREIGN KEY (created_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE office_supplies (
    supply_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
    quantity_on_hand DECIMAL(12,2) NOT NULL DEFAULT 0,
    reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_office_supply_name (item_name),
    CONSTRAINT chk_office_supply_qty CHECK (quantity_on_hand >= 0 AND reorder_level >= 0)
) ENGINE=InnoDB;

CREATE TABLE office_supply_transactions (
    transaction_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supply_id INT UNSIGNED NOT NULL,
    transaction_type ENUM('Stock In','Stock Out','Adjustment','Return') NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    performed_by INT UNSIGNED NOT NULL,
    transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    remarks TEXT NULL,
    KEY idx_supply_tx_date (supply_id, transaction_date),
    CONSTRAINT fk_supply_tx_supply FOREIGN KEY (supply_id) REFERENCES office_supplies(supply_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_supply_tx_user FOREIGN KEY (performed_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_supply_tx_qty CHECK (quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE announcements (
    announcement_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    content TEXT NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    start_date DATETIME NULL,
    end_date DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_announcements_dates (start_date, end_date),
    CONSTRAINT fk_announcements_creator FOREIGN KEY (created_by) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- 15. FILE FK REFERENCES THAT REQUIRE FILES TO EXIST
-- ============================================================

ALTER TABLE truck_documents
    ADD CONSTRAINT fk_truck_documents_file FOREIGN KEY (file_id) REFERENCES files(file_id)
        ON UPDATE CASCADE ON DELETE SET NULL;

CREATE TABLE inspection_photos (
    photo_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id BIGINT UNSIGNED NOT NULL,
    finding_id BIGINT UNSIGNED NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    photo_type ENUM('Departure','Arrival','Damage','Other') NOT NULL,
    caption VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_inspection_photos_inspection (inspection_id),
    CONSTRAINT fk_inspection_photos_inspection FOREIGN KEY (inspection_id) REFERENCES vehicle_inspections(inspection_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_inspection_photos_finding FOREIGN KEY (finding_id) REFERENCES inspection_findings(finding_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_inspection_photos_file FOREIGN KEY (file_id) REFERENCES files(file_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

ALTER TABLE expense_records
    ADD CONSTRAINT fk_expense_file FOREIGN KEY (file_id) REFERENCES files(file_id)
        ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE payroll_deductions
    ADD CONSTRAINT fk_deduction_file FOREIGN KEY (file_id) REFERENCES files(file_id)
        ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE payslips
    ADD CONSTRAINT fk_payslip_file FOREIGN KEY (file_id) REFERENCES files(file_id)
        ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE purchase_receipts
    ADD CONSTRAINT fk_receipt_file FOREIGN KEY (file_id) REFERENCES files(file_id)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- ============================================================
-- 16. TRUCK CURRENT ASSIGNMENT FK
-- ============================================================

ALTER TABLE trucks
    ADD CONSTRAINT fk_truck_current_assignment FOREIGN KEY (current_assignment_id)
        REFERENCES truck_assignments(truck_assignment_id)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- ============================================================
-- 23. VALIDATION VIEWS
-- ============================================================

CREATE VIEW v_completed_unbilled_trips AS
SELECT
    t.trip_id,
    t.trip_number,
    t.scheduled_departure,
    t.client_id,
    c.client_name,
    t.truck_id,
    t.driver_id,
    t.dispatcher_id
FROM trips t
JOIN clients c ON c.client_id = t.client_id
LEFT JOIN billings b ON b.trip_id = t.trip_id
WHERE t.status = 'Completed'
  AND b.billing_id IS NULL;

CREATE VIEW v_low_stock_parts AS
SELECT
    p.part_id,
    p.part_number,
    p.part_name,
    i.quantity_on_hand,
    p.reorder_level
FROM parts p
JOIN inventory i ON i.part_id = p.part_id
WHERE i.quantity_on_hand <= p.reorder_level
  AND p.is_active = 1;

CREATE VIEW v_expiring_documents AS
SELECT
    d.document_id,
    d.document_category,
    d.description,
    d.expiry_date,
    DATEDIFF(d.expiry_date, CURRENT_DATE()) AS days_until_expiry
FROM documents d
WHERE d.expiry_date IS NOT NULL
  AND d.expiry_date >= CURRENT_DATE()
ORDER BY d.expiry_date;

-- ============================================================

SET FOREIGN_KEY_CHECKS = 1;
