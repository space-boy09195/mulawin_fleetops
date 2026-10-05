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
define('ROLE_HEAD_MANAGEMENT', ROLE_ADMIN); // Legacy alias for existing Admin-only routes.
define('ROLE_DISPATCHER',      2);
define('ROLE_MAINTENANCE',     3);
define('ROLE_ACCOUNTING',      4);

// ---- Role labels (for display) ----------------------------
define('ROLE_LABELS', [
    ROLE_ADMIN           => 'Admin',
    ROLE_DISPATCHER      => 'Dispatcher',
    ROLE_MAINTENANCE     => 'Maintenance',
    ROLE_ACCOUNTING      => 'Accounting',
]);

// ---- Redirect targets per role after login ----------------
define('ROLE_DASHBOARDS', [
    ROLE_ADMIN           => APP_BASE . '/pages/dashboard_head.php',
    ROLE_DISPATCHER      => APP_BASE . '/pages/dashboard.php',
    ROLE_MAINTENANCE     => APP_BASE . '/pages/dashboard.php',
    ROLE_ACCOUNTING      => APP_BASE . '/pages/dashboard.php',
]);
