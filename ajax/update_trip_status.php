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
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER]);
requirePostMethod();
enforceCsrf();

$tripId   = requiredInt('trip_id', 'Trip ID', 1);
$status   = requiredEnum('status', TRIP_STATUSES, 'Status');
$location = optionalString('location_note');
$notes    = optionalString('notes');

$pdo = getDBConnection();

// Verify trip exists
$tripRow   = findOrFail($pdo, 'trips', 'trip_id', $tripId, 'Trip not found.');
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
    ':location' => $location,
    ':notes'    => $notes,
]);

auditLog('UPDATE', 'trips', $tripId, ['status' => $oldStatus], ['status' => $status]);

jsonOk([], 'Trip updated.');
