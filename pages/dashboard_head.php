<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard_metrics.php';
require_once __DIR__ . '/../includes/trip_queries.php';

requirePermission('company.dashboard.view');

$pdo = getDBConnection();
$canApprovals = currentUserHasAnyPermission(['approvals.review']);
$canIncidents = currentUserHasAnyPermission(['incidents.manage']);
$canMaintenance = currentUserHasAnyPermission(['maintenance.view']);
$canParts = currentUserHasAnyPermission(['parts.view']);

$activeTrips = $pdo->query(
    tripSelectSql() . "
     WHERE t.status IN (" . metricSqlList(TRIP_ACTIVE_STATUSES) . ")
     ORDER BY " . tripOverdueSql('t') . " DESC, t.scheduled_departure ASC
     LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);
$pastEtaTrips = $pdo->query(
    tripSelectSql() . "
     WHERE " . tripOverdueSql('t') . "
     ORDER BY t.scheduled_arrival ASC LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);

$alerts = [];
foreach ($pastEtaTrips as $trip) {
    $alerts[] = [
        'title' => 'Past ETA',
        'detail' => $trip['trip_number'] . ' · ' . ($trip['plate_number'] ?? 'No truck'),
        'url' => APP_BASE . '/pages/trip_monitor.php',
    ];
}
if ($canApprovals) {
    $pending = dashboardPendingApprovalCount($pdo, true, currentRoleId());
    if ($pending > 0) {
        $alerts[] = [
            'title' => 'Requests awaiting approval',
            'detail' => $pending . ' request' . ($pending === 1 ? '' : 's'),
            'url' => APP_BASE . '/pages/requests.php',
        ];
    }
}
if ($canIncidents) {
    foreach (dashboardOpenIncidents($pdo, 5) as $incident) {
        $alerts[] = [
            'title' => 'Open incident: ' . $incident['incident_type'],
            'detail' => $incident['trip_number'] . ' · ' . $incident['severity'],
            'url' => APP_BASE . '/pages/incidents.php',
        ];
    }
}
if ($canMaintenance) {
    foreach (dashboardMaintenanceDue($pdo, 14, 5) as $item) {
        $alerts[] = [
            'title' => 'Maintenance due',
            'detail' => $item['plate_number'] . ' · ' . $item['label'] . ' · ' . $item['next_due_date'],
            'url' => APP_BASE . '/pages/maintenance.php',
        ];
    }
}
if ($canParts) {
    foreach (dashboardLowStockParts($pdo, 5) as $part) {
        $alerts[] = [
            'title' => 'Reorder level reached',
            'detail' => $part['part_name'] . ' · ' . $part['quantity'] . ' / ' . $part['reorder_level'] . ' ' . $part['unit'],
            'url' => APP_BASE . '/pages/parts.php',
        ];
    }
}

$GLOBALS['page_needs_chart'] = false;
layoutHead('Management Dashboard');
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
  <div>
    <h1 class="page-title">Management Dashboard</h1>
    <p class="page-subtitle">Actionable alerts and current active trips.</p>
  </div>
  <a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/analytics.php">Analytics</a>
</div>

<section class="card mb-4" aria-labelledby="management-alerts-heading">
  <div class="card-header-custom"><h2 id="management-alerts-heading" class="card-title-custom mb-0">Alerts</h2></div>
  <div class="card-body-custom">
    <?php if (!$alerts): ?><p class="text-muted mb-0">No current actionable alerts.</p>
    <?php else: ?><ul class="list-group list-group-flush">
      <?php foreach ($alerts as $alert): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center gap-3">
          <span><strong><?= htmlspecialchars($alert['title']) ?></strong><span class="d-block small text-muted"><?= htmlspecialchars($alert['detail']) ?></span></span>
          <a class="btn btn-sm btn-outline-primary flex-shrink-0" href="<?= htmlspecialchars($alert['url']) ?>">Open</a>
        </li>
      <?php endforeach; ?>
    </ul><?php endif; ?>
  </div>
</section>

<section class="card" aria-labelledby="management-active-heading">
  <div class="card-header-custom d-flex justify-content-between align-items-center">
    <h2 id="management-active-heading" class="card-title-custom mb-0">Active Trips</h2>
    <a href="<?= APP_BASE ?>/pages/trip_monitor.php">Trip monitoring</a>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Trip</th><th>Client</th><th>Truck</th><th>Driver</th><th>Route</th><th>Status</th><th>Expected arrival</th></tr></thead>
      <tbody>
        <?php if (!$activeTrips): ?><tr><td colspan="7" class="text-center text-muted py-4">No active trips.</td></tr>
        <?php else: foreach ($activeTrips as $trip): ?>
          <tr>
            <td><?= htmlspecialchars($trip['trip_number']) ?></td>
            <td><?= htmlspecialchars($trip['client_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($trip['plate_number'] ?? '—') ?></td>
            <td><?= htmlspecialchars($trip['driver_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($trip['origin'] . ' → ' . $trip['destination']) ?></td>
            <td><?= htmlspecialchars($trip['status']) ?><?= (int)$trip['is_late'] === 1 ? ' · Past ETA' : '' ?></td>
            <td><?= $trip['expected_arrival'] ? htmlspecialchars(date('M j, g:i A', strtotime($trip['expected_arrival']))) : '—' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php layoutFoot(); ?>
