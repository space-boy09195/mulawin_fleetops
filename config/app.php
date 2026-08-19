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

// ---- Role IDs (must match roles table in DB) ---------------
define('ROLE_HEAD_MANAGEMENT', 1);
define('ROLE_DISPATCHER',      2);
define('ROLE_MAINTENANCE',     3);
define('ROLE_ACCOUNTING',      4);

// ---- Role labels (for display) ----------------------------
define('ROLE_LABELS', [
    ROLE_HEAD_MANAGEMENT => 'Head Management',
    ROLE_DISPATCHER      => 'Dispatcher',
    ROLE_MAINTENANCE     => 'Maintenance',
    ROLE_ACCOUNTING      => 'Accounting',
]);

// ---- Redirect targets per role after login ----------------
define('ROLE_DASHBOARDS', [
    ROLE_HEAD_MANAGEMENT => APP_BASE . '/pages/dashboard_head.php',
    ROLE_DISPATCHER      => APP_BASE . '/pages/dashboard_dispatcher.php',
    ROLE_MAINTENANCE     => APP_BASE . '/pages/dashboard_maintenance.php',
    ROLE_ACCOUNTING      => APP_BASE . '/pages/dashboard_accounting.php',
]);
