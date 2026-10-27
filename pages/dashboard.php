<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';
require_once __DIR__ . '/../includes/employee_profile.php';
require_once __DIR__ . '/../includes/dashboard_metrics.php';
require_once __DIR__ . '/../includes/trip_queries.php';
require_once __DIR__ . '/../includes/trip_completion.php';
require_once __DIR__ . '/../includes/home_tasks.php';

requireLogin();

$pdo = getDBConnection();
$GLOBALS['page_needs_chart'] = false;
$roleName = (string)($_SESSION['role_name'] ?? 'Account');
$canTrips = currentUserHasAnyPermission(['trips.view']);
$canIncidents = currentUserHasAnyPermission(['incidents.manage']);
$canMaintenance = currentUserHasAnyPermission(['maintenance.view']);
$canParts = currentUserHasAnyPermission(['parts.view']);
$canApprovals = currentUserHasAnyPermission(['approvals.review']);
$canDispatchQueue = currentUserHasAnyPermission(['dispatch.instructions.encode', 'dispatch.instructions.manage']);
$canPurchasing = currentUserHasAnyPermission(['purchasing.view']);
$isDispatcher = dispatcherScopeResult()['status'] !== 'none';
$dispatcherScope = $isDispatcher ? dispatcherScope() : [];
$employee = employeeForCurrentUser($pdo);
$roleKey = currentRoleKey();
$isCrew = in_array($roleKey, ['driver', 'helper'], true);
$canTrips = $canTrips && (!$isCrew || $employee !== null);
$canViewAssignedTrips = $isCrew && $employee !== null;
$canViewTripList = $isCrew ? $canViewAssignedTrips : $canTrips;
$userId = currentUserId();

$tripScopeSql = '';
$tripScopeParams = [];
if ($isDispatcher) {
    $tripScopeSql = ' AND t.trip_id IN (
        SELECT dr.trip_id FROM dispatch_requests dr WHERE dr.submitted_by = ?
    )';
    $tripScopeParams[] = $userId;
    if (!empty($dispatcherScope['truck_types'])) {
        $tripScopeSql .= ' AND tr.truck_type IN (' . implode(',', array_fill(0, count($dispatcherScope['truck_types']), '?')) . ')';
        $tripScopeParams = array_merge($tripScopeParams, $dispatcherScope['truck_types']);
    }
    if (!empty($dispatcherScope['shift']) || !empty($dispatcherScope['denied'])) {
        $tripScopeSql .= ' AND t.shift = ?';
        $tripScopeParams[] = (string)($dispatcherScope['shift'] ?? '');
    }
}
if ($isCrew && $employee !== null) {
    if ($roleKey === 'driver') {
        $tripScopeSql .= ' AND (t.driver_id = ? OR t.second_driver_id = ?)';
        $tripScopeParams[] = (int)$employee['employee_id'];
        $tripScopeParams[] = (int)$employee['employee_id'];
    } else {
        $tripScopeSql .= ' AND t.helper_id = ?';
        $tripScopeParams[] = (int)$employee['employee_id'];
    }
}

$alerts = [];
$alertsUnavailable = false;
$loadAlerts = static function (string $source, callable $load) use (&$alertsUnavailable, &$alerts): void {
    try {
        $alerts = array_merge($alerts, $load());
    } catch (PDOException $e) {
        error_log('Home alert query failed (' . $source . '): ' . $e->getMessage());
        $alertsUnavailable = true;
    }
};

if ($canTrips) {
    $loadAlerts('past ETA', static function () use ($pdo, $tripScopeSql, $tripScopeParams, $roleKey): array {
        $stmt = $pdo->prepare(
            tripSelectSql() . "
             WHERE " . tripOverdueSql('t') . $tripScopeSql . "
             ORDER BY t.scheduled_arrival ASC LIMIT 5"
        );
        $stmt->execute($tripScopeParams);
        return array_map(static fn(array $trip): array => [
            'trip_id' => (int)$trip['trip_id'],
            'title' => 'Past ETA',
            'detail' => $trip['trip_number'] . ' · ' . ($trip['plate_number'] ?? 'No truck'),
            'url' => in_array($roleKey, ['driver', 'helper'], true)
                ? APP_BASE . '/pages/trip_workflow.php?trip_id=' . (int)$trip['trip_id']
                : APP_BASE . '/pages/trip_monitor.php',
            'tone' => 'warning',
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    });
}

if ($canIncidents) {
    $loadAlerts('open incidents', static function () use ($pdo): array {
        return array_map(static fn(array $incident): array => [
            'title' => 'Open incident: ' . $incident['incident_type'],
            'detail' => $incident['trip_number'] . ' · ' . $incident['severity'],
            'url' => APP_BASE . '/pages/incidents.php',
            'tone' => 'danger',
        ], dashboardOpenIncidents($pdo, 5));
    });
}

if ($canMaintenance) {
    $loadAlerts('maintenance due', static function () use ($pdo): array {
        return array_map(static fn(array $item): array => [
            'title' => 'Maintenance due',
            'detail' => $item['plate_number'] . ' · ' . $item['label'] . ' · ' . $item['next_due_date'],
            'url' => APP_BASE . '/pages/maintenance_records.php',
            'tone' => 'warning',
        ], dashboardMaintenanceDue($pdo, 7, 5));
    });
}

if ($canParts) {
    $loadAlerts('low stock', static function () use ($pdo): array {
        return array_map(static fn(array $part): array => [
            'title' => 'Reorder level reached',
            'detail' => $part['part_name'] . ' · ' . $part['quantity'] . ' / ' . $part['reorder_level'] . ' ' . $part['unit'],
            'url' => APP_BASE . '/pages/parts.php',
            'tone' => 'info',
        ], dashboardLowStockParts($pdo, 5));
    });
}

$pendingPurchaseRequests = null;
if ($canPurchasing) {
    $loadAlerts('purchase requests', static function () use ($pdo, &$pendingPurchaseRequests): array {
        $pendingPurchaseRequests = dashboardCount($pdo, "
            SELECT COUNT(*)
            FROM purchase_orders po
            JOIN suppliers s ON s.supplier_id = po.supplier_id
            LEFT JOIN purchase_requests pr ON pr.purchase_request_id = po.purchase_request_id
            WHERE pr.status IN ('Pending Approval', 'Rejected')
        ");
        $stmt = $pdo->query("
            SELECT po.po_number, pr.status, s.supplier_name
            FROM purchase_orders po
            JOIN suppliers s ON s.supplier_id = po.supplier_id
            JOIN purchase_requests pr ON pr.purchase_request_id = po.purchase_request_id
            WHERE pr.status IN ('Pending Approval', 'Rejected')
            ORDER BY po.created_at DESC
            LIMIT 5
        ");
        return array_map(static fn(array $request): array => [
            'title' => 'Purchase request ' . $request['po_number'],
            'detail' => $request['supplier_name'] . ' · ' . $request['status'],
            'url' => APP_BASE . '/pages/purchasing.php',
            'tone' => 'info',
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    });
}

if ($canApprovals) {
    $loadAlerts('pending approvals', static function () use ($pdo): array {
        $pending = dashboardPendingApprovalCount($pdo, isAdmin(), currentRoleId());
        return $pending > 0 ? [[
            'title' => 'Awaiting your approval',
            'detail' => $pending . ' request' . ($pending === 1 ? '' : 's'),
            'url' => APP_BASE . '/pages/requests.php',
            'tone' => 'info',
        ]] : [];
    });
}

$pendingDispatches = null;
$dispatchDestination = currentUserHasAnyPermission(['trips.view'])
    ? '/pages/dispatch.php'
    : (currentUserHasAnyPermission(['dispatch.instructions.manage']) ? '/pages/dispatch_planning.php' : '/pages/dispatch_inbox.php');
if ($canDispatchQueue) {
    $loadAlerts('pending dispatch', static function () use ($pdo, $tripScopeSql, $tripScopeParams, &$pendingDispatches, $dispatchDestination): array {
        $pendingDispatches = dashboardPendingDispatchCount($pdo, $tripScopeSql, $tripScopeParams);
        return $pendingDispatches > 0 ? [[
            'title' => 'Dispatch requests awaiting review',
            'detail' => $pendingDispatches . ' pending request' . ($pendingDispatches === 1 ? '' : 's'),
            'url' => APP_BASE . $dispatchDestination,
            'tone' => 'info',
        ]] : [];
    });
}

// ── Work waiting for this user (includes/home_tasks.php) ─────────────────────
// Built from the current workflow data on every load, so finished or
// cancelled work drops out. Admin acts on others' work only as an
// override, so Admin gets no task items (approvals stay in the alert above).
$homeTasks = [];
$isAdminViewer = isAdmin();
$canInspectTrips = !$isAdminViewer && $roleKey !== 'operations_head'
    && currentUserHasAnyPermission(['maintenance.manage']);
$canClearTrips = !$isAdminViewer && currentUserHasAnyPermission(['dispatch.clear']);
$canOwnTripSteps = !$isAdminViewer && currentUserHasAnyPermission(['trips.assign', 'trips.update']);
$loadTasks = static function (string $source, callable $load) use (&$alertsUnavailable, &$homeTasks): void {
    try {
        $homeTasks = array_merge($homeTasks, $load());
    } catch (PDOException $e) {
        error_log('Home task query failed (' . $source . '): ' . $e->getMessage());
        $alertsUnavailable = true;
    }
};

// Trips whose next workflow step belongs to this user: their own trips
// (dispatcher), trips waiting for clearance (Operations Head), and trips
// waiting for an inspection (Maintenance). tripHomeTask() keeps only the
// steps this user can do now.
$taskConditions = [];
$taskParams = [];
if ($canOwnTripSteps) {
    $taskConditions[] = 'dr.submitted_by = ?';
    $taskParams[] = $userId;
}
if ($canClearTrips) {
    $taskConditions[] = "t.status = 'Ready for Dispatch'";
}
if ($canInspectTrips) {
    $taskConditions[] = "t.status IN ('Assigned', 'Delivered')";
}
if ($taskConditions) {
    $loadTasks('trip tasks', static function () use ($pdo, $taskConditions, $taskParams, $userId, $canClearTrips, $canInspectTrips): array {
        $stmt = $pdo->prepare(
            tripSelectSql() . "
             WHERE t.status IN ('Approved', 'Assigned', 'Ready for Dispatch', 'Dispatched', 'In Progress', 'Delivered', 'Returned')
               AND dr.dispatch_request_id IS NOT NULL
               AND (" . implode(' OR ', $taskConditions) . ")
             ORDER BY t.scheduled_departure ASC
             LIMIT 100"
        );
        $stmt->execute($taskParams);
        $facts = [
            'is_admin'    => false,
            'can_assign'  => currentUserHasAnyPermission(['trips.assign']),
            'can_update'  => currentUserHasAnyPermission(['trips.update']),
            'can_clear'   => $canClearTrips,
            'can_inspect' => $canInspectTrips,
        ];
        $tasks = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $trip) {
            $task = tripHomeTask(
                $trip,
                tripWorkflowCurrentStep($pdo, (int)$trip['trip_id']),
                $facts + ['is_owner' => (int)$trip['requested_by'] === $userId],
                time()
            );
            if ($task !== null) {
                $tasks[] = $task;
            }
        }
        return $tasks;
    });
}

// Open dispatch instructions for this dispatcher's truck types (the same
// rule as the Dispatcher Queue).
$instructionScope = dispatcherScopeResult();
if (!$isAdminViewer && $instructionScope['status'] === 'scoped'
    && currentUserHasAnyPermission(['dispatch.instructions.encode'])) {
    $loadTasks('dispatch instructions', static function () use ($pdo, $instructionScope): array {
        $types = $instructionScope['truck_types'];
        $stmt = $pdo->prepare(
            "SELECT booking_id, booking_reference, truck_type, shift, scheduled_departure
             FROM client_bookings
             WHERE status = 'Pending' AND scheduled_departure IS NOT NULL
               AND truck_type IN (" . implode(',', array_fill(0, count($types), '?')) . ")
             ORDER BY scheduled_departure ASC
             LIMIT 20"
        );
        $stmt->execute($types);
        return array_map(static fn(array $row): array => dispatchInstructionHomeTask($row, time()), $stmt->fetchAll(PDO::FETCH_ASSOC));
    });
}

// Approved fund requests waiting for Finance to record the disbursement
// (the same rule as the Finance page's "Record disbursement" list).
if (!$isAdminViewer && currentUserHasAnyPermission(['finance.disbursements.manage'])) {
    $loadTasks('disbursements', static function () use ($pdo): array {
        $rows = $pdo->query(
            "SELECT * FROM (
               SELECT fr.request_number, fr.request_type,
                      fr.amount - COALESCE((SELECT SUM(d.amount) FROM disbursements d
                                            WHERE d.reference_type = 'fund_request' AND d.reference_id = fr.fund_request_id), 0) AS remaining,
                      t.trip_number, t.status AS trip_status, t.scheduled_departure
               FROM fund_requests fr
               LEFT JOIN trips t ON t.trip_id = fr.trip_id
               WHERE fr.status IN ('Approved', 'Processing')
             ) eligible
             WHERE remaining > 0
             ORDER BY scheduled_departure IS NULL, scheduled_departure ASC
             LIMIT 20"
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $row): array => fundDisbursementHomeTask($row, time()), $rows);
    });
}

// Tasks come first (urgent ones on top). A trip with a task is not listed
// again as a plain "Past ETA" alert.
$homeTasks = sortHomeTasks($homeTasks);
$taskTripIds = array_filter(array_column($homeTasks, 'trip_id'));
$alerts = array_values(array_filter($alerts, static fn(array $alert): bool =>
    !isset($alert['trip_id']) || !in_array($alert['trip_id'], $taskTripIds, true)));
$alerts = array_merge(array_map(static fn(array $task): array => [
    'title'  => $task['title'],
    'detail' => $task['detail'],
    'url'    => APP_BASE . $task['url'],
    'tone'   => $task['urgent'] ? 'danger' : 'info',
    'urgent' => $task['urgent'],
], $homeTasks), $alerts);

$activeTrips = null;
if ($canViewTripList) {
    $crewStatuses = array_merge(['Approved'], TRIP_ACTIVE_STATUSES);
    $activeWhere = "t.status IN (" . metricSqlList($isCrew ? $crewStatuses : TRIP_ACTIVE_STATUSES) . ')';
    $activeParams = [];
    if ($isCrew) {
        $employeeId = (int)$employee['employee_id'];
        $activeWhere .= $roleKey === 'driver'
            ? ' AND (t.driver_id = ? OR t.second_driver_id = ?)'
            : ' AND t.helper_id = ?';
        $activeParams = $roleKey === 'driver'
            ? [$employeeId, $employeeId]
            : [$employeeId];
    }
    $activeParams = array_merge($activeParams, $tripScopeParams);
    $activeStmt = $pdo->prepare(
        tripSelectSql() . "
         WHERE $activeWhere $tripScopeSql
         ORDER BY " . tripOverdueSql('t') . " DESC, t.scheduled_departure ASC
         LIMIT 10"
    );
    $activeStmt->execute($activeParams);
    $activeTrips = $activeStmt->fetchAll(PDO::FETCH_ASSOC);
}

$canFleet = currentUserHasAnyPermission(['fleet.view']);
$canBilling = currentUserHasAnyPermission(['billing.view']);
$canPayroll = currentUserHasAnyPermission(['payroll.view']);
$canPayrollManage = currentUserHasAnyPermission(['payroll.manage']);
$canAudit = currentUserHasAnyPermission(['audit.view']);
$summaryCards = [];
$addSummaryCard = static function (string $key, string $label, string $value, string $detail, string $url, string $icon) use (&$summaryCards): void {
    $summaryCards[$key] = compact('label', 'value', 'detail', 'url', 'icon');
};

if ($canViewTripList) {
    $tripCount = $isCrew ? count($activeTrips ?? []) : dashboardActiveTripCount($pdo, $tripScopeSql, $tripScopeParams);
    $addSummaryCard('trips', $isCrew ? 'Assigned trips in view' : 'Active trips', (string)$tripCount, 'Current permitted trip records', $isCrew ? APP_BASE . '/pages/dashboard.php#home-active-trips-heading' : APP_BASE . '/pages/trip_monitor.php', 'bi-map');
}
if ($canDispatchQueue) {
    if ($pendingDispatches !== null) {
        $addSummaryCard('dispatch', 'Pending dispatch', (string)$pendingDispatches, 'Requests awaiting dispatch review', APP_BASE . $dispatchDestination, 'bi-send');
    }
}
if ($canApprovals) {
    $pendingApprovals = dashboardPendingApprovalCount($pdo, isAdmin(), currentRoleId());
    $addSummaryCard('approvals', 'Pending approvals', (string)$pendingApprovals, 'Requests assigned to your approval scope', APP_BASE . '/pages/requests.php', 'bi-inbox');
}
if ($canFleet) {
    $fleetCounts = dashboardFleetCounts($pdo);
    $addSummaryCard('fleet', 'Available trucks', (string)($fleetCounts['Available'] ?? 0), 'Fleet records marked available', APP_BASE . '/pages/fleet_status.php', 'bi-truck');
}
if ($canMaintenance) {
    $addSummaryCard('maintenance', 'Open maintenance', (string)dashboardOpenMaintenanceCount($pdo), 'Reported, scheduled, in-progress, or waiting', APP_BASE . '/pages/maintenance_records.php', 'bi-tools');
}
if ($canParts) {
    $addSummaryCard('stock', 'Low-stock parts', (string)dashboardLowStockCount($pdo), 'At or below recorded reorder level', APP_BASE . '/pages/parts.php', 'bi-box-seam');
}
if ($canBilling) {
    $receivables = dashboardReceivables($pdo);
    $addSummaryCard('receivables', 'Outstanding balances', '₱' . number_format($receivables['outstanding'], 2), 'Issued, partially paid, or overdue billing', APP_BASE . '/pages/billing.php', 'bi-receipt');
}
if ($canPayroll) {
    $payrollCount = (int)$pdo->query('SELECT COUNT(*) FROM payroll_records')->fetchColumn();
    $addSummaryCard('payroll', 'Recorded payroll entries', (string)$payrollCount, 'Existing payroll records', APP_BASE . '/pages/payroll.php', 'bi-cash-stack');
    $paidPayrollThisMonth = dashboardCount($pdo, "SELECT COUNT(*) FROM payroll_records pr
        WHERE " . payrollPaidDateSql('pr') . " >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND " . payrollPaidDateSql('pr') . " < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)");
    $addSummaryCard('payroll-current', 'Payroll paid this month', (string)$paidPayrollThisMonth, 'Recorded paid dates in the current month', APP_BASE . '/pages/payroll.php', 'bi-calendar-check');
    if ($canPayrollManage) {
        $employeeCount = dashboardCount($pdo, "SELECT COUNT(*) FROM employees
            WHERE employment_status = 'Active' AND (position IS NULL OR LOWER(position) NOT IN ('driver', 'helper'))");
        $addSummaryCard('employees', 'Active employee records', (string)$employeeCount, 'Employees included in payroll management', APP_BASE . '/pages/payroll.php', 'bi-people');
    }
}
if ($canPurchasing && $pendingPurchaseRequests !== null) {
    $addSummaryCard('purchasing', 'Purchase requests needing attention', (string)$pendingPurchaseRequests, 'Pending approval or rejected purchase requests', APP_BASE . '/pages/purchasing.php', 'bi-cart-check');
}
if ($canAudit && $roleKey !== 'admin') {
    $recentAuditCount = dashboardCount($pdo, "SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $recentApprovalCount = dashboardCount($pdo, "SELECT COUNT(*) FROM approval_history WHERE acted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $recentWorkflowCount = dashboardCount($pdo, "SELECT COUNT(*) FROM workflow_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $addSummaryCard('audit-records', 'Record changes · 7 days', (string)$recentAuditCount, 'Read-only audit activity', APP_BASE . '/pages/audit_history.php', 'bi-journal-text');
    $addSummaryCard('audit-approvals', 'Approval decisions · 7 days', (string)$recentApprovalCount, 'Read-only approval history', APP_BASE . '/pages/audit_history.php', 'bi-check2-square');
    $addSummaryCard('audit-workflows', 'Workflow events · 7 days', (string)$recentWorkflowCount, 'Read-only workflow history', APP_BASE . '/pages/audit_history.php', 'bi-clock-history');
}

$summaryPriority = match ($roleKey) {
    'admin' => ['trips', 'fleet', 'approvals', 'receivables', 'dispatch', 'maintenance', 'stock'],
    'operations_head' => ['trips', 'dispatch', 'maintenance', 'fleet', 'approvals'],
    'car_carrier_dispatcher', 'container_wing_van_dispatcher' => ['trips', 'dispatch', 'fleet', 'approvals'],
    'maintenance' => ['maintenance', 'fleet', 'stock', 'trips'],
    'purchasing_officer' => ['purchasing', 'stock', 'approvals', 'fleet'],
    'finance' => ['receivables', 'approvals', 'payroll', 'trips'],
    'billing_collection' => ['receivables', 'approvals', 'trips', 'fleet'],
    'payroll' => ['payroll-current', 'payroll', 'employees', 'approvals'],
    'audit' => ['audit-records', 'audit-approvals', 'audit-workflows'],
    'driver', 'helper' => ['trips', 'approvals'],
    default => array_keys($summaryCards),
};
uksort($summaryCards, static function (string $left, string $right) use ($summaryPriority): int {
    $leftRank = array_search($left, $summaryPriority, true);
    $rightRank = array_search($right, $summaryPriority, true);
    return ($leftRank === false ? PHP_INT_MAX : $leftRank) <=> ($rightRank === false ? PHP_INT_MAX : $rightRank);
});
$summaryCards = array_slice($summaryCards, 0, 4, true);

$quickActionDefinitions = [
    ['label' => 'Trip monitoring', 'url' => '/pages/trip_monitor.php', 'permission' => 'trips.view', 'icon' => 'bi-map'],
    ['label' => 'Dispatch workspace', 'url' => '/pages/dispatch.php', 'permission' => 'trips.view', 'icon' => 'bi-send'],
    ['label' => 'Dispatch planning', 'url' => '/pages/dispatch_planning.php', 'permission' => 'dispatch.instructions.manage', 'icon' => 'bi-list-check'],
    ['label' => 'Fleet status', 'url' => '/pages/fleet_status.php', 'permission' => 'fleet.view', 'icon' => 'bi-truck'],
    ['label' => 'Requests & approvals', 'url' => '/pages/requests.php', 'permission' => 'approvals.review', 'icon' => 'bi-inbox'],
    ['label' => 'Maintenance', 'url' => '/pages/maintenance.php', 'permission' => 'maintenance.view', 'icon' => 'bi-tools'],
    ['label' => 'Parts inventory', 'url' => '/pages/parts.php', 'permission' => 'parts.view', 'icon' => 'bi-box-seam'],
    ['label' => 'Purchasing', 'url' => '/pages/purchasing.php', 'permission' => 'purchasing.view', 'icon' => 'bi-cart-check'],
    ['label' => 'Finance', 'url' => '/pages/finance.php', 'permissions' => ['finance.funds.view', 'finance.disbursements.view', 'finance.ap.view'], 'icon' => 'bi-wallet2'],
    ['label' => 'Payroll', 'url' => '/pages/payroll.php', 'permission' => 'payroll.view', 'icon' => 'bi-cash-stack'],
    ['label' => 'Billing & collections', 'url' => '/pages/billing.php', 'permission' => 'billing.view', 'icon' => 'bi-receipt'],
    ['label' => 'Audit history', 'url' => '/pages/audit_history.php', 'permission' => 'audit.view', 'icon' => 'bi-journal-text'],
    ['label' => 'Analytics', 'url' => '/pages/analytics.php', 'permissions' => ['reports.view', 'operations.reports.view', 'maintenance.reports.view', 'billing.view'], 'icon' => 'bi-graph-up-arrow'],
];
$quickActions = [];
if ($isCrew) {
    if ($canViewAssignedTrips) {
        $quickActions[] = [
            'label' => 'My assigned trips',
            'url' => '/pages/dashboard.php#home-active-trips-heading',
            'icon' => 'bi-map',
        ];
    }
} else {
    foreach ($quickActionDefinitions as $action) {
        $permitted = isset($action['permission'])
            ? currentUserHasAnyPermission([$action['permission']])
            : currentUserHasAnyPermission($action['permissions']);
        if ($permitted) {
            $quickActions[] = $action;
        }
    }
}

$auditActivity = [];
if ($canAudit && $roleKey === 'audit') {
    $auditActivity = $pdo->query(
        "SELECT a.action, a.entity_type, a.entity_id, a.created_at,
                COALESCE(e.full_name, u.username, 'System') AS actor_name
         FROM audit_logs a
         LEFT JOIN users u ON u.user_id = a.user_id
         LEFT JOIN employees e ON e.employee_id = u.employee_id
         ORDER BY a.created_at DESC, a.audit_id DESC
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
}

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$fullName = trim((string)($_SESSION['full_name'] ?? ''));
layoutHead('Home', APP_BASE . '/assets/css/dashboard.css');
?>
<div class="fleet-dash">
  <header class="fleet-dash-header">
    <div>
      <p class="fleet-dash-eyebrow">Mulawin FleetOps · <?= htmlspecialchars($roleName) ?></p>
      <h1><?= htmlspecialchars($greeting . ($fullName !== '' ? ', ' . $fullName : '')) ?></h1>
      <p>Here is the work and activity relevant to your access.</p>
    </div>
    <time datetime="<?= htmlspecialchars(date('Y-m-d')) ?>"><?= date('l, F j, Y') ?></time>
  </header>

  <?php if ($summaryCards): ?>
  <section class="fleet-dash-kpis" aria-label="Dashboard summary">
    <?php foreach ($summaryCards as $card): ?>
      <a class="fleet-dash-kpi" href="<?= htmlspecialchars($card['url'], ENT_QUOTES) ?>">
        <span class="fleet-dash-kpi-icon"><i class="bi <?= htmlspecialchars($card['icon']) ?>" aria-hidden="true"></i></span>
        <span class="fleet-dash-kpi-copy">
          <strong><?= htmlspecialchars($card['value']) ?></strong>
          <span><?= htmlspecialchars($card['label']) ?></span>
          <small><?= htmlspecialchars($card['detail']) ?></small>
        </span>
        <i class="bi bi-arrow-up-right fleet-dash-kpi-link" aria-hidden="true"></i>
      </a>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <section class="fleet-dash-panel fleet-dash-quick mb-4" aria-labelledby="home-quick-actions-heading">
    <div class="fleet-dash-panel-heading">
      <div><span class="fleet-dash-panel-icon"><i class="bi bi-lightning-charge" aria-hidden="true"></i></span><h2 id="home-quick-actions-heading">Quick Actions</h2></div>
    </div>
    <?php if ($quickActions): ?>
      <nav class="fleet-dash-actions" aria-label="Quick actions">
        <?php foreach ($quickActions as $action): ?>
          <a href="<?= APP_BASE . htmlspecialchars($action['url'], ENT_QUOTES) ?>">
            <i class="bi <?= htmlspecialchars($action['icon']) ?>" aria-hidden="true"></i>
            <span><?= htmlspecialchars($action['label']) ?></span>
            <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php else: ?>
      <p class="fleet-dash-empty">No additional shortcuts are available for your permissions.</p>
    <?php endif; ?>
  </section>

  <section class="fleet-dash-panel fleet-dash-work mb-4" aria-labelledby="home-work-heading">
    <div class="fleet-dash-panel-heading">
      <div><span class="fleet-dash-panel-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span><h2 id="home-work-heading"><?= $roleKey === 'audit' ? 'Recent audit activity' : 'Work to Address' ?></h2></div>
      <?php if ($roleKey === 'audit'): ?><a href="<?= APP_BASE ?>/pages/audit_history.php">View history</a><?php endif; ?>
    </div>
    <div class="fleet-dash-work-content">
      <?php if ($alertsUnavailable): ?>
        <p class="alert alert-warning mx-3 mt-3" role="alert">Some alerts could not be loaded. Please refresh or open the relevant workspace.</p>
      <?php endif; ?>
      <?php if ($roleKey === 'audit'): ?>
        <?php if (!$auditActivity): ?><p class="fleet-dash-empty">No audit activity is recorded yet.</p>
        <?php else: ?><ul class="fleet-dash-list">
          <?php foreach ($auditActivity as $activity): ?>
            <li>
              <span class="fleet-dash-list-icon"><i class="bi bi-journal-text" aria-hidden="true"></i></span>
              <span class="fleet-dash-list-copy">
                <strong><?= htmlspecialchars($activity['action'] . ' · ' . ($activity['entity_type'] ?? 'Record') . ' #' . ($activity['entity_id'] ?? '—')) ?></strong>
                <span><?= htmlspecialchars($activity['actor_name'] . ' · ' . date('M j, Y g:i A', strtotime($activity['created_at']))) ?></span>
              </span>
              <a class="fleet-dash-work-link" href="<?= APP_BASE ?>/pages/audit_history.php">Open history</a>
            </li>
          <?php endforeach; ?>
        </ul><?php endif; ?>
      <?php elseif (!$alerts): ?>
        <p class="fleet-dash-empty"><?= $alertsUnavailable ? 'No additional alerts are available.' : 'No pending alerts for your access.' ?></p>
      <?php else: ?>
        <ul class="fleet-dash-list">
          <?php foreach (array_slice($alerts, 0, 12) as $alert): ?>
            <li>
              <span class="fleet-dash-list-icon"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></span>
              <span class="fleet-dash-list-copy">
                <strong><?= htmlspecialchars($alert['title']) ?><?php if (!empty($alert['urgent'])): ?> <span class="badge text-bg-danger">Urgent</span><?php endif; ?></strong>
                <span><?= htmlspecialchars($alert['detail']) ?></span>
                <?php if (!empty($alert['urgent'])): ?><span class="text-danger"><?= htmlspecialchars($alert['urgent']) ?></span><?php endif; ?>
              </span>
              <a class="fleet-dash-work-link" href="<?= htmlspecialchars($alert['url'], ENT_QUOTES) ?>">Open</a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($canViewTripList): ?>
  <section class="fleet-dash-panel fleet-dash-work" aria-labelledby="home-active-trips-heading">
    <div class="fleet-dash-panel-heading">
      <div><span class="fleet-dash-panel-icon"><i class="bi bi-map" aria-hidden="true"></i></span><h2 id="home-active-trips-heading"><?= $isCrew ? 'My Assigned Trips' : 'Active Trips' ?></h2></div>
      <?php if (!$isCrew): ?><a href="<?= APP_BASE ?>/pages/trip_monitor.php">Trip monitoring</a><?php endif; ?>
    </div>
    <?php if ($isCrew && !$employee): ?>
      <p class="fleet-dash-empty">An active employee profile must be linked to your account before assigned trips can be shown.</p>
    <?php elseif (!$activeTrips): ?>
      <p class="fleet-dash-empty"><?= $isCrew ? 'No approved, upcoming, or active trips are assigned to you.' : 'No active trips in your permitted scope.' ?></p>
    <?php else: ?>
      <ul class="fleet-dash-trips">
        <?php foreach ($activeTrips as $trip): ?>
          <li>
            <span class="fleet-dash-trip-ref"><?= htmlspecialchars($trip['trip_number']) ?></span>
            <span class="fleet-dash-trip-route"><?= htmlspecialchars($trip['origin'] . ' → ' . $trip['destination']) ?></span>
            <span class="fleet-dash-trip-detail"><?= htmlspecialchars($trip['plate_number'] ?? '—') ?> · <?= htmlspecialchars($trip['status']) ?><?= (int)$trip['is_late'] === 1 ? ' · Past ETA' : '' ?></span>
            <span class="fleet-dash-trip-detail"><?= $trip['expected_arrival'] ? htmlspecialchars(date('M j, g:i A', strtotime($trip['expected_arrival']))) : 'No ETA' ?></span>
            <?php if ($isCrew): ?><a class="fleet-dash-work-link" href="<?= APP_BASE ?>/pages/trip_workflow.php?trip_id=<?= (int)$trip['trip_id'] ?>">Open trip</a><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php layoutFoot(); ?>
