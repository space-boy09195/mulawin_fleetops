<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/dashboard_head.js';
layoutHead('Dashboard', APP_BASE . '/assets/css/dashboard_head.css');

$pdo = getDBConnection();

// ── Stat cards ────────────────────────────────────────────────────────────────
$fleetRows = $pdo->query("SELECT status, truck_count FROM v_fleet_status")->fetchAll(PDO::FETCH_ASSOC);
$fleet = [];
foreach ($fleetRows as $r) $fleet[$r['status']] = (int)$r['truck_count'];
$available  = $fleet['Available']         ?? 0;
$deployed   = $fleet['Deployed']          ?? 0;
$underMaint = $fleet['Under Maintenance'] ?? 0;
$inactive   = $fleet['Inactive']          ?? 0;

$activeTripsCount = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE status NOT IN ('Completed','Cancelled')")->fetchColumn();

// ── Diagnostic alerts (open incidents + late trips + low stock + pending dispatch) ──
$alerts = [];

// Late trips
$lateTrips = $pdo->query("
    SELECT t.trip_number, tr.plate_number, 'Late Trip' AS alert_type, 'Warning' AS severity
    FROM trips t
    JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
    JOIN trucks tr ON dr.truck_id = tr.truck_id
    WHERE t.is_late = 1 AND t.status NOT IN ('Completed','Cancelled')
    LIMIT 3
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($lateTrips as $lt) {
    $alerts[] = ['alert' => 'Late trip detected', 'severity' => 'Warning',
                 'source' => $lt['trip_number'], 'detail' => $lt['plate_number'],
                 'type' => 'trip'];
}

// Open incidents
$incidents = $pdo->query("
    SELECT i.incident_type, t.trip_number, i.reported_at
    FROM incidents i
    JOIN trips t ON i.trip_id = t.trip_id
    WHERE i.resolved_at IS NULL
    ORDER BY i.reported_at DESC LIMIT 3
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($incidents as $inc) {
    $alerts[] = ['alert' => $inc['incident_type'], 'severity' => 'Critical',
                 'source' => $inc['trip_number'], 'detail' => date('M d, Y H:i', strtotime($inc['reported_at'])),
                 'type' => 'incident'];
}

// Low stock
$lowStockAlerts = $pdo->query("
    SELECT part_name, quantity, reorder_level FROM v_low_stock_parts LIMIT 2
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($lowStockAlerts as $ls) {
    $alerts[] = ['alert' => 'Low parts inventory (' . $ls['part_name'] . ')',
                 'severity' => 'Info', 'source' => 'Maintenance',
                 'detail' => $ls['quantity'] . '/' . $ls['reorder_level'] . ' remaining',
                 'type' => 'stock'];
}

// Pending dispatches
$pendingDispCount = (int)$pdo->query("SELECT COUNT(*) FROM dispatch_requests WHERE status='Pending'")->fetchColumn();
if ($pendingDispCount > 0) {
    $alerts[] = ['alert' => 'Unapproved dispatch request',
                 'severity' => 'Info', 'source' => 'Dispatch',
                 'detail' => $pendingDispCount . ' pending approval',
                 'type' => 'dispatch'];
}

// ── Trip trends — last 14 days ────────────────────────────────────────────────
$trendRows = $pdo->query("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM trips
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
")->fetchAll(PDO::FETCH_ASSOC);

$trendMap = [];
foreach ($trendRows as $r) $trendMap[$r['day']] = (int)$r['cnt'];
$trendLabels = [];
$trendData   = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('M d', strtotime($d));
    $trendData[]   = $trendMap[$d] ?? 0;
}

// ── Fleet status for donut ────────────────────────────────────────────────────
$donutData = [
    'labels' => ['Deployed', 'Available', 'Maintenance', 'Inactive'],
    'data'   => [$deployed, $available, $underMaint, $inactive],
    'colors' => ['#0d6efd', '#198754', '#ffc107', '#6c757d'],
];
$totalFleet = array_sum($donutData['data']);

// Pass to JS
$GLOBALS['dash_data'] = json_encode([
    'trend'  => ['labels' => $trendLabels, 'data' => $trendData],
    'donut'  => $donutData,
]);
?>
<div class="dh-page">

  <!-- Header -->
  <div class="dh-header">
    <div>
      <h1 class="dh-title">Dashboard</h1>
      <p class="dh-subtitle">Operational analytics and diagnostic alerts</p>
    </div>
    <a href="<?= APP_BASE ?>/pages/dispatch.php" class="btn dh-btn-primary">
      <i class="bi bi-send me-1"></i> New Dispatch
    </a>
  </div>

  <!-- Stat cards -->
  <div class="dh-stats">
    <div class="dh-stat-card">
      <div class="dh-stat-label">Available Trucks</div>
      <div class="dh-stat-value dh-value-green"><?= $available ?></div>
    </div>
    <div class="dh-stat-card">
      <div class="dh-stat-label">Deployed Trucks</div>
      <div class="dh-stat-value"><?= $deployed ?></div>
    </div>
    <div class="dh-stat-card <?= $underMaint > 0 ? 'dh-stat-warn' : '' ?>">
      <div class="dh-stat-label">Under Maintenance</div>
      <div class="dh-stat-value dh-value-orange"><?= $underMaint ?></div>
    </div>
    <div class="dh-stat-card">
      <div class="dh-stat-label">Active Trips</div>
      <div class="dh-stat-value dh-value-blue"><?= $activeTripsCount ?></div>
    </div>
  </div>

  <!-- Main grid -->
  <div class="dh-grid">

    <!-- Diagnostic Alerts -->
    <div class="dh-widget dh-widget-alerts">
      <div class="dh-widget-header">
        <span class="dh-widget-title">
          <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Diagnostic Alerts
        </span>
        <a href="<?= APP_BASE ?>/pages/incidents.php" class="dh-link">View All</a>
      </div>
      <?php if (empty($alerts)): ?>
      <div class="dh-empty"><i class="bi bi-shield-check"></i><span>No active alerts</span></div>
      <?php else: ?>
      <table class="table dh-table">
        <thead><tr><th>Alert</th><th>Severity</th><th>Source</th><th>Detail</th></tr></thead>
        <tbody>
          <?php foreach ($alerts as $a): ?>
          <tr>
            <td class="dh-alert-name"><?= htmlspecialchars($a['alert']) ?></td>
            <td>
              <?php
              $sevCls = match($a['severity']) {
                  'Critical' => 'dh-sev-critical',
                  'Warning'  => 'dh-sev-warning',
                  default    => 'dh-sev-info',
              };
              ?>
              <span class="dh-severity <?= $sevCls ?>"><?= $a['severity'] ?></span>
            </td>
            <td class="dh-muted"><?= htmlspecialchars($a['source']) ?></td>
            <td class="dh-muted"><?= htmlspecialchars($a['detail']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- Trip Trends chart -->
    <div class="dh-widget dh-widget-chart">
      <div class="dh-widget-header">
        <span class="dh-widget-title"><i class="bi bi-graph-up me-2"></i>Trip Trends (Last 14 Days)</span>
      </div>
      <div class="dh-chart-wrap">
        <canvas id="tripTrendChart"></canvas>
      </div>
    </div>

    <!-- Fleet Status donut -->
    <div class="dh-widget dh-widget-donut">
      <div class="dh-widget-header">
        <span class="dh-widget-title"><i class="bi bi-pie-chart-fill me-2"></i>Fleet Status Distribution</span>
      </div>
      <div class="dh-donut-wrap">
        <div class="dh-donut-canvas-wrap">
          <canvas id="fleetDonutChart"></canvas>
          <div class="dh-donut-center">
            <span class="dh-donut-total"><?= $totalFleet ?></span>
            <span class="dh-donut-label">Total</span>
          </div>
        </div>
        <div class="dh-donut-legend">
          <?php foreach ($donutData['labels'] as $i => $lbl): ?>
          <div class="dh-legend-item">
            <span class="dh-legend-dot" style="background:<?= $donutData['colors'][$i] ?>"></span>
            <span class="dh-legend-label"><?= $lbl ?></span>
            <span class="dh-legend-val"><?= $donutData['data'][$i] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
window.DASH_DATA = <?= $GLOBALS['dash_data'] ?>;
</script>
<?php layoutFoot(); ?>