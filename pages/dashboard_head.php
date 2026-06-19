<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole([ROLE_HEAD_MANAGEMENT]);

$pageTitle = 'Dashboard';
layoutHead($pageTitle);
?>

<div class="page-header">
  <h1 class="page-title">Dashboard</h1>
  <p class="page-subtitle">Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?> — here's what's happening today.</p>
</div>

<!-- Stat cards placeholder — Phase 6 fills these with real data -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon amber"><i class="bi bi-truck"></i></div>
      <div class="stat-info">
        <div class="stat-value">—</div>
        <div class="stat-label">Total Trucks</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-check-circle"></i></div>
      <div class="stat-info">
        <div class="stat-value">—</div>
        <div class="stat-label">Available</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-map"></i></div>
      <div class="stat-info">
        <div class="stat-value">—</div>
        <div class="stat-label">Active Trips</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon red"><i class="bi bi-tools"></i></div>
      <div class="stat-info">
        <div class="stat-value">—</div>
        <div class="stat-label">Under Maintenance</div>
      </div>
    </div>
  </div>
</div>

<div class="card p-4 text-center text-muted">
  <i class="bi bi-bar-chart-line" style="font-size:2.5rem; opacity:.3;"></i>
  <p class="mt-2 mb-0">Full analytics coming in Phase 6 (Executive Dashboard).</p>
</div>

<?php layoutFoot(); ?>