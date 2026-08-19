<?php
// ============================================================
// login.php  —  Login Page
// ============================================================
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/env.php';

// Already logged in? Send to dashboard
if (isLoggedIn()) {
    $dashboards = ROLE_DASHBOARDS;
    header('Location: ' . ($dashboards[currentRoleId()] ?? APP_BASE . '/pages/dashboard_head.php'));
    exit;
}

// ---- Map error/reason codes to human-readable messages -----
$messages = [
    'error' => [
        'empty'   => 'Please enter both username and password.',
        'invalid' => 'Incorrect username or password.',
        'disabled'=> 'Your account has been disabled. Contact the administrator.',
    ],
    'reason' => [
        'logout'  => 'You have been successfully logged out.',
        'timeout' => 'Your session expired. Please log in again.',
    ],
];

$errorMsg   = $messages['error'][$_GET['error'] ?? ''] ?? '';
$reasonMsg  = $messages['reason'][$_GET['reason'] ?? ''] ?? '';

// ---- Dynamic employee access data with safe DB fallback --------
$systemStatus = [
    'state' => 'ready',
    'label' => 'Secure Access',
    'title' => 'Employee sign-in portal',
    'message' => 'Use your work credentials to access the Mulawin FleetOps system with secure account protection.',
    'stats' => [
        ['label' => 'Active departments', 'value' => 3],
        ['label' => 'Employees logged in', 'value' => 24],
        ['label' => 'Access roles', 'value' => 4],
    ],
];

try {
    loadEnv();

    $dbHost = env('DB_HOST', 'localhost');
    $dbName = env('DB_NAME', 'mulawin_fleetops');
    $dbUser = env('DB_USER', 'root');
    $dbPass = env('DB_PASS', '');
    $dbCharset = env('DB_CHARSET', 'utf8mb4');

    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $activeRoles = (int)$pdo->query("SELECT COUNT(*) FROM roles WHERE role_name <> 'Head Management'")->fetchColumn();
    $activeUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1 AND role_id <> (SELECT role_id FROM roles WHERE role_name = 'Head Management')")->fetchColumn();
    $rolesCount = (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();

    $systemStatus['stats'] = [
        ['label' => 'Active departments', 'value' => $activeRoles],
        ['label' => 'Employees logged in', 'value' => max(1, $activeUsers)],
        ['label' => 'Access roles', 'value' => $rolesCount],
    ];

    if ($activeUsers === 0) {
        $systemStatus['state'] = 'warning';
        $systemStatus['label'] = 'Review Access';
        $systemStatus['title'] = 'Access review required';
        $systemStatus['message'] = 'No active department staff accounts are available right now. Please review access credentials before continuing.';
    }
} catch (Throwable $e) {
    error_log('Login employee status fallback used: ' . $e->getMessage());
}

$statusClass = 'status-pill--' . $systemStatus['state'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mulawin FleetOps — Login</title>

  <!-- Bootstrap 5 CDN -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <!-- Custom Login CSS (separate file as required) -->
  <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>

<div class="login-shell">
  <aside class="login-visual" aria-label="Fleet operations overview">
    <div class="visual-topbar">
      <span class="status-pill <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($systemStatus['label']) ?></span>
    </div>

    <div class="visual-brand">
      <div class="brand-icon">
        <i class="bi bi-truck-front-fill"></i>
      </div>
      <div>
        <p class="eyebrow">Employee access</p>
        <h2>Mulawin FleetOps</h2>
      </div>
    </div>

    <div class="visual-copy">
      <div class="visual-message-card">
        <span class="message-label">System status</span>
        <h3><?= htmlspecialchars($systemStatus['title']) ?></h3>
        <p><?= htmlspecialchars($systemStatus['message']) ?></p>
      </div>
    </div>

    <div class="visual-stats" aria-label="Fleet metrics">
      <?php foreach ($systemStatus['stats'] as $stat): ?>
        <div class="stat-card">
          <span class="stat-label"><?= htmlspecialchars($stat['label']) ?></span>
          <strong><?= htmlspecialchars($stat['value']) ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
  </aside>

  <div class="login-card">

    <div class="login-brand">
      <div class="brand-icon brand-icon--compact">
        <i class="bi bi-truck-front-fill"></i>
      </div>
      <h1 class="brand-name">Welcome back</h1>
      <p class="brand-sub">Sign in to your dashboard</p>
    </div>

    <?php if ($errorMsg): ?>
      <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?= htmlspecialchars($errorMsg) ?></span>
      </div>
    <?php endif; ?>

    <?php if ($reasonMsg): ?>
      <div class="alert alert-info d-flex align-items-center gap-2" role="alert">
        <i class="bi bi-info-circle-fill"></i>
        <span><?= htmlspecialchars($reasonMsg) ?></span>
      </div>
    <?php endif; ?>

    <form method="POST" action="auth/login_handler.php" id="loginForm" novalidate>
      <?= csrfInput() ?>

      <div class="mb-3">
        <label for="username" class="form-label">Username</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
          <input
            type="text"
            class="form-control"
            id="username"
            name="username"
            placeholder="Enter your username"
            autocomplete="username"
            required
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
          >
        </div>
      </div>

      <div class="mb-4">
        <label for="password" class="form-label">Password</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
          <input
            type="password"
            class="form-control"
            id="password"
            name="password"
            placeholder="Enter your password"
            autocomplete="current-password"
            required
          >
          <button class="btn btn-outline-secondary" type="button" id="togglePassword" tabindex="-1" aria-label="Show or hide password">
            <i class="bi bi-eye-slash" id="toggleIcon"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 login-btn" id="loginBtn">
        <span id="loginBtnText"><i class="bi bi-box-arrow-in-right me-1"></i> Sign In</span>
        <span id="loginBtnSpinner" class="d-none">
          <span class="spinner-border spinner-border-sm me-1" role="status"></span> Signing in...
        </span>
      </button>
    </form>

    <p class="login-footer-text">
      Mulawin FleetOps &copy; <?= date('Y') ?>
    </p>
  </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Custom Login JS (separate file as required) -->
<script src="assets/js/login.js"></script>
</body>
</html>
