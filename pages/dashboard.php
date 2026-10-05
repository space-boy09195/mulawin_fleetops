<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';
require_once __DIR__ . '/../includes/employee_profile.php';
require_once __DIR__ . '/../includes/trip_completion.php';

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

$employeeLookupFailed = false;
try {
    $employee = employeeForCurrentUser($pdo);
} catch (PDOException $e) {
    error_log('Dashboard employee lookup failed: ' . $e->getMessage());
    $employee = null;
    $employeeLookupFailed = true;
}

$attendance = null;
$attendanceStale = false;
$attendanceDays = null;
$personalTrips = null;
$personalTripSummary = null;
$currentAssignment = null;
if ($employee) {
    $attendanceQuery = $pdo->prepare(
        'SELECT attendance_date, status, time_in, time_out, overtime_minutes
         FROM employee_attendance
         WHERE employee_id = ? AND (
             attendance_date = ? OR
             (time_in IS NOT NULL AND time_out IS NULL)
         )
         ORDER BY (time_in IS NOT NULL AND time_out IS NULL) DESC, attendance_date DESC LIMIT 1'
    );
    $attendanceQuery->execute([
        (int)$employee['employee_id'],
        date('Y-m-d'),
    ]);
    $attendance = $attendanceQuery->fetch(PDO::FETCH_ASSOC) ?: null;
    $attendanceStale = $attendance && $attendance['time_in'] && !$attendance['time_out']
        && (time() - strtotime($attendance['attendance_date'] . ' ' . $attendance['time_in'])) > 16 * 3600;

    $attendanceDaysQuery = $pdo->prepare(
        "SELECT COUNT(DISTINCT attendance_date)
         FROM employee_attendance
         WHERE employee_id = ? AND attendance_date >= ?
           AND status IN ('Present','On Duty')"
    );
    $attendanceDaysQuery->execute([(int)$employee['employee_id'], date('Y-m-01')]);
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

    if (preg_match('/driver/i', (string)$employee['position'])) {
        $currentAssignment = fleetDashboardQuery(static function () use ($pdo, $employeeId): ?array {
            $stmt = $pdo->prepare(
                "SELECT t.trip_id, t.trip_number, t.status, t.expected_arrival, t.is_late,
                        dr.client_name, dr.scheduled_at,
                        r.origin, r.destination, tr.plate_number,
                        ccr.report_id, ccr.reported_at, ccr.status AS completion_report_status
                 FROM dispatch_requests dr
                 JOIN trips t ON t.dispatch_id = dr.dispatch_id
                 JOIN routes r ON r.route_id = dr.route_id
                 JOIN trucks tr ON tr.truck_id = dr.truck_id
                 WHERE (dr.driver_id = ? OR dr.second_driver_id = ?)
                   AND t.status NOT IN ('Completed','Cancelled')
                 ORDER BY COALESCE(dr.scheduled_at, t.created_at) ASC, t.trip_id ASC
                 LIMIT 1"
            );
            $stmt->execute([$employeeId, $employeeId]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($assignment) {
                $report = findActiveTripCompletionReport($pdo, (int)$assignment['trip_id']);
                $assignment['report_id'] = $report['report_id'] ?? null;
                $assignment['reported_at'] = $report['reported_at'] ?? null;
                $assignment['completion_report_status'] = $report['status'] ?? null;
            }
            return $assignment;
        });
    }
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
        "SELECT action, table_name, logged_at
         FROM audit_logs
         WHERE user_id = ? AND action NOT IN ('LOGIN', 'LOGOUT')
         ORDER BY logged_at DESC LIMIT 5"
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
      <p class="fleet-dash-eyebrow">Employee dashboard · System role: <?= htmlspecialchars($roleName) ?></p>
      <h1><?= htmlspecialchars($greeting . ($fullName !== '' ? ', ' . $fullName : '')) ?></h1>
      <p><?= htmlspecialchars($subtitle) ?></p>
      <?php if ($employee): ?>
      <p class="fleet-dash-employee-meta">
        Position: <?= htmlspecialchars($employee['position']) ?>
        · Employment type: <?= htmlspecialchars($employee['employment_type']) ?>
      </p>
      <?php endif; ?>
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
        <a href="<?= APP_BASE ?>/pages/attendance.php">Attendance details</a>
      </div>
      <?php if (!$employee): ?>
        <p class="fleet-dash-empty" role="status"><?php if ($employeeLookupFailed): ?>
          Employee profile data is temporarily unavailable. Please try again later.
        <?php else: ?>
          No unique active employee record is linked to system account #<?= currentUserId() ?>.
          Ask HR or an administrator to link the existing employee record; this page does not create employee data.
        <?php endif; ?></p>
      <?php else: ?>
        <div id="dashboardAttendance" data-dashboard-attendance data-feedback="attendanceFeedback" data-elapsed="dashboardElapsed"
             data-start="<?= $attendance && $attendance['time_in'] && !$attendance['time_out'] ? (int)(strtotime($attendance['attendance_date'] . ' ' . $attendance['time_in']) * 1000) : '' ?>">
          <div data-attendance-display>
          <?php if ($attendanceStale): ?>
            <p class="fleet-dash-attendance-state text-danger" data-attendance-state>Earlier attendance record is still open.</p>
            <p class="fleet-dash-attendance-note" data-attendance-note>Your Time In on <strong><?= htmlspecialchars($attendance['attendance_date']) ?></strong> at <?= htmlspecialchars(date('g:i A', strtotime($attendance['time_in']))) ?> has no Time Out and is past the 16-hour shift limit. Time In is blocked until HR or an administrator corrects that record.</p>
          <?php elseif (!$attendance || !$attendance['time_in'] || ($attendance['attendance_date'] !== date('Y-m-d') && $attendance['time_out'])): ?>
            <p class="fleet-dash-attendance-state" data-attendance-state>No attendance record for today.</p>
            <button class="btn btn-primary" type="button" data-attendance-action="clock_in">Time In</button>
          <?php elseif (!$attendance['time_out']): ?>
            <p class="fleet-dash-attendance-state" data-attendance-state>Timed in at <strong><?= htmlspecialchars(date('g:i A', strtotime($attendance['time_in']))) ?></strong></p>
            <p class="fleet-dash-attendance-note" data-attendance-note>Your shift is in progress <span id="dashboardElapsed"></span>.</p>
            <button class="btn btn-outline-primary" type="button" data-attendance-action="clock_out">Time Out</button>
          <?php else: ?>
            <?php
              $start = strtotime($attendance['attendance_date'] . ' ' . $attendance['time_in']);
              $end = strtotime($attendance['attendance_date'] . ' ' . $attendance['time_out']);
              if ($end < $start) $end += 86400;
            ?>
            <p class="fleet-dash-attendance-state" data-attendance-state>Timed in <strong><?= htmlspecialchars(date('g:i A', $start)) ?></strong> &middot; timed out <strong><?= htmlspecialchars(date('g:i A', $end)) ?></strong></p>
            <p class="fleet-dash-attendance-note" data-attendance-note>Worked <?= (int)floor(($end - $start) / 3600) ?>h <?= (int)(floor(($end - $start) / 60) % 60) ?>m today.</p>
          <?php endif; ?>
          </div>
          <p class="fleet-dash-attendance-month" data-attendance-days><?= $attendanceDays ?> recorded work day<?= $attendanceDays === 1 ? '' : 's' ?> this month</p>
          <div id="attendanceFeedback" class="fleet-dash-feedback" role="status" aria-live="polite"></div>
        </div>
      <?php endif; ?>
    </article>

    <?php if ($employee && preg_match('/driver/i', (string)$employee['position'])): ?>
    <article class="fleet-dash-panel fleet-dash-assignment-panel">
      <div class="fleet-dash-panel-heading">
        <div><span class="fleet-dash-panel-icon"><i class="bi bi-signpost-2"></i></span><h2>My Current Assignment</h2></div>
        <?php if ($currentAssignment): ?><span class="fleet-dash-live"><i class="bi bi-circle-fill"></i> Active</span><?php endif; ?>
      </div>
      <?php if ($currentAssignment): ?>
        <div class="fleet-dash-assignment">
          <div class="fleet-dash-assignment-main">
            <div>
              <span class="fleet-dash-kicker">Trip</span>
              <strong><?= htmlspecialchars($currentAssignment['trip_number']) ?></strong>
            </div>
            <span class="fleet-dash-status"><?= htmlspecialchars($currentAssignment['status']) ?><?= (int)$currentAssignment['is_late'] === 1 ? ' · Late' : '' ?></span>
          </div>
          <div class="fleet-dash-assignment-route">
            <span><i class="bi bi-geo-alt"></i><?= htmlspecialchars($currentAssignment['origin']) ?></span>
            <i class="bi bi-arrow-right"></i>
            <span><i class="bi bi-flag"></i><?= htmlspecialchars($currentAssignment['destination']) ?></span>
          </div>
          <div class="fleet-dash-assignment-meta">
            <?php if (!empty($currentAssignment['client_name'])): ?><span><i class="bi bi-building"></i><?= htmlspecialchars($currentAssignment['client_name']) ?></span><?php endif; ?>
            <span><i class="bi bi-truck"></i><?= htmlspecialchars($currentAssignment['plate_number']) ?></span>
            <?php if (!empty($currentAssignment['scheduled_at'])): ?><span><i class="bi bi-calendar-event"></i> Scheduled <?= htmlspecialchars(date('M j, g:i A', strtotime($currentAssignment['scheduled_at']))) ?></span><?php endif; ?>
            <?php if (!empty($currentAssignment['expected_arrival'])): ?><span><i class="bi bi-clock"></i> ETA <?= htmlspecialchars(date('M j, g:i A', strtotime($currentAssignment['expected_arrival']))) ?></span><?php endif; ?>
          </div>
          <?php if (!empty($currentAssignment['report_id'])): ?>
            <div class="fleet-dash-completion-reported">
              <i class="bi bi-check2-circle"></i>
              <div><strong>Completion Reported</strong><span>Operations has been notified. The trip still needs its official post-trip completion.</span>
                <small>Reported <?= htmlspecialchars(date('M j, g:i A', strtotime($currentAssignment['reported_at']))) ?></small>
              </div>
            </div>
          <?php else: ?>
            <button type="button" class="btn btn-primary fleet-dash-complete-btn" data-report-trip-completed
                    data-trip-id="<?= (int)$currentAssignment['trip_id'] ?>"
                    data-trip-number="<?= htmlspecialchars($currentAssignment['trip_number'], ENT_QUOTES) ?>">
              <i class="bi bi-check2-circle me-1"></i> Report Trip Completed
            </button>
            <p class="fleet-dash-action-note">This notifies the post-trip recorder. It does not change the official trip status.</p>
          <?php endif; ?>
          <div id="tripCompletionFeedback" class="fleet-dash-feedback" role="status" aria-live="polite"></div>
        </div>
      <?php else: ?>
        <div class="fleet-dash-empty fleet-dash-assignment-empty"><i class="bi bi-truck"></i><span>No active trip is assigned to you right now.</span></div>
      <?php endif; ?>
    </article>

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

<script src="<?= APP_BASE ?>/assets/js/dashboard_attendance.js?v=<?= (int)filemtime(__DIR__ . '/../assets/js/dashboard_attendance.js') ?>"></script>
<?php if ($employee && preg_match('/driver/i', (string)$employee['position']) && $currentAssignment): ?>
<script src="<?= APP_BASE ?>/assets/js/dashboard_trip_completion.js?v=<?= (int)filemtime(__DIR__ . '/../assets/js/dashboard_trip_completion.js') ?>"></script>
<?php endif; ?>
<?php layoutFoot(); ?>
