<?php
// ============================================================
// ajax/update_trip_status.php
// AJAX endpoint — updates trip status + logs a trip_update row
// Method : POST
// Params : trip_id, status, location_note, notes
// Returns: JSON { success: bool, message: string }
// ============================================================

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

if (!in_array(currentRoleId(), [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

enforceCsrf();

$tripId   = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT);
$status   = trim($_POST['status']        ?? '');
$location = trim($_POST['location_note'] ?? '');
$notes    = trim($_POST['notes']         ?? '');

$allowedStatuses = ['Loading', 'In Transit', 'Unloading', 'Completed', 'Cancelled'];

if (!$tripId || !in_array($status, $allowedStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid input.']);
    exit;
}

$pdo = getDBConnection();

// Verify trip exists
$trip = $pdo->prepare("SELECT trip_id, status FROM trips WHERE trip_id = :id LIMIT 1");
$trip->execute([':id' => $tripId]);
$tripRow = $trip->fetch();

if (!$tripRow) {
    echo json_encode(['success' => false, 'message' => 'Trip not found.']);
    exit;
}

$oldStatus = $tripRow['status'];

// ---- Update trips table -----------------------------------
$actualArrival = $status === 'Completed' ? ', actual_arrival = NOW()' : '';
$pdo->prepare(
    "UPDATE trips
        SET status = :status,
            is_late = IF(expected_arrival < NOW() AND :status2 NOT IN ('Completed','Cancelled'), 1, 0)
            {$actualArrival}
      WHERE trip_id = :id"
)->execute([':status' => $status, ':status2' => $status, ':id' => $tripId]);

// If completed, free the truck back to Available
if ($status === 'Completed') {
    $pdo->prepare(
        "UPDATE trucks tr
           JOIN dispatch_requests dr ON dr.truck_id = tr.truck_id
           JOIN trips t              ON t.dispatch_id = dr.dispatch_id
            SET tr.status = 'Available'
          WHERE t.trip_id = :id"
    )->execute([':id' => $tripId]);
}

// ---- Insert trip_updates row ------------------------------
$pdo->prepare(
    "INSERT INTO trip_updates (trip_id, updated_by, status, location_note, notes)
     VALUES (:trip_id, :user_id, :status, :location, :notes)"
)->execute([
    ':trip_id'  => $tripId,
    ':user_id'  => currentUserId(),
    ':status'   => $status,
    ':location' => $location ?: null,
    ':notes'    => $notes    ?: null,
]);

auditLog('UPDATE', 'trips', $tripId, ['status' => $oldStatus], ['status' => $status]);

echo json_encode(['success' => true, 'message' => 'Trip updated.']);