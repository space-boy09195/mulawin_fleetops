<?php
// ============================================================
// auth/login_handler.php
// Handles the login form POST and the CSRF-protected logout POST action
// This is NOT a page — it only processes requests then redirects
// ============================================================

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';

const LOGIN_FAILURE_WINDOW_SECONDS = 30;
const LOGIN_FAILURE_LIMIT = 10;
const LOGIN_IP_FAILURE_LIMIT = 50;
const DUMMY_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
const IP_ATTEMPT_IDENTIFIER_PREFIX = "\x01ip:";

function loginClientIp(): ?string {
    return isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : null;
}

function loginFailureCounts(PDO $pdo, string $identifier, ?string $ip): array {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL " . LOGIN_FAILURE_WINDOW_SECONDS . " SECOND)
           AND identifier = ?"
    );
    $stmt->execute([$identifier]);
    $identifierCount = (int)$stmt->fetchColumn();

    $ipCount = 0;
    if ($ip !== null) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL " . LOGIN_FAILURE_WINDOW_SECONDS . " SECOND)
               AND ip_address = ?"
        );
        $stmt->execute([$ip]);
        $ipCount = (int)$stmt->fetchColumn();
    }

    return ['identifier' => $identifierCount, 'ip' => $ipCount];
}

function acquireLoginRateLimitLocks(PDO $pdo, string $identifier, ?string $ip): array {
    $lockNames = ['login:' . substr(hash('sha256', 'identifier:' . $identifier), 0, 58)];
    if ($ip !== null) {
        $lockNames[] = 'login:' . substr(hash('sha256', 'ip:' . $ip), 0, 58);
    }
    sort($lockNames, SORT_STRING);

    $acquired = [];
    try {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        foreach ($lockNames as $lockName) {
            $stmt->execute([$lockName]);
            if ((int)$stmt->fetchColumn() !== 1) {
                throw new RuntimeException('Could not acquire login rate-limit lock.');
            }
            $acquired[] = $lockName;
        }
    } catch (Throwable $e) {
        releaseLoginRateLimitLocks($pdo, $acquired);
        throw $e;
    }

    return $acquired;
}

function releaseLoginRateLimitLocks(PDO $pdo, array $lockNames): void {
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    foreach ($lockNames as $lockName) {
        $stmt->execute([$lockName]);
        $stmt->fetchColumn();
    }
}

function loginIsRateLimited(array $counts): bool {
    return $counts['identifier'] >= LOGIN_FAILURE_LIMIT
        || $counts['ip'] >= LOGIN_IP_FAILURE_LIMIT;
}

function recordLoginFailure(PDO $pdo, string $identifier, ?string $ip): void {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO login_attempts (identifier, ip_address) VALUES (?, ?)');
        $stmt->execute([$identifier, null]);
        if ($ip !== null) {
            $ipIdentifier = IP_ATTEMPT_IDENTIFIER_PREFIX . hash('sha256', $ip);
            $stmt->execute([$ipIdentifier, $ip]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function clearLoginIdentifierFailures(PDO $pdo, string $identifier): void {
    $pdo->prepare(
        'DELETE FROM login_attempts WHERE identifier = ? AND ip_address IS NULL'
    )->execute([$identifier]);
    $pdo->prepare(
        "UPDATE login_attempts
         SET identifier = CONCAT(CHAR(1), 'ip:', SHA2(ip_address, 256))
         WHERE identifier = ? AND ip_address IS NOT NULL"
    )->execute([$identifier]);
}

function redirectLoginError(string $error): void {
    header('Location: ' . APP_BASE . '/login.php?error=' . rawurlencode($error));
    exit;
}

// ---- LOGOUT ------------------------------------------------
if (($_POST['action'] ?? $_GET['action'] ?? '') === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . APP_BASE . '/login.php');
        exit;
    }
    enforceCsrf();
    if (isLoggedIn()) {
        auditLog('LOGOUT', 'users', currentUserId());
    }
    session_unset();
    session_destroy();
    header('Location: ' . APP_BASE . '/login.php?reason=logout');
    exit;
}

// ---- Only accept POST for login ----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE . '/login.php');
    exit;
}

// ---- CSRF check --------------------------------------------
enforceCsrf();

// ---- Sanitize inputs ---------------------------------------
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$returnTo = validatedLocalReturnPath($_POST['return_to'] ?? $_SESSION['return_to'] ?? null);

// Basic presence check
if ($username === '' || $password === '') {
    header('Location: ' . APP_BASE . '/login.php?error=empty');
    exit;
}

// ---- Look up user (fetch hash + role in one query) ---------
$pdo = getDBConnection();
$identifier = strtolower($username);
$clientIp = loginClientIp();
try {
    $rateLimitLocks = acquireLoginRateLimitLocks($pdo, $identifier, $clientIp);
    register_shutdown_function(static function () use ($pdo, $rateLimitLocks): void {
        try {
            releaseLoginRateLimitLocks($pdo, $rateLimitLocks);
        } catch (Throwable $e) {
            error_log('Login rate-limit lock release failed: ' . $e->getMessage());
        }
    });
    $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $failureCounts = loginFailureCounts($pdo, $identifier, $clientIp);
} catch (Throwable $e) {
    error_log('Login rate limit check failed: ' . $e->getMessage());
    redirectLoginError('rate_limit_unavailable');
}

if (loginIsRateLimited($failureCounts)) {
    redirectLoginError('rate_limited');
}

$stmt = $pdo->prepare(
    "SELECT u.user_id, u.full_name, u.password_hash, u.is_active, u.auth_version, u.role_id, r.role_name
       FROM users u
       JOIN roles r ON u.role_id = r.role_id
      WHERE u.username = :username
      LIMIT 1"
);
$stmt->execute([':username' => $username]);
$user = $stmt->fetch();

// ---- Verify password (constant-time) -----------------------
// Use a fixed hash for unknown usernames to perform comparable password work.
$passwordHash = $user ? $user['password_hash'] : DUMMY_PASSWORD_HASH;
$passwordValid = password_verify($password, $passwordHash);
if (!$user || !$passwordValid) {
    try {
        recordLoginFailure($pdo, $identifier, $clientIp);
        $failureCounts = loginFailureCounts($pdo, $identifier, $clientIp);
    } catch (PDOException $e) {
        error_log('Login failure counter update failed: ' . $e->getMessage());
        redirectLoginError('rate_limit_unavailable');
    }

    if (loginIsRateLimited($failureCounts)) {
        auditLog('LOGIN_FAILED', 'users', null, null, ['username' => $username]);
        redirectLoginError('rate_limited');
    }

    // Log failed attempt (user_id null since we don't know who this is yet).
    auditLog('LOGIN_FAILED', 'users', null, null, ['username' => $username]);

    $warning = match (true) {
        $failureCounts['identifier'] >= 9 => 'warning_last_attempt',
        $failureCounts['identifier'] >= 7 => 'warning_attempts',
        default => 'invalid',
    };
    redirectLoginError($warning);
}

// ---- Check if account is active ----------------------------
if (!(bool)$user['is_active']) {
    header('Location: ' . APP_BASE . '/login.php?error=disabled');
    exit;
}
try {
    clearLoginIdentifierFailures($pdo, $identifier);
} catch (Throwable $e) {
    error_log('Login failure counter reset failed: ' . $e->getMessage());
    redirectLoginError('rate_limit_unavailable');
}

// ---- Regenerate session on login (prevents fixation) -------
session_regenerate_id(true);

// ---- Store user info in session ----------------------------
$_SESSION['user_id']   = $user['user_id'];
$_SESSION['username']  = $username;
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role_id']   = (int)$user['role_id'];
$_SESSION['role_name'] = $user['role_name'];
$_SESSION['auth_version'] = (int)$user['auth_version'];

// ---- Log successful login ----------------------------------
auditLog('LOGIN', 'users', (int)$user['user_id']);

// ---- Redirect to role-specific dashboard -------------------
$defaultDashboard = dashboardUrlForRole((string)$user['role_name'], (int)$user['role_id']);
$destination = $returnTo ?? $defaultDashboard;
unset($_SESSION['return_to']);

header('Location: ' . $destination);
exit;
