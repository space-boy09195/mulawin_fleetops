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

// Sliding-window throttle, no permanent lockout: once a limit is reached,
// further attempts are refused without being recorded, and the block lifts by
// itself as failures age out of the window (at most 15 minutes).
const LOGIN_FAILURE_WINDOW_SECONDS = 900;
const LOGIN_FAILURE_LIMIT = 10;      // failures per account (or unknown username) per window
const LOGIN_IP_FAILURE_LIMIT = 50;   // failures per client IP per window, across all usernames
const DUMMY_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
// Internal identifiers start with \x01; usernames have control characters
// removed before use, so a typed username can never collide with them.
const IP_ATTEMPT_IDENTIFIER_PREFIX = "\x01ip:";
const ACCOUNT_ATTEMPT_IDENTIFIER_PREFIX = "\x01user:";
const LOGIN_USERNAME_MAX_LENGTH = 80; // users.username is VARCHAR(80) in the target schema.

// Only REMOTE_ADDR is used. X-Forwarded-For and similar headers are set by the
// client and are never trusted here.
function loginClientIp(): ?string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
}

// Failures for an existing account are counted per account (user_id), so
// every spelling the case- and accent-insensitive username lookup accepts for
// that account shares one budget. Unknown usernames are counted per
// normalized name: valid UTF-8, no control characters, single spaces,
// lowercase.
function loginAttemptIdentifier(string $username, ?int $userId): string {
    if ($userId !== null) {
        return ACCOUNT_ATTEMPT_IDENTIFIER_PREFIX . $userId;
    }
    $name = mb_scrub($username, 'UTF-8');
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
    $name = preg_replace('/\s+/u', ' ', trim($name));
    return mb_strtolower($name, 'UTF-8');
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

// Failed logins never sign anyone in, so if their audit row cannot be
// written the request still fails closed, with a generic "unavailable"
// message instead of an unhandled 500. The typed username is recorded only
// when it names an existing account: an unknown "username" is often a
// password typed into the wrong field and must not reach the audit log.
function auditFailedLogin(?string $knownUsername): void {
    try {
        auditLog('LOGIN_FAILED', 'users', null, null, ['username' => $knownUsername]);
    } catch (Throwable $e) {
        error_log('Failed-login audit could not be written: ' . $e->getMessage());
        redirectLoginError('rate_limit_unavailable');
    }
}

// ---- LOGOUT ------------------------------------------------
if (($_POST['action'] ?? $_GET['action'] ?? '') === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . APP_BASE . '/login.php');
        exit;
    }
    enforceCsrf();
    if (isLoggedIn()) {
        try {
            auditLog('LOGOUT', 'users', currentUserId());
        } catch (Throwable $e) {
            // Ending the session matters more than the audit row: a failed
            // audit must not leave the user signed in after choosing to log out.
            error_log('Logout audit failed for user ' . currentUserId() . ': ' . $e->getMessage());
        }
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
$rawUsername = $_POST['username'] ?? '';
$rawPassword = $_POST['password'] ?? '';
$username = is_string($rawUsername) ? trim($rawUsername) : '';
$password = is_string($rawPassword) ? $rawPassword : '';
// A longer value cannot match any account, so it is capped before it is used
// as a rate-limit identifier and never looked up.
$usernameTooLong = mb_strlen($username, 'UTF-8') > LOGIN_USERNAME_MAX_LENGTH;
if ($usernameTooLong) {
    $username = mb_substr($username, 0, LOGIN_USERNAME_MAX_LENGTH, 'UTF-8');
}
$returnTo = validatedLocalReturnPath($_POST['return_to'] ?? $_SESSION['return_to'] ?? null);

// Basic presence check
if ($username === '' || $password === '') {
    header('Location: ' . APP_BASE . '/login.php?error=empty');
    exit;
}

// ---- Look up user (fetch hash + role in one query) ---------
// The indexed lookup runs first so failures can be counted per account; the
// expensive password_verify() runs only after the rate-limit check passes.
$pdo = getDBConnection();

// The lookup reads roles.system_key (P21-7M), the same validated role data
// requireLogin() uses. A missing column or table is a schema outage, not a
// failed login: it is logged and refused before any password check or
// session change, without recording a failed attempt. Other errors are
// re-thrown unchanged. Real prepared statements are used, so the error can
// already occur in prepare().
$user = false;
try {
    $stmt = $pdo->prepare(
        "SELECT
             u.user_id,
             COALESCE(
                 NULLIF(
                     TRIM(CONCAT_WS(
                         ' ',
                         NULLIF(TRIM(e.first_name), ''),
                         NULLIF(TRIM(e.middle_name), ''),
                         NULLIF(TRIM(e.last_name), ''),
                         NULLIF(TRIM(e.suffix), '')
                     )),
                     ''
                 ),
                 u.username
             ) AS full_name,
             u.password_hash,
             u.is_active,
             u.auth_version,
             u.role_id,
             r.role_name,
             r.is_active AS role_active,
             r.system_key AS role_key
          FROM users u
          JOIN roles r ON u.role_id = r.role_id
          LEFT JOIN employees e ON u.employee_id = e.employee_id
          WHERE u.username = :username
          LIMIT 1"
    );
    if (!$usernameTooLong) {
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();
    }
} catch (PDOException $e) {
    // 42S22 = unknown column, 42S02 = unknown table.
    if (!in_array((string)$e->getCode(), ['42S22', '42S02'], true)) {
        throw $e;
    }
    error_log('Login account lookup unavailable (SQLSTATE ' . $e->getCode() . '): ' . $e->getMessage());
    redirectLoginError('rate_limit_unavailable');
}

// ---- Rate limit (before any password work) -----------------
// Storage failure fails closed: no password is checked and nobody is signed
// in, with a generic "temporarily unavailable" message. Login already needs
// this same database, so this cannot lock users out while login could work.
// Per-name GET_LOCK()s serialize concurrent attempts for the same account and
// IP from the count below until the request ends, so parallel requests cannot
// all pass a count taken before any of them recorded a failure.
$identifier = loginAttemptIdentifier($username, $user ? (int)$user['user_id'] : null);
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

    $knownUsername = $user ? $username : null;
    if (loginIsRateLimited($failureCounts)) {
        auditFailedLogin($knownUsername);
        redirectLoginError('rate_limited');
    }

    // Log failed attempt (user_id null since we don't know who this is yet).
    auditFailedLogin($knownUsername);

    // The same messages whether or not the username exists.
    $warning = match (true) {
        $failureCounts['identifier'] >= LOGIN_FAILURE_LIMIT - 1 => 'warning_last_attempt',
        $failureCounts['identifier'] >= LOGIN_FAILURE_LIMIT - 3 => 'warning_attempts',
        default => 'invalid',
    };
    redirectLoginError($warning);
}

// ---- Check if account and its role are active --------------
// Reached only after a correct password. An inactive role gets the same
// "disabled" response as an inactive account, so the reason is not revealed.
// A user whose role row is missing never gets here: the JOIN above returns no
// row, which is handled as an unknown username.
if (!(bool)$user['is_active'] || !(bool)$user['role_active']) {
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
// The session is already populated so the audit row records the user. If the
// row cannot be written, undo the sign-in rather than allow an unaudited login.
try {
    auditLog('LOGIN', 'users', (int)$user['user_id']);
} catch (Throwable $e) {
    error_log('Login audit failed for user ' . (int)$user['user_id'] . ': ' . $e->getMessage());
    session_unset();
    session_destroy();
    redirectLoginError('rate_limit_unavailable');
}

// ---- Redirect to role-specific dashboard -------------------
$defaultDashboard = dashboardUrlForRoleKey($user['role_key'] ?? null);
$destination = $returnTo ?? $defaultDashboard;
unset($_SESSION['return_to']);

header('Location: ' . $destination);
exit;
