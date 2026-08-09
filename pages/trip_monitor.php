<?php
// ============================================================
// pages/trip_monitor.php
// Trip Monitoring — active trips, status updates, late alerts
// Accessible by: Head Management, Dispatcher
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/trip_monitor.js';

$pdo = getDBConnection();

// ---- Auto-flag late trips before fetching -----------------
$pdo->exec(
    "UPDATE trips
        SET is_late = 1
      WHERE expected_arrival < NOW()
        AND status NOT IN ('Completed','Cancelled')
        AND is_late = 0"
);

// ---- Summary counts ---------------------------------------
$counts = $pdo->query(
    "SELECT
       COUNT(*)                                             AS total,
       SUM(status NOT IN ('Completed','Cancelled'))        AS active,
       SUM(status = 'Completed')                           AS completed,
       SUM(is_late = 1 AND status NOT IN ('Completed','Cancelled')) AS late
     FROM trips"
)->fetch();

// ---- Fetch trips ------------------------------------------
$trips = $pdo->query(
    "SELECT
       t.trip_id,
       t.trip_number,
       t.status,
       t.cargo_description,
       t.expected_arrival,
       t.actual_arrival,
       t.is_late,
       t.created_at,
       tr.plate_number,
       tr.brand,
       tr.model,
       e_d.full_name  AS driver_name,
       e_h.full_name  AS helper_name,
       r.origin,
       r.destination,
       -- Latest update note
       (SELECT location_note FROM trip_updates
         WHERE trip_id = t.trip_id
         ORDER BY updated_at DESC LIMIT 1) AS last_location,
       -- Departure time = when this trip first moved to 'In Transit'
       (SELECT MIN(updated_at) FROM trip_updates
         WHERE trip_id = t.trip_id AND status = 'In Transit') AS departed_at
     FROM trips t
     JOIN dispatch_requests dr ON t.dispatch_id  = dr.dispatch_id
     JOIN trucks tr             ON dr.truck_id    = tr.truck_id
     JOIN employees e_d         ON dr.driver_id   = e_d.employee_id
     LEFT JOIN employees e_h    ON dr.helper_id   = e_h.employee_id
     JOIN routes r              ON dr.route_id    = r.route_id
     ORDER BY
       FIELD(t.status,'In Transit','Loading','Unloading','Completed','Cancelled'),
       t.is_late DESC,
       t.expected_arrival ASC"
)->fetchAll();

layoutHead('Trip Monitoring', APP_BASE . '/assets/css/trip_monitor.css');
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Trip Monitoring</h1>
    <p class="page-subtitle">Track all trips in real time</p>
  </div>
  <?php if (currentRoleId() === ROLE_DISPATCHER): ?>
  <a href="<?= APP_BASE ?>/pages/dispatch.php" class="btn btn-primary btn-sm d-flex align-items-center gap-2">
    <i class="bi bi-send"></i> New Dispatch
  </a>
  <?php endif; ?>
</div>

<!-- ---- Summary cards ------------------------------------- -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon amber"><i class="bi bi-map"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= (int)$counts['total'] ?></div>
        <div class="stat-label">Total Trips</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-arrow-right-circle"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= (int)$counts['active'] ?></div>
        <div class="stat-label">Active</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-check-circle"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= (int)$counts['completed'] ?></div>
        <div class="stat-label">Completed</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon red"><i class="bi bi-alarm"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= (int)$counts['late'] ?></div>
        <div class="stat-label">Late</div>
      </div>
    </div>
  </div>
</div>

<?php if ((int)$counts['late'] > 0): ?>
<!-- ---- Late trip alert banner ---------------------------- -->
<div class="alert-banner mb-4">
  <i class="bi bi-exclamation-triangle-fill"></i>
  <strong><?= (int)$counts['late'] ?> trip<?= $counts['late'] > 1 ? 's are' : ' is' ?> overdue.</strong>
  Use the filter below to view them.
</div>
<?php endif; ?>

<!-- ---- Filter bar ---------------------------------------- -->
<div class="card mb-4">
  <div class="card-body-custom">
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <span class="text-muted" style="font-size:.8rem;">Filter:</span>
      <button class="filter-btn active" data-filter="all">All</button>
      <button class="filter-btn" data-filter="Loading">Loading</button>
      <button class="filter-btn" data-filter="In Transit">In Transit</button>
      <button class="filter-btn" data-filter="Unloading">Unloading</button>
      <button class="filter-btn" data-filter="Completed">Completed</button>
      <button class="filter-btn late-filter" data-filter="late">
        <i class="bi bi-alarm"></i> Late Only
      </button>
      <div class="ms-auto">
        <input type="text" id="tripSearch" class="form-control form-control-sm"
               placeholder="Search trip, plate, driver…" style="width:220px;">
      </div>
    </div>
  </div>
</div>

<!-- ---- Trips table --------------------------------------- -->
<div class="card">
  <div class="card-header-custom">
    <h2 class="card-title-custom">Trip List</h2>
    <span class="text-muted" style="font-size:.8rem;" id="rowCount"></span>
  </div>
  <div class="table-responsive">
    <table class="table-custom" id="tripTable">
      <thead>
        <tr>
          <th>Trip No.</th>
          <th>Truck</th>
          <th>Driver</th>
          <th>Route</th>
          <th>Status</th>
          <th>ETA</th>
          <th>Departed</th>
          <th>Last Location</th>
          <?php if (currentRoleId() === ROLE_DISPATCHER): ?>
          <th>Actions</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody id="tripBody">
        <?php if (empty($trips)): ?>
        <tr>
          <td colspan="9" class="text-center text-muted py-4">No trips found.</td>
        </tr>
        <?php else: ?>
        <?php foreach ($trips as $trip):
          $statusClass = match($trip['status']) {
            'Loading'    => 'maintenance',
            'In Transit' => 'deployed',
            'Unloading'  => 'available',
            'Completed'  => 'available',
            'Cancelled'  => 'inactive',
            default      => 'inactive',
          };
          $isLate    = (bool)$trip['is_late'];
          $isActive  = !in_array($trip['status'], ['Completed','Cancelled']);
          $searchStr = strtolower(
            $trip['trip_number'] . ' ' .
            $trip['plate_number'] . ' ' .
            $trip['driver_name'] . ' ' .
            $trip['origin'] . ' ' .
            $trip['destination']
          );
        ?>
        <tr data-status="<?= htmlspecialchars($trip['status']) ?>"
            data-late="<?= $isLate ? '1' : '0' ?>"
            data-search="<?= htmlspecialchars($searchStr) ?>"
            class="<?= $isLate && $isActive ? 'row-late' : '' ?>">
          <td>
            <span class="trip-number"><?= htmlspecialchars($trip['trip_number']) ?></span>
            <?php if ($isLate && $isActive): ?>
            <span class="late-pill">LATE</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-weight:600;"><?= htmlspecialchars($trip['plate_number']) ?></div>
            <div class="text-muted" style="font-size:.78rem;">
              <?= htmlspecialchars($trip['brand'] . ' ' . $trip['model']) ?>
            </div>
          </td>
          <td>
            <div><?= htmlspecialchars($trip['driver_name']) ?></div>
            <?php if ($trip['helper_name']): ?>
            <div class="text-muted" style="font-size:.78rem;">
              Helper: <?= htmlspecialchars($trip['helper_name']) ?>
            </div>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-size:.82rem;">
              <?= htmlspecialchars($trip['origin']) ?>
              <i class="bi bi-arrow-right text-muted"></i>
              <?= htmlspecialchars($trip['destination']) ?>
            </div>
          </td>
          <td>
            <span class="status-badge <?= $statusClass ?>">
              <?= htmlspecialchars($trip['status']) ?>
            </span>
          </td>
          <td>
            <?php if ($trip['status'] === 'Completed'): ?>
              <span class="text-muted" style="font-size:.82rem;">
                <?= $trip['actual_arrival'] ? date('M j, g:i A', strtotime($trip['actual_arrival'])) : '—' ?>
              </span>
            <?php elseif ($trip['expected_arrival']): ?>
              <span class="<?= $isLate ? 'text-danger fw-600' : '' ?>" style="font-size:.82rem;">
                <?= date('M j, g:i A', strtotime($trip['expected_arrival'])) ?>
              </span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td style="font-size:.82rem; color:var(--text-muted);">
            <?= $trip['departed_at'] ? date('M j, g:i A', strtotime($trip['departed_at'])) : '—' ?>
          </td>
          <td style="font-size:.82rem; color:var(--text-muted);">
            <?= htmlspecialchars($trip['last_location'] ?? '—') ?>
          </td>
          <?php if (currentRoleId() === ROLE_DISPATCHER): ?>
          <td>
            <div class="d-flex gap-1">
              <?php if ($isActive): ?>
              <button class="btn btn-sm btn-outline-primary"
                      onclick="openUpdateModal(<?= $trip['trip_id'] ?>, '<?= htmlspecialchars($trip['trip_number']) ?>', '<?= htmlspecialchars($trip['status']) ?>')"
                      title="Update status">
                <i class="bi bi-pencil"></i>
              </button>
              <?php endif; ?>
              <a href="<?= APP_BASE ?>/pages/incidents.php?trip_id=<?= $trip['trip_id'] ?>"
                 class="btn btn-sm btn-outline-warning" title="Log incident">
                <i class="bi bi-exclamation-triangle"></i>
              </a>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        <tr id="noTripResults" class="d-none">
          <td colspan="9">
            <div class="no-results">
              <i class="bi bi-search"></i>
              <span>No trips match your filters.</span>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ---- Trip Update Modal --------------------------------- -->
<?php if (currentRoleId() === ROLE_DISPATCHER): ?>
<div class="modal fade" id="updateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--card-bg);color:var(--text-primary);border:1px solid var(--card-border);">
      <div class="modal-header" style="border-color:var(--card-border);">
        <h5 class="modal-title">Update Trip Status</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-3">Trip: <strong id="modalTripNumber"></strong></p>
        <input type="hidden" id="modalTripId">

        <div class="mb-3">
          <label class="form-label fw-600">New Status</label>
          <select class="form-select" id="modalStatus">
            <option value="Loading">Loading</option>
            <option value="In Transit">In Transit</option>
            <option value="Unloading">Unloading</option>
            <option value="Completed">Completed</option>
            <option value="Cancelled">Cancelled</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-600">Current Location <span class="text-muted fw-400">(optional)</span></label>
          <input type="text" class="form-control" id="modalLocation"
                 placeholder="e.g. Batangas City Toll">
        </div>
        <div class="mb-1">
          <label class="form-label fw-600">Notes <span class="text-muted fw-400">(optional)</span></label>
          <textarea class="form-control" id="modalNotes" rows="2"
                    placeholder="Any remarks for this update…"></textarea>
        </div>
      </div>
      <div class="modal-footer" style="border-color:var(--card-border);">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="confirmUpdateBtn">
          <span id="updateBtnText"><i class="bi bi-check-lg me-1"></i>Save Update</span>
          <span id="updateBtnSpinner" class="d-none">
            <span class="spinner-border spinner-border-sm"></span> Saving…
          </span>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layoutFoot(); ?>