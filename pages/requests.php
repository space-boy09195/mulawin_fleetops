<?php
// ============================================================
// pages/requests.php
// Central approval queue for Head Management
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/trip_queries.php';

requirePermission('approvals.review');

$GLOBALS['page_js'] = APP_BASE . '/assets/js/requests.js';
$pdo = getDBConnection();

// Dispatch and route requests are listed only to roles that hold a step of
// those workflows (Operations Head); Admin sees every section. Other
// reviewers (for example Finance for fund requests) see only their own
// approvals below.
$stepWorkflowsStmt = $pdo->prepare(
    'SELECT DISTINCT w.workflow_key
     FROM approval_workflows w
     JOIN approval_workflow_steps s ON s.workflow_id = w.workflow_id
     WHERE s.approver_role_id = ?'
);
$stepWorkflowsStmt->execute([currentRoleId()]);
$stepWorkflows = $stepWorkflowsStmt->fetchAll(PDO::FETCH_COLUMN);
$showDispatchReview = isAdmin() || in_array('dispatch', $stepWorkflows, true);
$showRouteReview = isAdmin() || in_array('route_request', $stepWorkflows, true);

// Pending dispatch requests (target: trips + dispatch_requests, D2).
$dispatchRequests = !$showDispatchReview ? [] : $pdo->query(
    tripSelectSql() . "
    WHERE dr.status = 'Pending'
    ORDER BY dr.submitted_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($dispatchRequests as &$dispatchRequest) {
    // The view shows the requester's name under requested_by.
    $dispatchRequest['requested_by'] = $dispatchRequest['requested_by_name'];
}
unset($dispatchRequest);

// Route requests: inactive routes with a Pending route_request approval.
// requested_by = routes.created_by; request notes = the Submitted remarks.
$routeRequests = !$showRouteReview ? [] : $pdo->query("
    SELECT r.route_id, r.route_name, r.origin, r.destination, r.distance AS distance_km,
           ah.remarks AS request_notes, r.created_by AS requested_by,
           COALESCE(e.full_name, u.username) AS requested_by_name
    FROM approval_requests ar
    JOIN approval_workflows w ON w.workflow_id = ar.workflow_id AND w.workflow_key = 'route_request'
    JOIN routes r ON r.route_id = ar.entity_id
    LEFT JOIN approval_history ah ON ah.approval_request_id = ar.approval_request_id AND ah.action = 'Submitted'
    LEFT JOIN users u ON u.user_id = r.created_by
    LEFT JOIN employees e ON e.employee_id = u.employee_id
    WHERE ar.entity_type = 'route' AND ar.status = 'Pending'
    ORDER BY ar.submitted_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$recentDispatch = !$showDispatchReview ? [] : $pdo->query("
    SELECT dr.status, dr.reviewed_at, dr.dispatch_request_id AS dispatch_id,
           tr.plate_number, r.route_name
    FROM dispatch_requests dr
    JOIN trips t ON t.trip_id = dr.trip_id
    LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
    LEFT JOIN routes r ON r.route_id = t.route_id
    WHERE dr.status <> 'Pending'
    ORDER BY dr.reviewed_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$recentRoutes = !$showRouteReview ? [] : $pdo->query("
    SELECT r.route_name, r.origin, r.destination, ar.status AS approval_status
    FROM approval_requests ar
    JOIN approval_workflows w ON w.workflow_id = ar.workflow_id AND w.workflow_key = 'route_request'
    JOIN routes r ON r.route_id = ar.entity_id
    WHERE ar.entity_type = 'route' AND ar.status <> 'Pending'
    ORDER BY ar.completed_at DESC, ar.approval_request_id DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Other approvals (purchasing, funds, vouchers, payroll) whose current step
// belongs to this role; Admin sees every one. Decided through
// ajax/approval_decision_handler.php.
$otherApprovalSql = "
    SELECT ar.approval_request_id, w.workflow_key, w.workflow_name, ar.entity_id, ar.submitted_at,
           ar.requested_by, COALESCE(re.full_name, ru.username) AS requested_by_name,
           cur.step_number, cur.approver_role_id, rl.role_name AS step_role,
           COALESCE(pr.request_number, fr.request_number, pv.voucher_number,
                    CONCAT(pp.period_start, ' to ', pp.period_end)) AS reference,
           COALESCE(pr.total_amount, fr.amount, pv.amount,
                    (SELECT SUM(x.net_amount) FROM payroll_records x WHERE x.payroll_period_id = pp.payroll_period_id)) AS amount,
           COALESCE(pr.purpose, fr.purpose, pv.purpose, 'Payroll') AS purpose
    FROM approval_requests ar
    JOIN approval_workflows w ON w.workflow_id = ar.workflow_id
    JOIN approval_steps cur ON cur.approval_step_id = (
        SELECT s.approval_step_id FROM approval_steps s
        WHERE s.approval_request_id = ar.approval_request_id AND s.status = 'Pending'
        ORDER BY s.step_number LIMIT 1
    )
    JOIN roles rl ON rl.role_id = cur.approver_role_id
    JOIN users ru ON ru.user_id = ar.requested_by
    LEFT JOIN employees re ON re.employee_id = ru.employee_id
    LEFT JOIN purchase_requests pr ON w.workflow_key = 'purchase_request' AND pr.purchase_request_id = ar.entity_id
    LEFT JOIN fund_requests fr     ON w.workflow_key = 'fund_request' AND fr.fund_request_id = ar.entity_id
    LEFT JOIN payment_vouchers pv  ON w.workflow_key = 'payment_voucher' AND pv.voucher_id = ar.entity_id
    LEFT JOIN payroll_periods pp   ON w.workflow_key = 'payroll' AND pp.payroll_period_id = ar.entity_id
    WHERE ar.status = 'Pending'
      AND w.workflow_key IN ('purchase_request', 'fund_request', 'payment_voucher', 'payroll')
";
$otherApprovalParams = [];
if (!isAdmin()) {
    $otherApprovalSql .= ' AND cur.approver_role_id = ?';
    $otherApprovalParams[] = currentRoleId();
}
$otherApprovalStmt = $pdo->prepare($otherApprovalSql . ' ORDER BY ar.submitted_at ASC LIMIT 300');
$otherApprovalStmt->execute($otherApprovalParams);
$otherApprovals = $otherApprovalStmt->fetchAll(PDO::FETCH_ASSOC);

layoutHead('Requests', APP_BASE . '/assets/css/dispatch.css');
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Requests</h1>
    <p class="page-subtitle">Review and approve pending operational requests.</p>
  </div>
  <div class="d-flex gap-2">
    <span class="badge text-bg-warning align-self-center">
      <?= count($dispatchRequests) + count($routeRequests) + count($otherApprovals) ?> pending
    </span>
  </div>
</div>

<div id="requestsAlert" class="alert d-none" role="alert"></div>

<?php if ($showDispatchReview): ?>
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
          <th>Client</th><th>Shift</th><th>Scheduled</th><th>Remarks</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$dispatchRequests): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No dispatch requests are waiting for approval.</td></tr>
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
          <td class="small"><?= $request['client_name'] ? htmlspecialchars($request['client_name']) : '—' ?></td>
          <td><span class="badge text-bg-<?= $request['shift'] === 'Day' ? 'info' : 'dark' ?>"><?= htmlspecialchars($request['shift']) ?></span></td>
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
<?php endif; ?>

<?php if ($showRouteReview): ?>
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
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header-custom">
    <h2 class="card-title-custom">Purchasing, Fund, Voucher &amp; Payroll Approvals</h2>
    <span class="badge text-bg-warning"><?= count($otherApprovals) ?> pending</span>
  </div>
  <div class="table-responsive">
    <table class="table-custom">
      <thead><tr><th>Type</th><th>Reference</th><th>Purpose</th><th>Amount</th><th>Requested by</th><th>Current step</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (!$otherApprovals): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No approvals are waiting for you.</td></tr>
      <?php else: foreach ($otherApprovals as $approval): ?>
        <tr>
          <td><?= htmlspecialchars($approval['workflow_name']) ?></td>
          <td><?= htmlspecialchars((string)$approval['reference']) ?></td>
          <td class="small"><?= nl2br(htmlspecialchars(mb_strimwidth((string)$approval['purpose'], 0, 160, '…'))) ?></td>
          <td><?= $approval['amount'] !== null ? '₱' . number_format((float)$approval['amount'], 2) : '—' ?></td>
          <td><?= htmlspecialchars((string)$approval['requested_by_name']) ?><br><span class="text-muted small"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($approval['submitted_at']))) ?></span></td>
          <td>Step <?= (int)$approval['step_number'] ?> · <?= htmlspecialchars($approval['step_role']) ?></td>
          <td class="text-nowrap">
            <?php if ((int)$approval['requested_by'] === currentUserId() && !isAdmin()): ?>
            <span class="text-muted small">Your request</span>
            <?php else: ?>
            <button class="btn btn-sm btn-success js-decide-approval" data-id="<?= (int)$approval['approval_request_id'] ?>" data-decision="Approved" title="Approve"><i class="bi bi-check-lg"></i></button>
            <button class="btn btn-sm btn-danger js-decide-approval" data-id="<?= (int)$approval['approval_request_id'] ?>" data-decision="Rejected" title="Reject"><i class="bi bi-x-lg"></i></button>
            <?php endif; ?>
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
