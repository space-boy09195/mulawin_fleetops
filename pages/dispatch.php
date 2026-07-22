<?php
// ============================================================
// pages/dispatch.php
// Dispatch Requests + Route Management
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/dispatch.js';
$pdo = getDBConnection();

$isHead       = currentRoleId() === ROLE_HEAD_MANAGEMENT;
$isDispatcher = currentRoleId() === ROLE_DISPATCHER;

// ── Dropdown data ─────────────────────────────────────────────────────────────
$availableTrucks = $pdo->query("
    SELECT truck_id, plate_number, brand, model FROM trucks
    WHERE status = 'Available' ORDER BY plate_number
")->fetchAll();

$drivers = $pdo->query("
    SELECT employee_id, full_name, license_number FROM employees
    WHERE is_active = 1 AND license_number IS NOT NULL
    ORDER BY full_name
")->fetchAll();

$helpers = $pdo->query("
    SELECT employee_id, full_name FROM employees
    WHERE is_active = 1 ORDER BY full_name
")->fetchAll();

$routes = $pdo->query("
    SELECT route_id, route_name, origin, destination
    FROM routes WHERE is_active = 1 ORDER BY route_name
")->fetchAll();

// ── All routes for management tab ─────────────────────────────────────────────
$allRoutes = $pdo->query("
    SELECT route_id, route_name, origin, destination,
           distance_km, is_active
    FROM routes ORDER BY route_name
")->fetchAll();

// ── Dispatch requests ─────────────────────────────────────────────────────────
$requests = $pdo->query("
    SELECT
        dr.dispatch_id,
        dr.status,
        dr.scheduled_at,
        dr.remarks,
        dr.requested_at,
        dr.reviewed_at,
        tr.plate_number,
        tr.brand,
        tr.model,
        e_d.full_name   AS driver_name,
        e_h.full_name   AS helper_name,
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
    ORDER BY FIELD(dr.status,'Pending','Approved','Rejected'), dr.requested_at DESC
")->fetchAll();

$pendingCount = count(array_filter($requests, fn($r) => $r['status'] === 'Pending'));
$activeRoutes = count(array_filter($allRoutes, fn($r) => $r['is_active']));

layoutHead('Dispatch', APP_BASE . '/assets/css/dispatch.css');
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Dispatch</h1>
    <p class="page-subtitle">
      <?= $pendingCount ?> pending request<?= $pendingCount !== 1 ? 's' : '' ?> awaiting review
    </p>
  </div>
  <div class="d-flex gap-2">
    <?php if ($isDispatcher): ?>
    <button class="btn btn-primary btn-sm d-flex align-items-center gap-2"
            data-bs-toggle="modal" data-bs-target="#newDispatchModal">
      <i class="bi bi-plus-lg"></i> New Request
    </button>
    <?php endif; ?>
    <?php if ($isHead): ?>
    <button class="btn btn-success btn-sm d-flex align-items-center gap-2"
            data-bs-toggle="modal" data-bs-target="#addRouteModal">
      <i class="bi bi-signpost-2"></i> Add Route
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- ── Tabs ────────────────────────────────────────────────────────────────── -->
<ul class="nav disp-tabs mb-4" id="dispTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="disp-tab active" id="tab-requests" data-bs-toggle="tab"
            data-bs-target="#pane-requests" type="button" role="tab">
      <i class="bi bi-send me-1"></i> Dispatch Requests
      <span class="disp-tab-count"><?= count($requests) ?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="disp-tab" id="tab-routes" data-bs-toggle="tab"
            data-bs-target="#pane-routes" type="button" role="tab">
      <i class="bi bi-signpost-2 me-1"></i> Routes
      <span class="disp-tab-count"><?= count($allRoutes) ?></span>
    </button>
  </li>
</ul>

<div class="tab-content">

  <!-- ── Dispatch Requests pane ──────────────────────────────────────────── -->
  <div class="tab-pane fade show active" id="pane-requests" role="tabpanel">

    <!-- Filter bar -->
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

    <!-- Requests table -->
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
              <?php if ($isHead): ?>
              <th>Action</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody id="dispatchBody">
            <?php if (empty($requests)): ?>
            <tr>
              <td colspan="8" class="text-center text-muted py-4">
              
              </td>
            </tr>
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
                <div class="text-muted" style="font-size:.78rem;">
                  Helper: <?= htmlspecialchars($req['helper_name']) ?>
                </div>
                <?php endif; ?>
              </td>
              <td style="font-size:.82rem;">
                <div class="fw-600"><?= htmlspecialchars($req['route_name']) ?></div>
                <div class="text-muted">
                  <?= htmlspecialchars($req['origin']) ?>
                  <i class="bi bi-arrow-right"></i>
                  <?= htmlspecialchars($req['destination']) ?>
                </div>
              </td>
              <td style="font-size:.82rem;">
                <?= $req['scheduled_at']
                  ? date('M j, Y g:i A', strtotime($req['scheduled_at']))
                  : '<span class="text-muted">—</span>' ?>
              </td>
              <td><span class="status-badge <?= $badgeClass ?>"><?= $req['status'] ?></span></td>
              <td style="font-size:.82rem;">
                <?php if ($req['approved_by']): ?>
                  <?= htmlspecialchars($req['approved_by']) ?><br>
                  <span class="text-muted">
                    <?= date('M j, Y', strtotime($req['reviewed_at'])) ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
                <?php if ($req['remarks']): ?>
                <div class="text-muted mt-1" style="font-size:.75rem;font-style:italic;">
                  "<?= htmlspecialchars($req['remarks']) ?>"
                </div>
                <?php endif; ?>
              </td>
              <?php if ($isHead): ?>
              <td>
                <?php if ($req['status'] === 'Pending'): ?>
                <div class="d-flex gap-1">
                  <button class="btn btn-sm btn-success"
                          onclick="reviewDispatch(<?= $req['dispatch_id'] ?>, 'Approved')"
                          title="Approve">
                    <i class="bi bi-check-lg"></i>
                  </button>
                  <button class="btn btn-sm btn-danger"
                          onclick="openRejectModal(<?= $req['dispatch_id'] ?>)"
                          title="Reject">
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

      <!-- No results row -->
      <div id="noDispatchResults" class="disp-no-results d-none">
        <i class="bi bi-inbox"></i>
        <span>No dispatch requests found.</span>
      </div>
    </div>
  </div>

  <!-- ── Routes pane ─────────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="pane-routes" role="tabpanel">
    <div class="card">
      <div class="card-header-custom">
        <h2 class="card-title-custom">Route Registry</h2>
        <span class="text-muted" style="font-size:.8rem;">
          <?= $activeRoutes ?> active route<?= $activeRoutes !== 1 ? 's' : '' ?>
        </span>
      </div>

      <?php if (empty($allRoutes)): ?>
      <div class="disp-no-results">
        <i class="bi bi-signpost-2"></i>
        <span>No routes added yet.
          <?php if ($isHead): ?>
          <a href="#" data-bs-toggle="modal" data-bs-target="#addRouteModal">Add the first route.</a>
          <?php endif; ?>
        </span>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table-custom" id="routesTable">
          <thead>
            <tr>
              <th>Route Name</th>
              <th>Origin</th>
              <th>Destination</th>
              <th>Distance</th>
              <th>Status</th>
              <?php if ($isHead): ?>
              <th>Actions</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allRoutes as $rt): ?>
            <tr>
              <td class="fw-600"><?= htmlspecialchars($rt['route_name']) ?></td>
              <td><?= htmlspecialchars($rt['origin']) ?></td>
              <td><?= htmlspecialchars($rt['destination']) ?></td>
              <td>
                <?= $rt['distance_km']
                  ? number_format($rt['distance_km'], 1) . ' km'
                  : '<span class="text-muted">—</span>' ?>
              </td>
              <td>
                <span class="status-badge <?= $rt['is_active'] ? 'available' : 'inactive' ?>">
                  <?= $rt['is_active'] ? '🟢 Active' : '⚫ Inactive' ?>
                </span>
              </td>
              <?php if ($isHead): ?>
              <td>
                <div class="d-flex gap-1">
                  <button class="btn btn-sm btn-outline-primary btn-edit-route"
                          title="Edit route"
                          data-id="<?= $rt['route_id'] ?>"
                          data-name="<?= htmlspecialchars($rt['route_name'], ENT_QUOTES) ?>"
                          data-origin="<?= htmlspecialchars($rt['origin'], ENT_QUOTES) ?>"
                          data-destination="<?= htmlspecialchars($rt['destination'], ENT_QUOTES) ?>"
                          data-distance="<?= htmlspecialchars($rt['distance_km'] ?? '', ENT_QUOTES) ?>">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <button class="btn btn-sm <?= $rt['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-toggle-route"
                          title="<?= $rt['is_active'] ? 'Deactivate' : 'Activate' ?>"
                          data-id="<?= $rt['route_id'] ?>"
                          data-active="<?= $rt['is_active'] ?>">
                    <i class="bi <?= $rt['is_active'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                  </button>
                </div>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /tab-content -->

<!-- ══ New Dispatch Modal ══════════════════════════════════════════════════ -->
<?php if ($isDispatcher): ?>
<div class="modal fade" id="newDispatchModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content disp-modal">
      <div class="modal-header disp-modal-header-blue">
        <h5 class="modal-title"><i class="bi bi-send me-2"></i>New Dispatch Request</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body disp-modal-body">
        <div id="dispatchFormError" class="alert alert-danger d-none"></div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="disp-label">Truck <span class="text-danger">*</span></label>
            <select class="form-select disp-input" id="d_truck">
              <option value="">— Select available truck —</option>
              <?php foreach ($availableTrucks as $t): ?>
              <option value="<?= $t['truck_id'] ?>">
                <?= htmlspecialchars($t['plate_number'] . ' — ' . $t['brand'] . ' ' . $t['model']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($availableTrucks)): ?>
            <div class="form-text text-warning">
              <i class="bi bi-exclamation-triangle me-1"></i>No available trucks at the moment.
            </div>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <label class="disp-label">Route <span class="text-danger">*</span></label>
            <select class="form-select disp-input" id="d_route">
              <option value="">— Select route —</option>
              <?php foreach ($routes as $rt): ?>
              <option value="<?= $rt['route_id'] ?>">
                <?= htmlspecialchars($rt['route_name']) ?>
                (<?= htmlspecialchars($rt['origin']) ?> → <?= htmlspecialchars($rt['destination']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($routes)): ?>
            <div class="form-text text-warning">
              <i class="bi bi-exclamation-triangle me-1"></i>No active routes available. Contact Head Management.
            </div>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <label class="disp-label">Driver <span class="text-danger">*</span></label>
            <select class="form-select disp-input" id="d_driver">
              <option value="">— Select driver —</option>
              <?php foreach ($drivers as $d): ?>
              <option value="<?= $d['employee_id'] ?>"
                      data-name="<?= htmlspecialchars($d['full_name'], ENT_QUOTES) ?>">
                <?= htmlspecialchars($d['full_name'] . ' (' . $d['license_number'] . ')') ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="disp-label">Helper <span class="text-muted" style="font-weight:400;">(optional)</span></label>
            <select class="form-select disp-input" id="d_helper">
              <option value="">— None —</option>
              <?php foreach ($helpers as $h): ?>
              <option value="<?= $h['employee_id'] ?>"
                      data-name="<?= htmlspecialchars($h['full_name'], ENT_QUOTES) ?>">
                <?= htmlspecialchars($h['full_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="disp-label">Scheduled Departure <span class="text-danger">*</span></label>
            <input type="datetime-local" class="form-control disp-input" id="d_scheduled">
          </div>
          <div class="col-12">
            <label class="disp-label">Remarks <span class="text-muted" style="font-weight:400;">(optional)</span></label>
            <textarea class="form-control disp-input" id="d_remarks" rows="2"
                      placeholder="Any notes for this dispatch…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer disp-modal-footer">
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

<!-- ══ Reject Modal ════════════════════════════════════════════════════════ -->
<?php if ($isHead): ?>
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content disp-modal">
      <div class="modal-header disp-modal-header-red">
        <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Dispatch Request</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body disp-modal-body">
        <input type="hidden" id="rejectDispatchId">
        <label class="disp-label">Reason for rejection <span class="text-danger">*</span></label>
        <textarea class="form-control disp-input" id="rejectRemarks" rows="3"
                  placeholder="Provide a reason so the dispatcher knows what to fix…"></textarea>
      </div>
      <div class="modal-footer disp-modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger btn-sm" id="confirmRejectBtn">
          <i class="bi bi-x-lg me-1"></i>Confirm Rejection
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Add Route Modal ════════════════════════════════════════════════════ -->
<div class="modal fade" id="addRouteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content disp-modal">
      <div class="modal-header disp-modal-header-green">
        <h5 class="modal-title"><i class="bi bi-signpost-2 me-2"></i>Add Route</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body disp-modal-body">
        <div id="addRouteAlert" class="alert d-none" role="alert"></div>
        <div class="row g-3">
          <div class="col-12">
            <label class="disp-label">Route Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control disp-input" id="ar_name"
                   placeholder="e.g. Manila to Davao">
          </div>
          <div class="col-md-6">
            <label class="disp-label">Origin <span class="text-danger">*</span></label>
            <input type="text" class="form-control disp-input" id="ar_origin"
                   placeholder="e.g. Manila Port">
          </div>
          <div class="col-md-6">
            <label class="disp-label">Destination <span class="text-danger">*</span></label>
            <input type="text" class="form-control disp-input" id="ar_destination"
                   placeholder="e.g. Davao City">
          </div>
          <div class="col-md-6">
            <label class="disp-label">Distance (km) <span class="text-muted" style="font-weight:400;">(optional)</span></label>
            <input type="number" class="form-control disp-input" id="ar_distance"
                   min="0" step="0.1" placeholder="e.g. 1180.5">
          </div>

          <!-- ── Map Preview ─────────────────────────────────────────────── -->
          <div class="col-12">
            <label class="disp-label">Map Preview</label>
            <div class="row g-2">
              <div class="col-md-6">
                <div class="route-map-wrap" id="ar_origin_map_wrap">
                  <div class="route-map-placeholder" id="ar_origin_map_placeholder">
                    <i class="bi bi-geo-alt"></i><span>Origin preview</span>
                  </div>
                  <iframe class="route-map-frame d-none" id="ar_origin_map"
                          loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
              </div>
              <div class="col-md-6">
                <div class="route-map-wrap" id="ar_destination_map_wrap">
                  <div class="route-map-placeholder" id="ar_destination_map_placeholder">
                    <i class="bi bi-geo-alt-fill"></i><span>Destination preview</span>
                  </div>
                  <iframe class="route-map-frame d-none" id="ar_destination_map"
                          loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
              </div>
              <div class="col-12">
                <div class="route-map-wrap route-map-wrap-lg" id="ar_route_map_wrap">
                  <div class="route-map-placeholder" id="ar_route_map_placeholder">
                    <i class="bi bi-signpost-2"></i><span>Enter both origin and destination to preview the route</span>
                  </div>
                  <iframe class="route-map-frame route-map-frame-lg d-none" id="ar_route_map"
                          loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer disp-modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success btn-sm" id="submitAddRouteBtn">
          <span id="arBtnText"><i class="bi bi-plus-lg me-1"></i>Add Route</span>
          <span id="arBtnSpinner" class="d-none">
            <span class="spinner-border spinner-border-sm"></span> Saving…
          </span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Edit Route Modal ═══════════════════════════════════════════════════ -->
<div class="modal fade" id="editRouteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content disp-modal">
      <div class="modal-header disp-modal-header-blue">
        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Route</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body disp-modal-body">
        <div id="editRouteAlert" class="alert d-none" role="alert"></div>
        <input type="hidden" id="er_id">
        <div class="row g-3">
          <div class="col-12">
            <label class="disp-label">Route Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control disp-input" id="er_name">
          </div>
          <div class="col-md-6">
            <label class="disp-label">Origin <span class="text-danger">*</span></label>
            <input type="text" class="form-control disp-input" id="er_origin">
          </div>
          <div class="col-md-6">
            <label class="disp-label">Destination <span class="text-danger">*</span></label>
            <input type="text" class="form-control disp-input" id="er_destination">
          </div>
          <div class="col-md-6">
            <label class="disp-label">Distance (km) <span class="text-muted" style="font-weight:400;">(optional)</span></label>
            <input type="number" class="form-control disp-input" id="er_distance"
                   min="0" step="0.1">
          </div>

          <!-- ── Map Preview ─────────────────────────────────────────────── -->
          <div class="col-12">
            <label class="disp-label">Map Preview</label>
            <div class="row g-2">
              <div class="col-md-6">
                <div class="route-map-wrap" id="er_origin_map_wrap">
                  <div class="route-map-placeholder" id="er_origin_map_placeholder">
                    <i class="bi bi-geo-alt"></i><span>Origin preview</span>
                  </div>
                  <iframe class="route-map-frame d-none" id="er_origin_map"
                          loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
              </div>
              <div class="col-md-6">
                <div class="route-map-wrap" id="er_destination_map_wrap">
                  <div class="route-map-placeholder" id="er_destination_map_placeholder">
                    <i class="bi bi-geo-alt-fill"></i><span>Destination preview</span>
                  </div>
                  <iframe class="route-map-frame d-none" id="er_destination_map"
                          loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
              </div>
              <div class="col-12">
                <div class="route-map-wrap route-map-wrap-lg" id="er_route_map_wrap">
                  <div class="route-map-placeholder" id="er_route_map_placeholder">
                    <i class="bi bi-signpost-2"></i><span>Enter both origin and destination to preview the route</span>
                  </div>
                  <iframe class="route-map-frame route-map-frame-lg d-none" id="er_route_map"
                          loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer disp-modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="submitEditRouteBtn">
          <span id="erBtnText"><i class="bi bi-check-lg me-1"></i>Save Changes</span>
          <span id="erBtnSpinner" class="d-none">
            <span class="spinner-border spinner-border-sm"></span> Saving…
          </span>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layoutFoot(); ?>