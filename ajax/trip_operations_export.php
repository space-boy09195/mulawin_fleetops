<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reporting.php';
require_once __DIR__ . '/../app/modules/trips/crew_trip_access.php';
require_once __DIR__ . '/../app/modules/trips/trip_queries.php';
require_once __DIR__ . '/../config/enums.php';

// Exporting trip operations needs trips.export (not the generic reports.export).
requirePermission('trips.export');
refuseHelpers(); // the export covers every trip

try {
    $range = reportDateRange($_GET);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
}

$status = trim((string)($_GET['status'] ?? ''));
$shift = trim((string)($_GET['shift'] ?? ''));
$shifts = ['Day', 'Night'];
if ($shift !== '' && !in_array($shift, $shifts, true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Trip shift filter is invalid.';
    exit;
}
$statuses = TRIP_STATUSES;
if ($status !== '' && !in_array($status, $statuses, true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Trip status filter is invalid.';
    exit;
}

$clientIdRaw = trim((string)($_GET['client_id'] ?? ''));
$clientId = null;
if ($clientIdRaw !== '') {
    $clientId = filter_var($clientIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($clientId === false) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Client filter is invalid.';
        exit;
    }
}

$conditions = [tripOperationalSql('t', 'dr')];
$params = [];
if ($range['from'] !== null) {
    $conditions[] = 't.scheduled_departure >= ?';
    $params[] = $range['from'] . ' 00:00:00';
}
if ($range['to'] !== null) {
    $conditions[] = 't.scheduled_departure < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $range['to'] . ' 00:00:00';
}
if ($status !== '') {
    $conditions[] = 't.status = ?';
    $params[] = $status;
}
if ($shift !== '') {
    $conditions[] = 't.shift = ?';
    $params[] = $shift;
}
if ($clientId !== null) {
    $conditions[] = 't.client_id = ?';
    $params[] = $clientId;
}
$where = 'WHERE ' . implode(' AND ', $conditions);

// Target columns. The billing client and per-dispatch rate columns of the
// legacy export have no target counterpart (rates are looked up from
// client_rates when billing) and are no longer exported.
$pdo = getDBConnection();
$stmt = $pdo->prepare(
    "SELECT t.trip_number, t.status, t.shift, c.client_name,
            tr.plate_number, e_d.full_name AS driver,
            e_sd.full_name AS second_driver, e_h.full_name AS helper,
            route.origin, route.destination,
            t.origin AS pickup_location, t.destination AS delivery_location,
            cb.booking_reference, td.waybill_number, cb.quantity,
            t.scheduled_departure, t.scheduled_arrival,
            t.departure_time, " . tripArrivalSql('t') . " AS actual_arrival,
            CASE WHEN " . tripLateSql('t') . " OR " . tripOverdueSql('t') . " THEN 1 ELSE 0 END AS is_late
     FROM trips t
     LEFT JOIN dispatch_requests dr ON dr.trip_id = t.trip_id
     JOIN clients c ON c.client_id = t.client_id
     LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
     LEFT JOIN employees e_d ON e_d.employee_id = t.driver_id
     LEFT JOIN employees e_sd ON e_sd.employee_id = t.second_driver_id
     LEFT JOIN employees e_h ON e_h.employee_id = t.helper_id
     LEFT JOIN routes route ON route.route_id = t.route_id
     LEFT JOIN client_bookings cb ON cb.booking_id = t.booking_id
     LEFT JOIN trip_deliveries td ON td.trip_id = t.trip_id
     $where
     ORDER BY t.scheduled_departure DESC, t.trip_id DESC"
);
$stmt->execute($params);

$rows = static function () use ($stmt): Generator {
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        yield $row;
    }
};
streamCsvReport(
    'fleetops-trip-operations.csv',
    [
        'Trip Number', 'Status', 'Shift', 'Client', 'Truck',
        'Driver', 'Second Driver', 'Helper', 'Route Origin', 'Route Destination',
        'Pickup Location', 'Delivery Location', 'Booking Reference', 'Waybill Number',
        'Unit Count', 'Scheduled Departure', 'Expected Arrival', 'Actual Departure',
        'Actual Arrival', 'Late',
    ],
    $rows()
);
