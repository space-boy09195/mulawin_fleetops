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
        $returnTo = validatedLocalReturnPath($_SERVER['REQUEST_URI'] ?? null);
        session_unset();
        session_destroy();
        $loginUrl = APP_BASE . '/login.php?reason=timeout';
        if ($returnTo !== null) {
            $loginUrl .= '&return_to=' . rawurlencode($returnTo);
        }
        header('Location: ' . $loginUrl);
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
        $loginUrl = APP_BASE . '/login.php';
        $returnTo = validatedLocalReturnPath($_SERVER['REQUEST_URI'] ?? null);
        if ($returnTo !== null) {
            $loginUrl .= '?return_to=' . rawurlencode($returnTo);
        }
        header('Location: ' . $loginUrl);
        exit;
    }

    static $validatedUserId = null;
    $userId = currentUserId();
    if ($validatedUserId === $userId) {
        return;
    }

    require_once __DIR__ . '/../config/database.php';
    try {
        $stmt = getDBConnection()->prepare(
            'SELECT role_id, is_active, auth_version FROM users WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Session account validation failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Account status is temporarily unavailable. Please try again later.');
    }

    if (!$user
        || !(int)$user['is_active']
        || (int)$user['role_id'] !== currentRoleId()
        || !isset($_SESSION['auth_version'])
        || (int)$user['auth_version'] !== (int)$_SESSION['auth_version']) {
        session_unset();
        session_destroy();
        header('Location: ' . APP_BASE . '/login.php?reason=revoked');
        exit;
    }

    $validatedUserId = $userId;
}

// ============================================================
// Helper: enforce a specific role (or array of roles)
// Usage: requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER])
// ============================================================
function requireRole(array $allowedRoles): void {
    requireLogin();
    $permissionKeys = array_map(
        static fn(int $roleId): string => 'legacy.role.' . $roleId,
        array_values(array_filter($allowedRoles, 'is_int'))
    );
    if (!$permissionKeys || !currentUserHasAnyPermission($permissionKeys)) {
        http_response_code(403);
        include __DIR__ . '/../pages/403.php';
        exit;
    }
}

function requirePermission(string $permissionKey): void {
    requireLogin();
    if (!currentUserHasAnyPermission([$permissionKey])) {
        http_response_code(403);
        include __DIR__ . '/../pages/403.php';
        exit;
    }
}

function requireAnyPermission(array $permissionKeys): void {
    requireLogin();
    if (!$permissionKeys || !currentUserHasAnyPermission($permissionKeys)) {
        http_response_code(403);
        include __DIR__ . '/../pages/403.php';
        exit;
    }
}

function currentUserHasAnyPermission(array $permissionKeys): bool {
    static $requestCache = [];
    $roleId = currentRoleId();
    $cacheKey = $roleId . ':' . implode(',', $permissionKeys);
    if (array_key_exists($cacheKey, $requestCache)) {
        return $requestCache[$cacheKey];
    }

    require_once __DIR__ . '/../config/database.php';
    $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
    try {
        $stmt = getDBConnection()->prepare(
            "SELECT 1
             FROM role_permissions rp
             JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE rp.role_id = ? AND p.permission_key IN ($placeholders)
             LIMIT 1"
        );
        $stmt->execute(array_merge([$roleId], $permissionKeys));
        return $requestCache[$cacheKey] = (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Permission check failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Access permissions are temporarily unavailable. Please try again later.');
    }
}

function validatedLocalReturnPath(?string $candidate): ?string {
    if ($candidate === null || $candidate === '' || preg_match('/[\r\n\\\\]/', $candidate)) {
        return null;
    }

    $parts = parse_url($candidate);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) {
        return null;
    }

    $path = $parts['path'] ?? '';
    if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return null;
    }

    $basePath = rtrim(APP_BASE, '/');
    if ($basePath !== '' && $basePath !== '/' && $path !== $basePath && !str_starts_with($path, $basePath . '/')) {
        return null;
    }

    $returnPath = $path;
    if (isset($parts['query'])) {
        $returnPath .= '?' . $parts['query'];
    }
    return $returnPath;
}

// ============================================================
// Helper: get current user's role ID (safe, returns 0 if not set)
// ============================================================
function currentRoleId(): int {
    return (int)($_SESSION['role_id'] ?? 0);
}

function dashboardUrlForRole(string $roleName, int $roleId): string {
    $target = match ($roleName) {
        'Admin', 'Head Management', 'Management / Head' => '/pages/dashboard_head.php',
        'Operations Head' => '/pages/dashboard_operations_head.php',
        'Dispatcher' => '/pages/dashboard_dispatcher.php',
        'Car Carrier Dispatcher - Day',
        'Car Carrier Dispatcher - Night',
        'Container & Wing Van Dispatcher - Day',
        'Container & Wing Van Dispatcher - Night' => '/pages/dispatch_inbox.php',
        'Maintenance' => '/pages/dashboard_maintenance.php',
        'Purchasing Officer' => '/pages/parts.php',
        'Accounting' => '/pages/dashboard_accounting.php',
        'Finance' => '/pages/trip_costs.php',
        'Billing and Collection' => '/pages/billing.php',
        'Payroll' => '/pages/payroll.php',
        'Admin Officer' => '/pages/documents.php',
        default => match ($roleId) {
            ROLE_ADMIN => '/pages/dashboard_head.php',
            ROLE_DISPATCHER => '/pages/dashboard_dispatcher.php',
            ROLE_MAINTENANCE => '/pages/dashboard_maintenance.php',
            ROLE_ACCOUNTING => '/pages/dashboard_accounting.php',
            default => '/pages/403.php',
        },
    };
    return APP_BASE . $target;
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