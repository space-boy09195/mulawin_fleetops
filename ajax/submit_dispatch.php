<?php
// ============================================================
// ajax/submit_dispatch.php
// Creates a new dispatch_request row (status = Pending)
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isLoggedIn() || currentRoleId() !== ROLE_DISPATCHER) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

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

$pdo = getDBConnection();

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