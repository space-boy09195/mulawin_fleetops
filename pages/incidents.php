<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_MAINTENANCE]);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/incidents.js';

layoutHead('Incidents', APP_BASE . '/assets/css/incidents.css');

// Fetch all incidents joined with trip info
require_once __DIR__ . '/../config/database.php';
$pdo = getDBConnection();

$sql = "
    SELECT
        i.id,
        i.trip_id,
        i.type,
        i.description,
        i.status,
        i.reported_at,
        i.resolved_at,
        t.reference_no,
        t.origin,
        t.destination,
        v.plate_number,
        CONCAT(d.first_name, ' ', d.last_name) AS driver_name,
        CONCAT(u.first_name, ' ', u.last_name) AS reported_by_name
    FROM incidents i
    JOIN trips t ON i.trip_id = t.id
    JOIN vehicles v ON t.vehicle_id = v.id
    JOIN drivers d ON t.driver_id = d.id
    JOIN users u ON i.reported_by = u.id
    ORDER BY i.reported_at DESC
";
$incidents = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Fetch active/in-progress trips for the log form dropdown
$tripsSql = "
    SELECT t.id, t.reference_no, t.origin, t.destination, v.plate_number
    FROM trips t
    JOIN vehicles v ON t.vehicle_id = v.id
    WHERE t.status IN ('approved', 'in_progress')
    ORDER BY t.reference_no
";
$activeTrips = $pdo->query($tripsSql)->fetchAll(PDO::FETCH_ASSOC);

$incidentTypes = ['Vehicle Breakdown', 'Item Damage', 'Delay', 'Other'];
?>

<div class="inc-page">

  <!-- Page header -->
  <div class="inc-header d-flex align-items-center justify-content-between mb-4">
    <div>
      <h1 class="inc-title mb-0">Incidents</h1>
      <p class="inc-subtitle mb-0">Log and track trip incidents</p>
    </div>
    <?php if (in_array(currentRoleId(), [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER])): ?>
    <button class="btn btn-incident" data-bs-toggle="modal" data-bs-target="#logIncidentModal">
      <i class="bi bi-plus-lg me-1"></i> Log Incident
    </button>
    <?php endif; ?>
  </div>

  <!-- Filters -->
  <div class="inc-filters d-flex flex-wrap gap-2 mb-4">
    <select id="filterType" class="form-select inc-filter-select">
      <option value="">All Types</option>
      <?php foreach ($incidentTypes as $t): ?>
        <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
      <?php endforeach; ?>
    </select>
    <select id="filterStatus" class="form-select inc-filter-select">
      <option value="">All Statuses</option>
      <option value="open">Open</option>
      <option value="resolved">Resolved</option>
    </select>
    <input type="search" id="filterSearch" class="form-control inc-filter-search" placeholder="Search trip ref, vehicle, driver…">
  </div>

  <!-- Incidents table -->
  <div class="inc-table-wrap">
    <?php if (empty($incidents)): ?>
      <div class="inc-empty">
        <i class="bi bi-shield-check inc-empty-icon"></i>
        <p>No incidents logged yet.</p>
      </div>
    <?php else: ?>
    <table class="table inc-table" id="incidentsTable">
      <thead>
        <tr>
          <th>Trip Ref</th>
          <th>Vehicle</th>
          <th>Route</th>
          <th>Type</th>
          <th>Description</th>
          <th>Status</th>
          <th>Reported</th>
          <th>Reported By</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($incidents as $inc): ?>
        <tr
          data-id="<?= $inc['id'] ?>"
          data-type="<?= htmlspecialchars($inc['type']) ?>"
          data-status="<?= htmlspecialchars($inc['status']) ?>"
          data-search="<?= htmlspecialchars(strtolower(
            $inc['reference_no'] . ' ' . $inc['plate_number'] . ' ' . $inc['driver_name']
          )) ?>"
        >
          <td><span class="inc-ref"><?= htmlspecialchars($inc['reference_no']) ?></span></td>
          <td><?= htmlspecialchars($inc['plate_number']) ?></td>
          <td class="inc-route">
            <span class="inc-route-from"><?= htmlspecialchars($inc['origin']) ?></span>
            <i class="bi bi-arrow-right"></i>
            <span class="inc-route-to"><?= htmlspecialchars($inc['destination']) ?></span>
          </td>
          <td>
            <span class="inc-type-badge inc-type-<?= strtolower(str_replace([' ', '_'], '-', $inc['type'])) ?>">
              <?= htmlspecialchars($inc['type']) ?>
            </span>
          </td>
          <td class="inc-desc-cell">
            <span class="inc-desc-text" title="<?= htmlspecialchars($inc['description']) ?>">
              <?= htmlspecialchars($inc['description']) ?>
            </span>
          </td>
          <td>
            <span class="inc-status-badge inc-status-<?= $inc['status'] ?>">
              <?= ucfirst($inc['status']) ?>
            </span>
          </td>
          <td class="inc-date"><?= date('M d, Y H:i', strtotime($inc['reported_at'])) ?></td>
          <td><?= htmlspecialchars($inc['reported_by_name']) ?></td>
          <td class="text-end">
            <?php if ($inc['status'] === 'open' && in_array(currentRoleId(), [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_MAINTENANCE])): ?>
            <button
              class="btn btn-sm btn-resolve"
              data-id="<?= $inc['id'] ?>"
              data-trip="<?= htmlspecialchars($inc['reference_no']) ?>">
              Resolve
            </button>
            <?php else: ?>
            <span class="inc-resolved-at" title="Resolved <?= $inc['resolved_at'] ? date('M d, Y H:i', strtotime($inc['resolved_at'])) : '' ?>">
              <i class="bi bi-check-circle-fill text-success"></i>
            </span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- Log Incident Modal -->
<div class="modal fade" id="logIncidentModal" tabindex="-1" aria-labelledby="logIncidentLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content inc-modal-content">
      <div class="modal-header inc-modal-header">
        <h5 class="modal-title" id="logIncidentLabel">
          <i class="bi bi-exclamation-triangle me-2"></i>Log Incident
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body inc-modal-body">
        <div id="incFormAlert" class="alert d-none" role="alert"></div>

        <div class="mb-3">
          <label class="form-label inc-label" for="tripSelect">Trip</label>
          <select class="form-select inc-input" id="tripSelect" required>
            <option value="">— Select active trip —</option>
            <?php foreach ($activeTrips as $trip): ?>
            <option value="<?= $trip['id'] ?>">
              <?= htmlspecialchars($trip['reference_no']) ?> | <?= htmlspecialchars($trip['plate_number']) ?> | <?= htmlspecialchars($trip['origin']) ?> → <?= htmlspecialchars($trip['destination']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label inc-label" for="incidentType">Type</label>
          <select class="form-select inc-input" id="incidentType" required>
            <option value="">— Select type —</option>
            <?php foreach ($incidentTypes as $t): ?>
            <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-1">
          <label class="form-label inc-label" for="incidentDesc">Description</label>
          <textarea class="form-control inc-input" id="incidentDesc" rows="4"
            placeholder="Describe what happened, location, items affected, etc." required></textarea>
        </div>
      </div>
      <div class="modal-footer inc-modal-footer">
        <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-incident" id="submitIncidentBtn">
          <span id="submitBtnText">Log Incident</span>
          <span id="submitBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Resolve Confirmation Modal -->
<div class="modal fade" id="resolveModal" tabindex="-1" aria-labelledby="resolveModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content inc-modal-content">
      <div class="modal-header inc-modal-header-resolve">
        <h5 class="modal-title" id="resolveModalLabel">
          <i class="bi bi-check-circle me-2"></i>Resolve Incident
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body inc-modal-body">
        <p class="mb-0">Mark the incident for trip <strong id="resolveTripRef"></strong> as resolved?</p>
        <div id="resolveAlert" class="alert d-none mt-3" role="alert"></div>
      </div>
      <div class="modal-footer inc-modal-footer">
        <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-resolve-confirm" id="confirmResolveBtn">
          <span id="resolveBtnText">Resolve</span>
          <span id="resolveBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<?php layoutFoot(); ?>