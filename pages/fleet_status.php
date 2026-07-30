<?php
// ============================================================
// pages/fleet_status.php
// Fleet Status — view, add, and edit trucks
// Accessible by: Head Management, Dispatcher
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_MAINTENANCE]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/fleet_status.js';

$pdo = getDBConnection();

// ── Summary counts ────────────────────────────────────────────────────────────
$summaryStmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM trucks GROUP BY status");
$summary = ['Available' => 0, 'Deployed' => 0, 'Under Maintenance' => 0, 'Inactive' => 0];
foreach ($summaryStmt->fetchAll() as $row) {
    $summary[$row['status']] = (int)$row['cnt'];
}
$totalTrucks = array_sum($summary);

// ── Full truck list with active trip info ─────────────────────────────────────
$trucks = $pdo->query("
    SELECT
        t.truck_id,
        t.plate_number,
        t.chassis_number,
        t.engine_number,
        t.brand,
        t.model,
        t.year_model,
        t.body_type,
        t.fuel_type,
        t.capacity_tons,
        t.status,
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
    LEFT JOIN employees e ON dr.driver_id = e.employee_id
    LEFT JOIN routes r    ON dr.route_id  = r.route_id
    ORDER BY
        FIELD(t.status,'Deployed','Available','Under Maintenance','Inactive'),
        t.plate_number
")->fetchAll();

$isHead = currentRoleId() === ROLE_HEAD_MANAGEMENT;

layoutHead('Fleet Status', APP_BASE . '/assets/css/fleet_status.css');
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Fleet Status</h1>
    <p class="page-subtitle">Real-time overview of all <?= $totalTrucks ?> trucks</p>
  </div>
  <div class="d-flex gap-2">
    <?php if ($isHead): ?>
    <button class="btn btn-success btn-sm d-flex align-items-center gap-2"
            data-bs-toggle="modal" data-bs-target="#addTruckModal">
      <i class="bi bi-plus-lg"></i> Add Truck
    </button>
    <?php endif; ?>
    <?php if (in_array(currentRoleId(), [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER])): ?>
    <a href="<?= APP_BASE ?>/pages/dispatch.php"
       class="btn btn-primary btn-sm d-flex align-items-center gap-2">
      <i class="bi bi-send"></i> New Dispatch
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Stat cards -->
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

<!-- Filter bar -->
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

<!-- Truck table -->
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
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="fleetBody">
        <?php if (empty($trucks)): ?>
        <tr>
          <td colspan="8" class="text-center text-muted py-4">
            No trucks found.
            <?php if ($isHead): ?>
            <a href="#" data-bs-toggle="modal" data-bs-target="#addTruckModal">Add the first truck.</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($trucks as $truck):
          $badgeClass = match($truck['status']) {
            'Available'         => 'available',
            'Deployed'          => 'deployed',
            'Under Maintenance' => 'maintenance',
            default             => 'inactive',
          };
          $dot = match($truck['status']) {
            'Available'         => '🟢',
            'Deployed'          => '🔵',
            'Under Maintenance' => '🟡',
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
          <td><?= $truck['capacity_tons'] ? htmlspecialchars($truck['capacity_tons']) . ' t' : '—' ?></td>
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
          <td>
            <div class="d-flex gap-1">
              <!-- Status update (both roles) -->
              <button class="btn btn-sm btn-outline-secondary"
                      onclick="openStatusModal(<?= $truck['truck_id'] ?>, '<?= htmlspecialchars($truck['plate_number'], ENT_QUOTES) ?>', '<?= htmlspecialchars($truck['status'], ENT_QUOTES) ?>')"
                      title="Update status">
                <i class="bi bi-arrow-repeat"></i>
              </button>
              <!-- Edit (Head Management only) -->
              <?php if ($isHead): ?>
              <button class="btn btn-sm btn-outline-primary btn-edit-truck"
                      title="Edit truck"
                      data-id="<?= $truck['truck_id'] ?>"
                      data-plate="<?= htmlspecialchars($truck['plate_number'], ENT_QUOTES) ?>"
                      data-chassis="<?= htmlspecialchars($truck['chassis_number'] ?? '', ENT_QUOTES) ?>"
                      data-engine="<?= htmlspecialchars($truck['engine_number'] ?? '', ENT_QUOTES) ?>"
                      data-brand="<?= htmlspecialchars($truck['brand'], ENT_QUOTES) ?>"
                      data-model="<?= htmlspecialchars($truck['model'], ENT_QUOTES) ?>"
                      data-year="<?= htmlspecialchars($truck['year_model'], ENT_QUOTES) ?>"
                      data-body="<?= htmlspecialchars($truck['body_type'] ?? '', ENT_QUOTES) ?>"
                      data-fuel="<?= htmlspecialchars($truck['fuel_type'], ENT_QUOTES) ?>"
                      data-capacity="<?= htmlspecialchars($truck['capacity_tons'] ?? '', ENT_QUOTES) ?>"
                      data-status="<?= htmlspecialchars($truck['status'], ENT_QUOTES) ?>">
                <i class="bi bi-pencil"></i>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        <tr id="noFleetResults" class="d-none">
          <td colspan="8">
            <div class="no-results">
              <i class="bi bi-search"></i>
              <span>No trucks match your filters.</span>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ══ Add Truck Modal ════════════════════════════════════════════════════ -->
<?php if ($isHead): ?>
<div class="modal fade" id="addTruckModal" tabindex="-1" aria-labelledby="addTruckLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content fleet-modal">
      <div class="modal-header fleet-modal-header-add">
        <h5 class="modal-title" id="addTruckLabel">
          <i class="bi bi-truck me-2"></i>Add New Truck
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body fleet-modal-body">
        <div id="addTruckAlert" class="alert d-none" role="alert"></div>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="fleet-label">Plate Number <span class="text-danger">*</span></label>
            <input type="text" class="form-control fleet-input" id="at_plate"
                   placeholder="e.g. ABC 1234" required>
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Brand <span class="text-danger">*</span></label>
            <input type="text" class="form-control fleet-input" id="at_brand"
                   placeholder="e.g. Isuzu" required>
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Model <span class="text-danger">*</span></label>
            <input type="text" class="form-control fleet-input" id="at_model"
                   placeholder="e.g. Giga Forward" required>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Year Model <span class="text-danger">*</span></label>
            <input type="number" class="form-control fleet-input" id="at_year"
                   min="1990" max="<?= date('Y') + 1 ?>"
                   placeholder="<?= date('Y') ?>" required>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Body Type</label>
            <input type="text" class="form-control fleet-input" id="at_body"
                   placeholder="e.g. Closed Van, Flatbed"
                   list="bodyTypeList">
            <datalist id="bodyTypeList">
              <option value="Closed Van">
              <option value="Flatbed">
              <option value="Reefer">
              <option value="Tanker">
              <option value="Dump Truck">
              <option value="Wing Van">
            </datalist>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Fuel Type <span class="text-danger">*</span></label>
            <select class="form-select fleet-input" id="at_fuel" required>
              <option value="Diesel" selected>Diesel</option>
              <option value="Gasoline">Gasoline</option>
              <option value="LPG">LPG</option>
              <option value="Electric">Electric</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Capacity (tons)</label>
            <input type="number" class="form-control fleet-input" id="at_capacity"
                   min="0" step="0.01" placeholder="e.g. 5.00">
          </div>
          <div class="col-md-6">
            <label class="fleet-label">Chassis Number</label>
            <input type="text" class="form-control fleet-input" id="at_chassis"
                   placeholder="Optional">
          </div>
          <div class="col-md-6">
            <label class="fleet-label">Engine Number</label>
            <input type="text" class="form-control fleet-input" id="at_engine"
                   placeholder="Optional">
          </div>
        </div>
      </div>
      <div class="modal-footer fleet-modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success btn-sm" id="submitAddTruckBtn">
          <span id="atBtnText"><i class="bi bi-plus-lg me-1"></i>Add Truck</span>
          <span id="atBtnSpinner" class="d-none">
            <span class="spinner-border spinner-border-sm"></span> Saving…
          </span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Edit Truck Modal ═══════════════════════════════════════════════════ -->
<div class="modal fade" id="editTruckModal" tabindex="-1" aria-labelledby="editTruckLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content fleet-modal">
      <div class="modal-header fleet-modal-header-edit">
        <h5 class="modal-title" id="editTruckLabel">
          <i class="bi bi-pencil me-2"></i>Edit Truck
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body fleet-modal-body">
        <div id="editTruckAlert" class="alert d-none" role="alert"></div>
        <input type="hidden" id="et_id">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="fleet-label">Plate Number <span class="text-danger">*</span></label>
            <input type="text" class="form-control fleet-input" id="et_plate" required>
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Brand <span class="text-danger">*</span></label>
            <input type="text" class="form-control fleet-input" id="et_brand" required>
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Model <span class="text-danger">*</span></label>
            <input type="text" class="form-control fleet-input" id="et_model" required>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Year Model <span class="text-danger">*</span></label>
            <input type="number" class="form-control fleet-input" id="et_year"
                   min="1990" max="<?= date('Y') + 1 ?>" required>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Body Type</label>
            <input type="text" class="form-control fleet-input" id="et_body" list="bodyTypeList">
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Fuel Type <span class="text-danger">*</span></label>
            <select class="form-select fleet-input" id="et_fuel" required>
              <option value="Diesel">Diesel</option>
              <option value="Gasoline">Gasoline</option>
              <option value="LPG">LPG</option>
              <option value="Electric">Electric</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="fleet-label">Capacity (tons)</label>
            <input type="number" class="form-control fleet-input" id="et_capacity"
                   min="0" step="0.01">
          </div>
          <div class="col-md-6">
            <label class="fleet-label">Chassis Number</label>
            <input type="text" class="form-control fleet-input" id="et_chassis">
          </div>
          <div class="col-md-6">
            <label class="fleet-label">Engine Number</label>
            <input type="text" class="form-control fleet-input" id="et_engine">
          </div>
          <div class="col-md-4">
            <label class="fleet-label">Status</label>
            <select class="form-select fleet-input" id="et_status">
              <option value="Available">Available</option>
              <option value="Deployed">Deployed</option>
              <option value="Under Maintenance">Under Maintenance</option>
              <option value="Inactive">Inactive</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer fleet-modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="submitEditTruckBtn">
          <span id="etBtnText"><i class="bi bi-check-lg me-1"></i>Save Changes</span>
          <span id="etBtnSpinner" class="d-none">
            <span class="spinner-border spinner-border-sm"></span> Saving…
          </span>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══ Status Update Modal (existing, preserved) ══════════════════════════ -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content fleet-modal">
      <div class="modal-header fleet-modal-header-status">
        <h5 class="modal-title" id="statusModalLabel">
          <i class="bi bi-arrow-repeat me-2"></i>Update Truck Status
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body fleet-modal-body">
        <p class="text-muted mb-3">
          Truck: <strong id="modalPlate"></strong>
        </p>
        <input type="hidden" id="modalTruckId">
        <label class="fleet-label">New Status</label>
        <select class="form-select fleet-input" id="modalStatus">
          <option value="Available">Available</option>
          <option value="Deployed">Deployed</option>
          <option value="Under Maintenance">Under Maintenance</option>
          <option value="Inactive">Inactive</option>
        </select>
      </div>
      <div class="modal-footer fleet-modal-footer">
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

<?php layoutFoot(); ?>