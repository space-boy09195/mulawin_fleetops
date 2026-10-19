<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';
require_once __DIR__ . '/../includes/employee_profile.php';
require_once __DIR__ . '/../includes/dashboard_metrics.php';
require_once __DIR__ . '/../includes/trip_queries.php';
require_once __DIR__ . '/../includes/trip_completion.php';

requireLogin();

$pdo = getDBConnection();
$GLOBALS['page_needs_chart'] = false;
$roleName = (string)($_SESSION['role_name'] ?? 'Account');
$canTrips = currentUserHasAnyPermission(['trips.view']);
$canIncidents = currentUserHasAnyPermission(['incidents.manage']);
$canMaintenance = currentUserHasAnyPermission(['maintenance.view']);
$canParts = currentUserHasAnyPermission(['parts.view']);
$canApprovals = currentUserHasAnyPermission(['approvals.review']);
$isDispatcher = dispatcherScopeResult()['status'] !== 'none';
$dispatcherScope = $isDispatcher ? dispatcherScope() : [];
$employee = employeeForCurrentUser($pdo);
$roleKey = currentRoleKey();
$isCrew = in_array($roleKey, ['driver', 'helper'], true);
$canViewAssignedTrips = $isCrew && $employee !== null;
$canViewTripList = $canTrips || $canViewAssignedTrips;
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
    $loadAlerts('past ETA', static function () use ($pdo, $tripScopeSql, $tripScopeParams): array {
        $stmt = $pdo->prepare(
            tripSelectSql() . "
             WHERE " . tripOverdueSql('t') . $tripScopeSql . "
             ORDER BY t.scheduled_arrival ASC LIMIT 5"
        );
        $stmt->execute($tripScopeParams);
        return array_map(static fn(array $trip): array => [
            'title' => 'Past ETA',
            'detail' => $trip['trip_number'] . ' · ' . ($trip['plate_number'] ?? 'No truck'),
            'url' => APP_BASE . '/pages/trip_monitor.php',
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
            'url' => APP_BASE . '/pages/maintenance.php',
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

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$fullName = trim((string)($_SESSION['full_name'] ?? ''));
layoutHead('Home', APP_BASE . '/assets/css/dashboard.css');
?>
<main class="container py-4">
  <header class="mb-4">
    <p class="text-muted mb-1"><?= htmlspecialchars($roleName) ?></p>
    <h1 class="h3"><?= htmlspecialchars($greeting . ($fullName !== '' ? ', ' . $fullName : '')) ?></h1>
    <p class="text-muted mb-0"><?= date('l, F j, Y') ?></p>
  </header>

  <section class="card mb-4" aria-labelledby="home-alerts-heading">
    <div class="card-header-custom"><h2 id="home-alerts-heading" class="card-title-custom mb-0">Alerts</h2></div>
    <div class="card-body-custom">
      <?php if ($alertsUnavailable): ?>
        <p class="alert alert-warning mb-3" role="alert">Some alerts could not be loaded. Please refresh or open the relevant workspace.</p>
      <?php endif; ?>
      <?php if (!$alerts): ?>
        <p class="text-muted mb-0"><?= $alertsUnavailable ? 'No additional alerts are available.' : 'No actionable alerts for your access.' ?></p>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($alerts as $alert): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center gap-3">
              <span>
                <strong class="text-<?= htmlspecialchars($alert['tone']) ?>"><?= htmlspecialchars($alert['title']) ?></strong>
                <span class="d-block small text-muted"><?= htmlspecialchars($alert['detail']) ?></span>
              </span>
              <a class="btn btn-sm btn-outline-primary flex-shrink-0" href="<?= htmlspecialchars($alert['url']) ?>">Open</a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </section>

  <section class="card" aria-labelledby="home-active-trips-heading">
    <div class="card-header-custom d-flex justify-content-between align-items-center">
      <h2 id="home-active-trips-heading" class="card-title-custom mb-0"><?= $isCrew ? 'My Assigned Trips' : 'Active Trips' ?></h2>
      <?php if ($canTrips && !$isCrew): ?><a href="<?= APP_BASE ?>/pages/trip_monitor.php">Trip monitoring</a><?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead><tr><th>Trip</th><th>Truck</th><th>Route</th><th>Status</th><th>Expected arrival</th></tr></thead>
        <tbody>
          <?php if ($isCrew && !$employee): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">An active employee profile must be linked to your account before assigned trips can be shown.</td></tr>
          <?php elseif (!$canViewTripList): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">Trip access is not assigned to your account.</td></tr>
          <?php elseif (!$activeTrips): ?>
            <tr><td colspan="5" class="text-center text-muted py-4"><?= $isCrew ? 'No approved, upcoming, or active trips are assigned to you.' : 'No active trips in your permitted scope.' ?></td></tr>
          <?php else: foreach ($activeTrips as $trip): ?>
            <tr>
              <td><?= htmlspecialchars($trip['trip_number']) ?></td>
              <td><?= htmlspecialchars($trip['plate_number'] ?? '—') ?></td>
              <td><?= htmlspecialchars($trip['origin'] . ' → ' . $trip['destination']) ?></td>
              <td><?= htmlspecialchars($trip['status']) ?><?= (int)$trip['is_late'] === 1 ? ' · Past ETA' : '' ?></td>
              <td><?= $trip['expected_arrival'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($trip['expected_arrival']))) : '—' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<?php layoutFoot(); ?>
