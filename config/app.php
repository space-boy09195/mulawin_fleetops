<?php
// ============================================================
// config/app.php
// App-wide constants — session, security, RBAC role IDs
// ============================================================

require_once __DIR__ . '/../includes/env.php';
loadEnv();

// ---- Base path ---------------------------------------------
define('APP_BASE', env('APP_BASE', '/mulawin_fleetops'));

// ---- Session -----------------------------------------------
define('SESSION_NAME',    env('SESSION_NAME', 'mulawin_session'));
define('SESSION_TIMEOUT', (int) env('SESSION_TIMEOUT', 1800));

// ---- CSRF --------------------------------------------------
define('CSRF_TOKEN_NAME', env('CSRF_TOKEN_NAME', 'csrf_token'));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Asia/Manila'));
define('COMPANY_EMAIL_DOMAIN', strtolower(ltrim((string) env('COMPANY_EMAIL_DOMAIN', 'rpm.com'), '@')));
define('DOCUMENT_STORAGE_PATH', env('DOCUMENT_STORAGE_PATH', dirname(__DIR__) . '/uploads'));
// Minimum time required for vehicle inspection submissions (objective
// 1.10: fifteen minutes). Enforced on the server by
// includes/inspections.php; a template may only raise it.
define('MAINTENANCE_FORM_MIN_DURATION_SECONDS', 900);
// The two Pre-Departure Inspection sections each have their own minimum of
// five minutes (owner decision 2026-10-26): the 8-item pre-trip checklist
// and the trip departure inspection. Return and general inspections keep
// the minimum above.
define('PRE_TRIP_CHECKLIST_MIN_DURATION_SECONDS', 300);
define('DEPARTURE_INSPECTION_MIN_DURATION_SECONDS', 300);
date_default_timezone_set(APP_TIMEZONE);

// ---- Legacy role numbers -------------------------------------
// These are the legacy role numbers, NOT the current roles.role_id values,
// and they are not used for authorization: roles are identified by
// roles.system_key and access by permission grants (includes/session.php).
// requireRole() maps a number N to the permission key 'legacy.role.N', so
// renumbering would change the meaning of those grants. Kept for the role
// labels and auth/seed_users.php.
define('ROLE_ADMIN',           1);
define('ROLE_OPERATIONS_HEAD',      5);
define('ROLE_DISPATCHER',      2);
define('ROLE_CARRIER_DISPATCHER',   6);
define('ROLE_CONTAINER_WING_DISPATCHER', 7);
define('ROLE_PURCHASING_OFFICER',   8);
define('ROLE_MAINTENANCE',     3);
define('ROLE_FINANCE',         9);
define('ROLE_BILLING',        10);
define('ROLE_PAYROLL',       11);
define('ROLE_AUDIT',         12);
define('ROLE_DRIVER',        13); // position in employees, may have multiple active users
define('ROLE_HELPER',       14); // position in employees, may have multiple active users

define('ROLE_HEAD_MANAGEMENT', ROLE_ADMIN); // Legacy alias for existing Admin-only routes.
define('ROLE_ACCOUNTING', ROLE_FINANCE); // Legacy alias: Accounting is merged into Finance; keeps existing references from failing.

// ---- Role labels (for display) ----------------------------
define('ROLE_LABELS', [
    ROLE_ADMIN             => 'Admin',
    ROLE_OPERATIONS_HEAD   => 'Operations Head',
    ROLE_DISPATCHER        => 'Dispatcher',
    ROLE_CARRIER_DISPATCHER => 'Car Carrier Dispatcher',
    ROLE_CONTAINER_WING_DISPATCHER => 'Container & Wing Van Dispatcher',
    ROLE_PURCHASING_OFFICER => 'Purchasing Officer',
    ROLE_MAINTENANCE       => 'Maintenance',
    ROLE_FINANCE           => 'Finance',
    ROLE_BILLING           => 'Billing and Collection',
    ROLE_PAYROLL           => 'Payroll',
    ROLE_AUDIT             => 'Audit',
    ROLE_DRIVER            => 'Driver',
    ROLE_HELPER            => 'Helper',
]);
