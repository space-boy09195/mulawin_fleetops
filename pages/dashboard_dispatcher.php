<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/recommendations.php';

requireRole([ROLE_DISPATCHER]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/dashboard_dispatcher.js';
layoutHead('Dashboard', APP_BASE . '/assets/css/dashboard_dispatcher.css');

$pdo = getDBConnection();

// ── Period filter (scopes Trip Status chart + My Recent Requests) ────────────
$periods = [
    'today' => 'Today', '1w' => 'This Week', '1m' => 'This Month',
    '3m' => 'Last 3 Months', '6m' => 'Last 6 Months', '1y' => 'Last 12 Months', 'all' => 'All Time',
];
$period = $_GET['period'] ?? '1m';
if (!isset($periods[$period])) $period = '1m';
$rangeStart = match ($period) {
    'today' => new DateTime('today'),
    '1w'    => (new DateTime('today'))->modify('-6 days'),
    '1m'    => (new DateTime('today'))->modify('-1 months'),
    '3m'    => (new DateTime('today'))->modify('-3 months'),
    '6m'    => (new DateTime('today'))->modify('-6 months'),
    '1y'    => (new DateTime('today'))->modify('-12 months'),
    default => null,
};
$rangeStartSql = $rangeStart ? $rangeStart->format('Y-m-d 00:00:00') : null;

// ── Stat cards ────────────────────────────────────────────────────────────────
$activeTrips = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE status NOT IN ('Completed','Cancelled')")->fetchColumn();
$pendingDisp = (int)$pdo->query("SELECT COUNT(*) FROM dispatch_requests WHERE status='Pending'")->fetchColumn();
$availTrucks = (int)$pdo->query("SELECT COUNT(*) FROM trucks WHERE status='Available'")->fetchColumn();
$delayedTrips = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE is_late=1 AND status NOT IN ('Completed','Cancelled')")->fetchColumn();

// ── Live trips ────────────────────────────────────────────────────────────────
$liveTrips = $pdo->query("
    SELECT t.trip_id, t.trip_number, t.status, t.is_late, t.expected_arrival,
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

// ── Upcoming trips — loading now, scheduled to roll within 48h ───────────────
$upcomingTrips = $pdo->query("
    SELECT t.trip_id, t.trip_number, e.full_name AS driver_name,
           r.origin, r.destination, tr.plate_number, dr.scheduled_at
    FROM trips t
    JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
    JOIN employees e          ON dr.driver_id  = e.employee_id
    JOIN routes r             ON dr.route_id   = r.route_id
    JOIN trucks tr            ON dr.truck_id   = tr.truck_id
    WHERE t.status = 'Loading'
      AND dr.scheduled_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR)
    ORDER BY dr.scheduled_at ASC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ── This week's personal activity ─────────────────────────────────────────────
$weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
$currentUserId = $_SESSION['user_id'] ?? 0;

$weeklyReq = $pdo->prepare("
    SELECT
        COUNT(*)                                            AS submitted,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected
    FROM dispatch_requests
    WHERE requested_by = ? AND requested_at >= ?
");
$weeklyReq->execute([$currentUserId, $weekStart]);
$weekly = $weeklyReq->fetch(PDO::FETCH_ASSOC);
$weeklySubmitted = (int)($weekly['submitted'] ?? 0);
$weeklyApproved  = (int)($weekly['approved']  ?? 0);
$weeklyRejected  = (int)($weekly['rejected']  ?? 0);

$weeklyDone = $pdo->prepare("
    SELECT COUNT(*)
    FROM trips t
    JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
    WHERE dr.requested_by = ? AND t.status = 'Completed' AND t.actual_arrival >= ?
");
$weeklyDone->execute([$currentUserId, $weekStart]);
$weeklyCompleted = (int)$weeklyDone->fetchColumn();

// ── Recommended Actions (rule-based) ──────────────────────────────────────────
$recommendations = getDispatchRecommendations($pdo, 5);

// ── My pending dispatch requests ──────────────────────────────────────────────
$reqDateFilter = $rangeStartSql ? "AND dr.requested_at >= :rangeStart" : '';
$stmt = $pdo->prepare(
    "SELECT dr.dispatch_id, dr.status, dr.requested_at, dr.remarks,
           tr.plate_number, e.full_name AS driver_name,
           r.origin, r.destination, dr.scheduled_at
    FROM dispatch_requests dr
    JOIN trucks tr   ON dr.truck_id  = tr.truck_id
    JOIN employees e ON dr.driver_id = e.employee_id
    JOIN routes r    ON dr.route_id  = r.route_id
    WHERE dr.requested_by = :uid AND dr.status IN ('Pending','Approved','Rejected') $reqDateFilter
    ORDER BY dr.requested_at DESC LIMIT 5"
);
$stmt->bindValue(':uid', $_SESSION['user_id'] ?? 0);
if ($rangeStartSql) $stmt->bindValue(':rangeStart', $rangeStartSql);
$stmt->execute();
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
$statusDateFilter = $rangeStartSql ? "AND created_at >= :rangeStart" : '';
$statusStmt = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM trips
    WHERE 1=1 $statusDateFilter
    GROUP BY status
");
if ($rangeStartSql) $statusStmt->bindValue(':rangeStart', $rangeStartSql);
$statusStmt->execute();
$statusRows = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
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
    <div class="d-flex align-items-center gap-2">
      <form method="get" class="d-flex">
        <select name="period" class="form-select dd-period-select" onchange="this.form.submit()">
          <?php foreach ($periods as $key => $label): ?>
          <option value="<?= $key ?>" <?= $key === $period ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <a href="<?= APP_BASE ?>/pages/dispatch.php" class="btn dd-btn-primary">
        <i class="bi bi-plus-lg me-1"></i> New Request
      </a>
    </div>
  </div>

  <!-- Your activity this week -->
  <div class="dd-activity-strip">
    <span class="dd-activity-label"><i class="bi bi-calendar-week"></i> This Week</span>
    <span class="dd-activity-item"><strong><?= $weeklySubmitted ?></strong> submitted</span>
    <span class="dd-activity-item dd-activity-green"><strong><?= $weeklyApproved ?></strong> approved</span>
    <span class="dd-activity-item dd-activity-red"><strong><?= $weeklyRejected ?></strong> rejected</span>
    <span class="dd-activity-item dd-activity-blue"><strong><?= $weeklyCompleted ?></strong> trips completed</span>
  </div>

  <!-- Recommended Actions -->
  <?php if (!empty($recommendations)): ?>
  <div class="dd-rec-panel">
    <div class="dd-rec-header">
      <i class="bi bi-lightbulb"></i> Worth a Look
      <span class="dd-rec-count"><?= count($recommendations) ?></span>
    </div>
    <div class="dd-rec-list">
      <?php foreach ($recommendations as $rec):
        $prioLabel = $rec['priority'] === 'high' ? 'High Priority' : 'Medium Priority';
      ?>
      <div class="dd-rec-card dd-rec-<?= $rec['priority'] ?>" title="<?= htmlspecialchars($prioLabel) ?> — <?= htmlspecialchars($rec['detail']) ?>">
        <span class="dd-rec-priority dd-rec-priority-<?= $rec['priority'] ?>"><?= $rec['priority'] === 'high' ? 'HIGH' : 'MED' ?></span>
        <div class="dd-rec-body">
          <div class="dd-rec-title"><?= htmlspecialchars($rec['title']) ?></div>
          <div class="dd-rec-detail"><?= htmlspecialchars($rec['detail']) ?></div>
        </div>
        <a href="<?= APP_BASE . $rec['action_url'] ?>" class="dd-rec-action"><?= htmlspecialchars($rec['action_label']) ?></a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

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
            <tr><th>Trip</th><th>Driver</th><th>Route</th><th>ETA</th><th>Status</th><th>Update</th></tr>
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
              <td>
                <div class="dd-quick-update">
                  <select class="form-select dd-quick-select" data-trip-id="<?= $t['trip_id'] ?>">
                    <?php foreach (['Loading', 'In Transit', 'Unloading', 'Completed', 'Cancelled'] as $st): ?>
                    <option value="<?= $st ?>" <?= $st === $t['status'] ? 'selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="dd-quick-btn" data-trip-id="<?= $t['trip_id'] ?>" title="Apply status update">
                    <i class="bi bi-check-lg"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- My Recent Requests -->
    <div class="dd-widget">
      <div class="dd-widget-header">
        <span class="dd-widget-title"><i class="bi bi-send me-2"></i>My Recent Requests</span>
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
        <span class="dd-widget-title"><i class="bi bi-bar-chart me-2"></i>Trip Status (<?= htmlspecialchars($periods[$period]) ?>)</span>
      </div>
      <div class="dd-chart-wrap">
        <canvas id="tripStatusChart"></canvas>
      </div>
    </div>

    <!-- Upcoming Trips -->
    <div class="dd-widget dd-widget-wide">
      <div class="dd-widget-header">
        <span class="dd-widget-title"><i class="bi bi-calendar2-check me-2"></i>Upcoming (Next 48h)</span>
      </div>
      <?php if (empty($upcomingTrips)): ?>
      <div class="dd-empty"><i class="bi bi-calendar2-check"></i><span>Nothing scheduled to roll out in the next 48 hours</span></div>
      <?php else: ?>
      <div class="dd-table-wrap">
        <table class="table dd-table">
          <thead><tr><th>Trip</th><th>Driver</th><th>Truck</th><th>Route</th><th>Scheduled</th></tr></thead>
          <tbody>
            <?php foreach ($upcomingTrips as $u): ?>
            <tr>
              <td><span class="dd-ref"><?= htmlspecialchars($u['trip_number']) ?></span></td>
              <td><?= htmlspecialchars($u['driver_name']) ?></td>
              <td><?= htmlspecialchars($u['plate_number']) ?></td>
              <td class="dd-route">
                <?= htmlspecialchars($u['origin']) ?>
                <i class="bi bi-arrow-right"></i>
                <?= htmlspecialchars($u['destination']) ?>
              </td>
              <td class="dd-muted"><?= date('M d, H:i', strtotime($u['scheduled_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
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