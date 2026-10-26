<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard_metrics.php';
require_once __DIR__ . '/../includes/trip_queries.php';

requirePermission('operations.dashboard.view');

$pdo = getDBConnection();
$canTrips = currentUserHasAnyPermission(['trips.view']);
$canFleet = currentUserHasAnyPermission(['fleet.view']);
$canIncidents = currentUserHasAnyPermission(['incidents.manage']);
$canMaintenance = currentUserHasAnyPermission(['maintenance.view']);

$latestDispatches = $pdo->query(
    tripSelectSql() . "
     WHERE dr.status = 'Pending'
     ORDER BY dr.submitted_at ASC
     LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);
$pendingDispatchCount = $canTrips ? dashboardPendingDispatchCount($pdo) : 0;

$activeTrips = $pdo->query(
    tripSelectSql() . "
     WHERE t.status IN (" . metricSqlList(TRIP_ACTIVE_STATUSES) . ")
     ORDER BY " . tripOverdueSql('t') . " DESC, t.scheduled_departure ASC
     LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);
$activeTripCount = $canTrips ? dashboardActiveTripCount($pdo) : 0;
$pastEtaCount = $canTrips ? dashboardDelayedTripCount($pdo) : 0;
$fleetCounts = $canFleet ? dashboardFleetCounts($pdo) : [];

$pastEtaTrips = $pdo->query(
    tripSelectSql() . "
     WHERE " . tripOverdueSql('t') . "
     ORDER BY t.scheduled_arrival ASC
     LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);

$openIncidents = $canIncidents ? dashboardOpenIncidents($pdo, 5) : [];
$maintenanceDue = $canMaintenance ? dashboardMaintenanceDue($pdo, 14, 5) : [];

$GLOBALS['page_needs_chart'] = false;
layoutHead('Operations Head Dashboard');
$fullName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
$roleName = (string)($_SESSION['role_name'] ?? 'Operations Head');
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
  <div>
    <h1 class="page-title"><?= htmlspecialchars($greeting . ($fullName !== '' ? ', ' . $fullName : '')) ?></h1>
    <p class="page-subtitle"><?= htmlspecialchars($roleName) ?> · Dispatch work, active trips, and fleet alerts.</p>
  </div>
  <time class="text-muted"><?= date('l, F j, Y') ?></time>
</div>

<section class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-4" aria-label="Dashboard summary">
  <?php if ($canTrips): ?>
  <div class="col"><a class="stat-card text-decoration-none h-100" href="<?= APP_BASE ?>/pages/trip_monitor.php"><span class="stat-icon blue"><i class="bi bi-map"></i></span><span class="stat-info"><span class="stat-value"><?= $activeTripCount ?></span><span class="stat-label">Active trips</span></span></a></div>
  <div class="col"><a class="stat-card text-decoration-none h-100" href="<?= APP_BASE ?>/pages/dispatch.php"><span class="stat-icon amber"><i class="bi bi-send"></i></span><span class="stat-info"><span class="stat-value"><?= $pendingDispatchCount ?></span><span class="stat-label">Pending dispatch</span></span></a></div>
  <div class="col"><a class="stat-card text-decoration-none h-100" href="<?= APP_BASE ?>/pages/trip_monitor.php"><span class="stat-icon red"><i class="bi bi-clock-history"></i></span><span class="stat-info"><span class="stat-value"><?= $pastEtaCount ?></span><span class="stat-label">Past-ETA trips</span></span></a></div>
  <?php endif; ?>
  <?php if ($canFleet): ?><div class="col"><a class="stat-card text-decoration-none h-100" href="<?= APP_BASE ?>/pages/fleet_status.php"><span class="stat-icon green"><i class="bi bi-truck"></i></span><span class="stat-info"><span class="stat-value"><?= (int)($fleetCounts['Available'] ?? 0) ?></span><span class="stat-label">Available trucks</span></span></a></div>
  <?php elseif ($canMaintenance): ?><div class="col"><a class="stat-card text-decoration-none h-100" href="<?= APP_BASE ?>/pages/maintenance.php"><span class="stat-icon green"><i class="bi bi-tools"></i></span><span class="stat-info"><span class="stat-value"><?= dashboardOpenMaintenanceCount($pdo) ?></span><span class="stat-label">Open maintenance</span></span></a></div><?php endif; ?>
</section>

<section class="card mb-4" aria-labelledby="ops-quick-heading">
  <div class="card-header-custom"><h2 id="ops-quick-heading" class="card-title-custom mb-0">Quick Actions</h2></div>
  <div class="card-body-custom d-flex flex-wrap gap-2">
    <?php if ($canTrips): ?><a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/dispatcher_reports.php"><i class="bi bi-bar-chart-line me-1"></i>Dispatcher Performance</a><?php endif; ?>
    <?php if (currentUserHasAnyPermission(['dispatch.instructions.manage'])): ?><a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/dispatch_planning.php"><i class="bi bi-list-check me-1"></i>Plan Dispatch List</a><?php endif; ?>
    <?php if (currentUserHasAnyPermission(['approvals.review'])): ?><a class="btn btn-primary" href="<?= APP_BASE ?>/pages/requests.php"><i class="bi bi-inbox me-1"></i>Review Encoded Requests</a><?php endif; ?>
  </div>
</section>

<section class="card mb-4" aria-labelledby="ops-alerts-heading">
  <div class="card-header-custom"><h2 id="ops-alerts-heading" class="card-title-custom mb-0">Work to Address</h2></div>
  <div class="card-body-custom">
    <?php if (!$pastEtaTrips && !$openIncidents && !$maintenanceDue): ?>
      <p class="text-muted mb-0">No current operational alerts.</p>
    <?php endif; ?>
    <?php if ($pastEtaTrips): ?>
      <h3 class="h6">Past ETA</h3>
      <ul class="list-unstyled small">
        <?php foreach ($pastEtaTrips as $trip): ?>
          <li class="mb-1">
            <a href="<?= APP_BASE ?>/pages/trip_monitor.php"><?= htmlspecialchars($trip['trip_number']) ?></a>
            · <?= htmlspecialchars($trip['plate_number'] ?? 'No truck') ?>
            · expected <?= htmlspecialchars(date('M j, g:i A', strtotime($trip['expected_arrival']))) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($openIncidents): ?>
      <h3 class="h6">Open incidents</h3>
      <ul class="list-unstyled small">
        <?php foreach ($openIncidents as $incident): ?>
          <li class="mb-1"><a href="<?= APP_BASE ?>/pages/incidents.php"><?= htmlspecialchars($incident['incident_type']) ?></a>
            · <?= htmlspecialchars($incident['trip_number']) ?> · <?= htmlspecialchars($incident['severity']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($maintenanceDue): ?>
      <h3 class="h6">Maintenance due</h3>
      <ul class="list-unstyled small mb-0">
        <?php foreach ($maintenanceDue as $item): ?>
          <li class="mb-1"><a href="<?= APP_BASE ?>/pages/maintenance.php"><?= htmlspecialchars($item['plate_number']) ?></a>
            · <?= htmlspecialchars($item['label']) ?> · <?= htmlspecialchars($item['next_due_date']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<section class="card mb-4" aria-labelledby="ops-active-trips-heading">
  <div class="card-header-custom d-flex justify-content-between align-items-center">
    <h2 id="ops-active-trips-heading" class="card-title-custom mb-0">Active Trips</h2>
    <a href="<?= APP_BASE ?>/pages/trip_monitor.php">Trip monitoring</a>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Trip</th><th>Client</th><th>Truck</th><th>Driver</th><th>Route</th><th>Status</th><th>Expected arrival</th></tr></thead>
      <tbody>
        <?php if (!$activeTrips): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No active trips.</td></tr>
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

<section class="card" aria-labelledby="ops-dispatches-heading">
  <div class="card-header-custom d-flex justify-content-between align-items-center">
    <h2 id="ops-dispatches-heading" class="card-title-custom mb-0">Dispatch requests awaiting review</h2>
    <a href="<?= APP_BASE ?>/pages/dispatch.php" class="btn btn-sm btn-outline-primary">Dispatch workspace</a>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Request</th><th>Client</th><th>Shift</th><th>Truck</th><th>Driver</th><th>Route</th><th>Scheduled</th></tr></thead>
      <tbody>
        <?php if (!$latestDispatches): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No dispatch requests are awaiting review.</td></tr>
        <?php else: foreach ($latestDispatches as $dispatch): ?>
          <tr>
            <td><a href="<?= APP_BASE ?>/pages/requests.php">#<?= (int)$dispatch['dispatch_id'] ?></a></td>
            <td><?= htmlspecialchars($dispatch['client_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($dispatch['shift'] ?? '—') ?></td>
            <td><?= htmlspecialchars($dispatch['plate_number'] ?? '—') ?></td>
            <td><?= htmlspecialchars($dispatch['driver_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($dispatch['route_name'] ?? ($dispatch['origin'] . ' → ' . $dispatch['destination'])) ?></td>
            <td><?= $dispatch['scheduled_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($dispatch['scheduled_at']))) : '—' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php layoutFoot(); ?>
