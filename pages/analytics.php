<?php
// ============================================================
// pages/analytics.php
// Department analytics (Operations, Accounting, Maintenance). Each department
// shows key indicators first, then supporting charts, then detailed tables.
// Every chart carries its formula, source fields and date basis (see
// docs/analytics_metric_traceability.md). Nothing here invents a definition the
// client has not confirmed; unresolved metrics are listed as not shown.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';
require_once __DIR__ . '/../includes/finance_records.php';
require_once __DIR__ . '/../includes/analytics_period.php';

requireAnyPermission([
    'reports.view',
    'operations.reports.view',
    'maintenance.reports.view',
    'billing.view',
]);

$pdo = getDBConnection();
$isHead = isAdmin();
$roleKey = currentRoleKey();
$isDispatcher = in_array($roleKey, ['car_carrier_dispatcher', 'container_wing_van_dispatcher'], true);
$canOperations = $isHead || currentUserHasAnyPermission(['operations.reports.view'])
    || (currentUserHasAnyPermission(['reports.view']) && in_array($roleKey, [
        'operations_head', 'car_carrier_dispatcher', 'container_wing_van_dispatcher',
    ], true));
$canFleet = $isHead || ($canOperations && currentUserHasAnyPermission(['fleet.view']));
$canMaintenance = $isHead || currentUserHasAnyPermission(['maintenance.reports.view']);
$canFinancials = $isHead || currentUserHasAnyPermission(['billing.view']);

$periods = [
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
$periodLabel = $periods[$period]['label'];
$rangeEnd = new DateTime('tomorrow');
$rangeStart = $periods[$period]['months'] === null
    ? new DateTime('1000-01-01')
    : (new DateTime('today'))->modify('-' . $periods[$period]['months'] . ' months');
$rangeStartSql = $rangeStart->format('Y-m-d 00:00:00');
$rangeEndSql = $rangeEnd->format('Y-m-d 00:00:00');
$rangeDays = (int)$rangeStart->diff(new DateTime('today'))->days;

$granularities = ['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'];
$granularity = $_GET['granularity'] ?? 'month';
if (!is_string($granularity) || !isset($granularities[$granularity])) {
    $granularity = 'month';
}
if ($granularity === 'day' && $rangeDays > 92) {
    $granularity = 'week';
}
$rangeParams = [':rangeStart' => $rangeStartSql, ':rangeEnd' => $rangeEndSql];

// ── Operations scope (dispatchers see only their own scoped trips) ───────────
$tripWhere = [];
$tripParams = $rangeParams;
$scope = [];
if ($isDispatcher) {
    $scope = dispatcherScope();
    $tripWhere[] = "t.trip_id IN (
        SELECT dr.trip_id FROM dispatch_requests dr WHERE dr.submitted_by = :viewerId
    )";
    $tripParams[':viewerId'] = currentUserId();
    if (!empty($scope['truck_types'])) {
        $typeParams = [];
        foreach (array_values($scope['truck_types']) as $index => $truckType) {
            $key = ':truckType' . $index;
            $typeParams[] = $key;
            $tripParams[$key] = $truckType;
        }
        $tripWhere[] = 'tr.truck_type IN (' . implode(', ', $typeParams) . ')';
    }
    if (!empty($scope['shift']) || !empty($scope['denied'])) {
        $tripWhere[] = 't.shift = :shift';
        $tripParams[':shift'] = (string)($scope['shift'] ?? '');
    }
}
$tripScopeSql = $tripWhere ? ' AND ' . implode(' AND ', $tripWhere) : '';

// ── Display helpers (closures: this data layer is evaluated by static tests) ─
$peso = static fn($v): string => '₱' . number_format((float)$v, 2);
$sumField = static function (array $rows, string $field): float {
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float)($row[$field] ?? 0);
    }
    return $total;
};
$sortDesc = static function (array $rows, string $field): array {
    usort($rows, static fn($a, $b) => (float)$b[$field] <=> (float)$a[$field]);
    return $rows;
};
$orderBy = static function (array $rows, string $field, array $order): array {
    usort($rows, static function ($a, $b) use ($field, $order) {
        $pa = array_search((string)$a[$field], $order, true);
        $pb = array_search((string)$b[$field], $order, true);
        return ($pa === false ? 99 : $pa) <=> ($pb === false ? 99 : $pb);
    });
    return $rows;
};
$colorOf = static fn(array $map, $key): string => $map[(string)$key] ?? '#8a948c';
$countOf = static function (array $rows, string $field, string $value, string $count): int {
    foreach ($rows as $row) {
        if ((string)$row[$field] === $value) {
            return (int)$row[$count];
        }
    }
    return 0;
};
// Continuous bucket keys so quiet periods plot as 0 instead of a gap.
$buckets = static function (array ...$rowSets) use ($period, $rangeStart, $granularity): array {
    $present = [];
    foreach ($rowSets as $rows) {
        foreach ($rows as $row) {
            $present[] = (string)$row['bucket'];
        }
    }
    if (!$present) {
        return ['keys' => [], 'labels' => []];
    }
    sort($present);
    $start = clone $rangeStart;
    if ($period === 'all') {
        $first = $present[0];
        $start = new DateTime(strlen($first) === 7 ? $first . '-01' : $first);
    }
    return buildBuckets($start, new DateTime('today'), $granularity);
};
$series = static function (array $keys, array $rows, string $field): array {
    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row['bucket']] = (float)$row[$field];
    }
    return array_map(static fn($key) => $map[$key] ?? 0, $keys);
};

$tripStatusOrder = ['Pending Approval', 'Approved', 'Assigned', 'Ready for Dispatch', 'Dispatched', 'In Progress', 'Delivered', 'Returned', 'Completed', 'For Review', 'Cancelled'];
$tripStatusColors = [
    'Pending Approval' => '#d99a00', 'Approved' => '#2f6fdd', 'Assigned' => '#7a55c9', 'Ready for Dispatch' => '#1aa5a0',
    'Dispatched' => '#2aa6d6', 'In Progress' => '#1d4fb8', 'Delivered' => '#2f9e5b', 'Returned' => '#e07b1f',
    'Completed' => '#17753f', 'For Review' => '#8a948c', 'Cancelled' => '#d6403f',
];
$fleetStatusOrder = ['Available', 'Assigned', 'On Trip', 'Under Maintenance', 'Unavailable'];
$fleetStatusColors = ['Available' => '#2f9e5b', 'Assigned' => '#7a55c9', 'On Trip' => '#2f6fdd', 'Under Maintenance' => '#d99a00', 'Unavailable' => '#d6403f'];
$billingStatusOrder = ['Issued', 'Partially Paid', 'Paid', 'Overdue'];
$billingStatusColors = ['Issued' => '#2f6fdd', 'Partially Paid' => '#d99a00', 'Paid' => '#2f9e5b', 'Overdue' => '#d6403f'];
$methodColors = ['Cash' => '#2f9e5b', 'Check' => '#2f6fdd', 'Bank Transfer' => '#7a55c9', 'Other' => '#8a948c'];
$maintStatusOrder = ['Reported', 'Scheduled', 'In Progress', 'Waiting for Parts', 'Completed', 'Cancelled'];
$maintStatusColors = ['Reported' => '#8a948c', 'Scheduled' => '#2f6fdd', 'In Progress' => '#1d4fb8', 'Waiting for Parts' => '#d99a00', 'Completed' => '#2f9e5b', 'Cancelled' => '#d6403f'];
$maintTypeColors = ['Preventive' => '#2f9e5b', 'Repair' => '#d6403f', 'Inspection' => '#2f6fdd', 'Other' => '#8a948c'];

$charts = [];
$sections = [];

// ════════════════════════════════════════════════════════════════════════════
// OPERATIONS
// ════════════════════════════════════════════════════════════════════════════
if ($canOperations) {
    $departureBucket = bucketExpr('t.departure_time', $granularity);
    // COUNT(DISTINCT trip_id): one trip is never counted twice by a join.
    $stmt = $pdo->prepare("
        SELECT $departureBucket AS bucket, COUNT(DISTINCT t.trip_id) AS trip_count
        FROM trips t
        LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
        WHERE t.departure_time IS NOT NULL
          AND t.departure_time >= :rangeStart AND t.departure_time < :rangeEnd
          $tripScopeSql
        GROUP BY bucket
        ORDER BY bucket
    ");
    $stmt->execute($tripParams);
    $departureRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(cl.client_name), ''), 'Unassigned') AS client_name,
               COUNT(DISTINCT t.trip_id) AS trip_count
        FROM trips t
        LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
        LEFT JOIN clients cl ON cl.client_id = t.client_id
        WHERE t.departure_time IS NOT NULL
          AND t.departure_time >= :rangeStart AND t.departure_time < :rangeEnd
          $tripScopeSql
        GROUP BY client_name
        ORDER BY client_name
    ");
    $stmt->execute($tripParams);
    $clientRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(tr.plate_number), ''), 'Unassigned') AS plate_number,
               COUNT(DISTINCT t.trip_id) AS trip_count
        FROM trips t
        LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
        WHERE t.departure_time IS NOT NULL
          AND t.departure_time >= :rangeStart AND t.departure_time < :rangeEnd
          $tripScopeSql
        GROUP BY plate_number
        ORDER BY plate_number
    ");
    $stmt->execute($tripParams);
    $truckRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT t.status, COUNT(DISTINCT t.trip_id) AS trip_count
        FROM trips t
        LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
        WHERE t.scheduled_departure >= :rangeStart AND t.scheduled_departure < :rangeEnd
          $tripScopeSql
        GROUP BY t.status
        ORDER BY t.status
    ");
    $stmt->execute($tripParams);
    $statusRows = $orderBy($stmt->fetchAll(PDO::FETCH_ASSOC), 'status', $tripStatusOrder);

    // Current status breakdown: every open (not Completed / Cancelled) trip,
    // whatever its scheduled date, plus trips that finished or were
    // cancelled with a scheduled departure in the period (the Trip Monitor
    // convention). The period KPIs above keep using $statusRows.
    $stmt = $pdo->prepare("
        SELECT t.status, COUNT(DISTINCT t.trip_id) AS trip_count
        FROM trips t
        LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
        WHERE (t.status NOT IN ('Completed', 'Cancelled')
               OR (t.scheduled_departure >= :rangeStart AND t.scheduled_departure < :rangeEnd))
          $tripScopeSql
        GROUP BY t.status
        ORDER BY t.status
    ");
    $stmt->execute($tripParams);
    $currentStatusRows = $orderBy($stmt->fetchAll(PDO::FETCH_ASSOC), 'status', $tripStatusOrder);

    $fleetRows = [];
    if ($canFleet) {
        $fleetSql = 'SELECT tr.status, COUNT(*) AS truck_count FROM trucks tr';
        $fleetParams = [];
        if ($isDispatcher && !empty($scope['truck_types'])) {
            $fleetKeys = [];
            foreach (array_values($scope['truck_types']) as $index => $truckType) {
                $key = ':fleetType' . $index;
                $fleetKeys[] = $key;
                $fleetParams[$key] = $truckType;
            }
            $fleetSql .= ' WHERE tr.truck_type IN (' . implode(', ', $fleetKeys) . ')';
        }
        $fleetSql .= ' GROUP BY tr.status ORDER BY tr.status';
        $stmt = $pdo->prepare($fleetSql);
        $stmt->execute($fleetParams);
        $fleetRows = $orderBy($stmt->fetchAll(PDO::FETCH_ASSOC), 'status', $fleetStatusOrder);
    }

    $tripsDeparted = (int)$sumField($departureRows, 'trip_count');
    $tripsScheduled = (int)$sumField($statusRows, 'trip_count');
    $pendingTrips = $countOf($statusRows, 'status', 'Pending Approval', 'trip_count');
    $cancelledTrips = $countOf($statusRows, 'status', 'Cancelled', 'trip_count');
    $completedTrips = $countOf($statusRows, 'status', 'Completed', 'trip_count');

    $kpis = [
        ['label' => 'Trips with recorded departure', 'value' => number_format($tripsDeparted), 'sub' => 'By actual departure time, ' . $periodLabel, 'tone' => 'primary', 'hero' => true],
        ['label' => 'Trips scheduled', 'value' => number_format($tripsScheduled), 'sub' => 'By scheduled departure; all current statuses', 'tone' => ''],
        ['label' => 'Pending approval', 'value' => number_format($pendingTrips), 'sub' => 'Needs a decision before dispatch', 'tone' => $pendingTrips > 0 ? 'warn' : 'good'],
        ['label' => 'Cancelled', 'value' => number_format($cancelledTrips), 'sub' => 'Kept separate from operating trips', 'tone' => $cancelledTrips > 0 ? 'danger' : 'good'],
        ['label' => 'Status “Completed”', 'value' => number_format($completedTrips), 'sub' => 'Stored status; units not verified', 'tone' => ''],
    ];
    if ($canFleet) {
        $kpis[] = ['label' => 'Trucks on trip', 'value' => number_format($countOf($fleetRows, 'status', 'On Trip', 'truck_count')), 'sub' => 'Current stored status', 'tone' => ''];
        $underMaint = $countOf($fleetRows, 'status', 'Under Maintenance', 'truck_count');
        $kpis[] = ['label' => 'Trucks under maintenance', 'value' => number_format($underMaint), 'sub' => 'Current stored status', 'tone' => $underMaint > 0 ? 'warn' : 'good'];
    }

    $trip = $buckets($departureRows);
    $charts['opsTripTrend'] = [
        'title' => 'Trips by actual departure', 'span' => 8, 'height' => 'tall', 'type' => 'bar', 'format' => 'count', 'unit' => 'trip',
        'note' => $granularities[$granularity] . ' buckets' . ($granularity === 'week' ? ' (weeks start Monday)' : '') . '. Not a waybill-submission count.',
        'labels' => $trip['labels'],
        'datasets' => [['label' => 'Trips', 'data' => $series($trip['keys'], $departureRows, 'trip_count'), 'color' => '#2f6fdd']],
        'method' => [
            'Formula' => 'COUNT(DISTINCT trip_id) per bucket',
            'Source' => 'trips.trip_id, trips.departure_time',
            'Date basis' => 'Recorded actual departure time (proposed basis; the client’s weekly basis is unconfirmed). Trips without a departure are not counted.',
            'Limits' => 'Dispatchers see only their own scoped trips. Not units, not waybills.',
        ],
    ];
    $charts['opsTripStatus'] = [
        'title' => 'Trips by current status', 'span' => 4, 'height' => 'tall', 'type' => 'doughnut', 'format' => 'count', 'unit' => 'trip',
        'note' => 'All open trips now, plus completed and cancelled trips scheduled in ' . strtolower($periodLabel) . '. Statuses are never merged.',
        'labels' => array_map(static fn($r) => (string)$r['status'], $currentStatusRows),
        'datasets' => [[
            'label' => 'Trips', 'data' => array_map(static fn($r) => (int)$r['trip_count'], $currentStatusRows),
            'colors' => array_map(static fn($r) => $colorOf($tripStatusColors, $r['status']), $currentStatusRows),
        ]],
        'method' => [
            'Formula' => 'COUNT(DISTINCT trip_id) grouped by trips.status',
            'Source' => 'trips.trip_id, trips.status',
            'Date basis' => 'Open trips (not Completed or Cancelled) regardless of date; Completed and Cancelled trips by scheduled departure in the period. The status is the current stored one.',
            'Limits' => 'Delivered / Returned / Completed does not prove all required units were delivered. Pending approval and cancelled are separate slices.',
        ],
    ];
    $topClients = array_slice(array_values(array_filter($sortDesc($clientRows, 'trip_count'), static fn($r) => (int)$r['trip_count'] > 0)), 0, 8);
    $charts['opsClients'] = [
        'title' => 'Top clients by trips', 'span' => 4, 'height' => 'medium', 'type' => 'hbar', 'format' => 'count', 'unit' => 'trip',
        'note' => 'Top 8 of ' . count($clientRows) . '. Full list under detailed records.',
        'labels' => array_map(static fn($r) => (string)$r['client_name'], $topClients),
        'datasets' => [['label' => 'Trips', 'data' => array_map(static fn($r) => (int)$r['trip_count'], $topClients), 'color' => '#1aa5a0']],
        'method' => [
            'Formula' => 'COUNT(DISTINCT trip_id) per client, highest first',
            'Source' => 'trips.client_id → clients.client_name',
            'Date basis' => 'Actual departure time in the period.',
            'Limits' => 'One client level only; whether it is the company or the end client is unconfirmed.',
        ],
    ];
    $topTrucks = array_slice(array_values(array_filter($sortDesc($truckRows, 'trip_count'), static fn($r) => (int)$r['trip_count'] > 0)), 0, 8);
    $charts['opsTrucks'] = [
        'title' => 'Top trucks by trips', 'span' => 4, 'height' => 'medium', 'type' => 'hbar', 'format' => 'count', 'unit' => 'trip',
        'note' => 'Top 8 of ' . count($truckRows) . '. Full list under detailed records.',
        'labels' => array_map(static fn($r) => (string)$r['plate_number'], $topTrucks),
        'datasets' => [['label' => 'Trips', 'data' => array_map(static fn($r) => (int)$r['trip_count'], $topTrucks), 'color' => '#7a55c9']],
        'method' => [
            'Formula' => 'COUNT(DISTINCT trip_id) per truck, highest first',
            'Source' => 'trips.truck_id → trucks.plate_number',
            'Date basis' => 'Actual departure time in the period.',
            'Limits' => 'A trip count only; not a utilization or performance score.',
        ],
    ];
    if ($canFleet) {
        $charts['opsFleet'] = [
            'title' => 'Fleet by current status', 'span' => 4, 'height' => 'medium', 'type' => 'doughnut', 'format' => 'count', 'unit' => 'truck',
            'note' => 'Snapshot of today; no reporting period applies.',
            'labels' => array_map(static fn($r) => (string)$r['status'], $fleetRows),
            'datasets' => [[
                'label' => 'Trucks', 'data' => array_map(static fn($r) => (int)$r['truck_count'], $fleetRows),
                'colors' => array_map(static fn($r) => $colorOf($fleetStatusColors, $r['status']), $fleetRows),
            ]],
            'method' => [
                'Formula' => 'COUNT(truck_id) grouped by trucks.status',
                'Source' => 'trucks.truck_id, trucks.status',
                'Date basis' => 'Current stored status.',
                'Limits' => 'Not utilization, truck-days or downtime; no client status codes (NT, DT) are interpreted.',
            ],
        ];
    }

    $sections['operations'] = [
        'title' => 'Operations', 'icon' => 'bi-truck',
        'blurb' => 'Trip volume, trip status and fleet position.',
        'kpis' => $kpis,
        'charts' => array_values(array_filter(['opsTripTrend', 'opsTripStatus', 'opsClients', 'opsTrucks', 'opsFleet'], static fn($id) => isset($charts[$id]))),
        'tables' => [
            ['title' => 'Trips by actual departure period', 'headers' => [$granularities[$granularity] . ' bucket', 'Trips'],
             'rows' => array_map(static fn($r) => [(string)$r['bucket'], (int)$r['trip_count']], $departureRows)],
            ['title' => 'Trips by current status', 'headers' => ['Recorded status', 'Trips'],
             'rows' => array_map(static fn($r) => [(string)$r['status'], (int)$r['trip_count']], $currentStatusRows)],
            ['title' => 'Trips by recorded client field', 'headers' => ['Client field', 'Trips'],
             'rows' => array_map(static fn($r) => [(string)$r['client_name'], (int)$r['trip_count']], $sortDesc($clientRows, 'trip_count'))],
            ['title' => 'Trips by truck', 'headers' => ['Truck', 'Trips'],
             'rows' => array_map(static fn($r) => [(string)$r['plate_number'], (int)$r['trip_count']], $sortDesc($truckRows, 'trip_count'))],
        ],
        'unavailable' => 'Recorded delays (a past ETA is an alert, not a delay), delivered-unit completion, fleet utilization / truck-days, on-time rate, driver attribution and missing waybills are not shown: the data or the client’s definition is not confirmed.',
    ];
    if ($canFleet) {
        $sections['operations']['tables'][] = ['title' => 'Fleet by stored status', 'headers' => ['Stored truck status', 'Trucks'],
            'rows' => array_map(static fn($r) => [(string)$r['status'], (int)$r['truck_count']], $fleetRows)];
    }
}

// ════════════════════════════════════════════════════════════════════════════
// ACCOUNTING
// ════════════════════════════════════════════════════════════════════════════
if ($canFinancials) {
    $eligibleBilling = billingRevenueSql('b');
    $billBucket = bucketExpr('b.billing_date', $granularity);
    $payBucket = bucketExpr('c.payment_date', $granularity);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS record_count, SUM(b.total_amount) AS total_amount
        FROM billings b
        WHERE $eligibleBilling
          AND b.billing_date >= :rangeStart AND b.billing_date < :rangeEnd
    ");
    $stmt->execute($rangeParams);
    $billingStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS record_count, SUM(c.amount) AS total_amount
        FROM collections c
        JOIN billings cb ON cb.billing_id = c.billing_id AND cb.status <> 'Cancelled'
        WHERE c.payment_date >= :rangeStart AND c.payment_date < :rangeEnd
    ");
    $stmt->execute($rangeParams);
    $collectionStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $billedTrend = qRange($pdo, "
        SELECT $billBucket AS bucket, SUM(b.total_amount) AS amount
        FROM billings b
        WHERE $eligibleBilling
          AND b.billing_date >= :rangeStart AND b.billing_date < :rangeEnd
        GROUP BY bucket
        ORDER BY bucket
    ", $rangeStartSql, $rangeEndSql)->fetchAll(PDO::FETCH_ASSOC);

    $collectedTrend = qRange($pdo, "
        SELECT $payBucket AS bucket, SUM(c.amount) AS amount
        FROM collections c
        JOIN billings cb ON cb.billing_id = c.billing_id AND cb.status <> 'Cancelled'
        WHERE c.payment_date >= :rangeStart AND c.payment_date < :rangeEnd
        GROUP BY bucket
        ORDER BY bucket
    ", $rangeStartSql, $rangeEndSql)->fetchAll(PDO::FETCH_ASSOC);

    $billingStatusRows = $orderBy(qRange($pdo, "
        SELECT b.status, COUNT(*) AS record_count, SUM(b.total_amount) AS amount
        FROM billings b
        WHERE $eligibleBilling
          AND b.billing_date >= :rangeStart AND b.billing_date < :rangeEnd
        GROUP BY b.status
    ", $rangeStartSql, $rangeEndSql)->fetchAll(PDO::FETCH_ASSOC), 'status', $billingStatusOrder);

    $billingClientRows = qRange($pdo, "
        SELECT COALESCE(NULLIF(TRIM(cl.client_name), ''), 'Unassigned') AS client_name,
               COUNT(*) AS record_count, SUM(b.total_amount) AS amount
        FROM billings b
        LEFT JOIN clients cl ON cl.client_id = b.client_id
        WHERE $eligibleBilling
          AND b.billing_date >= :rangeStart AND b.billing_date < :rangeEnd
        GROUP BY client_name
        ORDER BY amount DESC
    ", $rangeStartSql, $rangeEndSql)->fetchAll(PDO::FETCH_ASSOC);

    $paymentMethodRows = qRange($pdo, "
        SELECT c.payment_method, COUNT(*) AS record_count, SUM(c.amount) AS amount
        FROM collections c
        JOIN billings cb ON cb.billing_id = c.billing_id AND cb.status <> 'Cancelled'
        WHERE c.payment_date >= :rangeStart AND c.payment_date < :rangeEnd
        GROUP BY c.payment_method
        ORDER BY amount DESC
    ", $rangeStartSql, $rangeEndSql)->fetchAll(PDO::FETCH_ASSOC);

    $billedTotal = (float)($billingStats['total_amount'] ?? 0);
    $billedCount = (int)($billingStats['record_count'] ?? 0);
    $collectedTotal = (float)($collectionStats['total_amount'] ?? 0);
    $collectedCount = (int)($collectionStats['record_count'] ?? 0);
    $overdueBillings = $countOf($billingStatusRows, 'status', 'Overdue', 'record_count');

    $kpis = [
        ['label' => 'Recorded billings', 'value' => $billedCount ? $peso($billedTotal) : '—', 'sub' => $billedCount ? number_format($billedCount) . ' billings by billing date' : 'No eligible billing in this period', 'tone' => 'primary', 'hero' => true],
        ['label' => 'Recorded collections', 'value' => $collectedCount ? $peso($collectedTotal) : '—', 'sub' => $collectedCount ? number_format($collectedCount) . ' payments by payment date' : 'No payment recorded in this period', 'tone' => 'good'],
        ['label' => 'Billings marked Overdue', 'value' => number_format($overdueBillings), 'sub' => 'Stored status; no aging is computed', 'tone' => $overdueBillings > 0 ? 'danger' : 'good'],
        ['label' => 'Billing records', 'value' => number_format($billedCount), 'sub' => 'Issued, Partially Paid, Paid, Overdue', 'tone' => ''],
        ['label' => 'Payments recorded', 'value' => number_format($collectedCount), 'sub' => 'Each payment counted once', 'tone' => ''],
    ];

    $money = $buckets($billedTrend, $collectedTrend);
    $charts['accTrend'] = [
        'title' => 'Billed and collected over time', 'span' => 8, 'height' => 'tall', 'type' => 'bar', 'format' => 'peso', 'unit' => '',
        'note' => $granularities[$granularity] . ' totals. Two independent date bases; the bars are not matched to the same invoices.',
        'labels' => $money['labels'],
        'datasets' => [
            ['label' => 'Billed', 'data' => $series($money['keys'], $billedTrend, 'amount'), 'color' => '#2f6fdd'],
            ['label' => 'Collected', 'data' => $series($money['keys'], $collectedTrend, 'amount'), 'color' => '#2f9e5b'],
        ],
        'method' => [
            'Formula' => 'Billed = SUM(total_amount); Collected = SUM(amount); each per bucket',
            'Source' => 'billings.total_amount, billings.billing_date, billings.status; collections.amount, collections.payment_date',
            'Date basis' => 'Billed by billing date (Issued, Partially Paid, Paid, Overdue only; Draft and Cancelled excluded). Collected by payment date.',
            'Limits' => 'Not a collection rate, receivable, cash position or profit.',
        ],
    ];
    $charts['accStatus'] = [
        'title' => 'Billed amount by status', 'span' => 4, 'height' => 'tall', 'type' => 'doughnut', 'format' => 'peso', 'unit' => '',
        'note' => 'Eligible billings dated in ' . strtolower($periodLabel) . '.',
        'labels' => array_map(static fn($r) => (string)$r['status'], $billingStatusRows),
        'datasets' => [[
            'label' => 'Billed', 'data' => array_map(static fn($r) => (float)$r['amount'], $billingStatusRows),
            'colors' => array_map(static fn($r) => $colorOf($billingStatusColors, $r['status']), $billingStatusRows),
        ]],
        'method' => [
            'Formula' => 'SUM(total_amount) grouped by billings.status',
            'Source' => 'billings.total_amount, billings.status',
            'Date basis' => 'Billing date in the period; the status is the stored current one.',
            'Limits' => 'Status is as stored; due dates and payment allocation are not used.',
        ],
    ];
    $topBilled = array_slice($billingClientRows, 0, 8);
    $charts['accClients'] = [
        'title' => 'Top clients by billed amount', 'span' => 6, 'height' => 'medium', 'type' => 'hbar', 'format' => 'peso', 'unit' => '',
        'note' => 'Top 8 of ' . count($billingClientRows) . '.',
        'labels' => array_map(static fn($r) => (string)$r['client_name'], $topBilled),
        'datasets' => [['label' => 'Billed', 'data' => array_map(static fn($r) => (float)$r['amount'], $topBilled), 'color' => '#1aa5a0']],
        'method' => [
            'Formula' => 'SUM(total_amount) per client, highest first',
            'Source' => 'billings.client_id → clients.client_name; billings.total_amount',
            'Date basis' => 'Billing date; eligible statuses only.',
            'Limits' => 'One client level only (company vs end client unconfirmed).',
        ],
    ];
    $charts['accMethods'] = [
        'title' => 'Collections by payment method', 'span' => 6, 'height' => 'medium', 'type' => 'doughnut', 'format' => 'peso', 'unit' => '',
        'note' => 'Recorded payments dated in ' . strtolower($periodLabel) . '.',
        'labels' => array_map(static fn($r) => (string)$r['payment_method'], $paymentMethodRows),
        'datasets' => [[
            'label' => 'Collected', 'data' => array_map(static fn($r) => (float)$r['amount'], $paymentMethodRows),
            'colors' => array_map(static fn($r) => $colorOf($methodColors, $r['payment_method']), $paymentMethodRows),
        ]],
        'method' => [
            'Formula' => 'SUM(amount) grouped by payment_method',
            'Source' => 'collections.amount, collections.payment_method',
            'Date basis' => 'Payment date in the period.',
            'Limits' => 'Only payments entered in the system; missing payments are not treated as zero collections.',
        ],
    ];

    $sections['accounting'] = [
        'title' => 'Accounting', 'icon' => 'bi-cash-coin',
        'blurb' => 'Recorded billings and collections.',
        'kpis' => $kpis,
        'charts' => ['accTrend', 'accStatus', 'accClients', 'accMethods'],
        'tables' => [
            ['title' => 'Billings and collections summary', 'headers' => ['Recorded information', 'Date basis', 'Records', 'Amount'],
             'rows' => [
                ['Billings (eligible statuses)', 'Billing date', $billedCount, $billedCount ? $peso($billedTotal) : 'No recorded billing'],
                ['Collections', 'Payment date', $collectedCount, $collectedCount ? $peso($collectedTotal) : 'No recorded payment'],
             ]],
            ['title' => 'Billed amount by status', 'headers' => ['Status', 'Billings', 'Amount'],
             'rows' => array_map(static fn($r) => [(string)$r['status'], (int)$r['record_count'], $peso($r['amount'])], $billingStatusRows)],
            ['title' => 'Billed amount by client field', 'headers' => ['Client field', 'Billings', 'Amount'],
             'rows' => array_map(static fn($r) => [(string)$r['client_name'], (int)$r['record_count'], $peso($r['amount'])], $billingClientRows)],
            ['title' => 'Collections by payment method', 'headers' => ['Method', 'Payments', 'Amount'],
             'rows' => array_map(static fn($r) => [(string)$r['payment_method'], (int)$r['record_count'], $peso($r['amount'])], $paymentMethodRows)],
        ],
        'unavailable' => 'Outstanding and overdue receivables, collection rate, expense categories and margin are not shown: payment allocation, client payment terms, waybill submission dates and approved definitions are missing or unconfirmed.',
    ];
}

// ════════════════════════════════════════════════════════════════════════════
// MAINTENANCE
// ════════════════════════════════════════════════════════════════════════════
if ($canMaintenance) {
    $maintenanceDate = maintenanceRecordDateSql('mr');
    $maintBucket = bucketExpr($maintenanceDate, $granularity);

    $maintenanceRows = qRange($pdo, "
        SELECT COALESCE(NULLIF(TRIM(tr.plate_number), ''), 'Unassigned') AS plate_number,
               COUNT(*) AS record_count,
               SUM(CASE WHEN mr.status = 'Completed' THEN mr.total_cost END) AS recorded_cost
        FROM maintenance_records mr
        LEFT JOIN trucks tr ON tr.truck_id = mr.truck_id
        WHERE mr.status <> 'Cancelled'
          AND $maintenanceDate >= :rangeStart AND $maintenanceDate < :rangeEnd
        GROUP BY plate_number
        ORDER BY plate_number
    ", $rangeStartSql, $rangeEndSql)->fetchAll(PDO::FETCH_ASSOC);

    $maintTrend = qRange($pdo, "
        SELECT $maintBucket AS bucket, SUM(mr.total_cost) AS cost
        FROM maintenance_records mr
        WHERE mr.status = 'Completed'
          AND $maintenanceDate >= :rangeStart AND $maintenanceDate < :rangeEnd
        GROUP BY bucket
        ORDER BY bucket
    ", $rangeStartSql, $rangeEndSql)->fetchAll(PDO::FETCH_ASSOC);

    $maintTypeRows = qRange($pdo, "
        SELECT mr.maintenance_type, COUNT(*) AS record_count, SUM(mr.total_cost) AS cost
        FROM maintenance_records mr
        WHERE mr.status = 'Completed'
          AND $maintenanceDate >= :rangeStart AND $maintenanceDate < :rangeEnd
        GROUP BY mr.maintenance_type
        ORDER BY cost DESC
    ", $rangeStartSql, $rangeEndSql)->fetchAll(PDO::FETCH_ASSOC);

    $maintStatusRows = $orderBy(qRange($pdo, "
        SELECT mr.status, COUNT(*) AS record_count
        FROM maintenance_records mr
        WHERE $maintenanceDate >= :rangeStart AND $maintenanceDate < :rangeEnd
        GROUP BY mr.status
    ", $rangeStartSql, $rangeEndSql)->fetchAll(PDO::FETCH_ASSOC), 'status', $maintStatusOrder);

    $withCost = array_values(array_filter($maintenanceRows, static fn($r) => $r['recorded_cost'] !== null));
    $maintTotal = $sumField($withCost, 'recorded_cost');
    $maintRecords = (int)$sumField($maintenanceRows, 'record_count');
    $inProgress = $countOf($maintStatusRows, 'status', 'In Progress', 'record_count');
    $waitingParts = $countOf($maintStatusRows, 'status', 'Waiting for Parts', 'record_count');

    $kpis = [
        ['label' => 'Completed maintenance cost', 'value' => $withCost ? $peso($maintTotal) : '—', 'sub' => 'Completed records only, ' . $periodLabel, 'tone' => 'primary', 'hero' => true],
        ['label' => 'Maintenance records', 'value' => number_format($maintRecords), 'sub' => 'Excludes cancelled', 'tone' => ''],
        ['label' => 'Trucks with records', 'value' => number_format(count($maintenanceRows)), 'sub' => 'Distinct plates in the period', 'tone' => ''],
        ['label' => 'Waiting for parts', 'value' => number_format($waitingParts), 'sub' => 'Records at this status now', 'tone' => $waitingParts > 0 ? 'warn' : 'good'],
        ['label' => 'In progress', 'value' => number_format($inProgress), 'sub' => 'Records at this status now', 'tone' => ''],
    ];

    $mt = $buckets($maintTrend);
    $charts['mntTrend'] = [
        'title' => 'Completed maintenance cost over time', 'span' => 8, 'height' => 'tall', 'type' => 'bar', 'format' => 'peso', 'unit' => '',
        'note' => $granularities[$granularity] . ' totals of the recorded cost of completed records.',
        'labels' => $mt['labels'],
        'datasets' => [['label' => 'Recorded cost', 'data' => $series($mt['keys'], $maintTrend, 'cost'), 'color' => '#d99a00']],
        'method' => [
            'Formula' => 'SUM(total_cost) per bucket, Completed records only',
            'Source' => 'maintenance_records.total_cost, status, actual_completion_date, start_date, scheduled_date, created_at',
            'Date basis' => 'Completion date, else start date, else scheduled date, else record creation date.',
            'Limits' => 'System-entered cost of completed records only; amounts on scheduled or open records may be estimates and are not included. May not be all company maintenance spending. Parts are not added again (they are already part of the record total).',
        ],
    ];
    $charts['mntType'] = [
        'title' => 'Cost by maintenance type', 'span' => 4, 'height' => 'tall', 'type' => 'doughnut', 'format' => 'peso', 'unit' => '',
        'note' => 'Share of completed maintenance cost in ' . strtolower($periodLabel) . '.',
        'labels' => array_map(static fn($r) => (string)$r['maintenance_type'], $maintTypeRows),
        'datasets' => [[
            'label' => 'Cost', 'data' => array_map(static fn($r) => (float)$r['cost'], $maintTypeRows),
            'colors' => array_map(static fn($r) => $colorOf($maintTypeColors, $r['maintenance_type']), $maintTypeRows),
        ]],
        'method' => [
            'Formula' => 'SUM(total_cost) grouped by maintenance_type, Completed records only',
            'Source' => 'maintenance_records.maintenance_type, total_cost',
            'Date basis' => 'Same maintenance date rule as the trend.',
            'Limits' => 'Types are as entered (Preventive, Repair, Inspection, Other).',
        ],
    ];
    $topMaint = array_slice($sortDesc($withCost, 'recorded_cost'), 0, 8);
    $charts['mntTrucks'] = [
        'title' => 'Top trucks by completed maintenance cost', 'span' => 6, 'height' => 'medium', 'type' => 'hbar', 'format' => 'peso', 'unit' => '',
        'note' => 'Top 8 of ' . count($withCost) . ' trucks with a recorded amount.',
        'labels' => array_map(static fn($r) => (string)$r['plate_number'], $topMaint),
        'datasets' => [['label' => 'Recorded cost', 'data' => array_map(static fn($r) => (float)$r['recorded_cost'], $topMaint), 'color' => '#d6403f']],
        'method' => [
            'Formula' => 'SUM(total_cost) of Completed records per truck, highest first',
            'Source' => 'maintenance_records.truck_id → trucks.plate_number; total_cost',
            'Date basis' => 'Same maintenance date rule; Completed records only.',
            'Limits' => 'Records without an amount are not plotted and not treated as zero.',
        ],
    ];
    $charts['mntStatus'] = [
        'title' => 'Records by current status', 'span' => 6, 'height' => 'medium', 'type' => 'doughnut', 'format' => 'count', 'unit' => 'record',
        'note' => 'Records dated in ' . strtolower($periodLabel) . ', including cancelled as its own slice.',
        'labels' => array_map(static fn($r) => (string)$r['status'], $maintStatusRows),
        'datasets' => [[
            'label' => 'Records', 'data' => array_map(static fn($r) => (int)$r['record_count'], $maintStatusRows),
            'colors' => array_map(static fn($r) => $colorOf($maintStatusColors, $r['status']), $maintStatusRows),
        ]],
        'method' => [
            'Formula' => 'COUNT(record) grouped by status',
            'Source' => 'maintenance_records.status',
            'Date basis' => 'Same maintenance date rule; status is the current one.',
            'Limits' => 'Which statuses count as “open” is unconfirmed, so none are merged.',
        ],
    ];

    $sections['maintenance'] = [
        'title' => 'Maintenance', 'icon' => 'bi-tools',
        'blurb' => 'Recorded maintenance cost and work status.',
        'kpis' => $kpis,
        'charts' => ['mntTrend', 'mntType', 'mntTrucks', 'mntStatus'],
        'tables' => [
            ['title' => 'Completed maintenance cost by truck', 'headers' => ['Truck', 'Records (non-cancelled)', 'Completed cost'],
             'rows' => array_map(static fn($r) => [(string)$r['plate_number'], (int)$r['record_count'], $r['recorded_cost'] === null ? 'No completed amount' : $peso($r['recorded_cost'])], $maintenanceRows)],
            ['title' => 'Completed maintenance cost by type', 'headers' => ['Type', 'Completed records', 'Completed cost'],
             'rows' => array_map(static fn($r) => [(string)$r['maintenance_type'], (int)$r['record_count'], $peso($r['cost'])], $maintTypeRows)],
            ['title' => 'Records by status', 'headers' => ['Status', 'Records'],
             'rows' => array_map(static fn($r) => [(string)$r['status'], (int)$r['record_count']], $maintStatusRows)],
        ],
        'unavailable' => 'Downtime and repair duration are not shown: no reliable down-start / return-to-service dates or daily truck status history exist.',
    ];
}

// Only plain chart definitions go to the browser; method text stays server-side.
$chartJson = [];
foreach ($charts as $id => $chart) {
    $chartJson[$id] = [
        'type' => $chart['type'], 'format' => $chart['format'], 'unit' => $chart['unit'],
        'labels' => $chart['labels'], 'datasets' => $chart['datasets'],
    ];
}

$GLOBALS['page_needs_chart'] = true;
$GLOBALS['page_js'] = APP_BASE . '/assets/js/analytics.js';
layoutHead('Analytics', APP_BASE . '/assets/css/analytics.css');
?>
<?php
function anHasData(array $chart): bool {
    foreach ($chart['datasets'] as $dataset) {
        foreach ($dataset['data'] as $value) {
            if ($value !== null && (float)$value > 0) {
                return true;
            }
        }
    }
    return false;
}

function anKpi(array $kpi): string {
    $tone = $kpi['tone'] !== '' ? ' an-kpi-' . $kpi['tone'] : '';
    $hero = !empty($kpi['hero']) ? ' an-kpi-hero' : '';
    return '<div class="an-kpi' . $tone . $hero . '">'
        . '<div class="an-kpi-label">' . htmlspecialchars($kpi['label']) . '</div>'
        . '<div class="an-kpi-value">' . htmlspecialchars($kpi['value']) . '</div>'
        . '<div class="an-kpi-sub">' . htmlspecialchars($kpi['sub']) . '</div></div>';
}

function anChartCard(string $id, array $chart): void { ?>
  <article class="an-card an-span-<?= (int)$chart['span'] ?>">
    <header class="an-card-head">
      <h3><?= htmlspecialchars($chart['title']) ?></h3>
      <p><?= htmlspecialchars($chart['note']) ?></p>
    </header>
    <?php if (anHasData($chart)): ?>
      <div class="an-chart an-chart-<?= htmlspecialchars($chart['height']) ?>">
        <canvas id="<?= htmlspecialchars($id) ?>" role="img" aria-label="<?= htmlspecialchars($chart['title'] . ' — values are listed in the detailed records below') ?>"></canvas>
      </div>
    <?php else: ?>
      <div class="an-chart-empty"><i class="bi bi-bar-chart" aria-hidden="true"></i> No recorded data for this period.</div>
    <?php endif; ?>
    <details class="an-method">
      <summary>How this is calculated</summary>
      <dl>
        <?php foreach ($chart['method'] as $term => $text): ?>
          <dt><?= htmlspecialchars($term) ?></dt><dd><?= htmlspecialchars($text) ?></dd>
        <?php endforeach; ?>
      </dl>
    </details>
  </article>
<?php }

function anTable(array $table): void { ?>
  <div class="an-table-card">
    <h4><?= htmlspecialchars($table['title']) ?></h4>
    <?php if (!$table['rows']): ?>
      <p class="an-muted">No records in this period.</p>
    <?php else: ?>
    <div class="an-table-wrap">
      <table class="table align-middle mb-0">
        <thead><tr><?php foreach ($table['headers'] as $i => $header): ?>
          <th class="<?= $i > 0 ? 'text-end' : '' ?>"><?= htmlspecialchars($header) ?></th>
        <?php endforeach; ?></tr></thead>
        <tbody><?php foreach ($table['rows'] as $row): ?>
          <tr><?php foreach ($row as $i => $cell): ?>
            <td class="<?= $i > 0 ? 'text-end' : '' ?>"><?= htmlspecialchars(is_int($cell) ? number_format($cell) : (string)$cell) ?></td>
          <?php endforeach; ?></tr>
        <?php endforeach; ?></tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
<?php }
?>
<div class="an-page">
  <header class="an-header">
    <div>
      <h1 class="an-title">Analytics</h1>
      <p class="an-subtitle">Recorded business data · <?= htmlspecialchars($periodLabel) ?> · grouped <?= htmlspecialchars(strtolower($granularities[$granularity])) ?></p>
    </div>
    <form method="get" class="an-filters">
      <div>
        <label for="period">Reporting period</label>
        <select id="period" name="period" class="form-select" onchange="this.form.submit()">
          <?php foreach ($periods as $key => $option): ?>
            <option value="<?= htmlspecialchars($key) ?>" <?= $period === $key ? 'selected' : '' ?>><?= htmlspecialchars($option['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="granularity">Group trends</label>
        <select id="granularity" name="granularity" class="form-select" onchange="this.form.submit()">
          <?php foreach ($granularities as $key => $label): ?>
            <option value="<?= htmlspecialchars($key) ?>" <?= $granularity === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </header>

  <?php if (!$sections): ?>
    <p class="an-muted">No analytics sections are available for your role.</p>
  <?php else: ?>
  <?php if (count($sections) > 1): ?>
  <nav class="an-tabs" aria-label="Analytics departments">
    <?php foreach ($sections as $key => $section): ?>
      <a href="#an-<?= htmlspecialchars($key) ?>"><i class="bi <?= htmlspecialchars($section['icon']) ?>" aria-hidden="true"></i> <?= htmlspecialchars($section['title']) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php foreach ($sections as $key => $section): ?>
  <section class="an-dept an-dept-<?= htmlspecialchars($key) ?>" id="an-<?= htmlspecialchars($key) ?>" aria-labelledby="an-<?= htmlspecialchars($key) ?>-h">
    <header class="an-dept-head">
      <span class="an-dept-icon"><i class="bi <?= htmlspecialchars($section['icon']) ?>" aria-hidden="true"></i></span>
      <div>
        <h2 id="an-<?= htmlspecialchars($key) ?>-h"><?= htmlspecialchars($section['title']) ?></h2>
        <p><?= htmlspecialchars($section['blurb']) ?></p>
      </div>
    </header>

    <div class="an-kpis">
      <?php foreach ($section['kpis'] as $kpi) { echo anKpi($kpi); } ?>
    </div>

    <div class="an-grid">
      <?php foreach ($section['charts'] as $chartId) { anChartCard($chartId, $charts[$chartId]); } ?>
    </div>

    <details class="an-detail">
      <summary><i class="bi bi-table" aria-hidden="true"></i> Detailed records</summary>
      <div class="an-table-grid">
        <?php foreach ($section['tables'] as $table) { anTable($table); } ?>
      </div>
    </details>

    <p class="an-unavailable"><i class="bi bi-info-circle" aria-hidden="true"></i> <strong>Not shown:</strong> <?= htmlspecialchars($section['unavailable']) ?></p>
  </section>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<script>
window.ANALYTICS_DATA = <?= json_encode($chartJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
</script>
<?php layoutFoot(); ?>
