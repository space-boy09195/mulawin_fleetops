<?php
// ============================================================
// pages/dispatch.php
// Dispatch Requests — submit (Dispatcher) + approve/reject (Head)
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/dispatch.js';
$pdo = getDBConnection();

// ---- Data for the form dropdowns --------------------------
$availableTrucks = $pdo->query(
    "SELECT truck_id, plate_number, brand, model FROM trucks
      WHERE status = 'Available' ORDER BY plate_number"
)->fetchAll();

$drivers = $pdo->query(
    "SELECT employee_id, full_name, license_number FROM employees
      WHERE is_active = 1 AND license_number IS NOT NULL
      ORDER BY full_name"
)->fetchAll();

$helpers = $pdo->query(
    "SELECT employee_id, full_name FROM employees
      WHERE is_active = 1 ORDER BY full_name"
)->fetchAll();

$routes = $pdo->query(
    "SELECT route_id, route_name, origin, destination FROM routes
      WHERE is_active = 1 ORDER BY route_name"
)->fetchAll();

// ---- Fetch dispatch requests ------------------------------
$requests = $pdo->query(
    "SELECT
       dr.dispatch_id,
       dr.status,
       dr.scheduled_at,
       dr.remarks,
       dr.requested_at,
       dr.reviewed_at,
       tr.plate_number,
       tr.brand,
       tr.model,
       e_d.full_name  AS driver_name,
       e_h.full_name  AS helper_name,
       r.route_name,
       r.origin,
       r.destination,
       u_req.full_name AS requested_by,
       u_apr.full_name AS approved_by
     FROM dispatch_requests dr
     JOIN trucks tr          ON dr.truck_id     = tr.truck_id
     JOIN employees e_d      ON dr.driver_id    = e_d.employee_id
     LEFT JOIN employees e_h ON dr.helper_id    = e_h.employee_id
     JOIN routes r           ON dr.route_id     = r.route_id
     JOIN users u_req        ON dr.requested_by = u_req.user_id
     LEFT JOIN users u_apr   ON dr.approved_by  = u_apr.user_id
     ORDER BY FIELD(dr.status,'Pending','Approved','Rejected'), dr.requested_at DESC"
)->fetchAll();

$pendingCount = count(array_filter($requests, fn($r) => $r['status'] === 'Pending'));

layoutHead('Dispatch', APP_BASE . '/assets/css/dispatch.css');
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Dispatch Requests</h1>
    <p class="page-subtitle">
      <?= $pendingCount ?> pending request<?= $pendingCount !== 1 ? 's' : '' ?> awaiting review
    </p>
  </div>
  <?php if (currentRoleId() === ROLE_DISPATCHER): ?>
  <button class="btn btn-primary btn-sm d-flex align-items-center gap-2"
          data-bs-toggle="modal" data-bs-target="#newDispatchModal">
    <i class="bi bi-plus-lg"></i> New Request
  </button>
  <?php endif; ?>
</div>

<!-- ---- Filter bar ---------------------------------------- -->
<div class="card mb-4">
  <div class="card-body-custom">
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <span class="text-muted" style="font-size:.8rem;">Filter:</span>
      <button class="filter-btn active" data-filter="all">All</button>
      <button class="filter-btn" data-filter="Pending">
        Pending <?= $pendingCount > 0 ? "($pendingCount)" : '' ?>
      </button>
      <button class="filter-btn" data-filter="Approved">Approved</button>
      <button class="filter-btn" data-filter="Rejected">Rejected</button>
    </div>
  </div>
</div>

<!-- ---- Requests table ------------------------------------ -->
<div class="card">
  <div class="card-header-custom">
    <h2 class="card-title-custom">Request List</h2>
    <span class="text-muted" style="font-size:.8rem;" id="rowCount"></span>
  </div>
  <div class="table-responsive">
    <table class="table-custom" id="dispatchTable">
      <thead>
        <tr>
          <th>Requested</th>
          <th>Truck</th>
          <th>Driver</th>
          <th>Route</th>
          <th>Scheduled</th>
          <th>Status</th>
          <th>Reviewed By</th>
          <?php if (currentRoleId() === ROLE_HEAD_MANAGEMENT): ?>
          <th>Action</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody id="dispatchBody">
        <?php if (empty($requests)): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No dispatch requests yet.</td></tr>
        <?php else: ?>
        <?php foreach ($requests as $req):
          $badgeClass = match($req['status']) {
            'Pending'  => 'maintenance',
            'Approved' => 'available',
            'Rejected' => 'inactive',
            default    => 'inactive',
          };
        ?>
        <tr data-status="<?= htmlspecialchars($req['status']) ?>">
          <td>
            <div style="font-size:.82rem;"><?= htmlspecialchars($req['requested_by']) ?></div>
            <div class="text-muted" style="font-size:.75rem;">
              <?= date('M j, Y g:i A', strtotime($req['requested_at'])) ?>
            </div>
          </td>
          <td>
            <div class="fw-600"><?= htmlspecialchars($req['plate_number']) ?></div>
            <div class="text-muted" style="font-size:.78rem;">
              <?= htmlspecialchars($req['brand'] . ' ' . $req['model']) ?>
            </div>
          </td>
          <td>
            <div><?= htmlspecialchars($req['driver_name']) ?></div>
            <?php if ($req['helper_name']): ?>
            <div class="text-muted" style="font-size:.78rem;">Helper: <?= htmlspecialchars($req['helper_name']) ?></div>
            <?php endif; ?>
          </td>
          <td style="font-size:.82rem;">
            <?= htmlspecialchars($req['route_name']) ?><br>
            <span class="text-muted">
              <?= htmlspecialchars($req['origin']) ?>
              <i class="bi bi-arrow-right"></i>
              <?= htmlspecialchars($req['destination']) ?>
            </span>
          </td>
          <td style="font-size:.82rem;">
            <?= $req['scheduled_at'] ? date('M j, Y g:i A', strtotime($req['scheduled_at'])) : '—' ?>
          </td>
          <td><span class="status-badge <?= $badgeClass ?>"><?= $req['status'] ?></span></td>
          <td style="font-size:.82rem;">
            <?php if ($req['approved_by']): ?>
              <?= htmlspecialchars($req['approved_by']) ?><br>
              <span class="text-muted"><?= date('M j, Y', strtotime($req['reviewed_at'])) ?></span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
            <?php if ($req['remarks']): ?>
              <div class="text-muted mt-1" style="font-size:.75rem;font-style:italic;">
                "<?= htmlspecialchars($req['remarks']) ?>"
              </div>
            <?php endif; ?>
          </td>
          <?php if (currentRoleId() === ROLE_HEAD_MANAGEMENT): ?>
          <td>
            <?php if ($req['status'] === 'Pending'): ?>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-success"
                      onclick="reviewDispatch(<?= $req['dispatch_id'] ?>, 'Approved')">
                <i class="bi bi-check-lg"></i>
              </button>
              <button class="btn btn-sm btn-danger"
                      onclick="openRejectModal(<?= $req['dispatch_id'] ?>)">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ---- New Dispatch Modal (Dispatcher only) -------------- -->
<?php if (currentRoleId() === ROLE_DISPATCHER): ?>
<div class="modal fade" id="newDispatchModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="background:var(--card-bg);color:var(--text-primary);border:1px solid var(--card-border);">
      <div class="modal-header" style="border-color:var(--card-border);">
        <h5 class="modal-title"><i class="bi bi-send me-2"></i>New Dispatch Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-600">Truck <span class="text-danger">*</span></label>
            <select class="form-select" id="d_truck">
              <option value="">— Select available truck —</option>
              <?php foreach ($availableTrucks as $t): ?>
              <option value="<?= $t['truck_id'] ?>">
                <?= htmlspecialchars($t['plate_number'] . ' — ' . $t['brand'] . ' ' . $t['model']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($availableTrucks)): ?>
            <div class="form-text text-warning">No available trucks at the moment.</div>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-600">Route <span class="text-danger">*</span></label>
            <select class="form-select" id="d_route">
              <option value="">— Select route —</option>
              <?php foreach ($routes as $rt): ?>
              <option value="<?= $rt['route_id'] ?>">
                <?= htmlspecialchars($rt['route_name'] . ' (' . $rt['origin'] . ' → ' . $rt['destination'] . ')') ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-600">Driver <span class="text-danger">*</span></label>
            <select class="form-select" id="d_driver">
              <option value="">— Select driver —</option>
              <?php foreach ($drivers as $d): ?>
              <option value="<?= $d['employee_id'] ?>">
                <?= htmlspecialchars($d['full_name'] . ' (' . $d['license_number'] . ')') ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-600">Helper <span class="text-muted fw-400">(optional)</span></label>
            <select class="form-select" id="d_helper">
              <option value="">— None —</option>
              <?php foreach ($helpers as $h): ?>
              <option value="<?= $h['employee_id'] ?>"><?= htmlspecialchars($h['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-600">Scheduled Departure <span class="text-danger">*</span></label>
            <input type="datetime-local" class="form-control" id="d_scheduled">
          </div>
          <div class="col-12">
            <label class="form-label fw-600">Remarks <span class="text-muted fw-400">(optional)</span></label>
            <textarea class="form-control" id="d_remarks" rows="2"
                      placeholder="Any notes for this dispatch…"></textarea>
          </div>
        </div>
        <div id="dispatchFormError" class="alert alert-danger mt-3 d-none"></div>
      </div>
      <div class="modal-footer" style="border-color:var(--card-border);">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="submitDispatchBtn">
          <span id="dispatchBtnText"><i class="bi bi-send me-1"></i>Submit Request</span>
          <span id="dispatchBtnSpinner" class="d-none">
            <span class="spinner-border spinner-border-sm"></span> Submitting…
          </span>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ---- Reject Modal (Head Management only) --------------- -->
<?php if (currentRoleId() === ROLE_HEAD_MANAGEMENT): ?>
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--card-bg);color:var(--text-primary);border:1px solid var(--card-border);">
      <div class="modal-header" style="border-color:var(--card-border);">
        <h5 class="modal-title">Reject Dispatch Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="rejectDispatchId">
        <label class="form-label fw-600">Reason for rejection <span class="text-danger">*</span></label>
        <textarea class="form-control" id="rejectRemarks" rows="3"
                  placeholder="Provide a reason so the dispatcher knows what to fix…"></textarea>
      </div>
      <div class="modal-footer" style="border-color:var(--card-border);">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger btn-sm" id="confirmRejectBtn">
          <i class="bi bi-x-lg me-1"></i>Confirm Rejection
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layoutFoot(); ?>