<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_DISPATCHER]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/dashboard_dispatcher.js';
layoutHead('Dashboard', APP_BASE . '/assets/css/dashboard_dispatcher.css');

$pdo = getDBConnection();

// ── Stat cards ────────────────────────────────────────────────────────────────
$activeTrips = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE status NOT IN ('Completed','Cancelled')")->fetchColumn();
$pendingDisp = (int)$pdo->query("SELECT COUNT(*) FROM dispatch_requests WHERE status='Pending'")->fetchColumn();
$availTrucks = (int)$pdo->query("SELECT COUNT(*) FROM trucks WHERE status='Available'")->fetchColumn();
$delayedTrips = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE is_late=1 AND status NOT IN ('Completed','Cancelled')")->fetchColumn();

// ── Live trips ────────────────────────────────────────────────────────────────
$liveTrips = $pdo->query("
    SELECT t.trip_number, t.status, t.is_late, t.expected_arrival,
           e.full_name AS driver_name, r.origin, r.destination,
           tr.plate_number
    FROM trips t
    JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
    JOIN employees e          ON dr.driver_id  = e.employee_id
    JOIN routes r             ON dr.route_id   = r.route_id
    JOIN trucks tr            ON dr.truck_id   = tr.truck_id
    WHERE t.status NOT IN ('Completed','Cancelled')
    ORDER BY t.is_late DESC, t.updated_at DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ── My pending dispatch requests ──────────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT dr.dispatch_id, dr.status, dr.requested_at, dr.remarks,
           tr.plate_number, e.full_name AS driver_name,
           r.origin, r.destination, dr.scheduled_at
    FROM dispatch_requests dr
    JOIN trucks tr   ON dr.truck_id  = tr.truck_id
    JOIN employees e ON dr.driver_id = e.employee_id
    JOIN routes r    ON dr.route_id  = r.route_id
    WHERE dr.requested_by = ? AND dr.status IN ('Pending','Approved','Rejected')
    ORDER BY dr.requested_at DESC LIMIT 5"
);
$stmt->execute([$_SESSION['user_id'] ?? 0]);
$myRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fallback: show all recent if none for current user
if (empty($myRequests)) {
    $myRequests = $pdo->query("
        SELECT dr.dispatch_id, dr.status, dr.requested_at, dr.remarks,
               tr.plate_number, e.full_name AS driver_name,
               r.origin, r.destination, dr.scheduled_at
        FROM dispatch_requests dr
        JOIN trucks tr   ON dr.truck_id  = tr.truck_id
        JOIN employees e ON dr.driver_id = e.employee_id
        JOIN routes r    ON dr.route_id  = r.route_id
        ORDER BY dr.requested_at DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Delayed trip for alert banner ─────────────────────────────────────────────
$delayedBanner = $pdo->query("
    SELECT t.trip_number, tr.plate_number, r.destination, t.expected_arrival
    FROM trips t
    JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
    JOIN trucks tr            ON dr.truck_id   = tr.truck_id
    JOIN routes r             ON dr.route_id   = r.route_id
    WHERE t.is_late = 1 AND t.status NOT IN ('Completed','Cancelled')
    ORDER BY t.expected_arrival ASC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// ── Trip status breakdown for bar chart ───────────────────────────────────────
$statusRows = $pdo->query("
    SELECT status, COUNT(*) AS cnt
    FROM trips
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);
$statusMap = [];
foreach ($statusRows as $r) $statusMap[$r['status']] = (int)$r['cnt'];
$chartStatuses = ['Loading','In Transit','Unloading','Completed','Cancelled'];
$chartColors   = ['#6f42c1','#0d6efd','#fd7e14','#198754','#dc3545'];
$chartData = array_map(fn($s) => $statusMap[$s] ?? 0, $chartStatuses);

$GLOBALS['dash_data'] = json_encode([
    'statusChart' => ['labels' => $chartStatuses, 'data' => $chartData, 'colors' => $chartColors],
]);
?>
<div class="dd-page">

  <!-- Header -->
  <div class="dd-header">
    <div>
      <h1 class="dd-title">Dashboard</h1>
      <p class="dd-subtitle">Live trip monitoring and dispatch management</p>
    </div>
    <a href="<?= APP_BASE ?>/pages/dispatch.php" class="btn dd-btn-primary">
      <i class="bi bi-plus-lg me-1"></i> New Request
    </a>
  </div>

  <!-- Stat cards -->
  <div class="dd-stats">
    <div class="dd-stat-card">
      <div class="dd-stat-label">Active Trips</div>
      <div class="dd-stat-value dd-val-blue"><?= $activeTrips ?></div>
      <div class="dd-stat-sub">In transit or loading</div>
    </div>
    <div class="dd-stat-card <?= $pendingDisp > 0 ? 'dd-stat-warn' : '' ?>">
      <div class="dd-stat-label">Pending Dispatch</div>
      <div class="dd-stat-value dd-val-orange"><?= $pendingDisp ?></div>
      <div class="dd-stat-sub">Awaiting approval</div>
    </div>
    <div class="dd-stat-card">
      <div class="dd-stat-label">Available Trucks</div>
      <div class="dd-stat-value dd-val-green"><?= $availTrucks ?></div>
      <div class="dd-stat-sub">Ready to deploy</div>
    </div>
    <div class="dd-stat-card <?= $delayedTrips > 0 ? 'dd-stat-alert' : '' ?>">
      <div class="dd-stat-label">Delayed Trips</div>
      <div class="dd-stat-value dd-val-red"><?= $delayedTrips ?></div>
      <div class="dd-stat-sub">Requires attention</div>
    </div>
  </div>

  <!-- Main grid -->
  <div class="dd-grid">

    <!-- Live Trips -->
    <div class="dd-widget dd-widget-wide">
      <div class="dd-widget-header">
        <span class="dd-widget-title">
          <i class="bi bi-map me-2"></i>Live Trips
          <span class="dd-live-dot"></span><span class="dd-live-label">Live</span>
        </span>
        <a href="<?= APP_BASE ?>/pages/trip_monitor.php" class="dd-link">View All</a>
      </div>
      <?php if (empty($liveTrips)): ?>
      <div class="dd-empty"><i class="bi bi-map"></i><span>No active trips right now</span></div>
      <?php else: ?>
      <div class="dd-table-wrap">
        <table class="table dd-table">
          <thead>
            <tr><th>Trip</th><th>Driver</th><th>Route</th><th>ETA</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($liveTrips as $t): ?>
            <tr class="<?= $t['is_late'] ? 'dd-row-late' : '' ?>">
              <td><span class="dd-ref"><?= htmlspecialchars($t['trip_number']) ?></span></td>
              <td><?= htmlspecialchars($t['driver_name']) ?></td>
              <td class="dd-route">
                <?= htmlspecialchars($t['origin']) ?>
                <i class="bi bi-arrow-right"></i>
                <?= htmlspecialchars($t['destination']) ?>
              </td>
              <td class="<?= $t['is_late'] ? 'dd-late-text' : 'dd-muted' ?>">
                <?= $t['expected_arrival'] ? date('M d, H:i', strtotime($t['expected_arrival'])) : '—' ?>
              </td>
              <td>
                <?php
                $stCls = match($t['status']) {
                    'In Transit' => 'dd-badge-blue',
                    'Loading'    => 'dd-badge-purple',
                    'Unloading'  => 'dd-badge-orange',
                    'Completed'  => 'dd-badge-green',
                    default      => 'dd-badge-gray',
                };
                ?>
                <?php if ($t['is_late']): ?>
                <span class="dd-status-badge dd-badge-red">Delayed</span>
                <?php else: ?>
                <span class="dd-status-badge <?= $stCls ?>"><?= htmlspecialchars($t['status']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- My Pending Requests -->
    <div class="dd-widget">
      <div class="dd-widget-header">
        <span class="dd-widget-title"><i class="bi bi-send me-2"></i>My Pending Requests</span>
        <a href="<?= APP_BASE ?>/pages/dispatch.php" class="dd-link">View All</a>
      </div>
      <?php if (empty($myRequests)): ?>
      <div class="dd-empty"><i class="bi bi-send"></i><span>No recent requests</span></div>
      <?php else: ?>
      <ul class="dd-req-list">
        <?php foreach ($myRequests as $req): ?>
        <?php
          $reqCls = match($req['status']) {
              'Approved' => 'dd-req-approved',
              'Rejected' => 'dd-req-rejected',
              default    => 'dd-req-pending',
          };
          $reqBadge = match($req['status']) {
              'Approved' => 'dd-badge-green',
              'Rejected' => 'dd-badge-red',
              default    => 'dd-badge-orange',
          };
        ?>
        <li class="dd-req-item <?= $reqCls ?>">
          <div class="dd-req-body">
            <span class="dd-req-plate"><?= htmlspecialchars($req['plate_number']) ?></span>
            <span class="dd-req-route"><?= htmlspecialchars($req['origin']) ?> → <?= htmlspecialchars($req['destination']) ?></span>
            <span class="dd-req-meta"><?= htmlspecialchars($req['driver_name']) ?> · <?= date('M d, Y', strtotime($req['requested_at'])) ?></span>
          </div>
          <span class="dd-status-badge <?= $reqBadge ?>"><?= $req['status'] ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <!-- Trip Status chart -->
    <div class="dd-widget">
      <div class="dd-widget-header">
        <span class="dd-widget-title"><i class="bi bi-bar-chart me-2"></i>Trip Status (Last 30 Days)</span>
      </div>
      <div class="dd-chart-wrap">
        <canvas id="tripStatusChart"></canvas>
      </div>
    </div>

  </div>

  <!-- Delayed trip banner -->
  <?php if ($delayedBanner): ?>
  <div class="dd-alert-banner">
    <i class="bi bi-exclamation-triangle-fill dd-alert-icon"></i>
    <div class="dd-alert-body">
      <strong>Delayed Trip Requires Action</strong>
      <span>
        <?= htmlspecialchars($delayedBanner['trip_number']) ?>
        (<?= htmlspecialchars($delayedBanner['plate_number']) ?>)
        is delayed heading to <?= htmlspecialchars($delayedBanner['destination']) ?>.
        <?= $delayedBanner['expected_arrival'] ? 'ETA was ' . date('M d, H:i', strtotime($delayedBanner['expected_arrival'])) . '.' : '' ?>
      </span>
    </div>
    <a href="<?= APP_BASE ?>/pages/trip_monitor.php" class="dd-alert-btn">View Trip</a>
  </div>
  <?php endif; ?>

</div>

<script>window.DASH_DATA = <?= $GLOBALS['dash_data'] ?>;</script>
<?php layoutFoot(); ?>