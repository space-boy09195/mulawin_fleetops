<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reporting.php';

requirePermission('reports.export');

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
$statuses = ['Loading', 'In Transit', 'Unloading', 'Completed', 'Cancelled'];
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

$conditions = [];
$params = [];
if ($range['from'] !== null) {
    $conditions[] = 'dr.scheduled_at >= ?';
    $params[] = $range['from'] . ' 00:00:00';
}
if ($range['to'] !== null) {
    $conditions[] = 'dr.scheduled_at < DATE_ADD(?, INTERVAL 1 DAY)';
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
    $conditions[] = 'dr.client_id = ?';
    $params[] = $clientId;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$pdo = getDBConnection();
$stmt = $pdo->prepare(
    "SELECT t.trip_number, t.status, t.shift, dr.client_name,
            billing.client_name AS billing_client,
            tr.plate_number, e_d.full_name AS driver,
            e_sd.full_name AS second_driver, e_h.full_name AS helper,
            route.origin, route.destination,
            origin.location_name AS pickup_location,
            destination.location_name AS delivery_location,
            dr.booking_reference, dr.waybill_reference, dr.unit_count,
            dr.client_rate_amount, dr.client_rate_currency, dr.client_rate_basis,
            dr.scheduled_at, t.expected_arrival,
            t.actual_departure_at, t.actual_arrival, t.is_late
     FROM trips t
     JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
     JOIN trucks tr ON tr.truck_id = dr.truck_id
     JOIN employees e_d ON e_d.employee_id = dr.driver_id
     LEFT JOIN employees e_sd ON e_sd.employee_id = dr.second_driver_id
     LEFT JOIN employees e_h ON e_h.employee_id = dr.helper_id
     JOIN routes route ON route.route_id = dr.route_id
     LEFT JOIN clients billing ON billing.client_id = dr.billing_client_id
     LEFT JOIN client_locations origin ON origin.location_id = dr.origin_location_id
     LEFT JOIN client_locations destination ON destination.location_id = dr.destination_location_id
     $where
     ORDER BY dr.scheduled_at DESC, t.trip_id DESC"
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
        'Trip Number', 'Status', 'Shift', 'Client', 'Billing Client', 'Truck',
        'Driver', 'Second Driver', 'Helper', 'Route Origin', 'Route Destination',
        'Pickup Location', 'Delivery Location', 'Booking Reference', 'Waybill Reference',
        'Unit Count', 'Rate Amount', 'Rate Currency', 'Rate Basis', 'Scheduled Departure',
        'Expected Arrival', 'Actual Departure', 'Actual Arrival', 'Late',
    ],
    $rows()
);
