<?php
// ============================================================
// login.php  —  Login Page
// ============================================================
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/csrf.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mulawin FleetOps — Login</title>

  <!-- RPM Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap">
  <!-- Bootstrap 5 CDN -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <!-- Centralized RPM design tokens -->
  <link rel="stylesheet" href="assets/css/theme-tokens.css">
  <!-- Custom Login CSS (separate file as required) -->
  <link rel="stylesheet" href="assets/css/login.css">
  <script>
    (function(){
      var t = localStorage.getItem('mulawin_theme');
      document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : 'light');
    })();
  </script>
</head>
<body>

<button type="button" id="loginThemeToggle" class="login-theme-toggle" title="Toggle RPM Identity / Grid Mode">
  <i class="bi bi-moon-fill" id="loginThemeIcon"></i>
</button>

<div class="login-wrapper">
  <div class="login-card">

    <!-- Logo / Brand -->
    <div class="login-brand">
      <div class="brand-icon">
        <i class="bi bi-truck-front-fill"></i>
      </div>
      <h1 class="brand-name">Mulawin FleetOps</h1>
      <p class="brand-sub">RP Mulawin Trucking Services</p>
    </div>

    <!-- Status Messages -->
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

    <!-- Login Form -->
    <!-- Action points to auth/login_handler.php — NOT this file -->
    <form method="POST" action="auth/login_handler.php" id="loginForm" novalidate>

      <!-- CSRF hidden token — always include in every form -->
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
          <!-- Toggle password visibility — handled in login.js -->
          <button class="btn btn-outline-secondary" type="button" id="togglePassword" tabindex="-1">
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

    <script>
      (function () {
        var btn = document.getElementById('loginThemeToggle');
        var icon = document.getElementById('loginThemeIcon');
        function reflect(theme) {
          icon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
        }
        reflect(document.documentElement.getAttribute('data-theme') || 'light');
        btn.addEventListener('click', function () {
          var current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
          var next = current === 'dark' ? 'light' : 'dark';
          document.documentElement.setAttribute('data-theme', next);
          localStorage.setItem('mulawin_theme', next);
          reflect(next);
        });
      })();
    </script>

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
