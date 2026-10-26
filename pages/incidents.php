<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';

requireAnyPermission(['incidents.manage', 'legacy.role.3']);

$GLOBALS['page_js'] = APP_BASE . '/assets/js/incidents.js';

layoutHead('Trip Problem Reports', APP_BASE . '/assets/css/incidents.css');

$pdo = getDBConnection();

// Dispatchers see only trips in their assigned truck types and shift.
$scopeParams = [];
$scopeSql = dispatcherScopeReadSql('tr.truck_type', 't.shift', $scopeParams);

// ── All incidents with full context (target trip_incidents) ─────────────────
// resolution_notes = action_taken; reported_at = incident_date.
$sql = "
    SELECT
        i.incident_id,
        i.trip_id,
        i.incident_type,
        i.severity,
        i.description,
        i.action_taken  AS resolution_notes,
        i.resolved_at,
        i.incident_date AS reported_at,
        t.trip_number,
        COALESCE(r.origin, t.origin)           AS origin,
        COALESCE(r.destination, t.destination) AS destination,
        tr.plate_number,
        e.full_name      AS driver_name,
        COALESCE(ue.full_name, u.username) AS reported_by_name
    FROM trip_incidents i
    JOIN trips t              ON i.trip_id      = t.trip_id
    LEFT JOIN trucks tr       ON tr.truck_id    = COALESCE(i.truck_id, t.truck_id)
    LEFT JOIN employees e     ON e.employee_id  = t.driver_id
    LEFT JOIN routes r        ON r.route_id     = t.route_id
    LEFT JOIN users u         ON u.user_id      = i.reported_by
    LEFT JOIN employees ue    ON ue.employee_id = u.employee_id
    WHERE 1 = 1 $scopeSql
    ORDER BY i.incident_date DESC
    LIMIT 1000
";
$incidentStmt = $pdo->prepare($sql);
$incidentStmt->execute($scopeParams);
$incidents = $incidentStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Active trips for the log form dropdown ───────────────────────────────────
// Approved trips that are not finished (legacy: trips that existed and were
// not Completed/Cancelled).
$tripsSql = "
    SELECT
        t.trip_id,
        t.trip_number,
        COALESCE(r.origin, t.origin)           AS origin,
        COALESCE(r.destination, t.destination) AS destination,
        tr.plate_number
    FROM trips t
    LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
    LEFT JOIN routes r  ON r.route_id  = t.route_id
    WHERE t.status NOT IN ('Pending Approval', 'Completed', 'Cancelled') $scopeSql
    ORDER BY t.trip_number
";
$tripsStmt = $pdo->prepare($tripsSql);
$tripsStmt->execute($scopeParams);
$activeTrips = $tripsStmt->fetchAll(PDO::FETCH_ASSOC);

$incidentTypes = INCIDENT_TYPES;
?>

<div class="inc-page">

  <!-- Page header -->
  <div class="inc-header d-flex align-items-center justify-content-between mb-4">
    <div>
      <h1 class="inc-title mb-0">Trip Problem Reports</h1>
      <p class="inc-subtitle mb-0">Record, monitor, and resolve problems related to trips</p>
    </div>
    <?php if (currentUserHasAnyPermission(['incidents.manage'])): ?>
    <button class="btn btn-incident" data-bs-toggle="modal" data-bs-target="#logIncidentModal">
      <i class="bi bi-plus-lg me-1"></i> Report a Problem
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
    <input type="search" id="filterSearch" class="form-control inc-filter-search"
           placeholder="Search trip no., vehicle, driver…">
  </div>

  <!-- Trip problem reports table -->
  <div class="inc-table-wrap">
    <?php if (empty($incidents)): ?>
      <div class="inc-empty">
        <i class="bi bi-shield-check inc-empty-icon"></i>
        <p>No trip problem reports logged yet.</p>
      </div>
    <?php else: ?>
    <table class="table inc-table" id="incidentsTable">
      <thead>
        <tr>
          <th>Trip No.</th>
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
        <?php foreach ($incidents as $inc):
          $isResolved = !empty($inc['resolved_at']);
          $status     = $isResolved ? 'resolved' : 'open';
        ?>
        <tr
          data-id="<?= $inc['incident_id'] ?>"
          data-type="<?= htmlspecialchars($inc['incident_type']) ?>"
          data-status="<?= $status ?>"
          data-search="<?= htmlspecialchars(strtolower(
            $inc['trip_number'] . ' ' . $inc['plate_number'] . ' ' . $inc['driver_name']
          )) ?>"
        >
          <td><span class="inc-ref"><?= htmlspecialchars($inc['trip_number']) ?></span></td>
          <td><?= htmlspecialchars($inc['plate_number']) ?></td>
          <td class="inc-route">
            <span><?= htmlspecialchars($inc['origin']) ?></span>
            <i class="bi bi-arrow-right"></i>
            <span><?= htmlspecialchars($inc['destination']) ?></span>
          </td>
          <td>
            <span class="inc-type-badge inc-type-<?= strtolower(str_replace([' ', '_'], '-', $inc['incident_type'])) ?>">
              <?= htmlspecialchars($inc['incident_type']) ?>
            </span>
          </td>
          <td class="inc-desc-cell">
            <span class="inc-desc-text" title="<?= htmlspecialchars($inc['description']) ?>">
              <?= htmlspecialchars($inc['description']) ?>
            </span>
            <?php if ($isResolved && $inc['resolution_notes']): ?>
            <span class="inc-resolution-note" title="Resolution: <?= htmlspecialchars($inc['resolution_notes']) ?>">
              <i class="bi bi-check2-circle"></i> <?= htmlspecialchars($inc['resolution_notes']) ?>
            </span>
            <?php endif; ?>
          </td>
          <td>
            <span class="inc-status-badge inc-status-<?= $status ?>">
              <?= $isResolved ? 'Resolved' : 'Open' ?>
            </span>
          </td>
          <td class="inc-date"><?= date('M d, Y H:i', strtotime($inc['reported_at'])) ?></td>
          <td><?= htmlspecialchars($inc['reported_by_name']) ?></td>
          <td class="text-end">
            <?php if (!$isResolved && currentUserHasAnyPermission(['incidents.manage', 'legacy.role.3'])): ?>
            <button
              class="btn btn-sm btn-resolve"
              data-id="<?= $inc['incident_id'] ?>"
              data-trip="<?= htmlspecialchars($inc['trip_number']) ?>">
              Resolve
            </button>
            <?php elseif ($isResolved): ?>
            <span class="inc-resolved-check" title="Resolved <?= date('M d, Y', strtotime($inc['resolved_at'])) ?>">
              <i class="bi bi-check-circle-fill text-success"></i>
            </span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <tr id="noIncidentResults" class="d-none">
          <td colspan="9">
            <div class="no-results">
              <i class="bi bi-search"></i>
              <span>No trip problem reports match your filters.</span>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- Report Trip Problem Modal -->
<div class="modal fade" id="logIncidentModal" tabindex="-1" aria-labelledby="logIncidentLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content inc-modal-content">
      <div class="modal-header inc-modal-header">
        <h5 class="modal-title" id="logIncidentLabel">
          <i class="bi bi-exclamation-triangle me-2"></i>Report Trip Problem
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
            <option value="<?= $trip['trip_id'] ?>">
              <?= htmlspecialchars($trip['trip_number']) ?> |
              <?= htmlspecialchars($trip['plate_number']) ?> |
              <?= htmlspecialchars($trip['origin']) ?> → <?= htmlspecialchars($trip['destination']) ?>
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

        <div class="mb-3">
          <label class="form-label inc-label" for="incidentSeverity">Severity</label>
          <select class="form-select inc-input" id="incidentSeverity">
            <?php foreach (INCIDENT_SEVERITIES as $severity): ?>
            <option value="<?= htmlspecialchars($severity) ?>"><?= htmlspecialchars($severity) ?></option>
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

<!-- Resolve Modal -->
<div class="modal fade" id="resolveModal" tabindex="-1" aria-labelledby="resolveModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content inc-modal-content">
      <div class="modal-header inc-modal-header-resolve">
        <h5 class="modal-title" id="resolveModalLabel">
          <i class="bi bi-check-circle me-2"></i>Resolve Incident
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body inc-modal-body">
        <div id="resolveAlert" class="alert d-none" role="alert"></div>
        <p class="mb-3">
          Resolving incident for trip <strong id="resolveTripRef"></strong>.
          Add resolution notes below (optional).
        </p>
        <div class="mb-1">
          <label class="form-label inc-label" for="resolutionNotes">Resolution Notes</label>
          <textarea class="form-control inc-input" id="resolutionNotes" rows="3"
            placeholder="What was done to resolve this incident?"></textarea>
        </div>
      </div>
      <div class="modal-footer inc-modal-footer">
        <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-resolve-confirm" id="confirmResolveBtn">
          <span id="resolveBtnText">Mark Resolved</span>
          <span id="resolveBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<?php layoutFoot(); ?>