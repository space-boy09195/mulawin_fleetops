<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../app/modules/maintenance/inspections.php';
require_once __DIR__ . '/../app/modules/trips/trip_workflow.php';
require_once __DIR__ . '/../app/modules/fleet/truck_records.php';
require_once __DIR__ . '/../app/modules/documents/document_upload.php';

requireAnyPermission(['maintenance.view', 'maintenance.manage']);
$hideMaintenanceEntryButtons = isAdmin() || currentRoleKey() === 'operations_head';
$requestedTripId = filter_input(INPUT_GET, 'trip_id', FILTER_VALIDATE_INT);
$requestedInspectionStage = $_GET['inspection_stage'] ?? null;
if ($requestedInspectionStage === 'Arrival') {
    $requestedInspectionStage = 'Return';
}
if (!in_array($requestedInspectionStage, ['Departure', 'Return'], true)) {
    $requestedInspectionStage = null;
}

// This file serves two pages: the Maintenance work page (Work to Address,
// Pre-Departure Inspection, Return Inspection) and, when included by
// pages/maintenance_records.php, Maintenance Records & History. Both share
// the data below, so nothing is copied between them.
$isRecordsPage = defined('MAINTENANCE_RECORDS_PAGE');
if (!$isRecordsPage && (($_GET['view'] ?? '') === 'records' || isset($_GET['period']))) {
    // Records & History moved to its own page; older links still work.
    $recordsQuery = isset($_GET['period']) ? '?period=' . rawurlencode((string)$_GET['period']) : '';
    header('Location: ' . APP_BASE . '/pages/maintenance_records.php' . $recordsQuery);
    exit;
}

$pageBase = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$GLOBALS['page_js'] = $pageBase . '/assets/js/maintenance.js';

layoutHead($isRecordsPage ? 'Maintenance Records & History' : 'Maintenance', $pageBase . '/assets/css/maintenance.css');

$pdo = getDBConnection();

// ── Period filter (scopes Maintenance Records list below) ────────────────────
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
// Target date of a maintenance record (the legacy date_performed).
$recordDateSql = 'COALESCE(mr.actual_completion_date, mr.start_date, mr.scheduled_date, DATE(mr.created_at))';
$recDateFilter = $rangeStartSql ? "AND $recordDateSql >= :rangeStart" : '';

// ── Maintenance records ───────────────────────────────────────────────────────
// Target maintenance_records with record_source 'record' / 'pm_service'
// (T-M2); work orders and general inspections are listed separately below.
// The legacy truck_status of a record is derived from the record status.
$recordsSql = "
    SELECT
        mr.maintenance_id AS record_id,
        mr.maintenance_type,
        CASE mr.status
            WHEN 'Completed' THEN 'Operational'
            WHEN 'Scheduled' THEN 'Scheduled Maintenance'
            ELSE 'Under Repair'
        END AS truck_status,
        mr.status AS record_status,
        mr.description,
        mr.total_cost AS cost,
        $recordDateSql AS date_performed,
        mr.next_due_date,
        mr.vehicle_inspection_id AS inspection_id,
        mr.created_at,
        tr.plate_number,
        tr.brand,
        tr.model,
        COALESCE(ue.full_name, u.username, '—') AS performed_by_name,
        i.incident_type  AS linked_incident_type,
        i.incident_id
    FROM maintenance_records mr
    JOIN trucks tr   ON mr.truck_id = tr.truck_id
    LEFT JOIN users u ON u.user_id = COALESCE(mr.completed_by, mr.created_by)
    LEFT JOIN employees ue ON ue.employee_id = u.employee_id
    LEFT JOIN trip_incidents i ON mr.incident_id = i.incident_id
    WHERE mr.record_source IN ('record', 'pm_service') $recDateFilter
    ORDER BY date_performed DESC, mr.created_at DESC
    LIMIT 1000
";
$recordsStmt = $pdo->prepare($recordsSql);
if ($rangeStartSql) $recordsStmt->bindValue(':rangeStart', $rangeStartSql);
$recordsStmt->execute();
$records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Inspections ───────────────────────────────────────────────────────────────
// Trip inspections: vehicle_inspections on the 'Standard vehicle inspection'
// templates (stage Arrival shown as the legacy 'Return'). General
// inspections: maintenance_records (record_source 'inspection'); their ID is
// shown negated so it can never collide with a vehicle_inspections ID.
$checklistTemplate = inspectionTemplateForForm($pdo, 'checklist');
$checklistTemplateId = $checklistTemplate ? (int)$checklistTemplate['template_id'] : 0;
$tripInspectionRows = $pdo->prepare("
    SELECT vi.inspection_id, vi.truck_id, vi.trip_id,
           IF(vi.inspection_stage = 'Arrival', 'Return', vi.inspection_stage) AS inspection_stage,
           DATE(vi.completed_at) AS inspection_date, vi.remarks AS notes, vi.completed_at AS sort_at,
           tr.plate_number, COALESCE(ue.full_name, u.username) AS inspected_by,
           t.trip_number
    FROM vehicle_inspections vi
    JOIN trucks tr ON tr.truck_id = vi.truck_id
    JOIN users u ON u.user_id = vi.inspected_by
    LEFT JOIN employees ue ON ue.employee_id = u.employee_id
    LEFT JOIN trips t ON t.trip_id = vi.trip_id
    WHERE vi.completed_at IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM inspection_findings f
          JOIN inspection_items ii ON ii.inspection_item_id = f.inspection_item_id
          WHERE f.inspection_id = vi.inspection_id AND ii.template_id = ?
      )
    ORDER BY vi.completed_at DESC
    LIMIT 500
");
$tripInspectionRows->execute([$checklistTemplateId]);
$generalInspectionRows = $pdo->query("
    SELECT -mr.maintenance_id AS inspection_id, mr.truck_id, NULL AS trip_id,
           'General' AS inspection_stage,
           COALESCE(mr.actual_completion_date, mr.start_date, DATE(mr.created_at)) AS inspection_date,
           mr.description AS notes, mr.created_at AS sort_at,
           tr.plate_number, COALESCE(ue.full_name, u.username) AS inspected_by,
           NULL AS trip_number
    FROM maintenance_records mr
    JOIN trucks tr ON tr.truck_id = mr.truck_id
    LEFT JOIN users u ON u.user_id = COALESCE(mr.completed_by, mr.created_by)
    LEFT JOIN employees ue ON ue.employee_id = u.employee_id
    WHERE mr.record_source = 'inspection'
    ORDER BY mr.created_at DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);
$inspectionRows = array_merge($tripInspectionRows->fetchAll(PDO::FETCH_ASSOC), $generalInspectionRows);
usort($inspectionRows, static fn(array $a, array $b): int => strcmp((string)$b['sort_at'], (string)$a['sort_at']));

// Inspection photos (inspection_photos -> documents), linked through the
// existing authorized download endpoint. Only documents the user may
// download and see (documents.download + visibility scope) are listed;
// ajax/document_download.php re-checks both.
$inspectionPhotoDocuments = [];
$tripInspectionIds = array_values(array_filter(
    array_map(static fn(array $row): int => (int)$row['inspection_id'], $inspectionRows),
    static fn(int $id): bool => $id > 0
));
if ($tripInspectionIds && currentUserHasAnyPermission(['documents.download'])) {
    $photoParams = $tripInspectionIds;
    $photoVisibility = documentVisibilitySql(documentVisibilityScopesForCurrentUser(), 'd', $photoParams);
    $photoStmt = $pdo->prepare(
        'SELECT ip.inspection_id, MIN(d.document_id) AS document_id
         FROM inspection_photos ip
         JOIN documents d ON d.file_id = ip.file_id
         WHERE ip.inspection_id IN (' . implode(',', array_fill(0, count($tripInspectionIds), '?')) . ")
         $photoVisibility
         GROUP BY ip.photo_id, ip.inspection_id
         ORDER BY ip.photo_id"
    );
    $photoStmt->execute($photoParams);
    foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $photoRow) {
        $inspectionPhotoDocuments[(int)$photoRow['inspection_id']][] = (int)$photoRow['document_id'];
    }
}

// Findings in the legacy shape (view_name, part_name, condition, notes).
// condition_description holds "Condition — notes" (includes/inspections.php).
$splitCondition = static function (string $text): array {
    $parts = explode(' — ', $text, 2);
    return [$parts[0], $parts[1] ?? null];
};
$findingsByInspection = [];
$tripFindingRows = $pdo->query("
    SELECT f.inspection_id, ii.category AS view_name, ii.item_name AS part_name,
           f.result, f.condition_description
    FROM inspection_findings f
    JOIN inspection_items ii ON ii.inspection_item_id = f.inspection_item_id
    JOIN inspection_templates it ON it.template_id = ii.template_id
    WHERE it.template_name = 'Standard vehicle inspection'
    ORDER BY ii.category, ii.sort_order, ii.item_name
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($tripFindingRows as $finding) {
    [$condition, $notes] = $splitCondition((string)($finding['condition_description'] ?? ''));
    if ($condition === '' || !in_array($condition, INSPECTION_CONDITIONS, true)) {
        $condition = $finding['result'] === 'Good' ? 'Good' : ($finding['result'] === 'N/A' ? 'Not Checked' : 'Needs Attention');
    }
    $findingsByInspection[(int)$finding['inspection_id']][] = [
        'inspection_id' => (int)$finding['inspection_id'],
        'view_name' => $finding['view_name'],
        'part_name' => $finding['part_name'],
        'condition' => $condition,
        'notes' => $notes,
    ];
}
$generalFindingRows = $pdo->query("
    SELECT mf.maintenance_id, mf.finding_type, mf.description
    FROM maintenance_findings mf
    JOIN maintenance_records mr ON mr.maintenance_id = mf.maintenance_id
    WHERE mr.record_source = 'inspection'
    ORDER BY mf.finding_type
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($generalFindingRows as $finding) {
    [$view, $part] = array_pad(explode(': ', (string)$finding['finding_type'], 2), 2, '');
    [$condition, $notes] = $splitCondition((string)$finding['description']);
    $findingsByInspection[-(int)$finding['maintenance_id']][] = [
        'inspection_id' => -(int)$finding['maintenance_id'],
        'view_name' => $view,
        'part_name' => $part,
        'condition' => $condition,
        'notes' => $notes,
    ];
}
$inspectionTripsRaw = $pdo->query("
    SELECT t.trip_id, t.trip_number, t.status, t.truck_id, tr.plate_number
    FROM trips t
    JOIN trucks tr ON tr.truck_id = t.truck_id
    WHERE t.status IN ('Assigned', 'Delivered')
    ORDER BY t.created_at DESC
    LIMIT 300
")->fetchAll(PDO::FETCH_ASSOC);
// Departure inspections after the departure packet (step 6), arrival
// inspections after delivery details (step 11). The form keeps the legacy
// labels: Assigned = 'Loading', Delivered = 'Unloading'.
$inspectionTrips = [];
foreach ($inspectionTripsRaw as $inspectionTrip) {
    $step = tripWorkflowCurrentStep($pdo, (int)$inspectionTrip['trip_id']);
    $isDeparture = $inspectionTrip['status'] === 'Assigned';
    if ($step < ($isDeparture ? 6 : 11)) {
        continue;
    }
    $inspectionTrip['status'] = $isDeparture ? 'Loading' : 'Unloading';
    $inspectionTrips[] = $inspectionTrip;
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
// Departure inspections on the 'Pre-trip checklist' template (T-N1); each
// legacy *_ok flag is that item's 'Good' result.
$checklists = [];
if ($checklistTemplateId > 0) {
    $checklistStmt = $pdo->prepare("
        SELECT vi.inspection_id AS checklist_id,
               IF(vi.overall_result = 'Passed', 'Passed', 'Failed') AS result,
               vi.remarks AS notes, vi.completed_at AS submitted_at,
               tr.plate_number, tr.brand, tr.model,
               dr.status AS dispatch_status, t.trip_number,
               COALESCE(ue.full_name, u.username) AS submitted_by_name
        FROM vehicle_inspections vi
        JOIN trucks tr ON tr.truck_id = vi.truck_id
        JOIN trips t ON t.trip_id = vi.trip_id
        LEFT JOIN dispatch_requests dr ON dr.trip_id = t.trip_id
        JOIN users u ON u.user_id = vi.inspected_by
        LEFT JOIN employees ue ON ue.employee_id = u.employee_id
        WHERE vi.completed_at IS NOT NULL
          AND EXISTS (
              SELECT 1 FROM inspection_findings f
              JOIN inspection_items ii ON ii.inspection_item_id = f.inspection_item_id
              WHERE f.inspection_id = vi.inspection_id AND ii.template_id = ?
          )
        ORDER BY vi.completed_at DESC
        LIMIT 500
    ");
    $checklistStmt->execute([$checklistTemplateId]);
    $checklists = $checklistStmt->fetchAll(PDO::FETCH_ASSOC);
    $itemResults = $pdo->prepare("
        SELECT f.inspection_id, ii.item_name, f.result
        FROM inspection_findings f
        JOIN inspection_items ii ON ii.inspection_item_id = f.inspection_item_id
        WHERE ii.template_id = ?
    ");
    $itemResults->execute([$checklistTemplateId]);
    $goodItems = [];
    foreach ($itemResults->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $goodItems[(int)$row['inspection_id']][$row['item_name']] = $row['result'] === 'Good';
    }
    foreach ($checklists as &$checklistRow) {
        foreach (CHECKLIST_ITEM_FIELDS as $field => $label) {
            $checklistRow[$field] = !empty($goodItems[(int)$checklistRow['checklist_id']][$label]) ? 1 : 0;
        }
    }
    unset($checklistRow);
}

// ── Dropdowns for forms ───────────────────────────────────────────────────────
// Truck photos come from documents (includes/truck_records.php).
$trucks = $pdo->query("
    SELECT " . truckProfileColumnsSql() . ",
           COALESCE(t.body_details, 'Closed Van') AS body_type
    FROM trucks t
    WHERE t.status <> 'Unavailable'
    ORDER BY t.plate_number
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($trucks as &$truckRow) {
    $truckRow['image_path'] = $truckRow['image_front_path'];
}
unset($truckRow);
$availableTrucks = $pdo->query("
    SELECT truck_id, plate_number, brand, model, status
    FROM trucks WHERE status IN ('Available', 'Under Maintenance') ORDER BY plate_number
")->fetchAll(PDO::FETCH_ASSOC);
// Repair work orders: maintenance_records with record_source 'work_order'
// (T-M2). Legacy 'Open' = target 'Reported'; the expected completion is a
// date in the target.
$workOrders = $pdo->query("
    SELECT mr.maintenance_id AS work_order_id, mr.truck_id, mr.reported_issue AS title,
           mr.description, mr.work_order_priority AS priority,
           CASE mr.status WHEN 'Reported' THEN 'Open' WHEN 'Scheduled' THEN 'Open'
                          ELSE mr.status END AS status,
           mr.expected_completion_date AS expected_completion_at, mr.created_at AS opened_at,
           mr.closed_at, mr.closure_notes,
           tr.plate_number, tr.brand, tr.model,
           COALESCE(ce.full_name, cu.username, '—') AS created_by_name,
           TIMESTAMPDIFF(MINUTE, mr.created_at, COALESCE(mr.closed_at, NOW())) AS downtime_minutes
    FROM maintenance_records mr
    JOIN trucks tr ON tr.truck_id = mr.truck_id
    LEFT JOIN users cu ON cu.user_id = mr.created_by
    LEFT JOIN employees ce ON ce.employee_id = cu.employee_id
    WHERE mr.record_source = 'work_order'
    ORDER BY FIELD(mr.status, 'Reported', 'Scheduled', 'In Progress', 'Waiting for Parts', 'Completed', 'Cancelled'),
             mr.expected_completion_date IS NULL, mr.expected_completion_date, mr.created_at DESC
    LIMIT 500
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

// Open incidents (for linking to a maintenance record)
$openIncidents = $pdo->query("
    SELECT
        i.incident_id,
        i.incident_type,
        t.trip_number,
        tr.plate_number
    FROM trip_incidents i
    JOIN trips t   ON i.trip_id = t.trip_id
    JOIN trucks tr ON tr.truck_id = COALESCE(i.truck_id, t.truck_id)
    WHERE i.resolved_at IS NULL
    ORDER BY i.incident_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$maintenanceTypes = MAINTENANCE_TYPES;
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

// ── Page views ────────────────────────────────────────────────────────────────
// The work page has one view per task: work waiting for Maintenance, the
// Pre-Departure Inspection of one trip (pre-trip checklist + departure
// inspection), and trips waiting for the return inspection. The records
// page always shows the 'records' view.
const MAINTENANCE_VIEWS = [
    'overview'      => ['Work to Address', 'bi-clipboard-check'],
    'pre_departure' => ['Pre-Departure Inspection', 'bi-truck-front'],
    'return'        => ['Return Inspection', 'bi-arrow-return-left'],
];
$view = $isRecordsPage ? 'records' : (string)($_GET['view'] ?? '');
if (!$isRecordsPage && !isset(MAINTENANCE_VIEWS[$view])) {
    // Older links: inspection_stage=Departure opened the departure inspection,
    // Arrival/Return the return inspection.
    $view = match (true) {
        $requestedInspectionStage === 'Departure' => 'pre_departure',
        $requestedInspectionStage === 'Return'    => 'return',
        default                                   => 'overview',
    };
}
$canEnterInspections = !$hideMaintenanceEntryButtons && currentUserHasAnyPermission(['maintenance.manage']);
// Trips waiting for departure checks (Assigned, step 6 done) and for the
// return inspection (Delivered, step 11 done), from $inspectionTrips.
$departureQueue = array_values(array_filter($inspectionTrips, static fn(array $trip): bool => $trip['status'] === 'Loading'));
$returnQueue = array_values(array_filter($inspectionTrips, static fn(array $trip): bool => $trip['status'] === 'Unloading'));

// Pre-Departure Inspection of one trip: its two sections keep their own
// records, results and timers; nothing here changes a record.
$preDeparture = null;
if ($view === 'pre_departure' && $requestedTripId) {
    $tripQuery = $pdo->prepare(
        "SELECT t.trip_id, t.trip_number, t.status, t.truck_id, t.scheduled_departure,
                COALESCE(r.origin, t.origin) AS origin, COALESCE(r.destination, t.destination) AS destination,
                tr.plate_number, tr.truck_type, dr.dispatch_request_id AS dispatch_id, dr.status AS dispatch_status,
                COALESCE(e.full_name, CONCAT('Employee #', e.employee_id)) AS driver_name
         FROM trips t
         JOIN trucks tr ON tr.truck_id = t.truck_id
         LEFT JOIN routes r ON r.route_id = t.route_id
         LEFT JOIN employees e ON e.employee_id = t.driver_id
         LEFT JOIN dispatch_requests dr ON dr.trip_id = t.trip_id
         WHERE t.trip_id = ?"
    );
    $tripQuery->execute([$requestedTripId]);
    $preDepartureTrip = $tripQuery->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($preDepartureTrip) {
        $tripStep = tripWorkflowCurrentStep($pdo, (int)$preDepartureTrip['trip_id']);
        $departureTemplate = inspectionTemplateForForm($pdo, 'inspection_departure');
        $sectionStatus = static function (array $results): array {
            $passed = false;
            $failed = 0;
            foreach ($results as $row) {
                $passed = $passed || $row['overall_result'] === 'Passed';
                $failed += $row['overall_result'] === 'Passed' ? 0 : 1;
            }
            return ['passed' => $passed, 'failed' => $failed];
        };
        $truckForTrip = null;
        foreach ($trucks as $truckRow) {
            if ((int)$truckRow['truck_id'] === (int)$preDepartureTrip['truck_id']) {
                $truckForTrip = $truckRow;
            }
        }
        $preDeparture = [
            'trip'      => $preDepartureTrip,
            'truck'     => $truckForTrip,
            'step'      => $tripStep,
            // Same rule as the handlers: an approved dispatch, status
            // Assigned and the departure packet (step 6) recorded.
            'ready'     => $preDepartureTrip['status'] === 'Assigned' && $tripStep >= 6
                && $preDepartureTrip['dispatch_status'] === 'Approved' && $truckForTrip !== null,
            'checklist' => $sectionStatus($checklistTemplateId > 0
                ? tripTemplateInspectionResults($pdo, (int)$preDepartureTrip['trip_id'], $checklistTemplateId) : []),
            'departure' => $sectionStatus($departureTemplate
                ? tripTemplateInspectionResults($pdo, (int)$preDepartureTrip['trip_id'], (int)$departureTemplate['template_id']) : []),
            'summary'   => tripInspectionSummary($pdo, (int)$preDepartureTrip['trip_id'], 'Departure'),
        ];
    }
}
$sectionBadge = static function (array $status): array {
    if ($status['passed']) {
        return ['Completed', 'text-bg-success'];
    }
    return $status['failed'] > 0
        ? ['Failed · submit again', 'text-bg-danger']
        : ['Not submitted', 'text-bg-secondary'];
};
?>

<div class="mnt-page">

  <!-- Header -->
  <div class="mnt-header d-flex align-items-center justify-content-between mb-4">
    <?php if ($isRecordsPage): ?>
    <div>
      <h1 class="mnt-title mb-0">Maintenance Records &amp; History</h1>
      <p class="mnt-subtitle mb-0">PM schedules, work orders, maintenance records, checklists and inspection history</p>
      <a class="small" href="<?= APP_BASE ?>/pages/maintenance.php"><i class="bi bi-arrow-left me-1"></i>Back to Maintenance work</a>
    </div>
    <?php else: ?>
    <div>
      <h1 class="mnt-title mb-0">Maintenance</h1>
      <p class="mnt-subtitle mb-0"><?= htmlspecialchars(MAINTENANCE_VIEWS[$view][0]) ?></p>
      <nav class="nav nav-pills mt-2 gap-1" aria-label="Maintenance tasks">
        <?php foreach (MAINTENANCE_VIEWS as $viewKey => [$viewLabel, $viewIcon]): ?>
        <a class="nav-link py-1 px-2 small<?= $viewKey === $view ? ' active' : '' ?>"
           <?= $viewKey === $view ? 'aria-current="page"' : '' ?>
           href="<?= APP_BASE ?>/pages/maintenance.php?view=<?= $viewKey ?>">
          <i class="bi <?= $viewIcon ?> me-1"></i><?= htmlspecialchars($viewLabel) ?>
          <?php if ($viewKey === 'pre_departure' && $departureQueue): ?><span class="badge text-bg-secondary ms-1"><?= count($departureQueue) ?></span><?php endif; ?>
          <?php if ($viewKey === 'return' && $returnQueue): ?><span class="badge text-bg-secondary ms-1"><?= count($returnQueue) ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </nav>
    </div>
    <a class="btn btn-sm btn-outline-secondary align-self-start" href="<?= APP_BASE ?>/pages/maintenance_records.php">
      <i class="bi bi-journal-text me-1"></i>Records &amp; History
    </a>
    <?php endif; ?>
    <?php if ($isRecordsPage): ?>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <form method="get" class="d-flex">
        <select name="period" class="form-select mnt-input" style="min-width:160px;font-size:.85rem;" data-soft-submit>
          <?php foreach ($periods as $key => $label): ?>
          <option value="<?= $key ?>" <?= $key === $period ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if (!$hideMaintenanceEntryButtons): ?>
      <button class="btn btn-mnt-primary" data-bs-toggle="modal" data-bs-target="#inspectionModal">
        <i class="bi bi-bounding-box-circles me-1"></i> General Vehicle Inspection
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
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($isRecordsPage || $view === 'return'): ?>
    <!-- Interactive vehicle inspection: the general inspection on the records
         page, the return inspection on the Return Inspection view. The
         departure inspection is on the Pre-Departure Inspection view. -->
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
                  <?php if ($isRecordsPage): ?>
                  <option value="General" selected>General Vehicle Inspection</option>
                  <?php else: ?>
                  <option value="Return" selected>Trip Return Inspection</option>
                  <?php endif; ?>
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
                <label class="form-label mnt-label mt-3" for="inspectionPhotos">Inspection photos (departure or return; JPG, PNG, or WebP; up to 8, max 10 MB each)</label>
                <input type="file" id="inspectionPhotos" class="form-control mnt-input" accept="image/jpeg,image/png,image/webp" multiple>
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
    <?php endif; ?>
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
          <div class="row g-2 mt-1">
            <div class="col-6">
              <label class="form-label mnt-label mt-2" for="workOrderLaborCost">Labour cost (₱, optional)</label>
              <input type="number" id="workOrderLaborCost" class="form-control mnt-input" min="0" step="0.01">
            </div>
            <div class="col-6">
              <label class="form-label mnt-label mt-2" for="workOrderOtherCost">Other cost (₱, optional)</label>
              <input type="number" id="workOrderOtherCost" class="form-control mnt-input" min="0" step="0.01">
            </div>
          </div>
          <div class="small text-muted mt-1">Parts cost is added automatically when parts are issued to this job.</div>
          <label class="form-label mnt-label mt-3" for="workOrderFinding">Finding (optional)</label>
          <textarea id="workOrderFinding" class="form-control mnt-input" rows="2" maxlength="1000"></textarea>
          <label class="form-label mnt-label mt-2" for="workOrderFindingSeverity">Finding severity</label>
          <select id="workOrderFindingSeverity" class="form-select mnt-input">
            <option value="Low">Low</option>
            <option value="Medium" selected>Medium</option>
            <option value="High">High</option>
            <option value="Critical">Critical</option>
          </select>
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

  <?php if ($view === 'overview'): ?>
  <!-- ── Work to Address: inspections waiting for Maintenance ─────────────── -->
  <div class="row g-4 mb-4">
    <?php foreach ([
        ['Pre-departure checks waiting', $departureQueue, 'pre_departure', 'Open Pre-Departure Inspection', 'Trips appear here after the dispatcher records Step 6.'],
        ['Return inspections waiting', $returnQueue, 'return', 'Open Return Inspection', 'Trips appear here after the dispatcher marks them Delivered.'],
    ] as [$queueTitle, $queueTrips, $queueView, $queueAction, $queueHint]): ?>
    <div class="col-lg-6">
      <div class="mnt-table-wrap h-100">
        <h2 class="h6 px-3 pt-3"><?= htmlspecialchars($queueTitle) ?> <span class="badge text-bg-secondary"><?= count($queueTrips) ?></span></h2>
        <?php if (!$queueTrips): ?>
        <p class="small text-muted px-3 pb-3 mb-0">Nothing is waiting. <?= htmlspecialchars($queueHint) ?></p>
        <?php else: ?>
        <table class="table mnt-table mb-0">
          <thead><tr><th>Trip</th><th>Truck</th><th class="text-end">Action</th></tr></thead>
          <tbody>
          <?php foreach ($queueTrips as $queueTrip): ?>
            <tr>
              <td><?= htmlspecialchars($queueTrip['trip_number']) ?></td>
              <td><?= htmlspecialchars($queueTrip['plate_number']) ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= APP_BASE ?>/pages/maintenance.php?view=<?= $queueView ?>&amp;trip_id=<?= (int)$queueTrip['trip_id'] ?><?= $queueView === 'return' ? '&amp;inspection_stage=Return#inspectionModal' : '' ?>"><?= htmlspecialchars($queueAction) ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="small text-muted">Maintenance records, schedules, work orders and inspection history are under <a href="<?= APP_BASE ?>/pages/maintenance_records.php">Records &amp; History</a>.</p>

  <?php elseif ($view === 'pre_departure' && !$requestedTripId): ?>
  <!-- ── Pre-Departure Inspection: choose a trip ──────────────────────────── -->
  <div class="mnt-table-wrap mb-4">
    <?php if (!$departureQueue): ?>
    <div class="mnt-empty">
      <i class="bi bi-truck-front mnt-empty-icon"></i>
      <p>No trip is waiting for pre-departure checks. Trips appear here after the dispatcher records Step 6.</p>
    </div>
    <?php else: ?>
    <table class="table mnt-table mb-0">
      <thead><tr><th>Trip</th><th>Truck</th><th class="text-end">Action</th></tr></thead>
      <tbody>
      <?php foreach ($departureQueue as $queueTrip): ?>
        <tr>
          <td><?= htmlspecialchars($queueTrip['trip_number']) ?></td>
          <td><?= htmlspecialchars($queueTrip['plate_number']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= APP_BASE ?>/pages/maintenance.php?view=pre_departure&amp;trip_id=<?= (int)$queueTrip['trip_id'] ?>">Open Pre-Departure Inspection</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php elseif ($view === 'pre_departure' && !$preDeparture): ?>
  <div class="alert alert-warning">Trip not found. <a href="<?= APP_BASE ?>/pages/maintenance.php?view=pre_departure">Choose a trip</a>.</div>

  <?php elseif ($view === 'pre_departure'): ?>
  <?php
    $pdTrip = $preDeparture['trip'];
    $pdTruck = $preDeparture['truck'];
    $checklistBadge = $sectionBadge($preDeparture['checklist']);
    $departureBadge = $sectionBadge($preDeparture['departure']);
    $showChecklistForm = $canEnterInspections && $preDeparture['ready'] && !$preDeparture['checklist']['passed'];
    $showDepartureForm = $canEnterInspections && $preDeparture['ready'] && !$preDeparture['departure']['passed'];
  ?>
  <!-- ── Pre-Departure Inspection of one trip ─────────────────────────────── -->
  <div id="preDepartureInspection"
       data-initial-section="<?= $preDeparture['checklist']['passed'] && !$preDeparture['departure']['passed'] ? 'departure' : 'checklist' ?>">
    <div class="mnt-table-wrap p-3 mb-3">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div>
          <strong><?= htmlspecialchars($pdTrip['trip_number']) ?></strong>
          · <?= htmlspecialchars($pdTrip['plate_number']) ?> (<?= htmlspecialchars($pdTrip['truck_type']) ?>)
          · <?= htmlspecialchars($pdTrip['status']) ?>
          <div class="small text-muted">
            Driver: <?= htmlspecialchars($pdTrip['driver_name'] ?? '—') ?>
            · <?= htmlspecialchars($pdTrip['origin'] . ' → ' . $pdTrip['destination']) ?>
            · Departs <?= $pdTrip['scheduled_departure'] ? date('M j, Y g:i A', strtotime($pdTrip['scheduled_departure'])) : '—' ?>
          </div>
          <div class="small mt-1">
            Departure checks: <strong><?= (int)$preDeparture['summary']['covered'] ?>/<?= (int)$preDeparture['summary']['required'] ?> required items passed</strong>.
            Both sections must pass before the trip is Ready for Dispatch.
          </div>
        </div>
        <div class="d-flex gap-2 align-items-start">
          <a class="btn btn-sm btn-outline-secondary" href="<?= APP_BASE ?>/pages/maintenance.php?view=overview"><i class="bi bi-arrow-left me-1"></i>Work to Address</a>
          <?php if (currentUserHasAnyPermission(['trips.view'])): ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= APP_BASE ?>/pages/trip_workflow.php?trip_id=<?= (int)$pdTrip['trip_id'] ?>">Trip Workflow</a>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!$preDeparture['ready']): ?>
      <div class="alert alert-info mt-3 mb-0">
        This trip is not waiting for departure checks (status <?= htmlspecialchars($pdTrip['status']) ?>, step <?= (int)$preDeparture['step'] ?>).
        Checks open after the dispatcher records Step 6 while the trip is Assigned.
      </div>
      <?php elseif (!$canEnterInspections): ?>
      <div class="alert alert-info mt-3 mb-0">Maintenance personnel complete these checks. You can view their status here.</div>
      <?php endif; ?>
    </div>

    <div class="btn-group btn-group-sm mb-3" role="group" aria-label="Pre-Departure Inspection sections">
      <button type="button" class="btn btn-outline-primary" data-pre-departure-switch="checklist" aria-pressed="false">
        Pre-Trip Checklist <span class="badge <?= $checklistBadge[1] ?> ms-1" data-section-badge="checklist"><?= htmlspecialchars($checklistBadge[0]) ?></span>
      </button>
      <button type="button" class="btn btn-outline-primary" data-pre-departure-switch="departure" aria-pressed="false">
        Departure Inspection <span class="badge <?= $departureBadge[1] ?> ms-1" data-section-badge="departure"><?= htmlspecialchars($departureBadge[0]) ?></span>
      </button>
    </div>

    <!-- Section 1: Pre-Trip Checklist (submit_checklist; its own 5-minute timer) -->
    <section class="mnt-table-wrap p-3 mb-4" id="checklistSection" data-pre-departure-section="checklist"
             data-min-duration-seconds="<?= PRE_TRIP_CHECKLIST_MIN_DURATION_SECONDS ?>" aria-labelledby="checklistSectionTitle">
      <h2 class="h6" id="checklistSectionTitle"><i class="bi bi-clipboard-check me-1"></i>Pre-Trip Checklist</h2>
      <?php if ($preDeparture['checklist']['passed']): ?>
      <div class="alert alert-success mb-0">Completed: a passed Pre-Trip Checklist is recorded for this trip.</div>
      <?php elseif (!$showChecklistForm): ?>
      <p class="small text-muted mb-0"><?= $preDeparture['checklist']['failed'] > 0 ? 'A failed checklist is recorded; it must be submitted again and pass.' : 'No checklist has been submitted yet.' ?></p>
      <?php else: ?>
      <?php if ($preDeparture['checklist']['failed'] > 0): ?>
      <div class="alert alert-warning py-2">A failed checklist is recorded for this trip. Correct the items and submit it again.</div>
      <?php endif; ?>
      <div id="checklistFormAlert" class="alert d-none" role="alert"></div>
      <div id="checklistTimerStatus" class="small text-muted mb-3" role="status" aria-live="polite">The 5-minute minimum starts when this section is opened.</div>
      <!-- The checklist is tied to this trip's approved dispatch. -->
      <select class="d-none" id="clDispatchId" aria-hidden="true" tabindex="-1">
        <option value="<?= (int)$pdTrip['dispatch_id'] ?>" selected><?= htmlspecialchars($pdTrip['trip_number']) ?></option>
      </select>
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
        <textarea class="form-control mnt-input" id="clNotes" rows="2" placeholder="Any issues found or remarks…"></textarea>
      </div>
      <div class="mt-3 text-end">
        <button type="button" class="btn btn-mnt-secondary" id="submitChecklistBtn" disabled>
          <span id="clBtnText">Submit Checklist</span>
          <span id="clBtnSpinner" class="spinner-border spinner-border-sm ms-1 d-none"></span>
        </button>
      </div>
      <?php endif; ?>
    </section>

    <!-- Section 2: Departure Inspection (save_inspection, stage Departure; its own 5-minute timer) -->
    <section class="mnt-table-wrap p-3 mb-4 d-none" id="inspectionSection" data-pre-departure-section="departure"
             data-min-duration-seconds="<?= DEPARTURE_INSPECTION_MIN_DURATION_SECONDS ?>" aria-labelledby="departureSectionTitle">
      <h2 class="h6" id="departureSectionTitle"><i class="bi bi-bounding-box-circles me-1"></i>Departure Inspection</h2>
      <?php if ($preDeparture['departure']['passed']): ?>
      <div class="alert alert-success mb-0">Completed: a passed departure inspection is recorded for this trip.</div>
      <?php elseif (!$showDepartureForm): ?>
      <p class="small text-muted mb-0">No departure inspection has been submitted yet.</p>
      <?php else: ?>
      <div id="inspectionFormAlert" class="alert d-none" role="alert"></div>
      <div id="inspectionTimerStatus" class="small text-muted mb-3" role="status" aria-live="polite">The 5-minute minimum starts when this section is opened.</div>
      <!-- Fixed to this trip and its truck (same fields as the inspection form). -->
      <select class="d-none" id="inspectionTruck" aria-hidden="true" tabindex="-1">
        <option value="<?= (int)$pdTruck['truck_id'] ?>" selected
                data-body="<?= htmlspecialchars($pdTruck['body_type']) ?>"
                data-image-front="<?= htmlspecialchars($pdTruck['image_front_path'] ? APP_BASE . '/' . ltrim($pdTruck['image_front_path'], '/') : '', ENT_QUOTES) ?>"
                data-image-side="<?= htmlspecialchars($pdTruck['image_side_path'] ? APP_BASE . '/' . ltrim($pdTruck['image_side_path'], '/') : '', ENT_QUOTES) ?>"
                data-image-rear="<?= htmlspecialchars($pdTruck['image_rear_path'] ? APP_BASE . '/' . ltrim($pdTruck['image_rear_path'], '/') : '', ENT_QUOTES) ?>"
                data-image-top="<?= htmlspecialchars($pdTruck['image_top_path'] ? APP_BASE . '/' . ltrim($pdTruck['image_top_path'], '/') : '', ENT_QUOTES) ?>"><?= htmlspecialchars($pdTruck['plate_number']) ?></option>
      </select>
      <select class="d-none" id="inspectionStage" aria-hidden="true" tabindex="-1">
        <option value="Departure" selected>Trip Departure Inspection</option>
      </select>
      <select class="d-none" id="inspectionTrip" aria-hidden="true" tabindex="-1">
        <option value="">—</option>
        <option value="<?= (int)$pdTrip['trip_id'] ?>" selected data-truck-id="<?= (int)$pdTruck['truck_id'] ?>" data-trip-status="Loading"><?= htmlspecialchars($pdTrip['trip_number']) ?></option>
      </select>
      <div class="row g-3">
        <div class="col-lg-4">
          <label class="form-label mnt-label" for="inspectionDate">Inspection date</label>
          <input type="date" id="inspectionDate" class="form-control mnt-input" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
          <label class="form-label mnt-label mt-3" for="inspectionNotes">Overall notes</label>
          <textarea id="inspectionNotes" class="form-control mnt-input" rows="4" placeholder="Optional findings or recommendations"></textarea>
          <label class="form-label mnt-label mt-3" for="inspectionPhotos">Inspection photos (JPG, PNG, or WebP; up to 8, max 10 MB each)</label>
          <input type="file" id="inspectionPhotos" class="form-control mnt-input" accept="image/jpeg,image/png,image/webp" multiple>
          <div class="small text-muted mt-3"><i class="bi bi-info-circle me-1"></i>Select a vehicle view, then choose the condition and add notes for each listed part. Every listed part must be Good for the departure inspection to pass.</div>
          <div class="mt-3">
            <label class="form-label mnt-label" for="customInspectionPart">Part not listed?</label>
            <div class="input-group">
              <input type="text" id="customInspectionPart" class="form-control mnt-input" maxlength="100" placeholder="Type the part name">
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
              <button type="button" class="btn btn-sm btn-outline-primary inspection-view active" data-view="Front" aria-pressed="true"><i class="bi bi-truck-front me-1"></i>Front View</button>
              <button type="button" class="btn btn-sm btn-outline-primary inspection-view" data-view="Side" aria-pressed="false"><i class="bi bi-truck me-1"></i>Side View</button>
              <button type="button" class="btn btn-sm btn-outline-primary inspection-view" data-view="Rear" aria-pressed="false"><i class="bi bi-truck-rear me-1"></i>Rear View</button>
              <button type="button" class="btn btn-sm btn-outline-primary inspection-view" data-view="Top" aria-pressed="false"><i class="bi bi-bounding-box me-1"></i>Top View</button>
            </div>
            <span class="small text-muted" id="inspectionBodyLabel">Closed Van</span>
          </div>
          <div class="inspection-diagram" id="inspectionDiagram" data-view="Front"
               data-image-map="<?= htmlspecialchars(json_encode($inspectionImages), ENT_QUOTES, 'UTF-8') ?>"></div>
          <div id="inspectionParts" class="inspection-parts mt-3"></div>
        </div>
      </div>
      <div class="mt-3 text-end">
        <button type="button" class="btn btn-mnt-primary" id="submitInspectionBtn" disabled>Save Departure Inspection</button>
      </div>
      <?php endif; ?>
    </section>
  </div>

  <?php elseif ($view === 'return'): ?>
  <!-- ── Return Inspection: trips back from delivery ──────────────────────── -->
  <div class="mnt-table-wrap mb-4">
    <?php if (!$returnQueue): ?>
    <div class="mnt-empty">
      <i class="bi bi-arrow-return-left mnt-empty-icon"></i>
      <p>No trip is waiting for a return inspection. Trips appear here after the dispatcher marks them Delivered.</p>
    </div>
    <?php else: ?>
    <table class="table mnt-table mb-0">
      <thead><tr><th>Trip</th><th>Truck</th><th class="text-end">Action</th></tr></thead>
      <tbody>
      <?php foreach ($returnQueue as $queueTrip): ?>
        <tr>
          <td><?= htmlspecialchars($queueTrip['trip_number']) ?></td>
          <td><?= htmlspecialchars($queueTrip['plate_number']) ?></td>
          <td class="text-end">
            <?php if ($canEnterInspections): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= APP_BASE ?>/pages/maintenance.php?view=return&amp;trip_id=<?= (int)$queueTrip['trip_id'] ?>&amp;inspection_stage=Return#inspectionModal">Start Return Inspection</a>
            <?php else: ?>
            <span class="small text-muted">Maintenance completes this inspection.</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($isRecordsPage): ?>
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
                <?php if (in_array($workOrder['status'], ['Open', 'In Progress', 'Waiting for Parts'], true)): ?>
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
                <?php foreach ($inspectionPhotoDocuments[(int)$inspection['inspection_id']] ?? [] as $photoIndex => $photoDocumentId): ?>
                <a class="btn btn-sm btn-outline-secondary ms-1"
                   href="<?= APP_BASE ?>/ajax/document_download.php?id=<?= (int)$photoDocumentId ?>"
                   title="Download inspection photo <?= $photoIndex + 1 ?>">
                  <i class="bi bi-image" aria-hidden="true"></i> Photo <?= $photoIndex + 1 ?>
                </a>
                <?php endforeach; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /tab-content -->
  <?php endif; ?>
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
              <?php foreach (array_filter($inspectionRows, static fn(array $row): bool => (int)$row["inspection_id"] > 0) as $inspection): ?>
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