<?php
// ============================================================
// includes/session.php
// Bootstrap: must be the FIRST include on every PHP page
// Starts session, enforces timeout, regenerates session ID
// ============================================================

require_once __DIR__ . '/../config/app.php';

// Harden session cookie before session_start()
session_name(SESSION_NAME);

// ---- Auto-detect HTTPS -------------------------------------
// Works for direct SSL termination (e.g. Hostinger/cPanel) and
// for setups behind a proxy/load balancer that forwards the
// original protocol via X-Forwarded-Proto. No manual flag to
// remember to flip when moving from local to production.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

session_set_cookie_params([
    'lifetime' => 0,               // Cookie expires when browser closes
    'path'     => '/',
    'secure'   => $isHttps,        // Automatically true once served over HTTPS
    'httponly' => true,            // JS cannot read the cookie
    'samesite' => 'Strict',        // CSRF mitigation
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Idle Timeout ------------------------------------------
if (isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: ' . APP_BASE . '/login.php?reason=timeout');
        exit;
    }
}
$_SESSION['last_activity'] = time();

// ---- Session Fixation Guard --------------------------------
// Regenerate ID every 5 minutes to prevent fixation attacks
if (!isset($_SESSION['last_regenerated'])) {
    $_SESSION['last_regenerated'] = time();
} elseif ((time() - $_SESSION['last_regenerated']) > 300) {
    session_regenerate_id(true);   // TRUE = delete old session file
    $_SESSION['last_regenerated'] = time();
}

// ============================================================
// Helper: is the user logged in?
// ============================================================
function isLoggedIn(): bool {
    return isset($_SESSION['user_id'], $_SESSION['role_id']);
}

// ============================================================
// Helper: enforce authentication — redirect to login if not
// Call at the top of every protected page
// ============================================================
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_BASE . '/login.php');
        exit;
    }
}

// ============================================================
// Helper: enforce a specific role (or array of roles)
// Usage: requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER])
// ============================================================
function requireRole(array $allowedRoles): void {
    requireLogin();
    if (!in_array($_SESSION['role_id'], $allowedRoles, true)) {
        http_response_code(403);
        include __DIR__ . '/../pages/403.php';
        exit;
    }
}

// ============================================================
// Helper: get current user's role ID (safe, returns 0 if not set)
// ============================================================
function currentRoleId(): int {
    return (int)($_SESSION['role_id'] ?? 0);
}

// ============================================================
// Helper: get current user's ID
// ============================================================
function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function isValidDate(string $date): bool {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function isPassedDate(string $date): bool {
    return isValidDate($date) && $date < date('Y-m-d');
}