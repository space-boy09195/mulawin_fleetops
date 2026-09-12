<?php
// ============================================================
// pages/requests.php
// Central approval queue for Head Management
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/requests.js';
$pdo = getDBConnection();

$dispatchRequests = $pdo->query("
    SELECT dr.dispatch_id, dr.requested_at, dr.scheduled_at, dr.remarks,
           tr.plate_number, tr.brand, tr.model,
           e_d.full_name AS driver_name, e_h.full_name AS helper_name,
           r.route_name, r.origin, r.destination,
           u.full_name AS requested_by
    FROM dispatch_requests dr
    JOIN trucks tr ON tr.truck_id = dr.truck_id
    JOIN employees e_d ON e_d.employee_id = dr.driver_id
    LEFT JOIN employees e_h ON e_h.employee_id = dr.helper_id
    JOIN routes r ON r.route_id = dr.route_id
    JOIN users u ON u.user_id = dr.requested_by
    WHERE dr.status = 'Pending'
    ORDER BY dr.requested_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$routeRequests = $pdo->query("
    SELECT r.route_id, r.route_name, r.origin, r.destination, r.distance_km,
           r.request_notes, r.requested_by,
           u.full_name AS requested_by_name
    FROM routes r
    LEFT JOIN users u ON u.user_id = r.requested_by
    WHERE r.approval_status = 'Pending'
    ORDER BY r.route_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$recentDispatch = $pdo->query("
    SELECT dr.status, dr.reviewed_at, dr.dispatch_id,
           tr.plate_number, r.route_name
    FROM dispatch_requests dr
    JOIN trucks tr ON tr.truck_id = dr.truck_id
    JOIN routes r ON r.route_id = dr.route_id
    WHERE dr.status <> 'Pending'
    ORDER BY dr.reviewed_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$recentRoutes = $pdo->query("
    SELECT route_name, origin, destination, approval_status
    FROM routes
    WHERE approval_status <> 'Pending'
    ORDER BY route_id DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

layoutHead('Requests', APP_BASE . '/assets/css/dispatch.css');
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Requests</h1>
    <p class="page-subtitle">Review and approve pending operational requests.</p>
  </div>
  <div class="d-flex gap-2">
    <span class="badge text-bg-warning align-self-center">
      <?= count($dispatchRequests) + count($routeRequests) ?> pending
    </span>
  </div>
</div>

<div id="requestsAlert" class="alert d-none" role="alert"></div>

<div class="card mb-4">
  <div class="card-header-custom">
    <h2 class="card-title-custom"><i class="bi bi-send me-2"></i>Dispatch Requests</h2>
    <span class="text-muted small"><?= count($dispatchRequests) ?> pending</span>
  </div>
  <div class="table-responsive">
    <table class="table-custom">
      <thead>
        <tr>
          <th>Requester</th><th>Truck</th><th>Driver</th><th>Route</th>
          <th>Scheduled</th><th>Remarks</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$dispatchRequests): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No dispatch requests are waiting for approval.</td></tr>
      <?php else: foreach ($dispatchRequests as $request): ?>
        <tr>
          <td><?= htmlspecialchars($request['requested_by']) ?><br>
            <span class="text-muted small"><?= date('M j, Y g:i A', strtotime($request['requested_at'])) ?></span>
          </td>
          <td><?= htmlspecialchars($request['plate_number']) ?><br>
            <span class="text-muted small"><?= htmlspecialchars($request['brand'] . ' ' . $request['model']) ?></span>
          </td>
          <td><?= htmlspecialchars($request['driver_name']) ?>
            <?php if ($request['helper_name']): ?><br><span class="text-muted small">Helper: <?= htmlspecialchars($request['helper_name']) ?></span><?php endif; ?>
          </td>
          <td><?= htmlspecialchars($request['route_name']) ?><br>
            <span class="text-muted small"><?= htmlspecialchars($request['origin']) ?> <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($request['destination']) ?></span>
          </td>
          <td class="small"><?= $request['scheduled_at'] ? date('M j, Y g:i A', strtotime($request['scheduled_at'])) : '—' ?></td>
          <td class="small"><?= $request['remarks'] ? htmlspecialchars($request['remarks']) : '<span class="text-muted">—</span>' ?></td>
          <td>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-success js-approve-dispatch" data-id="<?= (int)$request['dispatch_id'] ?>" title="Approve">
                <i class="bi bi-check-lg"></i>
              </button>
              <button class="btn btn-sm btn-danger js-reject-dispatch" data-id="<?= (int)$request['dispatch_id'] ?>" title="Reject">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header-custom">
    <h2 class="card-title-custom"><i class="bi bi-signpost-2 me-2"></i>Route Requests</h2>
    <span class="text-muted small"><?= count($routeRequests) ?> pending</span>
  </div>
  <div class="table-responsive">
    <table class="table-custom">
      <thead><tr><th>Requester</th><th>Route</th><th>Distance</th><th>Side Note</th><th>Action</th></tr></thead>
      <tbody>
      <?php if (!$routeRequests): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No route requests are waiting for approval.</td></tr>
      <?php else: foreach ($routeRequests as $request): ?>
        <tr>
          <td><?= htmlspecialchars($request['requested_by_name'] ?? 'Unknown') ?></td>
          <td><strong><?= htmlspecialchars($request['route_name']) ?></strong><br>
            <span class="text-muted small"><?= htmlspecialchars($request['origin']) ?> <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($request['destination']) ?></span>
          </td>
          <td class="small"><?= $request['distance_km'] !== null ? number_format((float)$request['distance_km'], 1) . ' km' : '—' ?></td>
          <td class="small"><?= $request['request_notes'] ? htmlspecialchars($request['request_notes']) : '<span class="text-muted">—</span>' ?></td>
          <td>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-success js-review-route" data-id="<?= (int)$request['route_id'] ?>" data-status="Approved"><i class="bi bi-check-lg"></i></button>
              <button class="btn btn-sm btn-danger js-review-route" data-id="<?= (int)$request['route_id'] ?>" data-status="Rejected"><i class="bi bi-x-lg"></i></button>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header-custom"><h2 class="card-title-custom">Recently Reviewed</h2></div>
  <div class="table-responsive">
    <table class="table-custom">
      <thead><tr><th>Type</th><th>Request</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentDispatch as $item): ?>
        <tr><td>Dispatch</td><td><?= htmlspecialchars($item['plate_number'] . ' — ' . $item['route_name']) ?></td><td><span class="status-badge <?= $item['status'] === 'Approved' ? 'available' : 'inactive' ?>"><?= htmlspecialchars($item['status']) ?></span></td></tr>
      <?php endforeach; ?>
      <?php foreach ($recentRoutes as $item): ?>
        <tr><td>Route</td><td><?= htmlspecialchars($item['route_name'] . ' — ' . $item['origin'] . ' to ' . $item['destination']) ?></td><td><span class="status-badge <?= $item['approval_status'] === 'Approved' ? 'available' : 'inactive' ?>"><?= htmlspecialchars($item['approval_status']) ?></span></td></tr>
      <?php endforeach; ?>
      <?php if (!$recentDispatch && !$recentRoutes): ?>
        <tr><td colspan="3" class="text-center text-muted py-4">No reviewed requests yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layoutFoot(); ?>
