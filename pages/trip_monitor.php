<?php
// ============================================================
// pages/trip_monitor.php
// Trip Monitoring — active trips, status updates, and past-ETA alerts
// Access is controlled by the trips.view permission.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/crew_trip_access.php';
require_once __DIR__ . '/../includes/trip_queries.php';

// trips.view, or a Helper limited to trips assigned to them (employee ID).
$helperEmployeeId = tripPageAccess(['trips.view']);
$canUpdateTrips = currentUserHasAnyPermission(['trips.update']);
$tripColumnCount = $canUpdateTrips ? 9 : 8;

$GLOBALS['page_js'] = APP_BASE . '/assets/js/trip_monitor.js';

$pdo = getDBConnection();
$reportClients = $pdo->query("SELECT client_id, client_name FROM clients WHERE status = 'Active' ORDER BY client_name")->fetchAll(PDO::FETCH_ASSOC);

// ── Period filter (scopes historical Completed/Cancelled trips only —
// active trips always show regardless, since they need eyes on them now) ────
$periods = [
    'today' => 'Today', '1w' => 'Last 7 Days', '1m' => 'Last 1 Month',
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

// Past ETA is an operational alert based on the scheduled arrival, not a
// client-reported delay metric.
$operational = tripOperationalSql('t', 'dr');

// ---- Fetch trips ------------------------------------------
// Shared trip SELECT (includes/trip_queries.php) plus the incident flag.
$tripStmt = $pdo->prepare(
    'SELECT ' . tripSelectColumnsSql() . ",
       t.scheduled_departure AS etd,
       EXISTS (
         SELECT 1 FROM trip_incidents i
         WHERE i.trip_id = t.trip_id
       ) AS has_problem
     " . tripFromSql() . "
     WHERE $operational
       AND (t.status NOT IN ('Completed','Cancelled') " .
     ($rangeStartSql ? "OR (t.status IN ('Completed','Cancelled') AND t.created_at >= :rangeStart)" : "OR t.status IN ('Completed','Cancelled')") . ")
" .
     ($helperEmployeeId !== null ? " AND t.helper_id = :helperEmployeeId" : "") . "
     ORDER BY
       FIELD(t.status,'In Progress','Dispatched','Ready for Dispatch','Assigned','Approved','Delivered','Returned','Completed','Cancelled'),
       is_late DESC,
       t.scheduled_arrival ASC
     LIMIT 1000"
);
if ($rangeStartSql) $tripStmt->bindValue(':rangeStart', $rangeStartSql);
if ($helperEmployeeId !== null) $tripStmt->bindValue(':helperEmployeeId', $helperEmployeeId, PDO::PARAM_INT);
$tripStmt->execute();
$trips = $tripStmt->fetchAll();
foreach ($trips as &$tripRow) {
    // Keys the view uses. Client rates are looked up at billing time in the
    // target (client_rates), not stored per dispatch.
    $tripRow['origin_location_name'] = $tripRow['trip_origin'];
    $tripRow['destination_location_name'] = $tripRow['trip_destination'];
    $tripRow['waybill_reference'] = null;
    $tripRow['client_rate_amount'] = null;
    $tripRow['client_rate_currency'] = null;
    $tripRow['client_rate_basis'] = null;
    $tripRow['cargo_description'] = null;
}
unset($tripRow);

layoutHead('Trip Monitoring', APP_BASE . '/assets/css/trip_monitor.css');
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Trip Monitoring</h1>
    <p class="page-subtitle">Track all trips in real time</p>
  </div>
  <div class="trip-monitor-controls d-flex gap-2 align-items-center flex-wrap">
    <?php if ($helperEmployeeId === null && currentUserHasAnyPermission(['trips.export'])): ?>
    <form method="get" action="<?= APP_BASE ?>/ajax/trip_operations_export.php"
          class="trip-export-controls" target="_blank">
      <label class="trip-export-field"><span>From date</span>
        <input type="date" name="from" class="form-control form-control-sm" aria-label="Export from date">
      </label>
      <label class="trip-export-field"><span>To date</span>
        <input type="date" name="to" class="form-control form-control-sm" aria-label="Export to date">
      </label>
      <label class="trip-export-field"><span>Status</span>
      <select name="status" class="form-select form-select-sm" aria-label="Export trip status">
        <option value="">All trip statuses</option>
        <?php foreach (TRIP_STATUSES as $statusOption): ?>
        <option value="<?= htmlspecialchars($statusOption) ?>"><?= htmlspecialchars($statusOption) ?></option>
        <?php endforeach; ?>
      </select>
      </label>
      <label class="trip-export-field"><span>Client</span>
      <select name="client_id" class="form-select form-select-sm" aria-label="Export client">
        <option value="">All clients</option>
        <?php foreach ($reportClients as $client): ?>
        <option value="<?= (int)$client['client_id'] ?>"><?= htmlspecialchars($client['client_name']) ?></option>
        <?php endforeach; ?>
      </select>
      </label>
      <label class="trip-export-field"><span>Shift</span>
      <select name="shift" class="form-select form-select-sm" aria-label="Export trip shift">
        <option value="">All shifts</option>
        <option value="Day">Day Shift</option>
        <option value="Night">Night Shift</option>
      </select>
      </label>
      <button type="submit" class="btn btn-outline-success btn-sm text-nowrap">
        <i class="bi bi-download me-1"></i>Export CSV
      </button>
    </form>
    <?php endif; ?>
    <form method="get" class="trip-period-control">
      <label for="tripPeriod">Period</label>
      <select id="tripPeriod" name="period" class="form-select" onchange="this.form.submit()">
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

<!-- ---- Filter bar ---------------------------------------- -->
<div class="card mb-4">
  <div class="card-body-custom">
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <span class="text-muted" style="font-size:.8rem;">Filter:</span>
      <button class="filter-btn active" data-filter="all">All</button>
      <button class="filter-btn" data-filter="Assigned">Assigned</button>
      <button class="filter-btn" data-filter="Dispatched">Dispatched</button>
      <button class="filter-btn" data-filter="In Progress">In Progress</button>
      <button class="filter-btn" data-filter="Delivered">Delivered</button>
      <button class="filter-btn" data-filter="Returned">Returned</button>
      <button class="filter-btn" data-filter="Completed">Completed</button>
      <button class="filter-btn" data-filter="shift:Day">Day Shift</button>
      <button class="filter-btn" data-filter="shift:Night">Night Shift</button>
      <button class="filter-btn late-filter" data-filter="late">
        <i class="bi bi-alarm"></i> Past ETA
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
            'Approved', 'Assigned', 'Ready for Dispatch' => 'maintenance',
            'Dispatched', 'In Progress' => 'deployed',
            'Delivered', 'Returned' => 'available',
            'Completed'  => 'available',
            'Cancelled'  => 'inactive',
            default      => 'inactive',
          };
          $isLate    = (bool)$trip['is_late'];
          $hasProblem = (bool)$trip['has_problem'];
          $isActive  = !in_array($trip['status'], ['Completed','Cancelled']);
          $isPastEta = $isLate && $isActive;
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
            data-late="<?= $isPastEta ? '1' : '0' ?>"
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
            <span class="late-pill">PAST ETA</span>
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
            <option value="In Progress">In Progress</option>
            <option value="Delivered">Delivered</option>
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