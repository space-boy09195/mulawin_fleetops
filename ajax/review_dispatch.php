<?php
// ============================================================
// ajax/review_dispatch.php
// Approves or rejects a dispatch request (Head Management only)
// On Approve: sets truck to Deployed + creates a trip row
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/trip_number.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT]);
requirePostMethod();
enforceCsrf();

$dispatchId = requiredInt('dispatch_id', 'Dispatch ID', 1);
$status     = requiredEnum('status', ['Approved', 'Rejected'], 'Status');
$remarks    = optionalString('remarks');

$pdo = getDBConnection();
$pdo->beginTransaction();
try {

// Fetch the dispatch request
    $dr = $pdo->prepare(
    "SELECT dr.*, tr.truck_id FROM dispatch_requests dr
       JOIN trucks tr ON dr.truck_id = tr.truck_id
      WHERE dr.dispatch_id = :id AND dr.status = 'Pending'
      FOR UPDATE"
);
    $dr->execute([':id' => $dispatchId]);
    $dispatch = $dr->fetch();

    if (!$dispatch) {
        $pdo->rollBack();
        jsonFail('Request not found or already reviewed.', 404);
    }
    if (($dispatch['status'] ?? '') !== 'Pending') {
        $pdo->rollBack();
        jsonFail('Request not found or already reviewed.', 404);
    }
    $truckStatus = $pdo->prepare('SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE');
    $truckStatus->execute([(int)$dispatch['truck_id']]);
    if ($status === 'Approved' && $truckStatus->fetchColumn() !== 'Available') {
        $pdo->rollBack();
        jsonFail('The selected truck is no longer available.', 409);
    }

    $pdo->prepare(
    "UPDATE dispatch_requests
        SET status = :status, approved_by = :user, remarks = :remarks, reviewed_at = NOW()
      WHERE dispatch_id = :id"
    )->execute([
    ':status'  => $status,
    ':user'    => currentUserId(),
    ':remarks' => $remarks,
    ':id'      => $dispatchId,
    ]);

    $tripId = null;
    $tripNumber = null;
    if ($status === 'Approved') {

    // Mark truck as Deployed
        $pdo->prepare("UPDATE trucks SET status = 'Deployed' WHERE truck_id = :id")
        ->execute([':id' => $dispatch['truck_id']]);

        $tripNumber = nextTripNumber($pdo);

    // Create the trip
        $pdo->prepare(
        "INSERT INTO trips (dispatch_id, trip_number, status)
         VALUES (:dispatch, :number, 'Loading')"
        )->execute([':dispatch' => $dispatchId, ':number' => $tripNumber]);

        $tripId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE maintenance_checklists SET trip_id = ? WHERE dispatch_id = ? AND trip_id IS NULL')
            ->execute([$tripId, $dispatchId]);

        $driverUser = $pdo->prepare('SELECT user_id FROM employees WHERE employee_id = ?');
        $driverUser->execute([(int)$dispatch['driver_id']]);
        if ($userId = $driverUser->fetchColumn()) {
            $pdo->prepare('INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)')
                ->execute([(int)$userId, 'Dispatch approved', "Your dispatch {$tripNumber} was approved.", APP_BASE . '/pages/trip_monitor.php']);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('review_dispatch: ' . $e->getMessage());
    jsonFail('Could not review the dispatch request.', 500);
}

auditLog('UPDATE', 'dispatch_requests', $dispatchId, ['status' => 'Pending'], ['status' => $status]);
if ($tripId !== null) {
    auditLog('CREATE', 'trips', $tripId, null, ['trip_number' => $tripNumber]);
}

jsonOk($tripId !== null ? ['trip_id' => $tripId] : [], "Request {$status}.");
