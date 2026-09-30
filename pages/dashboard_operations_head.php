<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('operations.dashboard.view');

$pdo = getDBConnection();
$pendingDispatches = (int)$pdo->query(
    "SELECT COUNT(*) FROM dispatch_requests WHERE status = 'Pending'"
)->fetchColumn();
$activeTrips = (int)$pdo->query(
    "SELECT COUNT(*) FROM trips WHERE status NOT IN ('Completed', 'Cancelled')"
)->fetchColumn();
$lateTrips = (int)$pdo->query(
    "SELECT COUNT(*) FROM trips
     WHERE is_late = 1 AND status NOT IN ('Completed', 'Cancelled')"
)->fetchColumn();
$availableTrucks = (int)$pdo->query(
    "SELECT COUNT(*) FROM trucks WHERE status = 'Available'"
)->fetchColumn();
$pendingRouteRequests = (int)$pdo->query(
    "SELECT COUNT(*) FROM routes WHERE approval_status = 'Pending'"
)->fetchColumn();
$latestDispatches = $pdo->query(
    "SELECT dr.dispatch_id, dr.client_name, dr.scheduled_at, dr.requested_at, dr.shift,
            tr.plate_number, driver.full_name AS driver_name,
            route.route_name, dr.status
     FROM dispatch_requests dr
     JOIN trucks tr ON tr.truck_id = dr.truck_id
     JOIN employees driver ON driver.employee_id = dr.driver_id
     JOIN routes route ON route.route_id = dr.route_id
     WHERE dr.status = 'Pending'
     ORDER BY dr.requested_at ASC
     LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);

layoutHead('Operations Head Dashboard');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
  <div>
    <h1 class="page-title">Operations Head Dashboard</h1>
    <p class="page-subtitle">Send shift dispatch lists to Dispatchers, review encoded requests, and monitor fleet operations.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/dispatch_planning.php">
      <i class="bi bi-list-check me-1"></i>Plan Dispatch List
    </a>
    <a class="btn btn-primary" href="<?= APP_BASE ?>/pages/requests.php">
      <i class="bi bi-inbox me-1"></i>Review Encoded Requests
    </a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-xl">
    <div class="stat-card">
      <div class="stat-icon amber"><i class="bi bi-send"></i></div>
      <div class="stat-info"><div class="stat-value"><?= $pendingDispatches ?></div><div class="stat-label">Pending Dispatches</div></div>
    </div>
  </div>
  <div class="col-6 col-xl">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-map"></i></div>
      <div class="stat-info"><div class="stat-value"><?= $activeTrips ?></div><div class="stat-label">Active Trips</div></div>
    </div>
  </div>
  <div class="col-6 col-xl">
    <div class="stat-card">
      <div class="stat-icon red"><i class="bi bi-alarm"></i></div>
      <div class="stat-info"><div class="stat-value"><?= $lateTrips ?></div><div class="stat-label">Late Trips</div></div>
    </div>
  </div>
  <div class="col-6 col-xl">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-truck"></i></div>
      <div class="stat-info"><div class="stat-value"><?= $availableTrucks ?></div><div class="stat-label">Available Trucks</div></div>
    </div>
  </div>
  <div class="col-6 col-xl">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-signpost-2"></i></div>
      <div class="stat-info"><div class="stat-value"><?= $pendingRouteRequests ?></div><div class="stat-label">Pending Route Requests</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header-custom d-flex justify-content-between align-items-center">
    <h2 class="card-title-custom mb-0">Dispatch requests awaiting review</h2>
    <a href="<?= APP_BASE ?>/pages/dispatch.php" class="btn btn-sm btn-outline-primary">Dispatch workspace</a>
  </div>
  <div class="table-responsive">
    <table class="table-custom">
      <thead><tr><th>Request</th><th>Client</th><th>Shift</th><th>Truck</th><th>Driver</th><th>Route</th><th>Scheduled</th></tr></thead>
      <tbody>
      <?php if (!$latestDispatches): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No dispatch requests are awaiting review.</td></tr>
      <?php else: foreach ($latestDispatches as $dispatch): ?>
        <tr>
          <td><a href="<?= APP_BASE ?>/pages/dispatch.php">#<?= (int)$dispatch['dispatch_id'] ?></a></td>
          <td><?= htmlspecialchars($dispatch['client_name'] ?? '—') ?></td>
          <td><?= htmlspecialchars($dispatch['shift']) ?></td>
          <td><?= htmlspecialchars($dispatch['plate_number']) ?></td>
          <td><?= htmlspecialchars($dispatch['driver_name']) ?></td>
          <td><?= htmlspecialchars($dispatch['route_name']) ?></td>
          <td><?= $dispatch['scheduled_at'] ? date('M j, Y g:i A', strtotime($dispatch['scheduled_at'])) : '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layoutFoot(); ?>
