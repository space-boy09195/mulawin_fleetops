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
require_once __DIR__ . '/../includes/document_upload.php';

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

try {
    $pdo->beginTransaction();

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
    $tripNumber = $tripRow['trip_number'] ?? ('Trip #' . $tripId);
    $uploadedDocuments = [];
    foreach ([
        'delivery_receipt' => 'Delivery Receipt',
        'waybill' => 'Waybill',
    ] as $field => $docType) {
        if (!empty($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadedDocuments[] = storeUploadedDocument(
                $pdo,
                $_FILES[$field],
                $docType,
                $tripId,
                'Completed trip report attachment for ' . $tripNumber,
                'operations',
                currentUserId()
            );
        }
    }

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

    $pdo->commit();

auditLog('UPDATE', 'trips', $tripId, ['status' => $oldStatus], ['status' => $status]);
foreach ($uploadedDocuments ?? [] as $documentId) {
    auditLog('UPLOAD_DOCUMENT', 'documents', $documentId, null, [
        'trip_id' => $tripId,
        'source' => 'completed_trip_report',
    ]);
}

jsonOk([], 'Trip updated.');
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonFail($e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('update_trip_status: ' . $e->getMessage());
    jsonFail('Could not update the trip and its attachments.', 500);
}
