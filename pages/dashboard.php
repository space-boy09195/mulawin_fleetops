<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';

requireLogin();

$pdo = getDBConnection();
$GLOBALS['page_needs_chart'] = false;
$roleName = (string)($_SESSION['role_name'] ?? '');
$isDispatcher = str_contains(strtolower($roleName), 'dispatcher');
$dispatcherScope = $isDispatcher ? dispatcherScope() : [];
$dispatchConditions = $isDispatcher ? ['dr.requested_by = ?'] : [];
$dispatchParams = $isDispatcher ? [currentUserId()] : [];
if (!empty($dispatcherScope['truck_types'])) {
    $dispatchConditions[] = 'tr.truck_type IN (' . implode(',', array_fill(0, count($dispatcherScope['truck_types']), '?')) . ')';
    $dispatchParams = array_merge($dispatchParams, $dispatcherScope['truck_types']);
}
$dispatchWhere = $dispatchConditions ? ' AND ' . implode(' AND ', $dispatchConditions) : '';

function fleetDashboardQuery(callable $query, mixed $default = null): mixed {
    try {
        return $query();
    } catch (PDOException $e) {
        error_log('Dashboard query failed: ' . $e->getMessage());
        return $default;
    }
}

$can = static fn(string $permission): bool => currentUserHasAnyPermission([$permission]);
$canTrips = $can('trips.view');
$canFleet = $can('fleet.view');
$canMaintenance = $can('maintenance.view');
$canParts = $can('parts.view');
$canBilling = $can('billing.view');
$canFunds = $can('finance.funds.view');
$canDisbursements = $can('finance.disbursements.view');
$canPayables = $can('finance.ap.view');
$canFinance = $canFunds || $canDisbursements || $canPayables;
$canPayroll = $can('payroll.view');
$canPurchasing = $can('purchasing.view');
$canApprovals = $can('approvals.review');

$stats = [];
$metric = static function (string $label, string $icon, string $color, string $href, callable $query) use (&$stats): void {
    $value = fleetDashboardQuery($query);
    if ($value !== null) {
        $stats[] = [$label, (int)$value, $icon, $color, $href];
    }
};

if ($canTrips) {
    $metric('Active Trips', 'bi-map', 'blue', '/pages/trip_monitor.php', static function () use ($pdo, $dispatchWhere, $dispatchParams, $isDispatcher): int {
        $sql = "SELECT COUNT(*) FROM trips t";
        if ($isDispatcher) $sql .= ' JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id JOIN trucks tr ON tr.truck_id = dr.truck_id';
        $sql .= " WHERE t.status NOT IN ('Completed','Cancelled')" . $dispatchWhere;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dispatchParams);
        return (int)$stmt->fetchColumn();
    });
    $metric('Late Trips', 'bi-alarm', 'red', '/pages/trip_monitor.php', static function () use ($pdo, $dispatchWhere, $dispatchParams, $isDispatcher): int {
        $sql = "SELECT COUNT(*) FROM trips t";
        if ($isDispatcher) $sql .= ' JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id JOIN trucks tr ON tr.truck_id = dr.truck_id';
        $sql .= " WHERE t.is_late = 1 AND t.status NOT IN ('Completed','Cancelled')" . $dispatchWhere;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dispatchParams);
        return (int)$stmt->fetchColumn();
    });
    $metric('Pending Dispatches', 'bi-send', 'amber', '/pages/dispatch.php', static function () use ($pdo, $isDispatcher, $dispatcherScope): int {
        $sql = 'SELECT COUNT(*) FROM dispatch_requests dr';
        $conditions = ["dr.status = 'Pending'"];
        $params = [];
        if ($isDispatcher) {
            $conditions[] = 'dr.requested_by = ?';
            $params[] = currentUserId();
            if (!empty($dispatcherScope['truck_types'])) {
                $sql .= ' JOIN trucks tr ON tr.truck_id = dr.truck_id';
                $conditions[] = 'tr.truck_type IN (' . implode(',', array_fill(0, count($dispatcherScope['truck_types']), '?')) . ')';
                $params = array_merge($params, $dispatcherScope['truck_types']);
            }
        }
        $stmt = $pdo->prepare($sql . ' WHERE ' . implode(' AND ', $conditions));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    });
}

if ($canFleet) {
    $metric('Available Trucks', 'bi-truck', 'green', '/pages/fleet_status.php', static function () use ($pdo, $dispatcherScope, $isDispatcher): int {
        $sql = "SELECT COUNT(*) FROM trucks WHERE status = 'Available'";
        $params = [];
        if ($isDispatcher && !empty($dispatcherScope['truck_types'])) {
            $sql .= ' AND truck_type IN (' . implode(',', array_fill(0, count($dispatcherScope['truck_types']), '?')) . ')';
            $params = $dispatcherScope['truck_types'];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    });
}

if ($canMaintenance) {
    $metric('Open Work Orders', 'bi-wrench-adjustable', 'blue', '/pages/maintenance.php', static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM repair_work_orders WHERE status IN ('Open','In Progress')"
    )->fetchColumn());
    $metric('Maintenance Due Soon', 'bi-calendar-check', 'amber', '/pages/maintenance.php', static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM preventive_maintenance_schedules
         WHERE status = 'Active' AND next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
    )->fetchColumn());
}

if ($canParts) {
    $metric('Low-Stock Parts', 'bi-box-seam', 'red', '/pages/parts.php', static fn() => (int)$pdo->query(
        'SELECT COUNT(*) FROM v_low_stock_parts'
    )->fetchColumn());
}

if ($canBilling) {
    $metric('Open Invoices', 'bi-receipt', 'amber', '/pages/billing.php', static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM billings WHERE status IN ('Unpaid','Partial')"
    )->fetchColumn());
    $metric('Overdue Invoices', 'bi-exclamation-circle', 'red', '/pages/billing.php', static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM billings WHERE status IN ('Unpaid','Partial') AND due_date < CURDATE()"
    )->fetchColumn());
}

if ($canFunds) {
    $metric('Pending Fund Requests', 'bi-wallet2', 'amber', '/pages/finance.php', static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM fund_requests WHERE status = 'Pending Approval'"
    )->fetchColumn());
}

if ($canDisbursements) {
    $metric('Disbursements This Month', 'bi-cash-stack', 'blue', '/pages/finance.php', static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM finance_disbursements WHERE disbursed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    )->fetchColumn());
}

if ($canPayables) {
    $metric('Pending Payment Vouchers', 'bi-receipt', 'amber', '/pages/finance.php', static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM payment_vouchers WHERE status = 'Pending Approval'"
    )->fetchColumn());
}

if ($canPayroll) {
    $metric('Payroll Records This Month', 'bi-cash-stack', 'blue', '/pages/payroll.php', static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM payroll_records WHERE pay_period_end >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    )->fetchColumn());
}

if ($canPurchasing) {
    $metric('Pending Purchase Orders', 'bi-cart-check', 'amber', '/pages/purchasing.php', static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM purchase_order_headers WHERE status = 'Pending Approval'"
    )->fetchColumn());
    $metric('Orders Awaiting Receipt', 'bi-box-seam', 'blue', '/pages/purchasing.php', static fn() => (int)$pdo->query(
        "SELECT COUNT(*) FROM purchase_order_headers WHERE status IN ('Ordered','Partially Received')"
    )->fetchColumn());
}

if ($canApprovals) {
    $metric('Awaiting My Approval', 'bi-inbox', 'amber', '/pages/requests.php', static function () use ($pdo): int {
        $sql = "SELECT COUNT(*)
                FROM approval_steps s
                JOIN approval_requests a ON a.approval_id = s.approval_id
                WHERE a.status = 'Pending' AND s.status = 'Pending'
                  AND s.step_order = a.current_step";
        $params = [];
        if (currentRoleId() !== ROLE_ADMIN) {
            $sql .= ' AND (s.approver_role_id = ? OR s.assigned_to = ?)';
            $params = [currentRoleId(), currentUserId()];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    });
}

$stats = array_slice($stats, 0, 5);

$linkedQuery = $pdo->prepare(
    'SELECT employee_id, full_name, position FROM employees WHERE user_id = ? AND is_active = 1 LIMIT 1'
);
$linkedQuery->execute([currentUserId()]);
$employee = $linkedQuery->fetch(PDO::FETCH_ASSOC) ?: null;

$attendance = null;
$attendanceDays = null;
$personalTrips = null;
$personalTripSummary = null;
if ($employee) {
    $attendanceQuery = $pdo->prepare(
        'SELECT attendance_date, status, time_in, time_out, overtime_minutes
         FROM employee_attendance
         WHERE employee_id = ? AND attendance_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND CURDATE()
         ORDER BY (time_out IS NULL) DESC, attendance_date DESC LIMIT 1'
    );
    $attendanceQuery->execute([(int)$employee['employee_id']]);
    $attendance = $attendanceQuery->fetch(PDO::FETCH_ASSOC) ?: null;

    $attendanceDaysQuery = $pdo->prepare(
        "SELECT COUNT(DISTINCT attendance_date)
         FROM employee_attendance
         WHERE employee_id = ? AND attendance_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
           AND status IN ('Present','On Duty')"
    );
    $attendanceDaysQuery->execute([(int)$employee['employee_id']]);
    $attendanceDays = (int)$attendanceDaysQuery->fetchColumn();

    $employeeId = (int)$employee['employee_id'];
    $personalTrips = fleetDashboardQuery(static function () use ($pdo, $employeeId): array {
        $stmt = $pdo->prepare(
            "SELECT t.trip_number, t.status, t.is_late, t.updated_at,
                    r.origin, r.destination, tr.plate_number
             FROM dispatch_requests dr
             JOIN trips t ON t.dispatch_id = dr.dispatch_id
             JOIN routes r ON r.route_id = dr.route_id
             JOIN trucks tr ON tr.truck_id = dr.truck_id
             WHERE dr.driver_id = ? OR dr.second_driver_id = ? OR dr.helper_id = ?
             ORDER BY (t.status NOT IN ('Completed','Cancelled')) DESC, t.updated_at DESC
             LIMIT 5"
        );
        $stmt->execute([$employeeId, $employeeId, $employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    });
    $personalTripSummary = fleetDashboardQuery(static function () use ($pdo, $employeeId): array {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS completed,
                    SUM(t.is_late = 0) AS on_time
             FROM dispatch_requests dr
             JOIN trips t ON t.dispatch_id = dr.dispatch_id
             WHERE (dr.driver_id = ? OR dr.second_driver_id = ? OR dr.helper_id = ?)
               AND t.status = 'Completed'"
        );
        $stmt->execute([$employeeId, $employeeId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [];
        $row['on_time'] = (int)($row['on_time'] ?? 0);
        return $row;
    });
}

$recentTrips = [];
if ($canTrips && (!$employee || !preg_match('/driver|helper/i', (string)$employee['position']))) {
    $recentTrips = fleetDashboardQuery(static function () use ($pdo, $isDispatcher, $dispatchWhere, $dispatchParams): array {
        $sql = "SELECT t.trip_number, t.status, t.is_late, t.updated_at,
                       r.origin, r.destination, tr.plate_number
                FROM trips t
                JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
                JOIN routes r ON r.route_id = dr.route_id
                JOIN trucks tr ON tr.truck_id = dr.truck_id
                WHERE t.status NOT IN ('Completed','Cancelled')" . $dispatchWhere . "
                ORDER BY t.is_late DESC, t.updated_at DESC
                LIMIT 5";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($isDispatcher ? $dispatchParams : []);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    });
}

$personalActivity = fleetDashboardQuery(static function () use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT action, table_name, logged_at
         FROM audit_logs WHERE user_id = ?
         ORDER BY logged_at DESC LIMIT 5'
    );
    $stmt->execute([currentUserId()]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
});

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$fullName = trim((string)($_SESSION['full_name'] ?? ''));
$roleName = $roleName !== '' ? $roleName : 'Account';
$roleDescriptions = [
    'Operations Head' => 'Your operational priorities and fleet activity for today.',
    'Dispatcher' => 'Your dispatch queue and the trips that need attention.',
    'Maintenance' => 'Maintenance work, upcoming service, and parts status.',
    'Accounting' => 'Billing, collections, and financial work in progress.',
    'Finance' => 'Your finance work items and current operational context.',
    'Billing and Collection' => 'Billing and collection work that needs attention.',
    'Purchasing Officer' => 'Purchase orders and inventory items requiring attention.',
    'Payroll' => 'Payroll activity and employee work records.',
];
$subtitle = $roleDescriptions[$roleName] ?? 'Your FleetOps work summary and latest activity.';
layoutHead('Dashboard', APP_BASE . '/assets/css/dashboard.css');
?>
<div class="fleet-dash">
  <header class="fleet-dash-header">
    <div>
      <p class="fleet-dash-eyebrow"><?= htmlspecialchars($roleName) ?> dashboard</p>
      <h1><?= htmlspecialchars($greeting . ($fullName !== '' ? ', ' . $fullName : '')) ?></h1>
      <p><?= htmlspecialchars($subtitle) ?></p>
    </div>
    <time datetime="<?= htmlspecialchars(date('Y-m-d')) ?>"><?= date('l, F j, Y') ?></time>
  </header>

  <?php if ($stats): ?>
  <section class="fleet-dash-kpis" aria-label="Dashboard summary">
    <?php foreach ($stats as [$label, $value, $icon, $color, $href]): ?>
      <a class="fleet-dash-kpi" href="<?= htmlspecialchars(APP_BASE . $href) ?>">
        <span class="fleet-dash-kpi-icon <?= htmlspecialchars($color) ?>"><i class="bi <?= htmlspecialchars($icon) ?>"></i></span>
        <span class="fleet-dash-kpi-copy"><strong><?= $value ?></strong><span><?= htmlspecialchars($label) ?></span></span>
        <i class="bi bi-arrow-up-right fleet-dash-kpi-link" aria-hidden="true"></i>
      </a>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <section class="fleet-dash-grid">
    <article class="fleet-dash-panel fleet-dash-attendance">
      <div class="fleet-dash-panel-heading">
        <div><span class="fleet-dash-panel-icon"><i class="bi bi-clock-history"></i></span><h2>My Attendance</h2></div>
        <?php if ($employee): ?><a href="<?= APP_BASE ?>/pages/attendance.php">Attendance details</a><?php endif; ?>
      </div>
      <?php if (!$employee): ?>
        <p class="fleet-dash-empty">Attendance isn't assigned to this account.</p>
      <?php else: ?>
        <div id="dashboardAttendance" data-dashboard-attendance data-feedback="attendanceFeedback" data-elapsed="dashboardElapsed"
             data-start="<?= $attendance && $attendance['time_in'] && !$attendance['time_out'] ? (int)(strtotime($attendance['attendance_date'] . ' ' . $attendance['time_in']) * 1000) : '' ?>">
          <?php if (!$attendance || !$attendance['time_in'] || ($attendance['attendance_date'] !== date('Y-m-d') && $attendance['time_out'])): ?>
            <p class="fleet-dash-attendance-state">No attendance record for today.</p>
            <button class="btn btn-primary" type="button" data-attendance-action="clock_in">Time In</button>
          <?php elseif (!$attendance['time_out']): ?>
            <p class="fleet-dash-attendance-state">Timed in at <strong><?= htmlspecialchars(date('g:i A', strtotime($attendance['time_in']))) ?></strong></p>
            <p class="fleet-dash-attendance-note">Your shift is in progress <span id="dashboardElapsed"></span>.</p>
            <button class="btn btn-outline-primary" type="button" data-attendance-action="clock_out">Time Out</button>
          <?php else: ?>
            <?php
              $start = strtotime($attendance['attendance_date'] . ' ' . $attendance['time_in']);
              $end = strtotime($attendance['attendance_date'] . ' ' . $attendance['time_out']);
              if ($end < $start) $end += 86400;
            ?>
            <p class="fleet-dash-attendance-state">Timed in <strong><?= htmlspecialchars(date('g:i A', $start)) ?></strong> &middot; timed out <strong><?= htmlspecialchars(date('g:i A', $end)) ?></strong></p>
            <p class="fleet-dash-attendance-note">Worked <?= (int)floor(($end - $start) / 3600) ?>h <?= (int)(floor(($end - $start) / 60) % 60) ?>m today.</p>
          <?php endif; ?>
          <p class="fleet-dash-attendance-month"><?= $attendanceDays ?> recorded work day<?= $attendanceDays === 1 ? '' : 's' ?> this month</p>
          <div id="attendanceFeedback" class="fleet-dash-feedback" role="status" aria-live="polite"></div>
        </div>
      <?php endif; ?>
    </article>

    <?php if ($employee && preg_match('/driver|helper/i', (string)$employee['position'])): ?>
    <article class="fleet-dash-panel">
      <div class="fleet-dash-panel-heading">
        <div><span class="fleet-dash-panel-icon"><i class="bi bi-truck"></i></span><h2>My Work Summary</h2></div>
        <?php if ($canTrips): ?><a href="<?= APP_BASE ?>/pages/trip_monitor.php">Trip monitoring</a><?php endif; ?>
      </div>
      <?php if (is_array($personalTripSummary)): ?>
      <div class="fleet-dash-personal-stats">
        <div><strong><?= (int)$personalTripSummary['completed'] ?></strong><span>Completed trips</span></div>
        <div><strong><?= (int)$personalTripSummary['on_time'] ?></strong><span>On-time completions</span></div>
      </div>
      <?php endif; ?>
      <?php if (is_array($personalTrips) && $personalTrips): ?>
        <ul class="fleet-dash-list">
          <?php foreach ($personalTrips as $trip): ?>
          <li>
            <span class="fleet-dash-list-icon"><i class="bi bi-geo-alt"></i></span>
            <span class="fleet-dash-list-copy">
              <strong><?= htmlspecialchars($trip['trip_number']) ?> · <?= htmlspecialchars($trip['plate_number']) ?></strong>
              <span><?= htmlspecialchars($trip['origin'] . ' → ' . $trip['destination']) ?></span>
            </span>
            <span class="fleet-dash-status"><?= htmlspecialchars($trip['status']) ?><?= (int)$trip['is_late'] === 1 ? ' · Late' : '' ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php elseif ($personalTrips === null): ?>
        <p class="fleet-dash-empty">Assigned trip activity is temporarily unavailable.</p>
      <?php else: ?>
        <p class="fleet-dash-empty">No assigned work yet.</p>
      <?php endif; ?>
    </article>
    <?php elseif ($canApprovals || $canPurchasing || $canMaintenance || $canBilling || $canPayroll || $canFinance): ?>
    <article class="fleet-dash-panel">
      <div class="fleet-dash-panel-heading">
        <div><span class="fleet-dash-panel-icon"><i class="bi bi-list-check"></i></span><h2>Today's Priorities</h2></div>
      </div>
      <ul class="fleet-dash-priorities">
        <?php if ($canApprovals): ?><li><i class="bi bi-inbox"></i><span>Review pending approvals</span><a href="<?= APP_BASE ?>/pages/requests.php">Open</a></li><?php endif; ?>
        <?php if ($canPurchasing): ?><li><i class="bi bi-cart-check"></i><span>Check purchase orders awaiting action</span><a href="<?= APP_BASE ?>/pages/purchasing.php">Open</a></li><?php endif; ?>
        <?php if ($canMaintenance): ?><li><i class="bi bi-tools"></i><span>Review open maintenance work</span><a href="<?= APP_BASE ?>/pages/maintenance.php">Open</a></li><?php endif; ?>
        <?php if ($canBilling): ?><li><i class="bi bi-receipt"></i><span>Review invoices and collections</span><a href="<?= APP_BASE ?>/pages/billing.php">Open</a></li><?php endif; ?>
        <?php if ($canPayroll): ?><li><i class="bi bi-cash-stack"></i><span>Review payroll activity</span><a href="<?= APP_BASE ?>/pages/payroll.php">Open</a></li><?php endif; ?>
        <?php if ($canFunds || $canDisbursements || $canPayables): ?><li><i class="bi bi-wallet2"></i><span>Review finance work and payment status</span><a href="<?= APP_BASE ?>/pages/finance.php">Open</a></li><?php endif; ?>
      </ul>
    </article>
    <?php endif; ?>
  </section>

  <section class="fleet-dash-panel fleet-dash-recent-activity">
    <div class="fleet-dash-panel-heading">
      <div><span class="fleet-dash-panel-icon"><i class="bi bi-activity"></i></span><h2>My Recent Activity</h2></div>
    </div>
    <?php if ($personalActivity === null): ?><p class="fleet-dash-empty">Personal activity is temporarily unavailable.</p>
    <?php elseif (!$personalActivity): ?><p class="fleet-dash-empty">No recent personal activity yet.</p>
    <?php else: ?><ul class="fleet-dash-priorities">
      <?php foreach ($personalActivity as $activity): ?>
      <li><i class="bi bi-check2-circle"></i><span><?= htmlspecialchars($activity['action'] . ' · ' . $activity['table_name']) ?></span><time><?= htmlspecialchars(date('M j, g:i A', strtotime($activity['logged_at']))) ?></time></li>
      <?php endforeach; ?>
    </ul><?php endif; ?>
  </section>

  <?php if ($employee && !preg_match('/driver|helper/i', (string)$employee['position']) && !$recentTrips && !$stats): ?>
    <section class="fleet-dash-panel fleet-dash-empty-panel"><i class="bi bi-check2-circle"></i><p>No assigned work yet. Your dashboard will show activity as it is recorded.</p></section>
  <?php elseif ($canTrips && (!$employee || !preg_match('/driver|helper/i', (string)$employee['position']))): ?>
    <section class="fleet-dash-panel fleet-dash-work">
      <div class="fleet-dash-panel-heading">
        <div><span class="fleet-dash-panel-icon"><i class="bi bi-map"></i></span><h2>Active Trips</h2></div>
        <a href="<?= APP_BASE ?>/pages/trip_monitor.php">Trip monitoring</a>
      </div>
      <?php if ($recentTrips === null): ?><p class="fleet-dash-empty">Trip activity is temporarily unavailable.</p>
      <?php elseif (!$recentTrips): ?><p class="fleet-dash-empty">No active trips right now.</p>
      <?php else: ?><div class="table-responsive"><table class="table-custom">
        <thead><tr><th>Trip</th><th>Truck</th><th>Route</th><th>Status</th><th>Updated</th></tr></thead><tbody>
        <?php foreach ($recentTrips as $trip): ?><tr>
          <td><?= htmlspecialchars($trip['trip_number']) ?></td><td><?= htmlspecialchars($trip['plate_number']) ?></td>
          <td><?= htmlspecialchars($trip['origin'] . ' → ' . $trip['destination']) ?></td>
          <td><?= htmlspecialchars($trip['status']) ?><?= (int)$trip['is_late'] === 1 ? ' · Late' : '' ?></td>
          <td><?= $trip['updated_at'] ? date('M j, g:i A', strtotime($trip['updated_at'])) : '—' ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
  <?php endif; ?>
</div>

<?php if ($employee): ?>
<script src="<?= APP_BASE ?>/assets/js/dashboard_attendance.js?v=<?= (int)filemtime(__DIR__ . '/../assets/js/dashboard_attendance.js') ?>"></script>
<?php endif; ?>
<?php layoutFoot(); ?>
