<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/dashboard.js';
layoutHead('Dashboard', APP_BASE . '/assets/css/dashboard.css');

$pdo = getDBConnection();

// ── Fleet ─────────────────────────────────────────────────────────────────────
$fleetRows = $pdo->query("SELECT status, truck_count FROM v_fleet_status")->fetchAll(PDO::FETCH_ASSOC);
$fleet = [];
foreach ($fleetRows as $r) $fleet[$r['status']] = $r['truck_count'];
$totalTrucks = array_sum($fleet);
$available   = $fleet['Available']         ?? 0;
$deployed    = $fleet['Deployed']          ?? 0;
$underMaint  = $fleet['Under Maintenance'] ?? 0;

// ── Active trips ──────────────────────────────────────────────────────────────
$activeTrips = $pdo->query("
    SELECT trip_id, trip_number, trip_status, plate_number,
           driver_name, origin, destination, expected_arrival, is_late
    FROM v_active_trips ORDER BY is_late DESC, expected_arrival ASC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);
$lateCount = count(array_filter($activeTrips, fn($t) => $t['is_late']));

// ── Pending dispatches ────────────────────────────────────────────────────────
$pendingDispatches = (int)$pdo->query("SELECT COUNT(*) FROM dispatch_requests WHERE status='Pending'")->fetchColumn();

// ── Open incidents ────────────────────────────────────────────────────────────
$openIncidents = $pdo->query("
    SELECT i.incident_type, i.reported_at, t.trip_number, tr.plate_number
    FROM incidents i
    JOIN trips t              ON i.trip_id     = t.trip_id
    JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
    JOIN trucks tr            ON dr.truck_id   = tr.truck_id
    WHERE i.resolved_at IS NULL ORDER BY i.reported_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── Billing ───────────────────────────────────────────────────────────────────
$bilRow = $pdo->query("
    SELECT SUM(status='Unpaid') AS unpaid, SUM(status='Partial') AS partial,
           SUM(balance) AS outstanding FROM v_billing_summary
")->fetch(PDO::FETCH_ASSOC);

// ── Low stock ─────────────────────────────────────────────────────────────────
$lowStock = $pdo->query("
    SELECT part_name, quantity, reorder_level, unit FROM v_low_stock_parts
    ORDER BY shortage DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── License alerts ────────────────────────────────────────────────────────────
$licAlerts = $pdo->query("
    SELECT full_name, license_expiry, days_until_expiry
    FROM v_license_expiry_alerts LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="dash-page">
  <div class="dash-header mb-4">
    <h1 class="dash-title">Dashboard</h1>
    <p class="dash-subtitle">Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?> — here's what's happening today.</p>
  </div>

  <!-- Stat cards -->
  <div class="dash-stats mb-4">
    <div class="stat-card"><div class="stat-icon stat-amber"><i class="bi bi-truck"></i></div><div class="stat-info"><div class="stat-value"><?= $totalTrucks ?></div><div class="stat-label">Total Trucks</div></div></div>
    <div class="stat-card"><div class="stat-icon stat-green"><i class="bi bi-check-circle"></i></div><div class="stat-info"><div class="stat-value"><?= $available ?></div><div class="stat-label">Available</div></div></div>
    <div class="stat-card"><div class="stat-icon stat-blue"><i class="bi bi-map"></i></div><div class="stat-info"><div class="stat-value"><?= count($activeTrips) ?></div><div class="stat-label">Active Trips</div></div></div>
    <div class="stat-card <?= $lateCount > 0 ? 'stat-card-alert' : '' ?>"><div class="stat-icon stat-red"><i class="bi bi-clock-history"></i></div><div class="stat-info"><div class="stat-value"><?= $lateCount ?></div><div class="stat-label">Late Trips</div></div></div>
    <div class="stat-card <?= $pendingDispatches > 0 ? 'stat-card-warn' : '' ?>"><div class="stat-icon stat-purple"><i class="bi bi-send"></i></div><div class="stat-info"><div class="stat-value"><?= $pendingDispatches ?></div><div class="stat-label">Pending Dispatches</div></div></div>
    <div class="stat-card <?= count($openIncidents) > 0 ? 'stat-card-alert' : '' ?>"><div class="stat-icon stat-red"><i class="bi bi-exclamation-triangle"></i></div><div class="stat-info"><div class="stat-value"><?= count($openIncidents) ?></div><div class="stat-label">Open Incidents</div></div></div>
    <div class="stat-card <?= ($bilRow['outstanding'] ?? 0) > 0 ? 'stat-card-warn' : '' ?>"><div class="stat-icon stat-green"><i class="bi bi-receipt"></i></div><div class="stat-info"><div class="stat-value">₱<?= number_format($bilRow['outstanding'] ?? 0, 0) ?></div><div class="stat-label">Outstanding Balance</div></div></div>
    <div class="stat-card <?= $underMaint > 0 ? 'stat-card-warn' : '' ?>"><div class="stat-icon stat-orange"><i class="bi bi-tools"></i></div><div class="stat-info"><div class="stat-value"><?= $underMaint ?></div><div class="stat-label">Under Maintenance</div></div></div>
  </div>

  <div class="dash-grid">
    <!-- Active Trips table -->
    <div class="dash-widget dash-widget-wide">
      <div class="dash-widget-header">
        <span class="dash-widget-title"><i class="bi bi-map me-2"></i>Active Trips</span>
        <a href="<?= APP_BASE ?>/pages/trip_monitor.php" class="dash-widget-link">View all</a>
      </div>
      <?php if (empty($activeTrips)): ?>
      <div class="dash-empty"><i class="bi bi-map"></i><span>No active trips</span></div>
      <?php else: ?>
      <div class="dash-table-wrap">
        <table class="table dash-table">
          <thead><tr><th>Trip No.</th><th>Truck</th><th>Route</th><th>Driver</th><th>ETA</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($activeTrips as $t): ?>
            <tr>
              <td><span class="dash-ref"><?= htmlspecialchars($t['trip_number']) ?></span></td>
              <td><?= htmlspecialchars($t['plate_number']) ?></td>
              <td class="dash-route"><?= htmlspecialchars($t['origin']) ?> <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($t['destination']) ?></td>
              <td><?= htmlspecialchars($t['driver_name']) ?></td>
              <td class="<?= $t['is_late'] ? 'dash-late' : '' ?>"><?= $t['expected_arrival'] ? date('M d, H:i', strtotime($t['expected_arrival'])) : '—' ?></td>
              <td><?php if ($t['is_late']): ?><span class="dash-badge dash-badge-red">Late</span><?php else: ?><span class="dash-badge dash-badge-blue"><?= htmlspecialchars($t['trip_status']) ?></span><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Open Incidents -->
    <div class="dash-widget">
      <div class="dash-widget-header">
        <span class="dash-widget-title"><i class="bi bi-exclamation-triangle me-2"></i>Open Incidents</span>
        <a href="<?= APP_BASE ?>/pages/incidents.php" class="dash-widget-link">View all</a>
      </div>
      <?php if (empty($openIncidents)): ?>
      <div class="dash-empty"><i class="bi bi-shield-check"></i><span>No open incidents</span></div>
      <?php else: ?>
      <ul class="dash-list">
        <?php foreach ($openIncidents as $inc): ?>
        <li class="dash-list-item">
          <span class="dash-list-icon dash-list-icon-red"><i class="bi bi-exclamation-triangle-fill"></i></span>
          <div class="dash-list-body"><span class="dash-list-main"><?= htmlspecialchars($inc['incident_type']) ?></span><span class="dash-list-sub"><?= htmlspecialchars($inc['trip_number']) ?> · <?= htmlspecialchars($inc['plate_number']) ?></span></div>
          <span class="dash-list-time"><?= date('M d', strtotime($inc['reported_at'])) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <!-- Low Stock -->
    <div class="dash-widget">
      <div class="dash-widget-header">
        <span class="dash-widget-title"><i class="bi bi-box-seam me-2"></i>Low Stock Parts</span>
        <a href="<?= APP_BASE ?>/pages/parts.php" class="dash-widget-link">View all</a>
      </div>
      <?php if (empty($lowStock)): ?>
      <div class="dash-empty"><i class="bi bi-box-seam"></i><span>All parts adequately stocked</span></div>
      <?php else: ?>
      <ul class="dash-list">
        <?php foreach ($lowStock as $p): ?>
        <li class="dash-list-item">
          <span class="dash-list-icon dash-list-icon-orange"><i class="bi bi-box-seam"></i></span>
          <div class="dash-list-body"><span class="dash-list-main"><?= htmlspecialchars($p['part_name']) ?></span><span class="dash-list-sub"><?= $p['quantity'] ?>/<?= $p['reorder_level'] ?> <?= htmlspecialchars($p['unit']) ?></span></div>
          <?php if ($p['quantity'] == 0): ?><span class="dash-badge dash-badge-red">Out</span><?php else: ?><span class="dash-badge dash-badge-orange">Low</span><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <!-- License Alerts -->
    <div class="dash-widget">
      <div class="dash-widget-header">
        <span class="dash-widget-title"><i class="bi bi-card-text me-2"></i>License Expiry</span>
        <a href="<?= APP_BASE ?>/pages/users.php" class="dash-widget-link">View all</a>
      </div>
      <?php if (empty($licAlerts)): ?>
      <div class="dash-empty"><i class="bi bi-patch-check"></i><span>No expiring licenses</span></div>
      <?php else: ?>
      <ul class="dash-list">
        <?php foreach ($licAlerts as $a): ?>
        <li class="dash-list-item">
          <span class="dash-list-icon <?= $a['days_until_expiry'] <= 14 ? 'dash-list-icon-red' : 'dash-list-icon-orange' ?>"><i class="bi bi-card-text"></i></span>
          <div class="dash-list-body"><span class="dash-list-main"><?= htmlspecialchars($a['full_name']) ?></span><span class="dash-list-sub"><?= date('M d, Y', strtotime($a['license_expiry'])) ?></span></div>
          <?php if ($a['days_until_expiry'] <= 0): ?><span class="dash-badge dash-badge-red">Expired</span><?php elseif ($a['days_until_expiry'] <= 14): ?><span class="dash-badge dash-badge-red"><?= $a['days_until_expiry'] ?>d</span><?php else: ?><span class="dash-badge dash-badge-orange"><?= $a['days_until_expiry'] ?>d</span><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php layoutFoot(); ?>