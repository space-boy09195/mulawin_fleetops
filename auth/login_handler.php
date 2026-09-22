<?php
// ============================================================
// auth/login_handler.php
// Handles the login form POST and the logout GET/POST action
// This is NOT a page — it only processes requests then redirects
// ============================================================

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';

// ---- LOGOUT ------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
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

// Basic presence check
if ($username === '' || $password === '') {
    header('Location: ' . APP_BASE . '/login.php?error=empty');
    exit;
}

// ---- Look up user (fetch hash + role in one query) ---------
$pdo = getDBConnection();

$stmt = $pdo->prepare(
    "SELECT u.user_id, u.full_name, u.password_hash, u.is_active, u.role_id, r.role_name
       FROM users u
       JOIN roles r ON u.role_id = r.role_id
      WHERE u.username = :username
      LIMIT 1"
);
$stmt->execute([':username' => $username]);
$user = $stmt->fetch();

// ---- Verify password (constant-time) -----------------------
// password_verify handles timing attacks inherently
if (!$user || !password_verify($password, $user['password_hash'])) {
    // Log failed attempt (user_id null since we don't know who this is yet)
    auditLog('LOGIN_FAILED', 'users', null, null, ['username' => $username]);
    header('Location: ' . APP_BASE . '/login.php?error=invalid');
    exit;
}

// ---- Check if account is active ----------------------------
if (!(bool)$user['is_active']) {
    header('Location: ' . APP_BASE . '/login.php?error=disabled');
    exit;
}

// ---- Regenerate session on login (prevents fixation) -------
session_regenerate_id(true);

// ---- Store user info in session ----------------------------
$_SESSION['user_id']   = $user['user_id'];
$_SESSION['username']  = $username;
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role_id']   = (int)$user['role_id'];
$_SESSION['role_name'] = $user['role_name'];

// ---- Log successful login ----------------------------------
auditLog('LOGIN', 'users', (int)$user['user_id']);

// ---- Redirect to role-specific dashboard -------------------
$dashboards = ROLE_DASHBOARDS;
$destination = $dashboards[$_SESSION['role_id']] ?? '/pages/dashboard_head.php';

header('Location: ' . $destination);
exit;
