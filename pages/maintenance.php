<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAnyPermission(['maintenance.view', 'maintenance.manage']);
$requestedTripId = filter_input(INPUT_GET, 'trip_id', FILTER_VALIDATE_INT);
$requestedInspectionStage = $_GET['inspection_stage'] ?? null;
if (!in_array($requestedInspectionStage, ['Departure', 'Return'], true)) {
    $requestedInspectionStage = null;
}

$pageBase = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$GLOBALS['page_js'] = $pageBase . '/assets/js/maintenance.js';

layoutHead('Maintenance', $pageBase . '/assets/css/maintenance.css');

$pdo = getDBConnection();

// ── Period filter (scopes Maintenance Records list below) ────────────────────
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
$recDateFilter = $rangeStartSql ? "AND mr.date_performed >= :rangeStart" : '';

// ── Maintenance records ───────────────────────────────────────────────────────
$recordsSql = "
    SELECT
        mr.record_id,
        mr.maintenance_type,
        mr.truck_status,
        mr.description,
        mr.cost,
        mr.date_performed,
        mr.next_due_date,
        mr.inspection_id,
        mr.created_at,
        tr.plate_number,
        tr.brand,
        tr.model,
        u.full_name      AS performed_by_name,
        i.incident_type  AS linked_incident_type,
        i.incident_id
    FROM maintenance_records mr
    JOIN trucks tr   ON mr.truck_id     = tr.truck_id
    JOIN users u     ON mr.performed_by = u.user_id
    LEFT JOIN incidents i ON mr.incident_id = i.incident_id
    WHERE 1=1 $recDateFilter
    ORDER BY mr.date_performed DESC, mr.created_at DESC
";
$recordsStmt = $pdo->prepare($recordsSql);
if ($rangeStartSql) $recordsStmt->bindValue(':rangeStart', $rangeStartSql);
$recordsStmt->execute();
$records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

$inspectionRows = $pdo->query("
    SELECT vi.inspection_id, vi.truck_id, vi.trip_id, vi.inspection_stage,
           vi.inspection_date, vi.notes, tr.plate_number, u.full_name AS inspected_by,
           t.trip_number
    FROM vehicle_inspections vi
    JOIN trucks tr ON tr.truck_id = vi.truck_id
    JOIN users u ON u.user_id = vi.inspected_by
    LEFT JOIN trips t ON t.trip_id = vi.trip_id
    ORDER BY vi.inspection_date DESC, vi.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
$inspectionTrips = $pdo->query("
    SELECT t.trip_id, t.trip_number, t.status, dr.truck_id, tr.plate_number
    FROM trips t
    JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
    JOIN trucks tr ON tr.truck_id = dr.truck_id
    WHERE t.status IN ('Loading', 'Unloading')
      AND (
        NOT EXISTS (
          SELECT 1 FROM trip_workflow_state tws
          WHERE tws.dispatch_id = t.dispatch_id
        )
        OR EXISTS (
          SELECT 1 FROM trip_workflow_state tws
          WHERE tws.dispatch_id = t.dispatch_id
            AND tws.current_step >= IF(t.status = 'Loading', 6, 11)
        )
      )
    ORDER BY t.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
$findingRows = $pdo->query("
    SELECT inspection_id, view_name, part_name, `condition`, notes
    FROM vehicle_inspection_findings
    ORDER BY view_name, part_name
")->fetchAll(PDO::FETCH_ASSOC);
$findingsByInspection = [];
foreach ($findingRows as $finding) {
    $findingsByInspection[(int)$finding['inspection_id']][] = $finding;
}
$departureInspectionByTrip = [];
foreach ($inspectionRows as $inspection) {
    if ($inspection['trip_id'] !== null && $inspection['inspection_stage'] === 'Departure') {
        $departureInspectionByTrip[(int)$inspection['trip_id']] = (int)$inspection['inspection_id'];
    }
}
$newFindingsByInspection = [];
foreach ($inspectionRows as $inspection) {
    if ($inspection['trip_id'] === null || $inspection['inspection_stage'] !== 'Return') {
        continue;
    }
    $departureId = $departureInspectionByTrip[(int)$inspection['trip_id']] ?? null;
    if ($departureId === null) {
        continue;
    }
    foreach ($findingsByInspection[(int)$inspection['inspection_id']] ?? [] as $finding) {
        $key = $finding['view_name'] . ':' . $finding['part_name'];
        $departureCondition = null;
        foreach ($findingsByInspection[$departureId] ?? [] as $departureFinding) {
            if ($departureFinding['view_name'] . ':' . $departureFinding['part_name'] === $key) {
                $departureCondition = $departureFinding['condition'];
                break;
            }
        }
        if ($departureCondition === 'Good' && $finding['condition'] !== 'Good') {
            $newFindingsByInspection[(int)$inspection['inspection_id']][] = $finding;
        }
    }
}

// ── Checklists ────────────────────────────────────────────────────────────────
$checklistSql = "
    SELECT
        mc.checklist_id,
        mc.result,
        mc.notes,
        mc.submitted_at,
        mc.lights_ok,
        mc.tires_ok,
        mc.tools_ok,
        mc.medical_kit_ok,
        mc.license_ok,
        mc.or_cr_ok,
        mc.waybill_ok,
        mc.fuel_po_ok,
        tr.plate_number,
        tr.brand,
        tr.model,
        dr.status        AS dispatch_status,
        t.trip_number,
        u.full_name      AS submitted_by_name
    FROM maintenance_checklists mc
    JOIN trucks tr            ON mc.truck_id    = tr.truck_id
    JOIN dispatch_requests dr ON mc.dispatch_id = dr.dispatch_id
    LEFT JOIN trips t         ON mc.trip_id     = t.trip_id
    JOIN users u              ON mc.submitted_by = u.user_id
    ORDER BY mc.submitted_at DESC
";
$checklists = $pdo->query($checklistSql)->fetchAll(PDO::FETCH_ASSOC);

// ── Dropdowns for forms ───────────────────────────────────────────────────────
// Requires db/truck_image_migration.sql to have been applied — see
// instructions/instructions.md, which now runs every db/*_migration.sql file
// as a required setup step.
$trucks = $pdo->query("
    SELECT truck_id, plate_number, brand, model, image_path,
           image_front_path, image_side_path, image_rear_path, image_top_path,
           COALESCE(body_type, 'Closed Van') AS body_type
    FROM trucks
    WHERE status != 'Inactive'
    ORDER BY plate_number
")->fetchAll(PDO::FETCH_ASSOC);
$availableTrucks = $pdo->query("
    SELECT truck_id, plate_number, brand, model, status
    FROM trucks WHERE status IN ('Available', 'Under Maintenance') ORDER BY plate_number
")->fetchAll(PDO::FETCH_ASSOC);
$workOrders = $pdo->query("
    SELECT wo.work_order_id, wo.truck_id, wo.title, wo.description, wo.priority, wo.status,
           wo.expected_completion_at, wo.opened_at, wo.closed_at, wo.closure_notes,
           tr.plate_number, tr.brand, tr.model, creator.full_name AS created_by_name,
           TIMESTAMPDIFF(MINUTE, wo.opened_at, COALESCE(wo.closed_at, NOW())) AS downtime_minutes
    FROM repair_work_orders wo
    JOIN trucks tr ON tr.truck_id = wo.truck_id
    JOIN users creator ON creator.user_id = wo.created_by
    ORDER BY FIELD(wo.status, 'Open', 'In Progress', 'Completed', 'Cancelled'),
             wo.expected_completion_at IS NULL, wo.expected_completion_at, wo.opened_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
$preventiveSchedules = $pdo->query("
    SELECT pms.schedule_id, pms.truck_id, pms.service_name, pms.description,
           pms.interval_days, pms.next_due_date, pms.last_completed_at, pms.status,
           tr.plate_number, tr.brand, tr.model, tr.status AS truck_status,
           DATE(pms.last_completed_at) AS last_service_date,
           DATEDIFF(pms.next_due_date, CURDATE()) AS days_until_due,
           CASE
             WHEN pms.status <> 'Active' THEN pms.status
             WHEN pms.next_due_date < CURDATE() THEN 'Overdue'
             WHEN pms.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) THEN 'Due Soon'
             ELSE 'Scheduled'
           END AS due_status
    FROM preventive_maintenance_schedules pms
    JOIN trucks tr ON tr.truck_id = pms.truck_id
    ORDER BY FIELD(pms.status, 'Active', 'Paused', 'Archived'),
             pms.next_due_date, pms.service_name
")->fetchAll(PDO::FETCH_ASSOC);

$inspectionImageDir = __DIR__ . '/../assets/images/inspection';
$inspectionImageUrl = $pageBase . '/assets/images/inspection';
// Default artwork is used only when a truck has no uploaded photo for the view.
$inspectionImageSets = [
    'Closed Van' => [
        'Front' => 'demo-truck-front.svg',
        'Side'  => 'demo-truck-side.svg',
        'Rear'  => 'demo-truck-rear.svg',
        'Top'   => 'demo-truck-top.svg',
    ],
    'Flatbed' => [
        'Front' => 'demo-truck-front.svg',
        'Side'  => 'demo-truck-side.svg',
        'Rear'  => 'demo-truck-rear.svg',
        'Top'   => 'demo-truck-top.svg',
    ],
    'Reefer' => [
        'Front' => 'demo-truck-front.svg',
        'Side'  => 'demo-truck-side.svg',
        'Rear'  => 'demo-truck-rear.svg',
        'Top'   => 'demo-truck-top.svg',
    ],
    'Wing Van' => [
        'Front' => 'demo-truck-front.svg',
        'Side'  => 'demo-truck-side.svg',
        'Rear'  => 'demo-truck-rear.svg',
        'Top'   => 'demo-truck-top.svg',
    ],
    'Carrier' => [
        'Front' => 'demo-truck-front.svg',
        'Side'  => 'demo-truck-side.svg',
        'Rear'  => 'demo-truck-rear.svg',
        'Top'   => 'demo-truck-top.svg',
    ],
];

$inspectionImages = [];
foreach ($inspectionImageSets as $bodyType => $views) {
    foreach ($views as $viewName => $image) {
        $isUrl = (bool)preg_match('/^https?:\/\//i', $image);
        if ($isUrl || is_file($inspectionImageDir . '/' . $image)) {
            $inspectionImages[$bodyType][$viewName] = $isUrl
                ? $image
                : $inspectionImageUrl . '/' . rawurlencode($image);
        }
    }
}

// Approved dispatches without a passed checklist
$pendingDispatchesSql = "
    SELECT
        dr.dispatch_id,
        dr.truck_id,
        t.trip_id,
        tr.plate_number,
        tr.brand,
        tr.model,
        e.full_name  AS driver_name,
        r.origin,
        r.destination,
        dr.scheduled_at
    FROM dispatch_requests dr
    JOIN trucks tr    ON dr.truck_id  = tr.truck_id
    JOIN employees e  ON dr.driver_id = e.employee_id
    JOIN routes r     ON dr.route_id  = r.route_id
    LEFT JOIN trips t ON t.dispatch_id = dr.dispatch_id
    WHERE dr.status = 'Approved'
      AND NOT EXISTS (
          SELECT 1 FROM maintenance_checklists mc
          WHERE mc.dispatch_id = dr.dispatch_id AND mc.result = 'Passed'
      )
      AND (
        NOT EXISTS (
          SELECT 1 FROM trip_workflow_state tws
          WHERE tws.dispatch_id = dr.dispatch_id
        )
        OR EXISTS (
          SELECT 1 FROM trip_workflow_state tws
          WHERE tws.dispatch_id = dr.dispatch_id AND tws.current_step >= 6
        )
      )
    ORDER BY dr.scheduled_at ASC
";
$pendingDispatches = $pdo->query($pendingDispatchesSql)->fetchAll(PDO::FETCH_ASSOC);

// Open incidents (for linking to a maintenance record)
$openIncidentsSql = "
    SELECT
        i.incident_id,
        i.incident_type,
        t.trip_number,
        tr.plate_number
    FROM incidents i
    JOIN trips t              ON i.trip_id     = t.trip_id
    JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
    JOIN trucks tr            ON dr.truck_id   = tr.truck_id
    WHERE i.resolved_at IS NULL
    ORDER BY i.reported_at DESC
";
$openIncidents = $pdo->query($openIncidentsSql)->fetchAll(PDO::FETCH_ASSOC);

$maintenanceTypes = ['Preventive', 'Corrective', 'Inspection'];
$truckStatuses    = ['Operational', 'Scheduled Maintenance', 'Under Repair'];

$checklistItems = [
    'lights_ok'      => 'Lights',
    'tires_ok'       => 'Tires',
    'tools_ok'       => 'Tools',
    'medical_kit_ok' => 'Medical Kit',
    'license_ok'     => "Driver's License",
    'or_cr_ok'       => 'OR/CR',
    'waybill_ok'     => 'Waybill',
    'fuel_po_ok'     => 'Fuel PO',
];
?>

<div class="mnt-page">

  <!-- Header -->
  <div class="mnt-header d-flex align-items-center justify-content-between mb-4">
    <div>
      <h1 class="mnt-title mb-0">Maintenance</h1>
      <p class="mnt-subtitle mb-0">Checklists, records, and truck status</p>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <form method="get" class="d-flex">
        <select name="period" class="form-select mnt-input" style="min-width:160px;font-size:.85rem;" onchange="this.form.submit()">
          <?php foreach ($periods as $key => $label): ?>
          <option value="<?= $key ?>" <?= $key === $period ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <button class="btn btn-mnt-secondary" data-bs-toggle="modal" data-bs-target="#checklistModal">
        <i class="bi bi-clipboard-check me-1"></i> New Checklist
      </button>
      <button class="btn btn-mnt-primary" data-bs-toggle="modal" data-bs-target="#inspectionModal">
        <i class="bi bi-bounding-box-circles me-1"></i> Inspect Vehicle
      </button>
      <button class="btn btn-mnt-primary" data-bs-toggle="modal" data-bs-target="#recordModal">
        <i class="bi bi-wrench me-1"></i> Log Record
      </button>
      <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#workOrderModal"
              <?= empty($availableTrucks) ? 'disabled title="No eligible trucks are available."' : '' ?>>
        <i class="bi bi-tools me-1"></i> Open Repair Work Order
      </button>
      <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#pmScheduleModal"
              <?= empty($trucks) ? 'disabled title="No active trucks are available."' : '' ?>>
        <i class="bi bi-calendar-check me-1"></i> New PM Schedule
      </button>
    </div>

    <!-- Interactive vehicle inspection -->
    <div class="modal fade" id="inspectionModal" data-min-duration-seconds="<?= MAINTENANCE_FORM_MIN_DURATION_SECONDS ?>" tabindex="-1" aria-labelledby="inspectionModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content mnt-modal-content">
          <div class="modal-header mnt-modal-header-primary">
            <h5 class="modal-title" id="inspectionModalLabel"><i class="bi bi-bounding-box-circles me-2"></i>Interactive Vehicle Inspection</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="inspectionFormAlert" class="alert d-none" role="alert"></div>
            <div id="inspectionTimerStatus" class="small text-muted mb-3" role="status" aria-live="polite">Select a vehicle or trip to start the minimum completion timer.</div>
            <div class="row g-3">
              <div class="col-lg-4">
                <label class="form-label mnt-label" for="inspectionTruck">Vehicle</label>
                <select id="inspectionTruck" class="form-select mnt-input" required>
                  <option value="">— Select vehicle —</option>
                  <?php foreach ($trucks as $truck): ?>
                  <option value="<?= $truck['truck_id'] ?>"
                          data-body="<?= htmlspecialchars($truck['body_type']) ?>"
                          data-image-front="<?= htmlspecialchars($truck['image_front_path'] ? APP_BASE . '/' . ltrim($truck['image_front_path'], '/') : '', ENT_QUOTES) ?>"
                          data-image-side="<?= htmlspecialchars($truck['image_side_path'] ? APP_BASE . '/' . ltrim($truck['image_side_path'], '/') : '', ENT_QUOTES) ?>"
                          data-image-rear="<?= htmlspecialchars($truck['image_rear_path'] ? APP_BASE . '/' . ltrim($truck['image_rear_path'], '/') : '', ENT_QUOTES) ?>"
                          data-image-top="<?= htmlspecialchars($truck['image_top_path'] ? APP_BASE . '/' . ltrim($truck['image_top_path'], '/') : '', ENT_QUOTES) ?>">
                    <?= htmlspecialchars($truck['plate_number'] . ' — ' . $truck['brand'] . ' ' . $truck['model']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <label class="form-label mnt-label mt-3" for="inspectionStage">Inspection Stage</label>
                <select id="inspectionStage" class="form-select mnt-input">
                  <option value="General" <?= !$requestedTripId ? 'selected' : '' ?>>General Vehicle Inspection</option>
                  <option value="Departure" <?= $requestedInspectionStage === 'Departure' || (!$requestedInspectionStage && array_filter($inspectionTrips, static fn($item) => (int)$item['trip_id'] === $requestedTripId && $item['status'] === 'Loading')) ? 'selected' : '' ?>>Trip Departure Inspection</option>
                  <option value="Return" <?= $requestedInspectionStage === 'Return' || (!$requestedInspectionStage && array_filter($inspectionTrips, static fn($item) => (int)$item['trip_id'] === $requestedTripId && $item['status'] === 'Unloading')) ? 'selected' : '' ?>>Trip Return Inspection</option>
                </select>
                <label class="form-label mnt-label mt-3" for="inspectionTrip">Trip</label>
                <select id="inspectionTrip" class="form-select mnt-input" disabled>
                  <option value="">— Select inspection stage first —</option>
                  <?php foreach ($inspectionTrips as $inspectionTrip): ?>
                  <option value="<?= (int)$inspectionTrip['trip_id'] ?>"
                          <?= (int)$inspectionTrip['trip_id'] === $requestedTripId ? 'selected' : '' ?>
                          data-truck-id="<?= (int)$inspectionTrip['truck_id'] ?>"
                          data-trip-status="<?= htmlspecialchars($inspectionTrip['status']) ?>">
                    <?= htmlspecialchars($inspectionTrip['trip_number'] . ' — ' . $inspectionTrip['plate_number'] . ' (' . $inspectionTrip['status'] . ')') ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <label class="form-label mnt-label mt-3" for="inspectionDate">Inspection date</label>
                <input type="date" id="inspectionDate" class="form-control mnt-input" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
                <label class="form-label mnt-label mt-3" for="inspectionNotes">Overall notes</label>
                <textarea id="inspectionNotes" class="form-control mnt-input" rows="4" placeholder="Optional findings or recommendations"></textarea>
                <div class="small text-muted mt-3"><i class="bi bi-info-circle me-1"></i>Select a vehicle view, then choose the condition and add notes for each listed part. The image is a reference only.</div>
                <div class="mt-3">
                  <label class="form-label mnt-label" for="customInspectionPart">Part not listed?</label>
                  <div class="input-group">
                    <input type="text" id="customInspectionPart" class="form-control mnt-input"
                           maxlength="100" placeholder="Type the part name">
                    <button type="button" class="btn btn-outline-primary" id="addInspectionPartBtn">
                      <i class="bi bi-plus-lg me-1"></i>Add Part
                    </button>
                  </div>
                  <div class="form-text">Add a custom part to the currently selected view.</div>
                </div>
              </div>
              <div class="col-lg-8">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <div class="btn-group" role="group" aria-label="Vehicle view">
                    <button type="button" class="btn btn-sm btn-outline-primary inspection-view active" data-view="Front" aria-pressed="true">
                      <i class="bi bi-truck-front me-1"></i>Front View
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary inspection-view" data-view="Side" aria-pressed="false">
                      <i class="bi bi-truck me-1"></i>Side View
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary inspection-view" data-view="Rear" aria-pressed="false">
                      <i class="bi bi-truck-rear me-1"></i>Rear View
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary inspection-view" data-view="Top" aria-pressed="false">
                      <i class="bi bi-bounding-box me-1"></i>Top View
                    </button>
                  </div>
                  <span class="small text-muted" id="inspectionBodyLabel">Closed Van</span>
                </div>
                <div class="inspection-diagram" id="inspectionDiagram"
                     data-view="Front"
                     data-image-map="<?= htmlspecialchars(json_encode($inspectionImages), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div id="inspectionParts" class="inspection-parts mt-3"></div>
              </div>
            </div>
          </div>
          <div class="modal-footer mnt-modal-footer">
            <button type="button" class="btn btn-mnt-cancel" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-mnt-primary" id="submitInspectionBtn">Save Inspection</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="workOrderModal" tabindex="-1" aria-labelledby="workOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content mnt-modal-content">
        <div class="modal-header mnt-modal-header-primary">
          <h5 class="modal-title" id="workOrderModalLabel"><i class="bi bi-tools me-2"></i>Open Repair Work Order</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="workOrderFormAlert" class="alert d-none" role="alert"></div>
          <label class="form-label mnt-label" for="workOrderTruck">Truck</label>
          <select id="workOrderTruck" class="form-select mnt-input" required>
            <option value="">— Select available or maintained truck —</option>
            <?php foreach ($availableTrucks as $truck): ?>
            <option value="<?= (int)$truck['truck_id'] ?>"><?= htmlspecialchars($truck['plate_number'] . ' — ' . $truck['brand'] . ' ' . $truck['model'] . ' (' . $truck['status'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
          <label class="form-label mnt-label mt-3" for="workOrderTitle">Work order title</label>
          <input id="workOrderTitle" class="form-control mnt-input" maxlength="160" required>
          <label class="form-label mnt-label mt-3" for="workOrderDescription">Repair details</label>
          <textarea id="workOrderDescription" class="form-control mnt-input" rows="3" maxlength="5000" required></textarea>
          <div class="row g-3 mt-1">
            <div class="col-sm-6">
              <label class="form-label mnt-label" for="workOrderPriority">Priority</label>
              <select id="workOrderPriority" class="form-select mnt-input">
                <option>Normal</option><option>Low</option><option>High</option><option>Urgent</option>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label mnt-label" for="workOrderEta">Expected completion</label>
              <input type="datetime-local" id="workOrderEta" class="form-control mnt-input">
            </div>
          </div>
          <div class="small text-muted mt-3">The truck will be marked or kept Under Maintenance until the work order is completed and no active trip or other repair order blocks its return.</div>
        </div>
        <div class="modal-footer mnt-modal-footer">
          <button type="button" class="btn btn-mnt-cancel" data-bs-dismiss="modal">Cancel</button>
          <button type="button" id="createWorkOrderBtn" class="btn btn-mnt-primary">Open Work Order</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="workOrderUpdateModal" tabindex="-1" aria-labelledby="workOrderUpdateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content mnt-modal-content">
        <div class="modal-header mnt-modal-header-primary">
          <h5 class="modal-title" id="workOrderUpdateModalLabel">Manage Repair Work Order</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="workOrderUpdateAlert" class="alert d-none" role="alert"></div>
          <p id="workOrderUpdateTitle" class="fw-semibold"></p>
          <input type="hidden" id="workOrderUpdateId">
          <label class="form-label mnt-label" for="workOrderNextStatus">Next status</label>
          <select id="workOrderNextStatus" class="form-select mnt-input"></select>
          <label class="form-label mnt-label mt-3" for="workOrderClosureNotes">Completion / cancellation notes</label>
          <textarea id="workOrderClosureNotes" class="form-control mnt-input" rows="3" maxlength="1000"></textarea>
          <div class="small text-muted mt-2">Closure notes are required to complete or cancel the order. Cancellation does not return the truck to service.</div>
        </div>
        <div class="modal-footer mnt-modal-footer">
          <button type="button" class="btn btn-mnt-cancel" data-bs-dismiss="modal">Close</button>
          <button type="button" id="saveWorkOrderStatusBtn" class="btn btn-mnt-primary">Save Status</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="pmScheduleModal" tabindex="-1" aria-labelledby="pmScheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content mnt-modal-content">
        <div class="modal-header mnt-modal-header-primary">
          <h5 class="modal-title" id="pmScheduleModalLabel"><i class="bi bi-calendar-check me-2"></i>New Preventive Maintenance Schedule</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="pmScheduleFormAlert" class="alert d-none" role="alert"></div>
          <label class="form-label mnt-label" for="pmScheduleTruck">Truck</label>
          <select id="pmScheduleTruck" class="form-select mnt-input" required>
            <option value="">— Select truck —</option>
            <?php foreach ($trucks as $truck): ?>
            <option value="<?= (int)$truck['truck_id'] ?>"><?= htmlspecialchars($truck['plate_number'] . ' — ' . $truck['brand'] . ' ' . $truck['model']) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="form-label mnt-label mt-3" for="pmScheduleName">Service name</label>
          <input id="pmScheduleName" class="form-control mnt-input" maxlength="160" placeholder="e.g. Oil and filter service" required>
          <label class="form-label mnt-label mt-3" for="pmScheduleDescription">Service details</label>
          <textarea id="pmScheduleDescription" class="form-control mnt-input" rows="3" maxlength="5000" required></textarea>
          <div class="row g-3 mt-1">
            <div class="col-sm-6">
              <label class="form-label mnt-label" for="pmScheduleInterval">Repeat every (days)</label>
              <input type="number" id="pmScheduleInterval" class="form-control mnt-input" min="1" max="3650" value="90" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label mnt-label" for="pmScheduleDueDate">Next due date</label>
              <input type="date" id="pmScheduleDueDate" class="form-control mnt-input" value="<?= date('Y-m-d') ?>" required>
            </div>
          </div>
          <div class="small text-muted mt-3">Schedules use calendar dates. After recording a service, the next due date is calculated from the completion date and repeat interval. Only Available trucks can have service recorded; each completion is added to the maintenance history.</div>
        </div>
        <div class="modal-footer mnt-modal-footer">
          <button type="button" class="btn btn-mnt-cancel" data-bs-dismiss="modal">Cancel</button>
          <button type="button" id="createPmScheduleBtn" class="btn btn-mnt-primary">Create Schedule</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav mnt-tabs mb-4" id="mntTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="mnt-tab" id="tab-pm-schedules" data-bs-toggle="tab"
              data-bs-target="#pane-pm-schedules" type="button" role="tab">
        <i class="bi bi-calendar-check me-1"></i> Preventive Schedules
        <span class="mnt-tab-count"><?= count($preventiveSchedules) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="mnt-tab" id="tab-work-orders" data-bs-toggle="tab"
              data-bs-target="#pane-work-orders" type="button" role="tab">
        <i class="bi bi-tools me-1"></i> Repair Work Orders
        <span class="mnt-tab-count"><?= count($workOrders) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="mnt-tab active" id="tab-records" data-bs-toggle="tab"
              data-bs-target="#pane-records" type="button" role="tab">
        <i class="bi bi-wrench me-1"></i> Maintenance Records
        <span class="mnt-tab-count"><?= count($records) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="mnt-tab" id="tab-checklists" data-bs-toggle="tab"
              data-bs-target="#pane-checklists" type="button" role="tab">
        <i class="bi bi-clipboard-check me-1"></i> Pre-Trip Checklists
        <span class="mnt-tab-count"><?= count($checklists) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="mnt-tab" id="tab-inspections" data-bs-toggle="tab"
              data-bs-target="#pane-inspections" type="button" role="tab">
        <i class="bi bi-car-front me-1"></i> Trip Inspections
        <span class="mnt-tab-count"><?= count($inspectionRows) ?></span>
      </button>
    </li>
  </ul>

  <div class="tab-content">

    <div class="tab-pane fade" id="pane-pm-schedules" role="tabpanel">
      <div id="pmScheduleActionAlert" class="alert d-none" role="alert"></div>
      <div class="mnt-table-wrap">
        <?php if (empty($preventiveSchedules)): ?>
        <div class="mnt-empty">
          <i class="bi bi-calendar-check mnt-empty-icon"></i>
          <p>No preventive maintenance schedules yet.</p>
        </div>
        <?php else: ?>
        <table class="table mnt-table" id="pmSchedulesTable">
          <thead><tr><th>Truck</th><th>Scheduled Service</th><th>Interval</th><th>Next Due</th><th>Status</th><th>Last Completed</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($preventiveSchedules as $schedule): ?>
            <?php
              $dueStatus = $schedule['due_status'];
              $dueBadge = $dueStatus === 'Overdue' ? 'danger'
                  : ($dueStatus === 'Due Soon' ? 'warning'
                  : ($dueStatus === 'Scheduled' ? 'primary' : 'secondary'));
              $dueLabel = $dueStatus;
              if ($dueStatus === 'Overdue') {
                  $dueLabel = abs((int)$schedule['days_until_due']) . ' day(s) overdue';
              } elseif ($dueStatus === 'Due Soon') {
                  $dueLabel = (int)$schedule['days_until_due'] === 0
                      ? 'Due today'
                      : 'Due in ' . (int)$schedule['days_until_due'] . ' day(s)';
              }
            ?>
            <tr>
              <td>
                <div class="mnt-truck-label">
                  <span class="mnt-plate"><?= htmlspecialchars($schedule['plate_number']) ?></span>
                  <span class="mnt-truck-model"><?= htmlspecialchars($schedule['brand'] . ' ' . $schedule['model']) ?></span>
                </div>
              </td>
              <td>
                <strong><?= htmlspecialchars($schedule['service_name']) ?></strong>
                <div class="small text-muted"><?= nl2br(htmlspecialchars($schedule['description'])) ?></div>
              </td>
              <td>Every <?= (int)$schedule['interval_days'] ?> days</td>
              <td><?= date('M d, Y', strtotime($schedule['next_due_date'])) ?></td>
              <td><span class="badge text-bg-<?= $dueBadge ?>"><?= htmlspecialchars($dueLabel) ?></span></td>
              <td><?= $schedule['last_service_date'] ? date('M d, Y', strtotime($schedule['last_service_date'])) : '<span class="text-muted">Not yet recorded</span>' ?></td>
              <td class="text-nowrap">
                <?php if ($schedule['status'] === 'Active'): ?>
                <button type="button" class="btn btn-sm btn-outline-success pm-schedule-complete-btn"
                        data-schedule-id="<?= (int)$schedule['schedule_id'] ?>"
                        data-service-name="<?= htmlspecialchars($schedule['service_name'], ENT_QUOTES) ?>"
                        <?= $schedule['truck_status'] !== 'Available' ? 'disabled title="Truck must be Available to record service."' : '' ?>>
                  Record Service
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary pm-schedule-status-btn"
                        data-schedule-id="<?= (int)$schedule['schedule_id'] ?>" data-next-status="Paused">Pause</button>
                <?php elseif ($schedule['status'] === 'Paused'): ?>
                <button type="button" class="btn btn-sm btn-outline-primary pm-schedule-status-btn"
                        data-schedule-id="<?= (int)$schedule['schedule_id'] ?>" data-next-status="Active">Resume</button>
                <?php endif; ?>
                <?php if ($schedule['status'] !== 'Archived'): ?>
                <button type="button" class="btn btn-sm btn-outline-danger pm-schedule-status-btn"
                        data-schedule-id="<?= (int)$schedule['schedule_id'] ?>" data-next-status="Archived">Archive</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <p class="small text-muted mt-2">Due soon means within 14 calendar days. Recording service creates a Preventive maintenance record and advances the next due date from today by the configured interval. Schedules can be paused or archived without deleting their history.</p>
    </div>

    <div class="tab-pane fade" id="pane-work-orders" role="tabpanel">
      <div class="mnt-table-wrap">
        <?php if (empty($workOrders)): ?>
        <div class="mnt-empty">
          <i class="bi bi-tools mnt-empty-icon"></i>
          <p>No repair work orders yet.</p>
        </div>
        <?php else: ?>
        <table class="table mnt-table" id="workOrdersTable">
          <thead><tr><th>Truck</th><th>Work Order</th><th>Priority</th><th>Status</th><th>Opened</th><th>Expected Completion</th><th>Downtime</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($workOrders as $workOrder): ?>
            <?php
              $downtime = max(0, (int)$workOrder['downtime_minutes']);
              $downtimeLabel = intdiv($downtime, 60) . 'h ' . ($downtime % 60) . 'm';
            ?>
            <tr>
              <td>
                <div class="mnt-truck-label">
                  <span class="mnt-plate"><?= htmlspecialchars($workOrder['plate_number']) ?></span>
                  <span class="mnt-truck-model"><?= htmlspecialchars($workOrder['brand'] . ' ' . $workOrder['model']) ?></span>
                </div>
              </td>
              <td>
                <strong><?= htmlspecialchars($workOrder['title']) ?></strong>
                <div class="small text-muted"><?= nl2br(htmlspecialchars($workOrder['description'])) ?></div>
                <?php if ($workOrder['closure_notes']): ?>
                <div class="small mt-1"><strong>Closure:</strong> <?= nl2br(htmlspecialchars($workOrder['closure_notes'])) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="badge text-bg-<?= $workOrder['priority'] === 'Urgent' ? 'danger' : ($workOrder['priority'] === 'High' ? 'warning' : 'secondary') ?>"><?= htmlspecialchars($workOrder['priority']) ?></span></td>
              <td><span class="badge text-bg-<?= $workOrder['status'] === 'Completed' ? 'success' : ($workOrder['status'] === 'Cancelled' ? 'secondary' : 'warning') ?>"><?= htmlspecialchars($workOrder['status']) ?></span></td>
              <td><?= date('M d, Y H:i', strtotime($workOrder['opened_at'])) ?><div class="small text-muted">by <?= htmlspecialchars($workOrder['created_by_name']) ?></div></td>
              <td><?= $workOrder['expected_completion_at'] ? date('M d, Y H:i', strtotime($workOrder['expected_completion_at'])) : '<span class="text-muted">Not set</span>' ?></td>
              <td><?= htmlspecialchars($downtimeLabel) ?><?= $workOrder['closed_at'] ? '<div class="small text-muted">ended ' . date('M d, Y H:i', strtotime($workOrder['closed_at'])) . '</div>' : '<div class="small text-warning">ongoing</div>' ?></td>
              <td>
                <?php if (in_array($workOrder['status'], ['Open', 'In Progress'], true)): ?>
                <button type="button" class="btn btn-sm btn-outline-primary work-order-manage-btn"
                        data-bs-toggle="modal" data-bs-target="#workOrderUpdateModal"
                        data-work-order-id="<?= (int)$workOrder['work_order_id'] ?>"
                        data-current-status="<?= htmlspecialchars($workOrder['status']) ?>"
                        data-work-order-title="<?= htmlspecialchars($workOrder['title'], ENT_QUOTES) ?>">
                  Manage
                </button>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <p class="small text-muted mt-2">Opening a work order takes an available truck out of service. Only a completed order can return it to Available; cancelling an order leaves it Under Maintenance until a safety review records it Operational.</p>
    </div>

    <!-- ── Records pane ─────────────────────────────────────────────────── -->
    <div class="tab-pane fade show active" id="pane-records" role="tabpanel">

      <!-- Filters -->
      <div class="mnt-filters d-flex flex-wrap gap-2 mb-3">
        <select id="filterRecordType" class="form-select mnt-filter-select">
          <option value="">All Types</option>
          <?php foreach ($maintenanceTypes as $mt): ?>
          <option value="<?= $mt ?>"><?= $mt ?></option>
          <?php endforeach; ?>
        </select>
        <input type="search" id="filterRecordSearch" class="form-control mnt-filter-search"
               placeholder="Search plate, brand, description…">
      </div>

      <div class="mnt-table-wrap">
        <?php if (empty($records)): ?>
        <div class="mnt-empty">
          <i class="bi bi-wrench mnt-empty-icon"></i>
          <p>No maintenance records yet.</p>
        </div>
        <?php else: ?>
        <table class="table mnt-table" id="recordsTable">
          <thead>
            <tr>
              <th>Truck</th>
              <th>Type</th>
              <th>Description</th>
              <th>Truck Status</th>
              <th>Cost</th>
              <th>Date Performed</th>
              <th>Next Due</th>
              <th>Performed By</th>
              <th>Inspection Results</th>
              <th>Linked Incident</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $rec): ?>
            <tr
              data-type="<?= htmlspecialchars($rec['maintenance_type']) ?>"
              data-search="<?= htmlspecialchars(strtolower(
                $rec['plate_number'] . ' ' . $rec['brand'] . ' ' . $rec['model'] . ' ' . $rec['description']
              )) ?>"
            >
              <td>
                <div class="mnt-truck-label">
                  <span class="mnt-plate"><?= htmlspecialchars($rec['plate_number']) ?></span>
                  <span class="mnt-truck-model"><?= htmlspecialchars($rec['brand'] . ' ' . $rec['model']) ?></span>
                </div>
              </td>
              <td>
                <span class="mnt-type-badge mnt-type-<?= strtolower($rec['maintenance_type']) ?>">
                  <?= htmlspecialchars($rec['maintenance_type']) ?>
                </span>
              </td>
              <td class="mnt-desc-cell">
                <button type="button" class="mnt-desc-text mnt-desc-btn"
                        data-truck="<?= htmlspecialchars($rec['plate_number'] . ' — ' . $rec['brand'] . ' ' . $rec['model']) ?>"
                        data-type="<?= htmlspecialchars($rec['maintenance_type']) ?>"
                        data-description="<?= htmlspecialchars($rec['description']) ?>"
                        aria-label="Read full maintenance description">
                  <span class="mnt-desc-preview"><?= htmlspecialchars($rec['description']) ?></span>
                  <span class="mnt-desc-more" aria-hidden="true">...</span>
                </button>
              </td>
              <td>
                <span class="mnt-status-badge mnt-ts-<?= strtolower(str_replace(' ', '-', $rec['truck_status'])) ?>">
                  <?= htmlspecialchars($rec['truck_status']) ?>
                </span>
              </td>
              <td class="mnt-cost">
                <?= $rec['cost'] !== null ? '₱' . number_format($rec['cost'], 2) : '<span class="text-muted">—</span>' ?>
              </td>
              <td class="mnt-date"><?= date('M d, Y', strtotime($rec['date_performed'])) ?></td>
              <td class="mnt-date">
                <?php if ($rec['next_due_date']): ?>
                  <?php
                    $daysLeft = (int)((strtotime($rec['next_due_date']) - time()) / 86400);
                    $dueCls   = $daysLeft <= 7 ? 'mnt-due-urgent' : ($daysLeft <= 30 ? 'mnt-due-soon' : '');
                  ?>
                  <span class="<?= $dueCls ?>"><?= date('M d, Y', strtotime($rec['next_due_date'])) ?></span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($rec['performed_by_name']) ?></td>
              <td>
                <?php if ($rec['inspection_id'] && !empty($findingsByInspection[(int)$rec['inspection_id']])): ?>
                <?php $inspection = array_values(array_filter($inspectionRows, fn($item) => (int)$item['inspection_id'] === (int)$rec['inspection_id']))[0] ?? null; ?>
                <button type="button" class="mnt-desc-text inspection-result-btn"
                        data-inspection="<?= htmlspecialchars(json_encode([
                          'date' => $inspection['inspection_date'] ?? '',
                          'notes' => $inspection['notes'] ?? '',
                          'inspected_by' => $inspection['inspected_by'] ?? '',
                        'stage' => $inspection['inspection_stage'] ?? 'General',
                        'trip_number' => $inspection['trip_number'] ?? '',
                        'findings' => $findingsByInspection[(int)$rec['inspection_id']],
                      ]), ENT_QUOTES, 'UTF-8') ?>">
                  <i class="bi bi-eye me-1"></i>View inspection
                </button>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <td>
                <?php if ($rec['incident_id']): ?>
                <span class="mnt-incident-link">
                  <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                  <?= htmlspecialchars($rec['linked_incident_type']) ?>
                </span>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="noRecordResults" class="no-results d-none">
          <i class="bi bi-search"></i>
          <span>No maintenance records match your filters.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Checklists pane ───────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="pane-checklists" role="tabpanel">

      <div class="mnt-filters d-flex flex-wrap gap-2 mb-3">
        <select id="filterCheckResult" class="form-select mnt-filter-select">
          <option value="">All Results</option>
          <option value="Passed">Passed</option>
          <option value="Failed">Failed</option>
        </select>
        <input type="search" id="filterCheckSearch" class="form-control mnt-filter-search"
               placeholder="Search plate, trip no…">
      </div>

      <div class="mnt-table-wrap">
        <?php if (empty($checklists)): ?>
        <div class="mnt-empty">
          <i class="bi bi-clipboard-check mnt-empty-icon"></i>
          <p>No checklists submitted yet.</p>
        </div>
        <?php else: ?>
        <table class="table mnt-table" id="checklistsTable">
          <thead>
            <tr>
              <th>Truck</th>
              <th>Trip No.</th>
              <th>Result</th>
              <th>Checklist Items</th>
              <th>Notes</th>
              <th>Submitted</th>
              <th>Submitted By</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($checklists as $cl): ?>
            <tr
              data-result="<?= htmlspecialchars($cl['result']) ?>"
              data-search="<?= htmlspecialchars(strtolower($cl['plate_number'] . ' ' . ($cl['trip_number'] ?? ''))) ?>"
            >
              <td>
                <div class="mnt-truck-label">
                  <span class="mnt-plate"><?= htmlspecialchars($cl['plate_number']) ?></span>
                  <span class="mnt-truck-model"><?= htmlspecialchars($cl['brand'] . ' ' . $cl['model']) ?></span>
                </div>
              </td>
              <td>
                <?php if ($cl['trip_number']): ?>
                <span class="mnt-ref"><?= htmlspecialchars($cl['trip_number']) ?></span>
                <?php else: ?>
                <span class="text-muted fst-italic" style="font-size:0.8rem;">Pending</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="mnt-result-badge mnt-result-<?= strtolower($cl['result']) ?>">
                  <?= $cl['result'] ?>
                </span>
              </td>
              <td>
                <div class="mnt-checklist-items">
                  <?php foreach ($checklistItems as $field => $label): ?>
                  <span class="mnt-check-item <?= $cl[$field] ? 'mnt-check-ok' : 'mnt-check-fail' ?>"
                        title="<?= $label ?>">
                    <i class="bi <?= $cl[$field] ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i>
                    <?= $label ?>
                  </span>
                  <?php endforeach; ?>
                </div>
              </td>
              <td class="mnt-desc-cell">
                <span class="mnt-desc-text" title="<?= htmlspecialchars($cl['notes'] ?? '') ?>">
                  <?= $cl['notes'] ? htmlspecialchars($cl['notes']) : '<span class="text-muted">—</span>' ?>
                </span>
              </td>
              <td class="mnt-date"><?= date('M d, Y H:i', strtotime($cl['submitted_at'])) ?></td>
              <td><?= htmlspecialchars($cl['submitted_by_name']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="noChecklistResults" class="no-results d-none">
          <i class="bi bi-search"></i>
          <span>No checklists match your filters.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="tab-pane fade" id="pane-inspections" role="tabpanel">
      <div class="mnt-table-wrap">
        <?php if (empty($inspectionRows)): ?>
        <div class="mnt-empty">
          <i class="bi bi-car-front mnt-empty-icon"></i>
          <p>No vehicle inspections submitted yet.</p>
        </div>
        <?php else: ?>
        <table class="table mnt-table" id="tripInspectionsTable">
          <thead><tr><th>Truck</th><th>Trip</th><th>Stage</th><th>Inspection Date</th><th>Findings</th><th>Result</th><th>Inspected By</th></tr></thead>
          <tbody>
          <?php foreach ($inspectionRows as $inspection): ?>
            <?php $inspectionFindings = $findingsByInspection[(int)$inspection['inspection_id']] ?? []; ?>
            <tr>
              <td><?= htmlspecialchars($inspection['plate_number']) ?></td>
              <td><?= $inspection['trip_number'] ? htmlspecialchars($inspection['trip_number']) : '—' ?></td>
              <td><?= htmlspecialchars($inspection['inspection_stage']) ?></td>
              <td><?= date('M d, Y', strtotime($inspection['inspection_date'])) ?></td>
              <td><?= count($inspectionFindings) ?></td>
              <td>
                <?php if (isset($newFindingsByInspection[(int)$inspection['inspection_id']])): ?>
                  <span class="mnt-result-badge mnt-result-failed">
                    <?= count($newFindingsByInspection[(int)$inspection['inspection_id']]) ?> new issue(s)
                  </span>
                <?php elseif ($inspection['inspection_stage'] === 'Departure'): ?>
                  <span class="mnt-result-badge mnt-result-passed">Clear to depart</span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td>
                <?= htmlspecialchars($inspection['inspected_by']) ?>
                <button type="button" class="btn btn-sm btn-outline-primary inspection-result-btn ms-2"
                        data-inspection="<?= htmlspecialchars(json_encode([
                          'date' => $inspection['inspection_date'],
                          'notes' => $inspection['notes'],
                          'stage' => $inspection['inspection_stage'],
                          'trip_number' => $inspection['trip_number'],
                          'findings' => $inspectionFindings,
                          'new_findings' => $newFindingsByInspection[(int)$inspection['inspection_id']] ?? [],
                        ]), ENT_QUOTES, 'UTF-8') ?>">
                  View
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /tab-content -->
</div>

<!-- ══ Log Maintenance Record Modal ═══════════════════════════════════════ -->
<div class="modal fade" id="recordModal" tabindex="-1" aria-labelledby="recordModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content mnt-modal-content">
      <div class="modal-header mnt-modal-header-primary">
        <h5 class="modal-title" id="recordModalLabel">
          <i class="bi bi-wrench me-2"></i>Log Maintenance Record
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body mnt-modal-body">
        <div id="recordFormAlert" class="alert d-none" role="alert"></div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label mnt-label" for="recTruckId">Truck</label>
            <select class="form-select mnt-input" id="recTruckId" required>
              <option value="">— Select truck —</option>
              <?php foreach ($trucks as $tr): ?>
              <option value="<?= $tr['truck_id'] ?>">
                <?= htmlspecialchars($tr['plate_number']) ?> — <?= htmlspecialchars($tr['brand'] . ' ' . $tr['model']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label mnt-label" for="recInspectionId">Vehicle Inspection (optional)</label>
            <select class="form-select mnt-input" id="recInspectionId">
              <option value="">— None —</option>
              <?php foreach ($inspectionRows as $inspection): ?>
              <option value="<?= $inspection['inspection_id'] ?>" data-truck-id="<?= $inspection['truck_id'] ?>">
                <?= htmlspecialchars($inspection['plate_number']) ?> — <?= date('M d, Y', strtotime($inspection['inspection_date'])) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label mnt-label" for="recType">Maintenance Type</label>
            <select class="form-select mnt-input" id="recType" required>
              <option value="">— Select type —</option>
              <?php foreach ($maintenanceTypes as $mt): ?>
              <option value="<?= $mt ?>"><?= $mt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label mnt-label" for="recTruckStatus">Truck Status After</label>
            <select class="form-select mnt-input" id="recTruckStatus" required>
              <option value="">— Select status —</option>
              <?php foreach ($truckStatuses as $ts): ?>
              <option value="<?= $ts ?>"><?= $ts ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label mnt-label" for="recDatePerformed">Date Performed</label>
            <input type="date" class="form-control mnt-input" id="recDatePerformed"
                   value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label mnt-label" for="recNextDue">
              Next Due Date <span class="mnt-unit-hint">(sets this truck's next alert)</span>
            </label>
            <input type="date" class="form-control mnt-input" id="recNextDue" min="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label mnt-label" for="recCost">Cost (₱)</label>
            <input type="number" class="form-control mnt-input" id="recCost"
                   min="0" step="0.01" placeholder="Leave blank if unknown">
          </div>
          <div class="col-md-6">
            <label class="form-label mnt-label" for="recIncidentId">Link to Incident (optional)</label>
            <select class="form-select mnt-input" id="recIncidentId">
              <option value="">— None —</option>
              <?php foreach ($openIncidents as $oi): ?>
              <option value="<?= $oi['incident_id'] ?>">
                #<?= $oi['incident_id'] ?> — <?= htmlspecialchars($oi['incident_type']) ?>
                (<?= htmlspecialchars($oi['trip_number']) ?> / <?= htmlspecialchars($oi['plate_number']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label mnt-label" for="recDescription">Description</label>
            <textarea class="form-control mnt-input" id="recDescription" rows="3"
              placeholder="What was done? Parts replaced, issues found, etc." required></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer mnt-modal-footer">
        <button type="button" class="btn btn-mnt-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-mnt-primary" id="submitRecordBtn">
          <span id="recBtnText">Save Record</span>
          <span id="recBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Pre-Trip Checklist Modal ═══════════════════════════════════════════ -->
<div class="modal fade" id="checklistModal" data-min-duration-seconds="<?= MAINTENANCE_FORM_MIN_DURATION_SECONDS ?>" tabindex="-1" aria-labelledby="checklistModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content mnt-modal-content">
      <div class="modal-header mnt-modal-header-secondary">
        <h5 class="modal-title" id="checklistModalLabel">
          <i class="bi bi-clipboard-check me-2"></i>Pre-Trip Checklist
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body mnt-modal-body">
        <div id="checklistFormAlert" class="alert d-none" role="alert"></div>
        <div id="checklistTimerStatus" class="small text-muted mb-3" role="status" aria-live="polite">Select a dispatch to start the minimum completion timer.</div>

        <div class="mb-3">
          <label class="form-label mnt-label" for="clDispatchId">Dispatch / Trip</label>
          <select class="form-select mnt-input" id="clDispatchId" required>
            <option value="">— Select approved dispatch —</option>
            <?php foreach ($pendingDispatches as $pd): ?>
            <option value="<?= $pd['dispatch_id'] ?>"
                    <?= (int)$pd['trip_id'] === $requestedTripId ? 'selected' : '' ?>
                    data-truck-id="<?= (int)$pd['truck_id'] ?>">
              <?= htmlspecialchars($pd['plate_number']) ?> —
              <?= htmlspecialchars($pd['brand'] . ' ' . $pd['model']) ?> |
              <?= htmlspecialchars($pd['driver_name']) ?> |
              <?= htmlspecialchars($pd['origin']) ?> → <?= htmlspecialchars($pd['destination']) ?>
              <?= $pd['scheduled_at'] ? '| ' . date('M d', strtotime($pd['scheduled_at'])) : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($pendingDispatches)): ?>
          <div class="form-text text-warning">
            <i class="bi bi-info-circle"></i>
            No approved dispatches without a passed checklist at the moment.
          </div>
          <?php endif; ?>
        </div>

        <p class="mnt-label mb-2">Checklist Items</p>
        <div class="mnt-checklist-grid mb-3">
          <?php foreach ($checklistItems as $field => $label): ?>
          <label class="mnt-check-toggle">
            <input type="checkbox" class="mnt-check-cb" id="cl_<?= $field ?>" name="<?= $field ?>">
            <span class="mnt-check-toggle-inner">
              <i class="bi bi-check-lg"></i>
            </span>
            <span class="mnt-check-toggle-label"><?= $label ?></span>
          </label>
          <?php endforeach; ?>
        </div>

        <div id="clResultBanner" class="mnt-result-banner d-none"></div>

        <div class="mt-3">
          <label class="form-label mnt-label" for="clNotes">Notes (optional)</label>
          <textarea class="form-control mnt-input" id="clNotes" rows="2"
            placeholder="Any issues found or remarks…"></textarea>
        </div>
      </div>
      <div class="modal-footer mnt-modal-footer">
        <button type="button" class="btn btn-mnt-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-mnt-secondary" id="submitChecklistBtn" disabled>
          <span id="clBtnText">Submit Checklist</span>
          <span id="clBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Description Expand Modal (Records tab) ═══════════════════════════════ -->
<div class="modal fade" id="descModal" tabindex="-1" aria-labelledby="descModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content mnt-modal-content">
      <div class="modal-header mnt-modal-header-primary">
        <h5 class="modal-title" id="descModalLabel">
          <i class="bi bi-file-text me-2"></i><span id="descModalTruck"></span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <span class="mnt-type-badge" id="descModalType"></span>
        <p class="mt-3 mb-0" id="descModalText" style="white-space: pre-wrap;"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php layoutFoot(); ?>