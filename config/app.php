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
// Change this value to adjust the minimum time required for checklist and vehicle inspection submissions.
define('MAINTENANCE_FORM_MIN_DURATION_SECONDS', 300);
date_default_timezone_set(APP_TIMEZONE);

// ---- Role IDs (must match roles table in DB) ---------------
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

// ---- Redirect targets per role after login ----------------
define('ROLE_DASHBOARDS', [
    ROLE_ADMIN           => APP_BASE . '/pages/dashboard_head.php',
    ROLE_OPERATIONS_HEAD => APP_BASE . '/pages/dashboard_head.php',
    ROLE_DISPATCHER      => APP_BASE . '/pages/dashboard.php',
    ROLE_CARRIER_DISPATCHER => APP_BASE . '/pages/dashboard.php',
    ROLE_CONTAINER_WING_DISPATCHER => APP_BASE . '/pages/dashboard.php',
    ROLE_PURCHASING_OFFICER => APP_BASE . '/pages/purchasing.php',
    ROLE_MAINTENANCE     => APP_BASE . '/pages/dashboard.php',
    ROLE_FINANCE         => APP_BASE . '/pages/finance.php',
    ROLE_BILLING         => APP_BASE . '/pages/billing.php',
    ROLE_PAYROLL         => APP_BASE . '/pages/payroll.php',
    ROLE_AUDIT           => APP_BASE . '/pages/permissions.php',
    ROLE_DRIVER          => APP_BASE . '/pages/dashboard.php',
    ROLE_HELPER          => APP_BASE . '/pages/dashboard.php',
]);
