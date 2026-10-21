<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';

requireLogin();

$roleKey = currentRoleKey();
$isAdmin = isAdmin();
$isOpsHead = $roleKey === 'operations_head';
// Admin and Operations Head see every dispatcher; dispatchers see only themselves.
$seesAll = $isAdmin || $isOpsHead;
if (!$isOpsHead) {
    requirePermission('dispatch.reports.view');
}
$isDispatcher = in_array($roleKey, ['car_carrier_dispatcher', 'container_wing_van_dispatcher'], true);
if (!$seesAll && !$isDispatcher) {
    http_response_code(403);
    include __DIR__ . '/403.php';
    exit;
}

$periods = [
    'today' => ['label' => 'Daily (today)', 'start' => 'today', 'end' => 'tomorrow'],
    'week' => ['label' => 'Weekly (this week)', 'start' => 'monday this week', 'end' => 'monday next week'],
    'month' => ['label' => 'Monthly (this month)', 'start' => 'first day of this month', 'end' => 'first day of next month'],
    '1m' => ['label' => 'Last 1 month', 'months' => 1],
    '3m' => ['label' => 'Last 3 months', 'months' => 3],
    '6m' => ['label' => 'Last 6 months', 'months' => 6],
    '1y' => ['label' => 'Last 12 months', 'months' => 12],
    'all' => ['label' => 'All recorded time', 'months' => null],
];
$period = $_GET['period'] ?? '6m';
if (!is_string($period) || !isset($periods[$period])) {
    $period = '6m';
}
if (isset($periods[$period]['start'])) {
    $rangeStart = (new DateTime('today'))->modify($periods[$period]['start']);
    $rangeEnd = (new DateTime('today'))->modify($periods[$period]['end']);
} else {
    $rangeStart = $periods[$period]['months'] === null
        ? null
        : (new DateTime('today'))->modify('-' . $periods[$period]['months'] . ' months');
    $rangeEnd = new DateTime('tomorrow');
}
$rangeStartSql = $rangeStart?->format('Y-m-d 00:00:00');
$rangeEndSql = $rangeEnd->format('Y-m-d 00:00:00');

// Dispatcher performance: bookings falling inside each active dispatcher
// assignment (shift, truck types, assignment dates) versus how many of them
// that dispatcher has already encoded as trips.
$perfPeriodSql = $rangeStartSql !== null ? ' AND b.scheduled_departure >= :perfStart AND b.scheduled_departure < :perfEnd' : '';
$perfStmt = getDBConnection()->prepare(
    "SELECT u.user_id,
            COALESCE(NULLIF(TRIM(e.full_name), ''), u.username) AS dispatcher_name,
            da.dispatcher_role_type, da.shift,
            COUNT(DISTINCT b.booking_id) AS assigned_count,
            COUNT(DISTINCT CASE WHEN dr.submitted_by = u.user_id THEN b.booking_id END) AS encoded_count,
            COUNT(DISTINCT CASE WHEN t.trip_id IS NULL AND b.status = 'Pending' THEN b.booking_id END) AS left_count,
            COUNT(DISTINCT CASE WHEN dr.submitted_by = u.user_id AND t.status = 'Completed' THEN t.trip_id END) AS completed_count
     FROM dispatcher_assignments da
     JOIN users u ON u.employee_id = da.employee_id AND u.is_active = 1
     JOIN employees e ON e.employee_id = da.employee_id
     LEFT JOIN client_bookings b
            ON b.status <> 'Cancelled'
           AND b.shift = da.shift
           AND b.scheduled_departure IS NOT NULL
           AND DATE(b.scheduled_departure) >= da.start_date
           AND (da.end_date IS NULL OR DATE(b.scheduled_departure) <= da.end_date)
           AND ((da.dispatcher_role_type = 'CAR_CARRIER' AND b.truck_type = 'Car Carrier')
             OR (da.dispatcher_role_type = 'CONTAINER_WING_VAN' AND b.truck_type IN ('Container', 'Wing Van')))
           $perfPeriodSql
     LEFT JOIN trips t ON t.booking_id = b.booking_id
     LEFT JOIN dispatch_requests dr ON dr.trip_id = t.trip_id
     WHERE da.is_active = 1
     GROUP BY u.user_id, dispatcher_name, da.dispatcher_role_type, da.shift
     ORDER BY dispatcher_name, da.shift"
);
$perfStmt->execute($rangeStartSql !== null ? [':perfStart' => $rangeStartSql, ':perfEnd' => $rangeEndSql] : []);
$performanceAll = $perfStmt->fetchAll(PDO::FETCH_ASSOC);

$dispatcherOptions = [];
foreach ($performanceAll as $row) {
    $dispatcherOptions[(int)$row['user_id']] = $row['dispatcher_name'];
}
$selectedDispatcher = 0;
if ($seesAll) {
    $requested = $_GET['dispatcher'] ?? '';
    if (is_string($requested) && ctype_digit($requested) && isset($dispatcherOptions[(int)$requested])) {
        $selectedDispatcher = (int)$requested;
    }
}
$performanceRows = array_values(array_filter(
    $performanceAll,
    static fn(array $row): bool => $seesAll
        ? ($selectedDispatcher === 0 || (int)$row['user_id'] === $selectedDispatcher)
        : (int)$row['user_id'] === currentUserId()
));

$scope = $seesAll ? ['status' => 'none'] : dispatcherScopeResult();
$scopeAvailable = $scope['status'] !== 'denied';
$tripFilters = [];
$requestFilters = [];
$tripParams = [];
$requestParams = [];

if ($isDispatcher && $scopeAvailable) {
    $tripFilters[] = 'dr.submitted_by = :viewerId';
    $requestFilters[] = 'dr.submitted_by = :viewerId';
    $tripParams[':viewerId'] = currentUserId();
    $requestParams[':viewerId'] = currentUserId();
    if ($scope['status'] === 'scoped') {
        $tripFilters[] = 't.shift = :scopeShift';
        $requestFilters[] = 't.shift = :scopeShift';
        $tripParams[':scopeShift'] = $scope['shift'];
        $requestParams[':scopeShift'] = $scope['shift'];
        $typePlaceholders = [];
        foreach (array_values($scope['truck_types']) as $index => $truckType) {
            $key = ':truckType' . $index;
            $typePlaceholders[] = $key;
            $tripParams[$key] = $truckType;
            $requestParams[$key] = $truckType;
        }
        if ($typePlaceholders) {
            $tripFilters[] = 't.trip_type IN (' . implode(', ', $typePlaceholders) . ')';
            $requestFilters[] = 't.trip_type IN (' . implode(', ', $typePlaceholders) . ')';
        } else {
            $scopeAvailable = false;
        }
    }
}
if ($seesAll && $selectedDispatcher > 0) {
    $tripFilters[] = 'dr.submitted_by = :selectedDispatcher';
    $requestFilters[] = 'dr.submitted_by = :selectedDispatcher';
    $tripParams[':selectedDispatcher'] = $selectedDispatcher;
    $requestParams[':selectedDispatcher'] = $selectedDispatcher;
}
if ($rangeStartSql !== null) {
    $tripFilters[] = 't.scheduled_departure >= :tripRangeStart AND t.scheduled_departure < :rangeEnd';
    $tripParams[':tripRangeStart'] = $rangeStartSql;
    $tripParams[':rangeEnd'] = $rangeEndSql;
    $requestFilters[] = 'dr.submitted_at >= :requestRangeStart AND dr.submitted_at < :rangeEnd';
    $requestParams[':requestRangeStart'] = $rangeStartSql;
    $requestParams[':rangeEnd'] = $rangeEndSql;
}
$tripWhere = $tripFilters ? ' WHERE ' . implode(' AND ', $tripFilters) : '';
$requestWhere = $requestFilters ? ' WHERE ' . implode(' AND ', $requestFilters) : '';

$tripStatuses = [];
$dispatchStatuses = [];
$tripRows = [];
$requestRows = [];
if ($scopeAvailable) {
    $pdo = getDBConnection();
    $tripSummary = $pdo->prepare(
        "SELECT t.status, COUNT(*) AS trip_count
         FROM trips t
         JOIN dispatch_requests dr ON dr.trip_id = t.trip_id
         $tripWhere
         GROUP BY t.status
         ORDER BY FIELD(t.status, 'Pending Approval', 'Approved', 'Assigned', 'Ready for Dispatch',
                        'Dispatched', 'In Progress', 'Delivered', 'Returned', 'Completed', 'For Review', 'Cancelled')"
    );
    $tripSummary->execute($tripParams);
    $tripStatuses = $tripSummary->fetchAll(PDO::FETCH_ASSOC);

    $dispatchSummary = $pdo->prepare(
        "SELECT dr.status, COUNT(*) AS request_count
         FROM dispatch_requests dr
         JOIN trips t ON t.trip_id = dr.trip_id
         $requestWhere
         GROUP BY dr.status
         ORDER BY FIELD(dr.status, 'Pending', 'Approved', 'Rejected', 'Cancelled')"
    );
    $dispatchSummary->execute($requestParams);
    $dispatchStatuses = $dispatchSummary->fetchAll(PDO::FETCH_ASSOC);

    $tripList = $pdo->prepare(
        "SELECT t.trip_number, t.trip_type, t.shift, t.scheduled_departure, t.status,
                c.client_name, COALESCE(r.route_name, CONCAT(t.origin, ' to ', t.destination)) AS route_name
         FROM trips t
         JOIN dispatch_requests dr ON dr.trip_id = t.trip_id
         JOIN clients c ON c.client_id = t.client_id
         LEFT JOIN routes r ON r.route_id = t.route_id
         $tripWhere
         ORDER BY t.scheduled_departure DESC, t.trip_id DESC
         LIMIT 200"
    );
    $tripList->execute($tripParams);
    $tripRows = $tripList->fetchAll(PDO::FETCH_ASSOC);

    $requestList = $pdo->prepare(
        "SELECT dr.dispatch_request_id, dr.submitted_at, dr.status, dr.reviewed_at,
                t.trip_number, t.trip_type, t.shift, t.scheduled_departure,
                c.client_name, COALESCE(r.route_name, CONCAT(t.origin, ' to ', t.destination)) AS route_name
         FROM dispatch_requests dr
         JOIN trips t ON t.trip_id = dr.trip_id
         JOIN clients c ON c.client_id = t.client_id
         LEFT JOIN routes r ON r.route_id = t.route_id
         $requestWhere
         ORDER BY dr.submitted_at DESC, dr.dispatch_request_id DESC
         LIMIT 200"
    );
    $requestList->execute($requestParams);
    $requestRows = $requestList->fetchAll(PDO::FETCH_ASSOC);
}

$totalTrips = array_sum(array_map(static fn(array $row): int => (int)$row['trip_count'], $tripStatuses));
$totalRequests = array_sum(array_map(static fn(array $row): int => (int)$row['request_count'], $dispatchStatuses));
layoutHead('My Dispatch Reports');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
  <div>
    <h1 class="page-title"><?= $seesAll ? 'Dispatcher Operational Reports' : 'My Dispatch Reports' ?></h1>
    <p class="page-subtitle">Operational trip and dispatch-request activity only. No billing, payroll, expense, or payment values are included.</p>
  </div>
  <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
    <?php if ($seesAll): ?>
    <label class="form-label mb-0" for="dispatchReportDispatcher">Dispatcher</label>
    <select class="form-select" name="dispatcher" id="dispatchReportDispatcher" data-soft-submit>
      <option value="">All dispatchers</option>
      <?php foreach ($dispatcherOptions as $userId => $name): ?>
      <option value="<?= $userId ?>" <?= $selectedDispatcher === $userId ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <label class="form-label mb-0" for="dispatchReportPeriod">Period</label>
    <select class="form-select" name="period" id="dispatchReportPeriod" data-soft-submit>
      <?php foreach ($periods as $key => $option): ?>
      <option value="<?= htmlspecialchars($key) ?>" <?= $period === $key ? 'selected' : '' ?>>
        <?= htmlspecialchars($option['label']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$seesAll && !$scopeAvailable): ?>
<div class="alert alert-warning">
  Your dispatcher assignment could not be verified, so no report data is shown. Ask an administrator to check your active dispatcher assignment.
</div>
<?php else: ?>
<div class="alert alert-info">
  Trips are grouped by scheduled departure date; requests are grouped by submission date. Dispatcher results include only your submitted requests and trips matching your current assigned shift and truck types. Historical records are therefore scoped by the current assignment.
</div>
<div class="card mb-4" id="dispatcherPerformance">
  <div class="card-header-custom">
    <h2 class="card-title-custom">Dispatcher performance</h2>
    <span class="text-muted small">Assigned bookings versus bookings encoded as trips</span>
  </div>
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Dispatcher</th><th>Assignment</th><th class="text-end">Assigned</th><th class="text-end">Encoded</th><th class="text-end">Left to encode</th><th class="text-end">Trips completed</th><th style="min-width:160px;">Progress</th></tr></thead>
    <tbody>
    <?php if (!$performanceRows): ?><tr><td colspan="7" class="text-muted p-3">No active dispatcher assignments found.</td></tr><?php endif; ?>
    <?php foreach ($performanceRows as $row):
        $assigned = (int)$row['assigned_count'];
        $encoded = (int)$row['encoded_count'];
        $percent = $assigned > 0 ? (int)round($encoded / $assigned * 100) : 0;
    ?>
    <tr>
      <td><?= htmlspecialchars($row['dispatcher_name']) ?></td>
      <td><?= htmlspecialchars(($row['dispatcher_role_type'] === 'CAR_CARRIER' ? 'Car Carrier' : 'Container / Wing Van') . ' · ' . $row['shift']) ?></td>
      <td class="text-end"><?= $assigned ?></td>
      <td class="text-end"><?= $encoded ?></td>
      <td class="text-end"><?= (int)$row['left_count'] ?></td>
      <td class="text-end"><?= (int)$row['completed_count'] ?></td>
      <td>
        <div class="progress" role="progressbar" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100" style="height:8px;"><div class="progress-bar" style="width: <?= $percent ?>%"></div></div>
        <div class="small text-muted mt-1"><?= $percent ?>% encoded</div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6"><div class="card h-100"><div class="card-body-custom"><div class="text-muted small">Trips in period</div><div class="fs-3 fw-semibold"><?= $totalTrips ?></div></div></div></div>
  <div class="col-md-6"><div class="card h-100"><div class="card-body-custom"><div class="text-muted small">Dispatch requests in period</div><div class="fs-3 fw-semibold"><?= $totalRequests ?></div></div></div></div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header-custom"><h2 class="card-title-custom">Current trip status</h2></div>
      <div class="table-responsive"><table class="table mb-0">
        <thead><tr><th>Status</th><th class="text-end">Trips</th></tr></thead>
        <tbody>
        <?php if (!$tripStatuses): ?><tr><td colspan="2" class="text-muted">No trips in this period.</td></tr><?php endif; ?>
        <?php foreach ($tripStatuses as $row): ?>
        <tr><td><?= htmlspecialchars($row['status']) ?></td><td class="text-end"><?= (int)$row['trip_count'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header-custom"><h2 class="card-title-custom">Dispatch request outcomes</h2></div>
      <div class="table-responsive"><table class="table mb-0">
        <thead><tr><th>Status</th><th class="text-end">Requests</th></tr></thead>
        <tbody>
        <?php if (!$dispatchStatuses): ?><tr><td colspan="2" class="text-muted">No requests in this period.</td></tr><?php endif; ?>
        <?php foreach ($dispatchStatuses as $row): ?>
        <tr><td><?= htmlspecialchars($row['status']) ?></td><td class="text-end"><?= (int)$row['request_count'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Trips</h2><span class="text-muted small">Showing up to 200 rows</span></div>
  <div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th>Trip</th><th>Scheduled</th><th>Truck type / shift</th><th>Client</th><th>Route</th><th>Current status</th></tr></thead>
    <tbody>
    <?php if (!$tripRows): ?><tr><td colspan="6" class="text-muted p-3">No trips in this period.</td></tr><?php endif; ?>
    <?php foreach ($tripRows as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['trip_number']) ?></td>
      <td><?= date('M d, Y H:i', strtotime($row['scheduled_departure'])) ?></td>
      <td><?= htmlspecialchars($row['trip_type'] . ' / ' . ($row['shift'] ?? '—')) ?></td>
      <td><?= htmlspecialchars($row['client_name']) ?></td>
      <td><?= htmlspecialchars($row['route_name']) ?></td>
      <td><?= htmlspecialchars($row['status']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Dispatch requests</h2><span class="text-muted small">Showing up to 200 rows</span></div>
  <div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th>Request submitted</th><th>Trip</th><th>Scheduled</th><th>Truck type / shift</th><th>Client</th><th>Route</th><th>Status</th><th>Reviewed</th></tr></thead>
    <tbody>
    <?php if (!$requestRows): ?><tr><td colspan="8" class="text-muted p-3">No requests in this period.</td></tr><?php endif; ?>
    <?php foreach ($requestRows as $row): ?>
    <tr>
      <td><?= date('M d, Y H:i', strtotime($row['submitted_at'])) ?></td>
      <td><?= htmlspecialchars($row['trip_number']) ?></td>
      <td><?= date('M d, Y H:i', strtotime($row['scheduled_departure'])) ?></td>
      <td><?= htmlspecialchars($row['trip_type'] . ' / ' . ($row['shift'] ?? '—')) ?></td>
      <td><?= htmlspecialchars($row['client_name']) ?></td>
      <td><?= htmlspecialchars($row['route_name']) ?></td>
      <td><?= htmlspecialchars($row['status']) ?></td>
      <td><?= $row['reviewed_at'] ? date('M d, Y H:i', strtotime($row['reviewed_at'])) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php layoutFoot(); ?>
