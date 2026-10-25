<?php
// ============================================================
// includes/session.php
// Bootstrap: must be the FIRST include on every PHP page
// Starts session, enforces timeout, regenerates session ID
// ============================================================

require_once __DIR__ . '/../config/app.php';

function isJsonRequest(): bool {
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
}

function sendJsonAccessResponse(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

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

// ---- Idle Timeout (opt-in) ---------------------------------
// Auto logout is a per-browser preference toggled from the account menu.
// When off (default) an idle session simply stays signed in.
if (($_COOKIE['fleetops_auto_logout'] ?? '') === '1' && isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        if (isJsonRequest()) {
            sendJsonAccessResponse(401, 'Your session expired. Sign in again and retry.');
        }
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
        if (isJsonRequest()) {
            sendJsonAccessResponse(401, 'Authentication is required.');
        }
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
        // LEFT JOIN so a user whose role row is missing is still found and
        // then rejected below (role_active is NULL), instead of looking deleted.
        // roles.system_key (P21-7M) is the only source of Admin identity. If
        // the column is missing this query fails and the request is refused.
        $stmt = getDBConnection()->prepare(
            'SELECT u.role_id, u.is_active, u.auth_version, r.is_active AS role_active,
                    r.system_key AS role_key
             FROM users u
             LEFT JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Session account validation failed: ' . $e->getMessage());
        if (isJsonRequest()) {
            sendJsonAccessResponse(503, 'Account status is temporarily unavailable. Please try again later.');
        }
        http_response_code(503);
        exit('Account status is temporarily unavailable. Please try again later.');
    }

    if (!$user
        || !(int)$user['is_active']
        || !(int)($user['role_active'] ?? 0)
        || (int)$user['role_id'] !== currentRoleId()
        || !isset($_SESSION['auth_version'])
        || (int)$user['auth_version'] !== (int)$_SESSION['auth_version']) {
        session_unset();
        session_destroy();
        if (isJsonRequest()) {
            sendJsonAccessResponse(401, 'Your account session is no longer valid. Sign in again.');
        }
        header('Location: ' . APP_BASE . '/login.php?reason=revoked');
        exit;
    }

    // The role key is kept for this request only, never in $_SESSION, so a
    // changed key takes effect on the next request.
    validatedRoleKey($user['role_key'] ?? null, true);
    $validatedUserId = $userId;
}

// ============================================================
// Stable role identity (roles.system_key)
// ============================================================
// Request-scoped store for the role key validated by requireLogin().
function validatedRoleKey(?string $key = null, bool $set = false): ?string {
    static $roleKey = null;
    if ($set) {
        $roleKey = ($key === null || $key === '') ? null : $key;
    }
    return $roleKey;
}

// The signed-in user's role key, validated against the database in this
// request. Null when signed out or when the role has no system key.
function currentRoleKey(): ?string {
    if (!isLoggedIn()) {
        return null;
    }
    requireLogin();
    return validatedRoleKey();
}

// Admin identity comes only from the validated system key, never from a
// role ID or role name. No role with key 'admin' means nobody is Admin.
function isAdmin(): bool {
    return currentRoleKey() === 'admin';
}

// Database lookup of any role, used for decisions about a target role.
function roleRowForId(PDO $pdo, int $roleId, bool $forUpdate = false): ?array {
    $stmt = $pdo->prepare(
        'SELECT role_id, is_active, system_key FROM roles WHERE role_id = ?'
        . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$roleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
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
        if (isJsonRequest()) {
            sendJsonAccessResponse(403, 'You do not have permission to perform this action.');
        }
        http_response_code(403);
        include __DIR__ . '/../pages/403.php';
        exit;
    }
}

function requirePermission(string $permissionKey): void {
    requireLogin();
    if (!currentUserHasAnyPermission([$permissionKey])) {
        if (isJsonRequest()) {
            sendJsonAccessResponse(403, 'You do not have permission to perform this action.');
        }
        http_response_code(403);
        include __DIR__ . '/../pages/403.php';
        exit;
    }
}

function requireAnyPermission(array $permissionKeys): void {
    requireLogin();
    if (!$permissionKeys || !currentUserHasAnyPermission($permissionKeys)) {
        if (isJsonRequest()) {
            sendJsonAccessResponse(403, 'You do not have permission to perform this action.');
        }
        http_response_code(403);
        include __DIR__ . '/../pages/403.php';
        exit;
    }
}

function currentUserHasAnyPermission(array $permissionKeys): bool {
    // Admin holds every current and future permission. Every other role is
    // checked against its own grants below, unchanged.
    if (isAdmin()) {
        return true;
    }

    // One query per request loads the role's full permission set, instead of
    // one query per checked permission (the sidebar alone checks ~30).
    static $roleKeysCache = [];
    $roleId = currentRoleId();
    if (!isset($roleKeysCache[$roleId])) {
        require_once __DIR__ . '/../config/database.php';
        try {
            $stmt = getDBConnection()->prepare(
                'SELECT p.permission_key
                 FROM role_permissions rp
                 JOIN permissions p ON p.permission_id = rp.permission_id
                 WHERE rp.role_id = ?'
            );
            $stmt->execute([$roleId]);
            $roleKeysCache[$roleId] = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
        } catch (PDOException $e) {
            error_log('Permission check failed: ' . $e->getMessage());
            if (isJsonRequest()) {
                sendJsonAccessResponse(503, 'Access permissions are temporarily unavailable. Please try again later.');
            }
            http_response_code(503);
            exit('Access permissions are temporarily unavailable. Please try again later.');
        }
    }

    foreach ($permissionKeys as $permissionKey) {
        if (isset($roleKeysCache[$roleId][$permissionKey])) {
            return true;
        }
    }
    return false;
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

// Every role shares the permission-driven home dashboard. Department access
// is reflected by its existing permissions and workflow links.
const ROLE_KEY_DASHBOARDS = [];

function dashboardUrlForRoleKey(?string $roleKey): string {
    return APP_BASE . (ROLE_KEY_DASHBOARDS[(string)$roleKey] ?? '/pages/dashboard.php');
}

// Kept for existing callers. The role name and ID are no longer used: the
// decision comes from the validated role key of the signed-in user.
function dashboardUrlForRole(string $roleName, int $roleId): string {
    return dashboardUrlForRoleKey(currentRoleKey());
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