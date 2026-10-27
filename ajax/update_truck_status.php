<?php
// ============================================================
// ajax/update_truck_status.php
// AJAX endpoint — updates a truck's status
// Method : POST
// Params : truck_id (int), status (string)
// Returns: JSON { success: bool, message: string }
// ============================================================

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../app/modules/fleet/truck_status_history.php';

header('Content-Type: application/json');

// ---- Auth check ---------------------------------------------
// The read-only status history keeps its existing access rule.
// Changing a truck's status is limited to Admin and Maintenance through
// fleet.status.update (Admin passes every permission check). Dispatchers
// do not hold it, so they are refused with the shared 403 response.
if (($_POST['action'] ?? '') === 'history') {
    requirePermission('fleet.view'); // same as the Fleet Status page
} else {
    requirePermission('fleet.status.update');
}

requirePostMethod();
enforceCsrf();

// ---- Validate inputs ------------------------------------------
$truckId = requiredInt('truck_id', 'Truck ID', 1);
$pdo   = getDBConnection();

if (($_POST['action'] ?? '') === 'history') {
    findOrFail($pdo, 'trucks', 'truck_id', $truckId, 'Truck not found.');
    // Target: truck status history is workflow_events (entity 'truck').
    $history = $pdo->prepare(
        "SELECT h.old_status AS previous_status, h.new_status, h.remarks AS reason,
                h.created_at AS changed_at,
                COALESCE(ce.full_name, u.username) AS changed_by_name
         FROM workflow_events h
         LEFT JOIN users u ON u.user_id = h.performed_by
         LEFT JOIN employees ce ON ce.employee_id = u.employee_id
         WHERE h.entity_type = 'truck' AND h.entity_id = ? AND h.event_type = 'truck_status'
         ORDER BY h.created_at DESC, h.workflow_event_id DESC
         LIMIT 100"
    );
    $history->execute([$truckId]);
    jsonOk(['history' => $history->fetchAll(PDO::FETCH_ASSOC)]);
}

// Assigned / On Trip are set only by the trip workflow.
$newStatus = requiredEnum('status', TRUCK_MANUAL_STATUSES, 'Status');
$reason = optionalString('reason', null, 500);

try {
    $pdo->beginTransaction();
    $truckQuery = $pdo->prepare('SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE');
    $truckQuery->execute([$truckId]);
    $oldStatus = $truckQuery->fetchColumn();
    if ($oldStatus === false) {
        $pdo->rollBack();
        jsonFail('Truck not found.', 404);
    }
    if ($oldStatus === $newStatus) {
        $pdo->rollBack();
        jsonOk([], 'No change needed.');
    }
    if ($reason === null) {
        $pdo->rollBack();
        jsonFail('Enter a reason for the status change.');
    }

    // A truck on an active trip (any trip of its dispatch requests that is
    // not Completed or Cancelled, the same rule as maintenance_handler.php)
    // keeps its status; the trip workflow releases it. The truck row is
    // already locked above, and the trip rows are locked here, in the same
    // order maintenance_handler.php uses.
    $activeTrip = $pdo->prepare(
        "SELECT t.trip_id
         FROM trips t
         WHERE t.truck_id = ? AND t.status NOT IN ('Completed', 'Cancelled')
         LIMIT 1 FOR UPDATE"
    );
    $activeTrip->execute([$truckId]);
    if ($activeTrip->fetchColumn()) {
        $pdo->rollBack();
        jsonFail('This truck has an active trip. Complete or cancel the trip before changing its status.', 409);
    }

    // availability_status mirrors whether the truck can be dispatched.
    $update = $pdo->prepare(
        "UPDATE trucks SET status = :status,
                availability_status = IF(:status2 = 'Available', 'Available', 'Unavailable')
         WHERE truck_id = :id"
    );
    $update->execute([':status' => $newStatus, ':status2' => $newStatus, ':id' => $truckId]);
    recordTruckStatusHistory($pdo, $truckId, (string)$oldStatus, $newStatus, $reason);
    auditLog('UPDATE', 'trucks', $truckId, ['status' => $oldStatus], [
        'status' => $newStatus,
        'reason' => $reason,
    ]);
    $pdo->commit();
    jsonOk([], 'Truck status updated.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('update_truck_status: ' . $e->getMessage());
    jsonFail('Could not update truck status.', 500);
}
