<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/recommendations.php';

requirePermission('company.dashboard.view');

$pdo = getDBConnection();
$can = static fn(string $permission): bool => currentUserHasAnyPermission([$permission]);
$canTrips = $can('trips.view');
$canFleet = $can('fleet.view');
$canMaintenance = $can('maintenance.view');
$canParts = $can('parts.view');
$canBilling = $can('billing.view');
$canFinance = $can('finance.view') || $can('finance.funds.view') || $can('finance.disbursements.view') || $can('finance.ap.view');
$canApprovals = $can('approvals.review');
$canIncidents = $can('incidents.manage');
$canAudit = $can('audit.view');

function adminDashboardQuery(callable $query, mixed $default = null): mixed {
    try {
        return $query();
    } catch (PDOException $e) {
        error_log('Admin dashboard query failed: ' . $e->getMessage());
        return $default;
    }
}

$stats = [];
$metric = static function (string $label, int|float $value, string $icon, string $tone, string $href) use (&$stats): void {
    $stats[] = [$label, $value, $icon, $tone, $href];
};

$fleet = null;
if ($canFleet) {
    $fleetRows = adminDashboardQuery(static fn() => $pdo->query(
        'SELECT status, truck_count FROM v_fleet_status'
    )->fetchAll(PDO::FETCH_ASSOC));
    $fleet = [];
    if (is_array($fleetRows)) {
        foreach ($fleetRows as $row) $fleet[$row['status']] = (int)$row['truck_count'];
        $metric('Available Trucks', $fleet['Available'] ?? 0, 'bi-truck', 'green', '/pages/fleet_status.php');
        $metric('Deployed Trucks', $fleet['Deployed'] ?? 0, 'bi-truck-front', 'blue', '/pages/fleet_status.php');
        $metric('Under Maintenance', $fleet['Under Maintenance'] ?? 0, 'bi-tools', 'amber', '/pages/fleet_status.php');
    }
}

$activeTripsCount = null;
$lateTripsCount = null;
$recentTrips = null;
$trendRows = [];
if ($canTrips) {
    $activeTripsCount = adminDashboardQuery(static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM trips WHERE status NOT IN ('Completed','Cancelled')"
    )->fetchColumn());
    if ($activeTripsCount !== null) $metric('Active Trips', $activeTripsCount, 'bi-map', 'blue', '/pages/trip_monitor.php');

    $lateTripsCount = adminDashboardQuery(static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM trips WHERE is_late = 1 AND status NOT IN ('Completed','Cancelled')"
    )->fetchColumn());
    if ($lateTripsCount !== null) $metric('Delayed Trips', $lateTripsCount, 'bi-alarm', 'red', '/pages/trip_monitor.php');

    $recentTrips = adminDashboardQuery(static fn() => $pdo->query(
        "SELECT t.trip_number, t.status, t.is_late, t.updated_at, r.origin, r.destination, tr.plate_number
         FROM trips t
         JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
         JOIN routes r ON r.route_id = dr.route_id
         JOIN trucks tr ON tr.truck_id = dr.truck_id
         WHERE t.status NOT IN ('Completed','Cancelled')
         ORDER BY t.is_late DESC, t.updated_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC));

    $trendRows = adminDashboardQuery(static fn() => $pdo->query(
        "SELECT DATE(created_at) AS day, COUNT(*) AS cnt
         FROM trips
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
         GROUP BY DATE(created_at) ORDER BY day"
    )->fetchAll(PDO::FETCH_ASSOC), []);
}

$pendingDispatchCount = null;
if ($canTrips) {
    $pendingDispatchCount = adminDashboardQuery(static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM dispatch_requests WHERE status = 'Pending'"
    )->fetchColumn());
    if ($pendingDispatchCount !== null) $metric('Pending Dispatches', $pendingDispatchCount, 'bi-send', 'amber', '/pages/dispatch.php');
}

$pendingApprovals = null;
if ($canApprovals) {
    $pendingApprovals = adminDashboardQuery(static function () use ($pdo): int {
        $sql = "SELECT COUNT(*)
                FROM approval_steps s JOIN approval_requests a ON a.approval_id = s.approval_id
                WHERE a.status = 'Pending' AND s.status = 'Pending' AND s.step_order = a.current_step";
        $params = [];
        if (currentRoleId() !== ROLE_ADMIN) {
            $sql .= ' AND (s.approver_role_id = ? OR s.assigned_to = ?)';
            $params = [currentRoleId(), currentUserId()];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    });
    if ($pendingApprovals !== null) $metric('Pending Approvals', $pendingApprovals, 'bi-inbox', 'amber', '/pages/requests.php');
}

$billingSummary = null;
if ($canBilling) {
    $billingSummary = adminDashboardQuery(static fn() => $pdo->query(
        "SELECT COALESCE(SUM(total_collected), 0) AS collected,
                COALESCE(SUM(balance), 0) AS receivables,
                SUM(status != 'Paid' AND due_date < CURDATE()) AS overdue
         FROM v_billing_summary"
    )->fetch(PDO::FETCH_ASSOC));
}
if ($canBilling && $billingSummary !== null) {
    $metric('Collections', (float)$billingSummary['collected'], 'bi-cash-coin', 'green', '/pages/billing.php');
    $metric('Receivables', (float)$billingSummary['receivables'], 'bi-receipt', 'amber', '/pages/billing.php');
}

if ($canFinance && $can('finance.funds.view')) {
    $pendingFunds = adminDashboardQuery(static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM fund_requests WHERE status = 'Pending Approval'"
    )->fetchColumn());
    if ($pendingFunds !== null) $metric('Pending Fund Requests', $pendingFunds, 'bi-wallet2', 'amber', '/pages/finance.php');
}
if ($canFinance && $can('finance.disbursements.view')) {
    $recentDisbursements = adminDashboardQuery(static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM finance_disbursements WHERE disbursed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    )->fetchColumn());
    if ($recentDisbursements !== null) $metric('Disbursements This Month', $recentDisbursements, 'bi-cash-stack', 'blue', '/pages/finance.php');
}

$openWorkOrders = null;
$dueMaintenance = null;
if ($canMaintenance) {
    $openWorkOrders = adminDashboardQuery(static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM repair_work_orders WHERE status IN ('Open','In Progress')"
    )->fetchColumn());
    $dueMaintenance = adminDashboardQuery(static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM preventive_maintenance_schedules
         WHERE status = 'Active' AND next_due_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)"
    )->fetchColumn());
    if ($openWorkOrders !== null) $metric('Open Maintenance Work', $openWorkOrders, 'bi-wrench-adjustable', 'amber', '/pages/maintenance.php');
}

$alerts = [];
$alertsAvailable = true;
if ($canTrips) {
    $lateAlertRows = adminDashboardQuery(static fn() => $pdo->query(
        "SELECT t.trip_number, tr.plate_number
         FROM trips t JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
         JOIN trucks tr ON tr.truck_id = dr.truck_id
         WHERE t.is_late = 1 AND t.status NOT IN ('Completed','Cancelled')
         ORDER BY t.updated_at DESC LIMIT 3"
    )->fetchAll(PDO::FETCH_ASSOC));
    if ($lateAlertRows === null) $alertsAvailable = false;
    else foreach ($lateAlertRows as $trip) {
        $alerts[] = ['label' => 'Delayed trip', 'detail' => $trip['trip_number'] . ' · ' . $trip['plate_number'], 'tone' => 'warning'];
    }
}
if ($canIncidents) {
    $incidentRows = adminDashboardQuery(static fn() => $pdo->query(
        "SELECT i.incident_type, t.trip_number, i.reported_at
         FROM incidents i JOIN trips t ON t.trip_id = i.trip_id
         WHERE i.resolved_at IS NULL ORDER BY i.reported_at DESC LIMIT 3"
    )->fetchAll(PDO::FETCH_ASSOC));
    if ($incidentRows === null) $alertsAvailable = false;
    else foreach ($incidentRows as $incident) {
        $alerts[] = ['label' => $incident['incident_type'], 'detail' => $incident['trip_number'] . ' · ' . date('M j, g:i A', strtotime($incident['reported_at'])), 'tone' => 'danger'];
    }
}
if ($canParts) {
    $lowStockRows = adminDashboardQuery(static fn() => $pdo->query(
        'SELECT part_name, quantity, reorder_level FROM v_low_stock_parts LIMIT 3'
    )->fetchAll(PDO::FETCH_ASSOC));
    if ($lowStockRows === null) $alertsAvailable = false;
    else foreach ($lowStockRows as $part) {
        $alerts[] = ['label' => 'Low parts inventory', 'detail' => $part['part_name'] . ' · ' . $part['quantity'] . '/' . $part['reorder_level'] . ' remaining', 'tone' => 'info'];
    }
}
if ($canTrips && $pendingDispatchCount === null) $alertsAvailable = false;
if ($canMaintenance && ($dueMaintenance === null || $openWorkOrders === null)) $alertsAvailable = false;
if ($canBilling && $billingSummary === null) $alertsAvailable = false;
if ($pendingDispatchCount > 0) {
    $alerts[] = ['label' => 'Pending dispatch approvals', 'detail' => $pendingDispatchCount . ' dispatch request' . ($pendingDispatchCount === 1 ? '' : 's'), 'tone' => 'info'];
}
if ($dueMaintenance > 0) {
    $alerts[] = ['label' => 'Maintenance due soon', 'detail' => $dueMaintenance . ' schedule' . ($dueMaintenance === 1 ? '' : 's') . ' within 14 days', 'tone' => 'warning'];
}
if ($canBilling && $billingSummary && (int)$billingSummary['overdue'] > 0) {
    $alerts[] = ['label' => 'Overdue receivables', 'detail' => (int)$billingSummary['overdue'] . ' invoice' . ((int)$billingSummary['overdue'] === 1 ? '' : 's') . ' past due', 'tone' => 'warning'];
}

$recommendations = [];
$recommendationSources = [];
if ($canMaintenance) $recommendationSources[] = static fn() => array_map(fn($r) => $r + ['category' => 'Maintenance', 'icon' => 'bi-tools'], getMaintenanceRecommendations($pdo, 3));
if ($canParts) $recommendationSources[] = static fn() => array_map(fn($r) => $r + ['category' => 'Parts', 'icon' => 'bi-box-seam'], getPartsReorderRecommendations($pdo, 3));
if ($canBilling) $recommendationSources[] = static fn() => array_map(fn($r) => $r + ['category' => 'Collections', 'icon' => 'bi-cash-coin'], getCollectionsRecommendations($pdo, 3));
if ($canTrips) $recommendationSources[] = static fn() => array_map(fn($r) => $r + ['category' => 'Dispatch', 'icon' => 'bi-person-badge'], getDispatchRecommendations($pdo, 3));
foreach ($recommendationSources as $source) {
    $recommendations = array_merge($recommendations, adminDashboardQuery($source, []));
}
usort($recommendations, static fn($a, $b) => ($a['priority'] === 'high' ? 0 : 1) <=> ($b['priority'] === 'high' ? 0 : 1));
$recommendations = array_slice($recommendations, 0, 6);

$linkedQuery = $pdo->prepare(
    'SELECT employee_id FROM employees WHERE user_id = ? AND is_active = 1 LIMIT 1'
);
$linkedQuery->execute([currentUserId()]);
$employeeId = (int)($linkedQuery->fetchColumn() ?: 0);
$attendance = null;
$attendanceDays = null;
if ($employeeId > 0) {
    $attendanceQuery = $pdo->prepare(
        'SELECT attendance_date, time_in, time_out
         FROM employee_attendance
         WHERE employee_id = ? AND attendance_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND CURDATE()
         ORDER BY (time_out IS NULL) DESC, attendance_date DESC LIMIT 1'
    );
    $attendanceQuery->execute([$employeeId]);
    $attendance = $attendanceQuery->fetch(PDO::FETCH_ASSOC) ?: null;
    $daysQuery = $pdo->prepare(
        "SELECT COUNT(DISTINCT attendance_date) FROM employee_attendance
         WHERE employee_id = ? AND attendance_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
           AND status IN ('Present','On Duty')"
    );
    $daysQuery->execute([$employeeId]);
    $attendanceDays = (int)$daysQuery->fetchColumn();
}

$auditRows = null;
if ($canAudit) {
    $auditRows = adminDashboardQuery(static fn() => $pdo->query(
        "SELECT l.action, l.table_name, l.logged_at, u.full_name
         FROM audit_logs l LEFT JOIN users u ON u.user_id = l.user_id
         ORDER BY l.logged_at DESC LIMIT 6"
    )->fetchAll(PDO::FETCH_ASSOC));
}

$charts = [];
if ($canTrips && $trendRows) {
    $trendMap = [];
    foreach ($trendRows as $row) $trendMap[$row['day']] = (int)$row['cnt'];
    $labels = [];
    $values = [];
    for ($i = 13; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $labels[] = date('M j', strtotime($day));
        $values[] = $trendMap[$day] ?? 0;
    }
    if (array_sum($values) > 0) $charts['trend'] = ['labels' => $labels, 'data' => $values];
}
if ($canFleet && $fleet && array_sum($fleet) > 0) {
    $charts['donut'] = [
        'labels' => ['Deployed', 'Available', 'Maintenance', 'Inactive'],
        'data' => [
            $fleet['Deployed'] ?? 0, $fleet['Available'] ?? 0,
            $fleet['Under Maintenance'] ?? 0, $fleet['Inactive'] ?? 0,
        ],
        'colors' => ['#d7a62a', '#2f9e5b', '#d95757', '#8fa596'],
    ];
}
if ($charts) {
    $GLOBALS['page_needs_chart'] = true;
    $GLOBALS['page_js'] = APP_BASE . '/assets/js/dashboard_head.js';
    $GLOBALS['dash_data'] = json_encode($charts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

$metricOrder = [
    'Active Trips', 'Pending Approvals', 'Pending Dispatches', 'Available Trucks',
    'Receivables', 'Open Maintenance Work', 'Delayed Trips', 'Collections',
    'Under Maintenance', 'Deployed Trucks', 'Pending Fund Requests', 'Disbursements This Month',
];
usort($stats, static function (array $left, array $right) use ($metricOrder): int {
    return array_search($left[0], $metricOrder, true) <=> array_search($right[0], $metricOrder, true);
});
$stats = array_slice($stats, 0, 5);

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$fullName = trim((string)($_SESSION['full_name'] ?? ''));
layoutHead('Dashboard', APP_BASE . '/assets/css/dashboard_head.css');
?>
<div class="dh-page">
  <header class="dh-header">
    <div>
      <p class="dh-eyebrow">Management overview · <?= date('l, F j, Y') ?></p>
      <h1 class="dh-title"><?= htmlspecialchars($greeting . ($fullName !== '' ? ', ' . $fullName : '')) ?></h1>
      <p class="dh-subtitle">Fleet performance, operational priorities, and business activity.</p>
    </div>
    <?php if ($canTrips && $can('trips.create')): ?>
    <a href="<?= APP_BASE ?>/pages/dispatch.php" class="btn dh-btn-primary"><i class="bi bi-send me-1"></i> New Dispatch</a>
    <?php endif; ?>
  </header>

  <?php if ($recommendations): ?>
  <section class="dh-rec-panel">
    <div class="dh-rec-header"><i class="bi bi-lightbulb"></i> Recommended Actions <span class="dh-rec-count"><?= count($recommendations) ?></span>
      <?php if ($can('reports.view')): ?><a href="<?= APP_BASE ?>/pages/analytics.php" class="dh-rec-viewall">Full Analytics</a><?php endif; ?>
    </div>
    <div class="dh-rec-list">
      <?php foreach ($recommendations as $rec): ?>
      <div class="dh-rec-card dh-rec-<?= htmlspecialchars($rec['priority']) ?>">
        <span class="dh-rec-priority dh-rec-priority-<?= htmlspecialchars($rec['priority']) ?>"><?= $rec['priority'] === 'high' ? 'HIGH' : 'MED' ?></span>
        <span class="dh-rec-category"><i class="bi <?= htmlspecialchars($rec['icon']) ?>"></i> <?= htmlspecialchars($rec['category']) ?></span>
        <div class="dh-rec-body"><div class="dh-rec-title"><?= htmlspecialchars($rec['title']) ?></div><div class="dh-rec-detail"><?= htmlspecialchars($rec['detail']) ?></div></div>
        <a href="<?= htmlspecialchars(APP_BASE . $rec['action_url']) ?>" class="dh-rec-action"><?= htmlspecialchars($rec['action_label']) ?></a>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($stats): ?>
  <section class="dh-stats" aria-label="Management summary">
    <?php foreach ($stats as [$label, $value, $icon, $tone, $href]): ?>
    <a class="dh-stat-card dh-stat-link" href="<?= htmlspecialchars(APP_BASE . $href) ?>">
      <div class="dh-stat-icon <?= htmlspecialchars($tone) ?>"><i class="bi <?= htmlspecialchars($icon) ?>"></i></div>
      <div class="dh-stat-label"><?= htmlspecialchars($label) ?></div>
      <div class="dh-stat-value"><?= is_float($value) ? '₱' . number_format($value, 2) : number_format($value) ?></div>
    </a>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <section class="dh-grid">
    <article class="dh-widget dh-widget-main">
      <div class="dh-widget-header"><span class="dh-widget-title"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Important Items</span>
        <?php if ($canIncidents): ?><a href="<?= APP_BASE ?>/pages/incidents.php" class="dh-link">Incidents</a><?php endif; ?>
      </div>
      <?php if (!$alerts && $alertsAvailable): ?><div class="dh-empty"><i class="bi bi-shield-check"></i><span>No items require attention.</span></div>
      <?php elseif (!$alerts): ?><div class="dh-empty"><i class="bi bi-info-circle"></i><span>Some dashboard data is temporarily unavailable.</span></div>
      <?php else: ?><ul class="dh-alert-list">
        <?php foreach (array_slice($alerts, 0, 8) as $alert): ?>
        <li><span class="dh-alert-dot dh-alert-<?= htmlspecialchars($alert['tone']) ?>"></span><span><strong><?= htmlspecialchars($alert['label']) ?></strong><small><?= htmlspecialchars($alert['detail']) ?></small></span></li>
        <?php endforeach; ?>
      </ul><?php endif; ?>
    </article>

    <article class="dh-widget dh-widget-attendance">
      <div class="dh-widget-header"><span class="dh-widget-title"><i class="bi bi-clock-history me-2"></i>My Attendance</span>
        <?php if ($employeeId > 0): ?><a href="<?= APP_BASE ?>/pages/attendance.php" class="dh-link">Details</a><?php endif; ?>
      </div>
      <?php if ($employeeId === 0): ?><div class="dh-empty"><span>Attendance isn't assigned to this account.</span></div>
      <?php else: ?>
        <div id="adminDashboardAttendance" class="dh-attendance-body" data-dashboard-attendance data-feedback="adminAttendanceFeedback" data-elapsed="adminDashboardElapsed"
             data-start="<?= $attendance && $attendance['time_in'] && !$attendance['time_out'] ? (int)(strtotime($attendance['attendance_date'] . ' ' . $attendance['time_in']) * 1000) : '' ?>">
          <?php if (!$attendance || !$attendance['time_in'] || ($attendance['attendance_date'] !== date('Y-m-d') && $attendance['time_out'])): ?>
            <p>No attendance record for today.</p><button type="button" class="btn btn-primary" data-attendance-action="clock_in">Time In</button>
          <?php elseif (!$attendance['time_out']): ?>
            <p>Timed in at <strong><?= htmlspecialchars(date('g:i A', strtotime($attendance['time_in']))) ?></strong></p>
            <p class="dh-muted">Shift in progress <span id="adminDashboardElapsed"></span></p><button type="button" class="btn btn-outline-primary" data-attendance-action="clock_out">Time Out</button>
          <?php else: ?>
            <?php $start = strtotime($attendance['attendance_date'] . ' ' . $attendance['time_in']); $end = strtotime($attendance['attendance_date'] . ' ' . $attendance['time_out']); if ($end < $start) $end += 86400; ?>
            <p>Timed in <strong><?= htmlspecialchars(date('g:i A', $start)) ?></strong>, out <strong><?= htmlspecialchars(date('g:i A', $end)) ?></strong></p>
            <p class="dh-muted">Worked <?= (int)floor(($end - $start) / 3600) ?>h <?= (int)(floor(($end - $start) / 60) % 60) ?>m</p>
          <?php endif; ?>
          <p class="dh-attendance-days"><?= $attendanceDays ?> recorded work day<?= $attendanceDays === 1 ? '' : 's' ?> this month</p>
          <div id="adminAttendanceFeedback" class="dh-attendance-feedback" role="status" aria-live="polite"></div>
        </div>
      <?php endif; ?>
    </article>

    <?php if ($canTrips): ?>
    <article class="dh-widget dh-widget-main">
      <div class="dh-widget-header"><span class="dh-widget-title"><i class="bi bi-map me-2"></i>Active Trips</span><a href="<?= APP_BASE ?>/pages/trip_monitor.php" class="dh-link">Trip Monitoring</a></div>
      <?php if ($recentTrips === null): ?><div class="dh-empty"><span>Trip activity is temporarily unavailable.</span></div>
      <?php elseif (!$recentTrips): ?><div class="dh-empty"><span>No active trips right now.</span></div>
      <?php else: ?><div class="table-responsive"><table class="table dh-table">
        <thead><tr><th>Trip</th><th>Truck</th><th>Route</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($recentTrips as $trip): ?><tr><td><?= htmlspecialchars($trip['trip_number']) ?></td><td><?= htmlspecialchars($trip['plate_number']) ?></td><td><?= htmlspecialchars($trip['origin'] . ' → ' . $trip['destination']) ?></td><td><?= htmlspecialchars($trip['status']) ?><?= (int)$trip['is_late'] === 1 ? ' · Late' : '' ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </article>
    <?php endif; ?>

    <?php if ($canAudit): ?>
    <article class="dh-widget dh-widget-main">
      <div class="dh-widget-header"><span class="dh-widget-title"><i class="bi bi-activity me-2"></i>Recent System Activity</span><a href="<?= APP_BASE ?>/pages/recycle_bin.php" class="dh-link">Audit Log</a></div>
      <?php if ($auditRows === null): ?><div class="dh-empty"><span>System activity is temporarily unavailable.</span></div>
      <?php elseif (!$auditRows): ?><div class="dh-empty"><span>No recent system activity.</span></div>
      <?php else: ?><ul class="dh-audit-list"><?php foreach ($auditRows as $entry): ?>
        <li><strong><?= htmlspecialchars($entry['action']) ?></strong><span><?= htmlspecialchars($entry['table_name']) ?> · <?= htmlspecialchars($entry['full_name'] ?? 'System') ?></span><time><?= htmlspecialchars(date('M j, g:i A', strtotime($entry['logged_at']))) ?></time></li>
      <?php endforeach; ?></ul><?php endif; ?>
    </article>
    <?php endif; ?>
  </section>

  <?php if (isset($charts['trend']) || isset($charts['donut'])): ?>
  <section class="dh-chart-grid">
    <?php if (isset($charts['trend'])): ?><article class="dh-widget">
      <div class="dh-widget-header"><span class="dh-widget-title"><i class="bi bi-graph-up me-2"></i>Trip Trends · 14 Days</span></div><div class="dh-chart-wrap"><canvas id="tripTrendChart"></canvas></div>
    </article><?php endif; ?>
    <?php if (isset($charts['donut'])): ?><article class="dh-widget">
      <div class="dh-widget-header"><span class="dh-widget-title"><i class="bi bi-pie-chart-fill me-2"></i>Fleet Status</span></div>
      <div class="dh-donut-wrap"><div class="dh-donut-canvas-wrap"><canvas id="fleetDonutChart"></canvas><div class="dh-donut-center"><span class="dh-donut-total"><?= array_sum($charts['donut']['data']) ?></span><span class="dh-donut-label">Trucks</span></div></div>
        <div class="dh-donut-legend"><?php foreach ($charts['donut']['labels'] as $i => $label): ?><div class="dh-legend-item"><span class="dh-legend-dot dh-legend-dot-<?= $i ?>"></span><span class="dh-legend-label"><?= htmlspecialchars($label) ?></span><span class="dh-legend-val"><?= (int)$charts['donut']['data'][$i] ?></span></div><?php endforeach; ?></div>
      </div>
    </article><?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php if ($charts): ?><script>window.DASH_DATA = <?= $GLOBALS['dash_data'] ?>;</script><?php endif; ?>
<?php if ($employeeId > 0): ?>
<script src="<?= APP_BASE ?>/assets/js/dashboard_attendance.js?v=<?= (int)filemtime(__DIR__ . '/../assets/js/dashboard_attendance.js') ?>"></script>
<?php endif; ?>
<?php layoutFoot(); ?>
