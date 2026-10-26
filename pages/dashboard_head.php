<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard_metrics.php';
require_once __DIR__ . '/../includes/trip_queries.php';

requirePermission('company.dashboard.view');

$pdo = getDBConnection();
$canTrips = currentUserHasAnyPermission(['trips.view']);
$canFleet = currentUserHasAnyPermission(['fleet.view']);
$canBilling = currentUserHasAnyPermission(['billing.view']);
$canApprovals = currentUserHasAnyPermission(['approvals.review']);
$canIncidents = currentUserHasAnyPermission(['incidents.manage']);
$canMaintenance = currentUserHasAnyPermission(['maintenance.view']);
$canParts = currentUserHasAnyPermission(['parts.view']);
$activeTripCount = $canTrips ? dashboardActiveTripCount($pdo) : 0;
$fleetCounts = $canFleet ? dashboardFleetCounts($pdo) : [];
$receivables = $canBilling ? dashboardReceivables($pdo) : ['outstanding' => 0.0];
$pendingApprovals = $canApprovals ? dashboardPendingApprovalCount($pdo, true, currentRoleId()) : 0;

$activeTrips = $canTrips ? $pdo->query(
    tripSelectSql() . "
     WHERE t.status IN (" . metricSqlList(TRIP_ACTIVE_STATUSES) . ")
     ORDER BY " . tripOverdueSql('t') . " DESC, t.scheduled_departure ASC
     LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC) : [];
$pastEtaTrips = $canTrips ? $pdo->query(
    tripSelectSql() . "
     WHERE " . tripOverdueSql('t') . "
     ORDER BY t.scheduled_arrival ASC LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC) : [];

$alerts = [];
foreach ($pastEtaTrips as $trip) {
    $alerts[] = [
        'title' => 'Past ETA',
        'detail' => $trip['trip_number'] . ' · ' . ($trip['plate_number'] ?? 'No truck'),
        'url' => APP_BASE . '/pages/trip_monitor.php',
    ];
}
if ($canApprovals) {
    if ($pendingApprovals > 0) {
        $alerts[] = [
            'title' => 'Requests awaiting approval',
            'detail' => $pendingApprovals . ' request' . ($pendingApprovals === 1 ? '' : 's'),
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
$fullName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
$roleName = (string)($_SESSION['role_name'] ?? 'Admin');
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
  <div>
    <h1 class="page-title"><?= htmlspecialchars($greeting . ($fullName !== '' ? ', ' . $fullName : '')) ?></h1>
    <p class="page-subtitle"><?= htmlspecialchars($roleName) ?> · Fleet, operations, finance, approvals, and current alerts.</p>
  </div>
  <time class="text-muted"><?= date('l, F j, Y') ?></time>
</div>

<section class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-4" aria-label="Dashboard summary">
  <?php if ($canTrips): ?><div class="col"><a class="stat-card text-decoration-none h-100" href="<?= APP_BASE ?>/pages/trip_monitor.php"><span class="stat-icon blue"><i class="bi bi-map"></i></span><span class="stat-info"><span class="stat-value"><?= $activeTripCount ?></span><span class="stat-label">Active trips</span></span></a></div><?php endif; ?>
  <?php if ($canFleet): ?><div class="col"><a class="stat-card text-decoration-none h-100" href="<?= APP_BASE ?>/pages/fleet_status.php"><span class="stat-icon green"><i class="bi bi-truck"></i></span><span class="stat-info"><span class="stat-value"><?= (int)($fleetCounts['Available'] ?? 0) ?></span><span class="stat-label">Available trucks</span></span></a></div><?php endif; ?>
  <?php if ($canBilling): ?><div class="col"><a class="stat-card text-decoration-none h-100" href="<?= APP_BASE ?>/pages/billing.php"><span class="stat-icon amber"><i class="bi bi-receipt"></i></span><span class="stat-info"><span class="stat-value">₱<?= number_format($receivables['outstanding'], 2) ?></span><span class="stat-label">Outstanding balances</span></span></a></div><?php endif; ?>
  <?php if ($canApprovals): ?><div class="col"><a class="stat-card text-decoration-none h-100" href="<?= APP_BASE ?>/pages/requests.php"><span class="stat-icon red"><i class="bi bi-inbox"></i></span><span class="stat-info"><span class="stat-value"><?= $pendingApprovals ?></span><span class="stat-label">Pending approvals</span></span></a></div><?php endif; ?>
</section>

<section class="card mb-4" aria-labelledby="management-quick-heading">
  <div class="card-header-custom"><h2 id="management-quick-heading" class="card-title-custom mb-0">Quick Actions</h2></div>
  <div class="card-body-custom d-flex flex-wrap gap-2">
    <?php if ($canApprovals): ?><a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/requests.php">Requests &amp; approvals</a><?php endif; ?>
    <?php if ($canTrips): ?><a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/dispatch.php">Dispatch workspace</a><?php endif; ?>
    <?php if ($canFleet): ?><a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/fleet_status.php">Fleet status</a><?php endif; ?>
    <?php if ($canBilling): ?><a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/billing.php">Billing</a><?php endif; ?>
    <?php if (currentUserHasAnyPermission(['finance.funds.view', 'finance.disbursements.view', 'finance.ap.view'])): ?><a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/finance.php">Finance</a><?php endif; ?>
    <?php if ($canMaintenance): ?><a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/maintenance.php">Maintenance</a><?php endif; ?>
    <?php if (currentUserHasAnyPermission(['reports.view', 'operations.reports.view', 'maintenance.reports.view', 'billing.view'])): ?><a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/analytics.php">Analytics</a><?php endif; ?>
  </div>
</section>

<section class="card mb-4" aria-labelledby="management-alerts-heading">
  <div class="card-header-custom"><h2 id="management-alerts-heading" class="card-title-custom mb-0">Work to Address</h2></div>
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

<?php if ($canTrips): ?><section class="card" aria-labelledby="management-active-heading">
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
<?php endif; ?>
<?php layoutFoot(); ?>
