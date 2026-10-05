<?php
// ============================================================
// pages/trip_monitor.php
// Trip Monitoring — active trips, status updates, late alerts
// Access is controlled by the trips.view permission.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('trips.view');
$canUpdateTrips = currentUserHasAnyPermission(['trips.update']);
$tripColumnCount = $canUpdateTrips ? 9 : 8;

$GLOBALS['page_js'] = APP_BASE . '/assets/js/trip_monitor.js';

$pdo = getDBConnection();
$reportClients = $pdo->query('SELECT client_id, client_name FROM clients WHERE is_active = 1 ORDER BY client_name')->fetchAll(PDO::FETCH_ASSOC);

// ── Period filter (scopes historical Completed/Cancelled trips only —
// active trips always show regardless, since they need eyes on them now) ────
$periods = [
    'today' => 'Today', '1w' => 'This Week', '1m' => 'This Month',
    '3m' => 'Last 3 Months', '6m' => 'Last 6 Months', '1y' => 'Last 12 Months', 'all' => 'All Time',
];
$period = $_GET['period'] ?? 'all';
if (!isset($periods[$period])) $period = 'all';
$rangeStart = match ($period) {
    'today' => new DateTime('today'),
    '1w'    => (new DateTime('today'))->modify('-6 days'),
    '1m'    => (new DateTime('today'))->modify('-1 months'),
    '3m'    => (new DateTime('today'))->modify('-3 months'),
    '6m'    => (new DateTime('today'))->modify('-6 months'),
    '1y'    => (new DateTime('today'))->modify('-12 months'),
    default => null,
};
$rangeStartSql = $rangeStart ? $rangeStart->format('Y-m-d 00:00:00') : null;

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
$tripStmt = $pdo->prepare(
    "SELECT
       t.trip_id,
       t.trip_number,
       t.status,
       t.shift,
       t.cargo_description,
       t.expected_arrival,
       t.actual_departure_at,
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
       dr.scheduled_at AS etd,
       dr.client_name,
       dr.booking_reference,
       dr.waybill_reference,
       dr.unit_count,
       dr.client_rate_amount,
       dr.client_rate_currency,
       dr.client_rate_basis,
       e_sd.full_name AS second_driver_name,
       origin.location_name AS origin_location_name,
       destination.location_name AS destination_location_name,
       EXISTS (
         SELECT 1 FROM incidents i
         WHERE i.trip_id = t.trip_id
       ) AS has_problem
     FROM trips t
     JOIN dispatch_requests dr ON t.dispatch_id  = dr.dispatch_id
     JOIN trucks tr             ON dr.truck_id    = tr.truck_id
     JOIN employees e_d         ON dr.driver_id   = e_d.employee_id
     LEFT JOIN employees e_sd   ON dr.second_driver_id = e_sd.employee_id
     LEFT JOIN employees e_h    ON dr.helper_id   = e_h.employee_id
     LEFT JOIN client_locations origin ON dr.origin_location_id = origin.location_id
     LEFT JOIN client_locations destination ON dr.destination_location_id = destination.location_id
     JOIN routes r              ON dr.route_id    = r.route_id
     WHERE t.status NOT IN ('Completed','Cancelled') " .
     ($rangeStartSql ? "OR (t.status IN ('Completed','Cancelled') AND t.created_at >= :rangeStart)" : "OR t.status IN ('Completed','Cancelled')") . "
     ORDER BY
       FIELD(t.status,'In Transit','Loading','Unloading','Completed','Cancelled'),
       t.is_late DESC,
       t.expected_arrival ASC"
);
if ($rangeStartSql) $tripStmt->bindValue(':rangeStart', $rangeStartSql);
$tripStmt->execute();
$trips = $tripStmt->fetchAll();

layoutHead('Trip Monitoring', APP_BASE . '/assets/css/trip_monitor.css');
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Trip Monitoring</h1>
    <p class="page-subtitle">Track all trips in real time</p>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <?php if (currentUserHasAnyPermission(['reports.export'])): ?>
    <form method="get" action="<?= APP_BASE ?>/ajax/trip_operations_export.php"
          class="d-flex gap-2 align-items-center flex-wrap" target="_blank">
      <input type="date" name="from" class="form-control form-control-sm" aria-label="Export from date">
      <input type="date" name="to" class="form-control form-control-sm" aria-label="Export to date">
      <select name="status" class="form-select form-select-sm" aria-label="Export trip status">
        <option value="">All trip statuses</option>
        <?php foreach (['Loading', 'In Transit', 'Unloading', 'Completed', 'Cancelled'] as $statusOption): ?>
        <option value="<?= htmlspecialchars($statusOption) ?>"><?= htmlspecialchars($statusOption) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="client_id" class="form-select form-select-sm" aria-label="Export client">
        <option value="">All clients</option>
        <?php foreach ($reportClients as $client): ?>
        <option value="<?= (int)$client['client_id'] ?>"><?= htmlspecialchars($client['client_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="shift" class="form-select form-select-sm" aria-label="Export trip shift">
        <option value="">All shifts</option>
        <option value="Day">Day Shift</option>
        <option value="Night">Night Shift</option>
      </select>
      <button type="submit" class="btn btn-outline-success btn-sm text-nowrap">
        <i class="bi bi-download me-1"></i>Export CSV
      </button>
    </form>
    <?php endif; ?>
    <form method="get" class="d-flex">
      <select name="period" class="form-select" style="min-width:160px;font-size:.85rem;" onchange="this.form.submit()">
        <?php foreach ($periods as $key => $label): ?>
        <option value="<?= $key ?>" <?= $key === $period ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if (currentUserHasAnyPermission(['trips.create'])): ?>
    <a href="<?= APP_BASE ?>/pages/dispatch.php" class="btn btn-primary btn-sm d-flex align-items-center gap-2">
      <i class="bi bi-send"></i> New Dispatch
    </a>
    <?php endif; ?>
  </div>
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
      <button class="filter-btn" data-filter="shift:Day">Day Shift</button>
      <button class="filter-btn" data-filter="shift:Night">Night Shift</button>
      <button class="filter-btn late-filter" data-filter="late">
        <i class="bi bi-alarm"></i> Late Only
      </button>
      <button class="filter-btn" data-filter="problem">Problems</button>
      <button class="filter-btn" data-filter="okay">No Problems</button>
      <div class="ms-auto">
        <input type="text" id="tripSearch" class="form-control form-control-sm"
               placeholder="Search trip, plate, driver…">
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
          <th>Shift</th>
          <th>Truck</th>
          <th>Driver</th>
          <th>Route</th>
          <th>Status</th>
          <th>ETA</th>
          <th>ETD</th>
          <?php if ($canUpdateTrips): ?>
          <th>Actions</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody id="tripBody">
        <?php if (empty($trips)): ?>
        <tr>
          <td colspan="<?= $tripColumnCount ?>" class="text-center text-muted py-4">No trips found.</td>
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
          $hasProblem = (bool)$trip['has_problem'];
          $isActive  = !in_array($trip['status'], ['Completed','Cancelled']);
          $searchStr = strtolower(
            $trip['trip_number'] . ' ' .
            $trip['plate_number'] . ' ' .
            $trip['driver_name'] . ' ' .
            ($trip['second_driver_name'] ?? '') . ' ' .
            ($trip['client_name'] ?? '') . ' ' .
            ($trip['booking_reference'] ?? '') . ' ' .
            ($trip['waybill_reference'] ?? '') . ' ' .
            $trip['origin'] . ' ' .
            $trip['destination']
          );
        ?>
        <tr data-status="<?= htmlspecialchars($trip['status']) ?>"
            data-shift="<?= htmlspecialchars($trip['shift']) ?>"
            data-late="<?= $isLate ? '1' : '0' ?>"
            data-problem="<?= $hasProblem ? '1' : '0' ?>"
            data-search="<?= htmlspecialchars($searchStr) ?>"
            class="<?= $isLate && $isActive ? 'row-late' : '' ?>">
          <td>
            <a class="trip-number text-decoration-none"
               href="<?= APP_BASE ?>/pages/trip_workflow.php?trip_id=<?= (int)$trip['trip_id'] ?>"
               title="Open the 13-step trip workflow">
              <?= htmlspecialchars($trip['trip_number']) ?>
            </a>
            <?php if ($isLate && $isActive): ?>
            <span class="late-pill">LATE</span>
            <?php endif; ?>
          </td>
          <td><span class="badge text-bg-<?= $trip['shift'] === 'Day' ? 'info' : 'dark' ?>"><?= htmlspecialchars($trip['shift']) ?></span></td>
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
            <?php if ($trip['second_driver_name']): ?>
            <div class="text-muted" style="font-size:.78rem;">
              Second driver: <?= htmlspecialchars($trip['second_driver_name']) ?>
            </div>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-size:.82rem;">
              <?= htmlspecialchars($trip['origin']) ?>
              <i class="bi bi-arrow-right text-muted"></i>
              <?= htmlspecialchars($trip['destination']) ?>
            </div>
            <?php if ($trip['origin_location_name'] || $trip['destination_location_name']): ?>
            <div class="text-muted" style="font-size:.76rem;">
              <?= htmlspecialchars($trip['origin_location_name'] ?? '—') ?>
              <i class="bi bi-arrow-right"></i>
              <?= htmlspecialchars($trip['destination_location_name'] ?? '—') ?>
            </div>
            <?php endif; ?>
            <?php if ($trip['client_name']): ?>
            <div class="text-muted" style="font-size:.76rem;">
              <?= htmlspecialchars($trip['client_name']) ?>
              <?= $trip['unit_count'] ? ' · ' . htmlspecialchars((string)$trip['unit_count']) . ' units' : '' ?>
              <?= $trip['client_rate_amount'] !== null ? ' · ' . htmlspecialchars($trip['client_rate_currency'] ?? 'PHP') . ' ' . number_format((float)$trip['client_rate_amount'], 2) . ' / ' . htmlspecialchars($trip['client_rate_basis'] ?? 'Per Trip') : '' ?>
            </div>
            <?php endif; ?>
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
            <?= $trip['etd'] ? date('M j, g:i A', strtotime($trip['etd'])) : '—' ?>
            <?php if ($trip['actual_departure_at']): ?>
            <div class="text-muted small">Departed <?= date('M j, g:i A', strtotime($trip['actual_departure_at'])) ?></div>
            <?php endif; ?>
          </td>
          <?php if ($canUpdateTrips): ?>
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
          <td colspan="<?= $tripColumnCount ?>">
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
<?php if ($canUpdateTrips): ?>
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
            <option value="In Transit">In Transit</option>
            <option value="Unloading">Unloading</option>
            <option value="Completed">Completed</option>
            <option value="Cancelled">Cancelled</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-600">Current Location <span class="text-muted fw-400">(optional)</span></label>
          <input type="text" class="form-control" id="modalLocation" maxlength="255">
        </div>
        <div class="mb-1">
          <label class="form-label fw-600" id="modalNotesLabel">
            Notes <span class="text-muted fw-400">(optional)</span>
          </label>
          <textarea class="form-control" id="modalNotes" rows="2"
                    placeholder="Any remarks for this update…"></textarea>
        </div>
        <div id="completedReportAttachments" class="mt-3 d-none">
          <label class="form-label fw-600">Delivery Receipt <span class="text-muted fw-400">(PDF or image, optional)</span></label>
          <input type="file" class="form-control mb-2" id="modalDeliveryReceipt"
                 accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
          <label class="form-label fw-600">Waybill <span class="text-muted fw-400">(PDF or image, optional)</span></label>
          <input type="file" class="form-control" id="modalWaybill"
                 accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
          <div class="form-text">These files will be linked to this completed trip in Documents.</div>
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