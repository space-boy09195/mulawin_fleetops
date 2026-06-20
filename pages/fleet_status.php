<?php
// ============================================================
// pages/fleet_status.php
// Fleet Status — view all trucks and their current status
// Accessible by: Head Management, Dispatcher
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER]);

// Tell layoutFoot() to load the page-specific JS after layout.js
$GLOBALS['page_js'] = APP_BASE . '/assets/js/fleet_status.js';

// ---- Fetch fleet data --------------------------------------
$pdo = getDBConnection();

// Summary counts per status
$summaryStmt = $pdo->query(
    "SELECT status, COUNT(*) AS cnt FROM trucks GROUP BY status"
);
$summary = [
    'Available'        => 0,
    'Deployed'         => 0,
    'Under Maintenance'=> 0,
    'Inactive'         => 0,
];
foreach ($summaryStmt->fetchAll() as $row) {
    $summary[$row['status']] = (int)$row['cnt'];
}
$totalTrucks = array_sum($summary);

// Full truck list with current active trip info if deployed
$trucks = $pdo->query(
    "SELECT
       t.truck_id,
       t.plate_number,
       t.brand,
       t.model,
       t.year_model,
       t.body_type,
       t.fuel_type,
       t.capacity_tons,
       t.status,
       -- If deployed, pull the active trip number and driver
       tr.trip_number,
       e.full_name AS driver_name,
       r.origin,
       r.destination
     FROM trucks t
     LEFT JOIN dispatch_requests dr
            ON dr.truck_id = t.truck_id
           AND dr.status   = 'Approved'
     LEFT JOIN trips tr
            ON tr.dispatch_id = dr.dispatch_id
           AND tr.status NOT IN ('Completed','Cancelled')
     LEFT JOIN employees e  ON dr.driver_id  = e.employee_id
     LEFT JOIN routes r     ON dr.route_id   = r.route_id
     ORDER BY
       FIELD(t.status,'Deployed','Available','Under Maintenance','Inactive'),
       t.plate_number"
)->fetchAll();

layoutHead('Fleet Status', APP_BASE . '/assets/css/fleet_status.css');
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Fleet Status</h1>
    <p class="page-subtitle">Real-time overview of all <?= $totalTrucks ?> trucks</p>
  </div>
  <?php if (currentRoleId() === ROLE_HEAD_MANAGEMENT || currentRoleId() === ROLE_DISPATCHER): ?>
  <a href="<?= APP_BASE ?>/pages/dispatch.php" class="btn btn-primary btn-sm d-flex align-items-center gap-2">
    <i class="bi bi-send"></i> New Dispatch
  </a>
  <?php endif; ?>
</div>

<!-- ---- Summary stat cards -------------------------------- -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon amber"><i class="bi bi-truck"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= $totalTrucks ?></div>
        <div class="stat-label">Total Trucks</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-check-circle"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= $summary['Available'] ?></div>
        <div class="stat-label">Available</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-arrow-right-circle"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= $summary['Deployed'] ?></div>
        <div class="stat-label">Deployed</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon red"><i class="bi bi-tools"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= $summary['Under Maintenance'] ?></div>
        <div class="stat-label">Under Maintenance</div>
      </div>
    </div>
  </div>
</div>

<!-- ---- Filter bar ---------------------------------------- -->
<div class="card mb-4">
  <div class="card-body-custom">
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <span class="text-muted" style="font-size:.8rem;">Filter:</span>
      <button class="filter-btn active" data-filter="all">All (<?= $totalTrucks ?>)</button>
      <button class="filter-btn" data-filter="Available">Available (<?= $summary['Available'] ?>)</button>
      <button class="filter-btn" data-filter="Deployed">Deployed (<?= $summary['Deployed'] ?>)</button>
      <button class="filter-btn" data-filter="Under Maintenance">Maintenance (<?= $summary['Under Maintenance'] ?>)</button>
      <button class="filter-btn" data-filter="Inactive">Inactive (<?= $summary['Inactive'] ?>)</button>

      <div class="ms-auto">
        <input type="text" id="truckSearch" class="form-control form-control-sm"
               placeholder="Search plate, brand, model…" style="width:220px;">
      </div>
    </div>
  </div>
</div>

<!-- ---- Truck table --------------------------------------- -->
<div class="card">
  <div class="card-header-custom">
    <h2 class="card-title-custom">Truck Registry</h2>
    <span class="text-muted" style="font-size:.8rem;" id="rowCount"></span>
  </div>

  <div class="table-responsive">
    <table class="table-custom" id="fleetTable">
      <thead>
        <tr>
          <th>Plate No.</th>
          <th>Truck</th>
          <th>Body Type</th>
          <th>Fuel</th>
          <th>Capacity</th>
          <th>Status</th>
          <th>Current Assignment</th>
          <?php if (currentRoleId() === ROLE_HEAD_MANAGEMENT || currentRoleId() === ROLE_DISPATCHER): ?>
          <th>Action</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody id="fleetBody">
        <?php if (empty($trucks)): ?>
        <tr>
          <td colspan="8" class="text-center text-muted py-4">
            No trucks found. Add trucks to get started.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($trucks as $truck):
          // Map status to badge class
          $badgeClass = match($truck['status']) {
            'Available'         => 'available',
            'Deployed'          => 'deployed',
            'Under Maintenance' => 'maintenance',
            'Inactive'          => 'inactive',
            default             => 'inactive',
          };
          $dot = match($truck['status']) {
            'Available'         => '🟢',
            'Deployed'          => '🔵',
            'Under Maintenance' => '🟡',
            'Inactive'          => '⚫',
            default             => '⚫',
          };
        ?>
        <tr data-status="<?= htmlspecialchars($truck['status']) ?>"
            data-search="<?= strtolower(htmlspecialchars($truck['plate_number'] . ' ' . $truck['brand'] . ' ' . $truck['model'])) ?>">
          <td>
            <span class="fw-600" style="font-family:monospace;letter-spacing:.03em;">
              <?= htmlspecialchars($truck['plate_number']) ?>
            </span>
          </td>
          <td>
            <div style="font-weight:600;"><?= htmlspecialchars($truck['brand'] . ' ' . $truck['model']) ?></div>
            <div class="text-muted" style="font-size:.78rem;"><?= htmlspecialchars($truck['year_model']) ?></div>
          </td>
          <td><?= htmlspecialchars($truck['body_type'] ?? '—') ?></td>
          <td><?= htmlspecialchars($truck['fuel_type']) ?></td>
          <td>
            <?= $truck['capacity_tons'] ? htmlspecialchars($truck['capacity_tons']) . ' t' : '—' ?>
          </td>
          <td>
            <span class="status-badge <?= $badgeClass ?>">
              <?= $dot ?> <?= htmlspecialchars($truck['status']) ?>
            </span>
          </td>
          <td>
            <?php if ($truck['status'] === 'Deployed' && $truck['trip_number']): ?>
              <div style="font-size:.82rem;">
                <span class="fw-600"><?= htmlspecialchars($truck['trip_number']) ?></span><br>
                <span class="text-muted">Driver: <?= htmlspecialchars($truck['driver_name'] ?? '—') ?></span><br>
                <span class="text-muted">
                  <?= htmlspecialchars($truck['origin'] ?? '') ?>
                  <i class="bi bi-arrow-right"></i>
                  <?= htmlspecialchars($truck['destination'] ?? '') ?>
                </span>
              </div>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <?php if (currentRoleId() === ROLE_HEAD_MANAGEMENT || currentRoleId() === ROLE_DISPATCHER): ?>
          <td>
            <button class="btn btn-sm btn-outline-secondary"
                    onclick="openStatusModal(<?= $truck['truck_id'] ?>, '<?= htmlspecialchars($truck['plate_number']) ?>', '<?= htmlspecialchars($truck['status']) ?>')"
                    title="Update status">
              <i class="bi bi-pencil"></i>
            </button>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ---- Update Status Modal ------------------------------- -->
<?php if (currentRoleId() === ROLE_HEAD_MANAGEMENT || currentRoleId() === ROLE_DISPATCHER): ?>
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--card-bg);color:var(--text-primary);border:1px solid var(--card-border);">
      <div class="modal-header" style="border-color:var(--card-border);">
        <h5 class="modal-title" id="statusModalLabel">Update Truck Status</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-3">
          Truck: <strong id="modalPlate"></strong>
        </p>
        <input type="hidden" id="modalTruckId">
        <label class="form-label fw-600">New Status</label>
        <select class="form-select" id="modalStatus">
          <option value="Available">Available</option>
          <option value="Deployed">Deployed</option>
          <option value="Under Maintenance">Under Maintenance</option>
          <option value="Inactive">Inactive</option>
        </select>
      </div>
      <div class="modal-footer" style="border-color:var(--card-border);">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="confirmStatusBtn">
          <span id="statusBtnText"><i class="bi bi-check-lg me-1"></i>Update Status</span>
          <span id="statusBtnSpinner" class="d-none">
            <span class="spinner-border spinner-border-sm"></span> Saving…
          </span>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layoutFoot(); ?>