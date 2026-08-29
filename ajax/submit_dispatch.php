<?php
// ============================================================
// ajax/submit_dispatch.php
// Creates a new dispatch_request row (status = Pending)
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json');

if (!isLoggedIn() || currentRoleId() !== ROLE_DISPATCHER) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}
enforceCsrf();

$truckId     = filter_input(INPUT_POST, 'truck_id',  FILTER_VALIDATE_INT);
$routeId     = filter_input(INPUT_POST, 'route_id',  FILTER_VALIDATE_INT);
$driverId    = filter_input(INPUT_POST, 'driver_id', FILTER_VALIDATE_INT);
$helperId    = filter_input(INPUT_POST, 'helper_id', FILTER_VALIDATE_INT) ?: null;
$scheduledAt = trim($_POST['scheduled_at'] ?? '');
$remarks     = trim($_POST['remarks']      ?? '') ?: null;

if (!$truckId || !$routeId || !$driverId || !$scheduledAt) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

// Validate scheduled_at is a valid datetime
if (!strtotime($scheduledAt)) {
    echo json_encode(['success' => false, 'message' => 'Invalid scheduled date.']);
    exit;
}
if (strtotime($scheduledAt) < time()) {
    echo json_encode(['success' => false, 'message' => 'New dispatches cannot use a passed date or time.']);
    exit;
}

$pdo = getDBConnection();

// A driver/helper may be scheduled again only after the current trip is done,
// and never twice on the same calendar day.
$availability = $pdo->prepare("
    SELECT dr.dispatch_id, dr.driver_id, dr.helper_id,
           e_d.full_name AS driver_name,
           e_h.full_name AS helper_name,
           dr.scheduled_at
    FROM dispatch_requests dr
    JOIN employees e_d ON e_d.employee_id = dr.driver_id
    LEFT JOIN employees e_h ON e_h.employee_id = dr.helper_id
    LEFT JOIN trips t ON t.dispatch_id = dr.dispatch_id
    WHERE dr.status IN ('Pending', 'Approved')
      AND DATE(dr.scheduled_at) = DATE(?)
      AND (t.trip_id IS NULL OR t.status NOT IN ('Completed', 'Cancelled'))
      AND (dr.driver_id = ? OR (? IS NOT NULL AND dr.helper_id = ?))
    LIMIT 1
");
$availability->execute([$scheduledAt, $driverId, $helperId, $helperId]);
$busy = $availability->fetch(PDO::FETCH_ASSOC);
if ($busy) {
    $person = (int)$busy['driver_id'] === $driverId
        ? $busy['driver_name']
        : $busy['helper_name'];
    if (!$person) {
        $person = $busy['helper_name'];
    }
    echo json_encode([
        'success' => false,
        'message' => $person . ' is already assigned to an active dispatch on that date.',
    ]);
    exit;
}

// Ensure truck is still Available
$truck = $pdo->prepare("SELECT status FROM trucks WHERE truck_id = :id LIMIT 1");
$truck->execute([':id' => $truckId]);
$truckRow = $truck->fetch();

if (!$truckRow || $truckRow['status'] !== 'Available') {
    echo json_encode(['success' => false, 'message' => 'Selected truck is no longer available.']);
    exit;
}

$stmt = $pdo->prepare(
    "INSERT INTO dispatch_requests
       (truck_id, driver_id, helper_id, route_id, requested_by, scheduled_at, remarks)
     VALUES
       (:truck, :driver, :helper, :route, :user, :scheduled, :remarks)"
);
$stmt->execute([
    ':truck'     => $truckId,
    ':driver'    => $driverId,
    ':helper'    => $helperId,
    ':route'     => $routeId,
    ':user'      => currentUserId(),
    ':scheduled' => $scheduledAt,
    ':remarks'   => $remarks,
]);

$newId = (int)$pdo->lastInsertId();
auditLog('CREATE', 'dispatch_requests', $newId);

echo json_encode(['success' => true, 'message' => 'Dispatch request submitted.']);