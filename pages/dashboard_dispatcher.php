<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole([ROLE_DISPATCHER]);

layoutHead('Dashboard');
?>
<div class="page-header">
  <h1 class="page-title">Dashboard</h1>
  <p class="page-subtitle">Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?></p>
</div>

<div class="card p-4 text-center text-muted">
  <i class="bi bi-bar-chart-line" style="font-size:2.5rem; opacity:.3;"></i>
  <p class="mt-2 mb-0">Full dashboard coming in Phase 6.</p>
</div>

<?php layoutFoot(); ?>
