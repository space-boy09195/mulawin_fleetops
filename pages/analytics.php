<?php
// ============================================================
// pages/analytics.php
// Cross-functional analytics hub — Head Management only.
// Pulls together Operations, Maintenance, and Accounting data
// that no single role dashboard shows together.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/analytics.js';
layoutHead('Analytics', APP_BASE . '/assets/css/analytics.css');

$pdo = getDBConnection();

// ── Period filter (whitelisted — safe to interpolate into SQL) ───────────────
$periods = [
    '1m'  => ['label' => 'This Month',      'interval' => '1 MONTH'],
    '3m'  => ['label' => 'Last 3 Months',   'interval' => '3 MONTH'],
    '6m'  => ['label' => 'Last 6 Months',   'interval' => '6 MONTH'],
    '1y'  => ['label' => 'Last 12 Months',  'interval' => '1 YEAR'],
    'all' => ['label' => 'All Time',        'interval' => null],
];
$period = $_GET['period'] ?? '6m';
if (!isset($periods[$period])) $period = '6m';
$interval    = $periods[$period]['interval'];
$periodLabel = $periods[$period]['label'];

$tripDateFilter  = $interval ? "AND t.created_at >= DATE_SUB(CURDATE(), INTERVAL $interval)"       : '';
$billDateFilter  = $interval ? "AND b.created_at >= DATE_SUB(CURDATE(), INTERVAL $interval)"       : '';
$collDateFilter  = $interval ? "AND c.payment_date >= DATE_SUB(CURDATE(), INTERVAL $interval)"      : '';
$maintDateFilter = $interval ? "AND date_performed >= DATE_SUB(CURDATE(), INTERVAL $interval)"      : '';
$incDateFilter   = $interval ? "AND reported_at >= DATE_SUB(CURDATE(), INTERVAL $interval)"          : '';

// ── KPI: Revenue billed vs collected ──────────────────────────────────────────
$revenue = (float)$pdo->query("
    SELECT COALESCE(SUM(b.amount), 0) FROM billings b WHERE 1=1 $billDateFilter
")->fetchColumn();

$collected = (float)$pdo->query("
    SELECT COALESCE(SUM(c.amount_paid), 0) FROM collections c WHERE 1=1 $collDateFilter
")->fetchColumn();

$collectionRate = $revenue > 0 ? round(($collected / $revenue) * 100, 1) : 0;

// ── KPI: Maintenance cost ─────────────────────────────────────────────────────
$maintCost = (float)$pdo->query("
    SELECT COALESCE(SUM(cost), 0) FROM maintenance_records WHERE 1=1 $maintDateFilter
")->fetchColumn();

// ── KPI: Completed trips + on-time rate ───────────────────────────────────────
$tripStats = $pdo->query("
    SELECT
        COUNT(*)                                            AS completed,
        SUM(CASE WHEN is_late = 1 THEN 1 ELSE 0 END)         AS late_count
    FROM trips t
    WHERE t.status = 'Completed' $tripDateFilter
")->fetch(PDO::FETCH_ASSOC);
$completedTrips = (int)($tripStats['completed'] ?? 0);
$lateCount      = (int)($tripStats['late_count'] ?? 0);
$onTimeRate     = $completedTrips > 0 ? round((($completedTrips - $lateCount) / $completedTrips) * 100, 1) : 0;

// ── KPI: Fleet utilization (distinct trucks dispatched / total trucks) ───────
$totalTrucks = (int)$pdo->query("SELECT COUNT(*) FROM trucks")->fetchColumn();
$utilizedTrucks = (int)$pdo->query("
    SELECT COUNT(DISTINCT dr.truck_id)
    FROM dispatch_requests dr
    JOIN trips t ON t.dispatch_id = dr.dispatch_id
    WHERE 1=1 $tripDateFilter
")->fetchColumn();
$utilizationRate = $totalTrucks > 0 ? round(($utilizedTrucks / $totalTrucks) * 100, 1) : 0;

// ── KPI: Avg revenue per completed trip ───────────────────────────────────────
$avgRevenuePerTrip = $completedTrips > 0 ? $revenue / $completedTrips : 0;

// ── Revenue vs Maintenance Cost — trailing 6 months (independent of filter) ──
$revByMonth = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(amount) AS total
    FROM billings
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym
")->fetchAll(PDO::FETCH_KEY_PAIR);

$costByMonth = $pdo->query("
    SELECT DATE_FORMAT(date_performed, '%Y-%m') AS ym, SUM(cost) AS total
    FROM maintenance_records
    WHERE date_performed >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym
")->fetchAll(PDO::FETCH_KEY_PAIR);

$monthLabels = [];
$revTrend    = [];
$costTrend   = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $monthLabels[] = date('M Y', strtotime("-$i months"));
    $revTrend[]    = round((float)($revByMonth[$ym]  ?? 0), 2);
    $costTrend[]   = round((float)($costByMonth[$ym] ?? 0), 2);
}

// ── Trip status breakdown (period-aware) ──────────────────────────────────────
$statusRows = $pdo->query("
    SELECT status, COUNT(*) AS cnt FROM trips t WHERE 1=1 $tripDateFilter GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);
$statusLabels = ['Loading', 'In Transit', 'Unloading', 'Completed', 'Cancelled'];
$statusData   = array_map(fn($s) => (int)($statusRows[$s] ?? 0), $statusLabels);
$statusColors = ['#6c757d', '#0d6efd', '#0dcaf0', '#198754', '#dc3545'];

// ── Maintenance cost by type (period-aware) ───────────────────────────────────
$maintTypeRows = $pdo->query("
    SELECT maintenance_type, COALESCE(SUM(cost), 0) AS total_cost
    FROM maintenance_records
    WHERE 1=1 $maintDateFilter
    GROUP BY maintenance_type
")->fetchAll(PDO::FETCH_KEY_PAIR);
$maintTypeLabels = ['Preventive', 'Corrective', 'Inspection'];
$maintTypeData   = array_map(fn($t) => round((float)($maintTypeRows[$t] ?? 0), 2), $maintTypeLabels);
$maintTypeColors = ['#198754', '#dc3545', '#0d6efd'];

// ── Top 5 trucks by revenue ────────────────────────────────────────────────────
$topTrucks = $pdo->query("
    SELECT tr.plate_number, tr.brand, tr.model,
           COUNT(DISTINCT t.trip_id) AS trip_count,
           COALESCE(SUM(b.amount), 0) AS revenue
    FROM trucks tr
    JOIN dispatch_requests dr ON dr.truck_id = tr.truck_id
    JOIN trips t ON t.dispatch_id = dr.dispatch_id AND t.status = 'Completed'
    LEFT JOIN billings b ON b.trip_id = t.trip_id
    WHERE 1=1 $tripDateFilter
    GROUP BY tr.truck_id
    ORDER BY revenue DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── Top 5 drivers by completed trips ──────────────────────────────────────────
$topDrivers = $pdo->query("
    SELECT e.full_name,
           COUNT(*) AS trip_count,
           SUM(CASE WHEN t.is_late = 1 THEN 1 ELSE 0 END) AS late_count
    FROM dispatch_requests dr
    JOIN trips t ON t.dispatch_id = dr.dispatch_id AND t.status = 'Completed'
    JOIN employees e ON e.employee_id = dr.driver_id
    WHERE 1=1 $tripDateFilter
    GROUP BY e.employee_id
    ORDER BY trip_count DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── Incident trend — trailing 6 months ────────────────────────────────────────
$incByMonth = $pdo->query("
    SELECT DATE_FORMAT(reported_at, '%Y-%m') AS ym, COUNT(*) AS cnt
    FROM incidents
    WHERE reported_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym
")->fetchAll(PDO::FETCH_KEY_PAIR);
$incTrend = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $incTrend[] = (int)($incByMonth[$ym] ?? 0);
}

// Pass chart data to JS
$GLOBALS['analytics_data'] = json_encode([
    'revCostTrend' => ['labels' => $monthLabels, 'revenue' => $revTrend, 'cost' => $costTrend],
    'tripStatus'   => ['labels' => $statusLabels, 'data' => $statusData, 'colors' => $statusColors],
    'maintType'    => ['labels' => $maintTypeLabels, 'data' => $maintTypeData, 'colors' => $maintTypeColors],
    'incTrend'     => ['labels' => $monthLabels, 'data' => $incTrend],
]);
?>
<div class="an-page">

  <!-- Header -->
  <div class="an-header">
    <div>
      <h1 class="an-title">Analytics</h1>
      <p class="an-subtitle">Cross-functional performance across Operations, Maintenance, and Accounting</p>
    </div>
    <form method="get" class="an-period-form">
      <select name="period" class="form-select an-period-select" onchange="this.form.submit()">
        <?php foreach ($periods as $key => $p): ?>
        <option value="<?= $key ?>" <?= $key === $period ? 'selected' : '' ?>><?= htmlspecialchars($p['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <!-- KPI cards -->
  <div class="an-kpis">
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-cash-coin"></i> Revenue Billed</div>
      <div class="an-kpi-value">₱<?= number_format($revenue, 2) ?></div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-wallet2"></i> Collected</div>
      <div class="an-kpi-value an-value-green">₱<?= number_format($collected, 2) ?></div>
      <div class="an-kpi-sub"><?= $collectionRate ?>% collection rate</div>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-tools"></i> Maintenance Cost</div>
      <div class="an-kpi-value an-value-orange">₱<?= number_format($maintCost, 2) ?></div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-clock-history"></i> On-Time Delivery</div>
      <div class="an-kpi-value <?= $onTimeRate >= 85 ? 'an-value-green' : ($onTimeRate >= 60 ? 'an-value-orange' : 'an-value-red') ?>">
        <?= $onTimeRate ?>%
      </div>
      <div class="an-kpi-sub"><?= $completedTrips ?> completed trip<?= $completedTrips !== 1 ? 's' : '' ?></div>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-speedometer2"></i> Fleet Utilization</div>
      <div class="an-kpi-value an-value-blue"><?= $utilizationRate ?>%</div>
      <div class="an-kpi-sub"><?= $utilizedTrucks ?> of <?= $totalTrucks ?> trucks active</div>
    </div>
    <div class="an-kpi-card">
      <div class="an-kpi-label"><i class="bi bi-graph-up-arrow"></i> Avg Revenue / Trip</div>
      <div class="an-kpi-value">₱<?= number_format($avgRevenuePerTrip, 2) ?></div>
      <div class="an-kpi-sub"><?= htmlspecialchars($periodLabel) ?></div>
    </div>
  </div>

  <!-- Chart grid -->
  <div class="an-grid">

    <div class="an-widget an-widget-wide">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-bar-chart-line me-2"></i>Revenue vs Maintenance Cost (Last 6 Months)</span>
      </div>
      <div class="an-chart-wrap an-chart-tall">
        <canvas id="revCostChart"></canvas>
      </div>
    </div>

    <div class="an-widget">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-pie-chart-fill me-2"></i>Trip Status</span>
      </div>
      <div class="an-donut-wrap">
        <div class="an-donut-canvas-wrap">
          <canvas id="tripStatusChart"></canvas>
        </div>
        <div class="an-donut-legend">
          <?php foreach ($statusLabels as $i => $lbl): ?>
          <div class="an-legend-item">
            <span class="an-legend-dot" style="background:<?= $statusColors[$i] ?>"></span>
            <span class="an-legend-label"><?= $lbl ?></span>
            <span class="an-legend-val"><?= $statusData[$i] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="an-widget">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-wrench-adjustable me-2"></i>Maintenance Cost by Type</span>
      </div>
      <div class="an-donut-wrap">
        <div class="an-donut-canvas-wrap">
          <canvas id="maintTypeChart"></canvas>
        </div>
        <div class="an-donut-legend">
          <?php foreach ($maintTypeLabels as $i => $lbl): ?>
          <div class="an-legend-item">
            <span class="an-legend-dot" style="background:<?= $maintTypeColors[$i] ?>"></span>
            <span class="an-legend-label"><?= $lbl ?></span>
            <span class="an-legend-val">₱<?= number_format($maintTypeData[$i], 0) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="an-widget an-widget-wide">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-exclamation-triangle me-2"></i>Incident Trend (Last 6 Months)</span>
      </div>
      <div class="an-chart-wrap">
        <canvas id="incTrendChart"></canvas>
      </div>
    </div>

  </div>

  <!-- Leaderboards -->
  <div class="an-tables-grid">

    <div class="an-widget">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-trophy me-2"></i>Top 5 Trucks by Revenue</span>
        <button class="an-export-btn" data-export="topTrucksTable" data-filename="top-trucks">
          <i class="bi bi-download"></i> CSV
        </button>
      </div>
      <?php if (empty($topTrucks)): ?>
      <div class="an-empty"><i class="bi bi-truck"></i><span>No completed trips in this period</span></div>
      <?php else: ?>
      <table class="table an-table" id="topTrucksTable">
        <thead><tr><th>Plate No.</th><th>Truck</th><th>Trips</th><th>Revenue</th></tr></thead>
        <tbody>
          <?php foreach ($topTrucks as $tt): ?>
          <tr>
            <td class="an-mono"><?= htmlspecialchars($tt['plate_number']) ?></td>
            <td><?= htmlspecialchars($tt['brand'] . ' ' . $tt['model']) ?></td>
            <td><?= (int)$tt['trip_count'] ?></td>
            <td class="an-money">₱<?= number_format((float)$tt['revenue'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="an-widget">
      <div class="an-widget-header">
        <span class="an-widget-title"><i class="bi bi-person-badge me-2"></i>Top 5 Drivers by Trips</span>
        <button class="an-export-btn" data-export="topDriversTable" data-filename="top-drivers">
          <i class="bi bi-download"></i> CSV
        </button>
      </div>
      <?php if (empty($topDrivers)): ?>
      <div class="an-empty"><i class="bi bi-person"></i><span>No completed trips in this period</span></div>
      <?php else: ?>
      <table class="table an-table" id="topDriversTable">
        <thead><tr><th>Driver</th><th>Completed Trips</th><th>Late Trips</th><th>On-Time %</th></tr></thead>
        <tbody>
          <?php foreach ($topDrivers as $td):
            $tCount = (int)$td['trip_count'];
            $lCount = (int)$td['late_count'];
            $otPct  = $tCount > 0 ? round((($tCount - $lCount) / $tCount) * 100, 1) : 0;
          ?>
          <tr>
            <td><?= htmlspecialchars($td['full_name']) ?></td>
            <td><?= $tCount ?></td>
            <td><?= $lCount ?></td>
            <td class="<?= $otPct >= 85 ? 'an-text-green' : ($otPct >= 60 ? 'an-text-orange' : 'an-text-red') ?>">
              <?= $otPct ?>%
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
window.ANALYTICS_DATA = <?= $GLOBALS['analytics_data'] ?>;
</script>
<?php layoutFoot(); ?>