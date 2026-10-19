<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/crew_trip_access.php';
require_once __DIR__ . '/../includes/trip_queries.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';

requirePermission('trips.view');
refuseHelpers();

$scope = dispatcherScope();
$scopeSql = '';
$scopeParams = [];
if (!empty($scope['truck_types'])) {
    $scopeSql .= ' AND tr.truck_type IN (' . implode(',', array_fill(0, count($scope['truck_types']), '?')) . ')';
    $scopeParams = array_merge($scopeParams, $scope['truck_types']);
}
if (!empty($scope['shift']) || !empty($scope['denied'])) {
    $scopeSql .= ' AND t.shift = ?';
    $scopeParams[] = (string)($scope['shift'] ?? '');
}

$pdo = getDBConnection();
$currentUserId = currentUserId();
$liveStmt = $pdo->prepare(
    tripSelectSql() . "
     WHERE t.status IN (" . metricSqlList(TRIP_ACTIVE_STATUSES) . ") $scopeSql
     ORDER BY " . tripOverdueSql('t') . " DESC, t.scheduled_departure ASC
     LIMIT 12"
);
$liveStmt->execute($scopeParams);
$liveTrips = $liveStmt->fetchAll(PDO::FETCH_ASSOC);

$upcomingStmt = $pdo->prepare(
    tripSelectSql() . "
     WHERE t.status IN ('Approved', 'Assigned', 'Ready for Dispatch')
       AND t.scheduled_departure BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR) $scopeSql
     ORDER BY t.scheduled_departure ASC
     LIMIT 8"
);
$upcomingStmt->execute($scopeParams);
$upcomingTrips = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

$requestStmt = $pdo->prepare(
    tripSelectSql() . "
     WHERE dr.submitted_by = ?
     ORDER BY dr.submitted_at DESC LIMIT 8"
);
$requestStmt->execute([$currentUserId]);
$myRequests = $requestStmt->fetchAll(PDO::FETCH_ASSOC);

$GLOBALS['page_needs_chart'] = false;
layoutHead('Dispatcher Dashboard', APP_BASE . '/assets/css/dashboard_dispatcher.css');
?>
<div class="dd-page">
  <header class="dd-header">
    <div>
      <p class="dd-eyebrow">Dispatch workspace</p>
      <h1 class="dd-title">Dispatcher Dashboard</h1>
      <p class="dd-subtitle">Your permitted trip scope, upcoming departures, and submitted requests.</p>
    </div>
    <a href="<?= APP_BASE ?>/pages/dispatch.php" class="btn dd-btn-primary"><i class="bi bi-plus-lg me-1"></i>New Request</a>
  </header>

  <section class="dd-widget dd-widget-wide mb-4" aria-labelledby="dispatcher-live-heading">
    <div class="dd-widget-header">
      <span id="dispatcher-live-heading" class="dd-widget-title"><i class="bi bi-map me-2"></i>Active Trips</span>
      <a href="<?= APP_BASE ?>/pages/trip_monitor.php" class="dd-link">Trip monitoring</a>
    </div>
    <?php if (!$liveTrips): ?><div class="dd-empty"><i class="bi bi-map"></i><span>No active trips in your assigned scope.</span></div>
    <?php else: ?><div class="dd-table-wrap"><table class="table dd-table">
      <thead><tr><th>Trip</th><th>Driver</th><th>Route</th><th>ETA</th><th>Status</th><th>Update</th></tr></thead>
      <tbody><?php foreach ($liveTrips as $trip): ?>
        <tr>
          <td><span class="dd-ref"><?= htmlspecialchars($trip['trip_number']) ?></span></td>
          <td><?= htmlspecialchars($trip['driver_name'] ?? '—') ?></td>
          <td class="dd-route"><?= htmlspecialchars($trip['origin']) ?> <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($trip['destination']) ?></td>
          <td><?= $trip['expected_arrival'] ? htmlspecialchars(date('M d, H:i', strtotime($trip['expected_arrival']))) : '—' ?></td>
          <td><span class="dd-status-badge <?= (int)$trip['is_late'] === 1 ? 'dd-badge-red' : 'dd-badge-blue' ?>">
            <?= (int)$trip['is_late'] === 1 ? 'Past ETA · ' : '' ?><?= htmlspecialchars($trip['status']) ?>
          </span></td>
          <td><a class="dd-link" href="<?= APP_BASE ?>/pages/trip_workflow.php?trip_id=<?= (int)$trip['trip_id'] ?>">Workflow</a></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </section>

  <div class="dd-grid">
    <section class="dd-widget" aria-labelledby="dispatcher-requests-heading">
      <div class="dd-widget-header">
        <span id="dispatcher-requests-heading" class="dd-widget-title"><i class="bi bi-send me-2"></i>My Recent Requests</span>
        <a href="<?= APP_BASE ?>/pages/dispatch.php" class="dd-link">View All</a>
      </div>
      <?php if (!$myRequests): ?><div class="dd-empty"><i class="bi bi-send"></i><span>No requests submitted yet.</span></div>
      <?php else: ?><ul class="dd-req-list">
        <?php foreach ($myRequests as $request): ?>
          <li class="dd-req-item">
            <div class="dd-req-body">
              <span class="dd-req-plate"><?= htmlspecialchars($request['plate_number'] ?? '—') ?></span>
              <span class="dd-req-route"><?= htmlspecialchars($request['origin'] . ' → ' . $request['destination']) ?></span>
              <span class="dd-req-meta"><?= htmlspecialchars($request['driver_name'] ?? '—') ?> · <?= $request['requested_at'] ? htmlspecialchars(date('M d, Y', strtotime($request['requested_at']))) : '—' ?></span>
            </div>
            <span class="dd-status-badge"><?= htmlspecialchars($request['dispatch_status'] ?? $request['status']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul><?php endif; ?>
    </section>

    <section class="dd-widget" aria-labelledby="dispatcher-upcoming-heading">
      <div class="dd-widget-header">
        <span id="dispatcher-upcoming-heading" class="dd-widget-title"><i class="bi bi-calendar-event me-2"></i>Upcoming Trips</span>
      </div>
      <?php if (!$upcomingTrips): ?><div class="dd-empty"><i class="bi bi-calendar-event"></i><span>No approved departures in the next 48 hours.</span></div>
      <?php else: ?><ul class="dd-req-list">
        <?php foreach ($upcomingTrips as $trip): ?>
          <li class="dd-req-item">
            <div class="dd-req-body">
              <span class="dd-req-plate"><?= htmlspecialchars($trip['plate_number'] ?? '—') ?></span>
              <span class="dd-req-route"><?= htmlspecialchars($trip['origin'] . ' → ' . $trip['destination']) ?></span>
              <span class="dd-req-meta"><?= htmlspecialchars($trip['driver_name'] ?? '—') ?> · <?= htmlspecialchars(date('M d, H:i', strtotime($trip['scheduled_at']))) ?></span>
            </div>
            <span class="dd-status-badge"><?= htmlspecialchars($trip['status']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul><?php endif; ?>
    </section>
  </div>
</div>
<?php layoutFoot(); ?>
