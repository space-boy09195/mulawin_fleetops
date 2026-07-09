<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_DISPATCHER]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/dashboard.js';
layoutHead('Dashboard', APP_BASE . '/assets/css/dashboard.css');

$pdo = getDBConnection();

// ── Fleet availability ────────────────────────────────────────────────────────
$fleetRows = $pdo->query("SELECT status, truck_count FROM v_fleet_status")->fetchAll(PDO::FETCH_ASSOC);
$fleet = [];
foreach ($fleetRows as $r) $fleet[$r['status']] = $r['truck_count'];
$available  = $fleet['Available']         ?? 0;
$deployed   = $fleet['Deployed']          ?? 0;
$underMaint = $fleet['Under Maintenance'] ?? 0;

// ── Active trips ──────────────────────────────────────────────────────────────
$activeTrips = $pdo->query("
    SELECT trip_number, trip_status, plate_number, driver_name,
           origin, destination, expected_arrival, is_late
    FROM v_active_trips ORDER BY is_late DESC, expected_arrival ASC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
$lateCount = count(array_filter($activeTrips, fn($t) => $t['is_late']));

// ── My pending dispatch requests ──────────────────────────────────────────────
$myPending = $pdo->query("
    SELECT dr.dispatch_id, dr.requested_at, dr.remarks,
           tr.plate_number, e.full_name AS driver_name,
           r.origin, r.destination
    FROM dispatch_requests dr
    JOIN trucks tr   ON dr.truck_id  = tr.truck_id
    JOIN employees e ON dr.driver_id = e.employee_id
    JOIN routes r    ON dr.route_id  = r.route_id
    WHERE dr.status = 'Pending'
    ORDER BY dr.requested_at DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ── Open incidents ────────────────────────────────────────────────────────────
$openIncidents = $pdo->query("
    SELECT i.incident_type, i.reported_at, t.trip_number, tr.plate_number
    FROM incidents i
    JOIN trips t              ON i.trip_id     = t.trip_id
    JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
    JOIN trucks tr            ON dr.truck_id   = tr.truck_id
    WHERE i.resolved_at IS NULL ORDER BY i.reported_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="dash-page">
  <div class="dash-header mb-4">
    <h1 class="dash-title">Dashboard</h1>
    <p class="dash-subtitle">Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?></p>
  </div>

  <div class="dash-stats mb-4">
    <div class="stat-card"><div class="stat-icon stat-green"><i class="bi bi-check-circle"></i></div><div class="stat-info"><div class="stat-value"><?= $available ?></div><div class="stat-label">Trucks Available</div></div></div>
    <div class="stat-card"><div class="stat-icon stat-blue"><i class="bi bi-truck"></i></div><div class="stat-info"><div class="stat-value"><?= $deployed ?></div><div class="stat-label">Deployed</div></div></div>
    <div class="stat-card"><div class="stat-icon stat-blue"><i class="bi bi-map"></i></div><div class="stat-info"><div class="stat-value"><?= count($activeTrips) ?></div><div class="stat-label">Active Trips</div></div></div>
    <div class="stat-card <?= $lateCount > 0 ? 'stat-card-alert' : '' ?>"><div class="stat-icon stat-red"><i class="bi bi-clock-history"></i></div><div class="stat-info"><div class="stat-value"><?= $lateCount ?></div><div class="stat-label">Late Trips</div></div></div>
    <div class="stat-card <?= count($myPending) > 0 ? 'stat-card-warn' : '' ?>"><div class="stat-icon stat-purple"><i class="bi bi-send"></i></div><div class="stat-info"><div class="stat-value"><?= count($myPending) ?></div><div class="stat-label">Pending Dispatches</div></div></div>
    <div class="stat-card <?= count($openIncidents) > 0 ? 'stat-card-alert' : '' ?>"><div class="stat-icon stat-red"><i class="bi bi-exclamation-triangle"></i></div><div class="stat-info"><div class="stat-value"><?= count($openIncidents) ?></div><div class="stat-label">Open Incidents</div></div></div>
  </div>

  <div class="dash-grid">
    <!-- Active Trips -->
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

    <!-- Pending Dispatches -->
    <div class="dash-widget">
      <div class="dash-widget-header">
        <span class="dash-widget-title"><i class="bi bi-send me-2"></i>Pending Dispatches</span>
        <a href="<?= APP_BASE ?>/pages/dispatch.php" class="dash-widget-link">View all</a>
      </div>
      <?php if (empty($myPending)): ?>
      <div class="dash-empty"><i class="bi bi-send"></i><span>No pending dispatches</span></div>
      <?php else: ?>
      <ul class="dash-list">
        <?php foreach ($myPending as $d): ?>
        <li class="dash-list-item">
          <span class="dash-list-icon dash-list-icon-purple"><i class="bi bi-send"></i></span>
          <div class="dash-list-body">
            <span class="dash-list-main"><?= htmlspecialchars($d['plate_number']) ?> · <?= htmlspecialchars($d['driver_name']) ?></span>
            <span class="dash-list-sub"><?= htmlspecialchars($d['origin']) ?> → <?= htmlspecialchars($d['destination']) ?></span>
          </div>
          <span class="dash-list-time"><?= date('M d', strtotime($d['requested_at'])) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
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
  </div>
</div>
<?php layoutFoot(); ?>