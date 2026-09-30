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
require_once __DIR__ . '/../includes/idempotency.php';
require_once __DIR__ . '/../includes/truck_status_history.php';
require_once __DIR__ . '/../includes/trip_workflow.php';

header('Content-Type: application/json');

requirePermission('trips.update');
requirePostMethod();
enforceCsrf();

$tripId   = requiredInt('trip_id', 'Trip ID', 1);
$status   = requiredEnum('status', TRIP_STATUSES, 'Status');
$location = optionalString('location_note');
$notes    = optionalString('notes');

$pdo = getDBConnection();
$requestKey = requestIdempotencyKey();
if ($requestKey === null) {
    jsonFail('Missing or invalid request idempotency key.', 400);
}

try {
    $pdo->beginTransaction();
    if (!claimIdempotencyKey($pdo, 'trip.status.' . $tripId, $requestKey)) {
        $pdo->rollBack();
        jsonFail('This trip update has already been processed.', 409);
    }

    $tripQuery = $pdo->prepare(
        'SELECT t.trip_id, t.dispatch_id, t.trip_number, t.status,
                dr.truck_id, dr.requested_by
         FROM trips t
         JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
         WHERE t.trip_id = ?
         FOR UPDATE'
    );
    $tripQuery->execute([$tripId]);
    $tripRow = $tripQuery->fetch(PDO::FETCH_ASSOC);
    if (!$tripRow) {
        $pdo->rollBack();
        jsonFail('Trip not found.', 404);
    }
    $workflowQuery = $pdo->prepare(
        'SELECT current_step, clearance_by
         FROM trip_workflow_state
         WHERE dispatch_id = ?
         FOR UPDATE'
    );
    $workflowQuery->execute([(int)$tripRow['dispatch_id']]);
    $workflowState = $workflowQuery->fetch(PDO::FETCH_ASSOC) ?: null;
    $isTrackedWorkflow = $workflowState !== null;
    if ($isTrackedWorkflow
        && currentRoleId() !== ROLE_ADMIN
        && (int)$tripRow['requested_by'] !== currentUserId()) {
        $pdo->rollBack();
        jsonFail('Only the dispatcher who submitted this trip can update its workflow.', 403);
    }
    $oldStatus = $tripRow['status'];
    $transitions = [
        'Loading' => ['In Transit', 'Cancelled'],
        'In Transit' => ['Unloading', 'Cancelled'],
        'Unloading' => ['Completed', 'Cancelled'],
    ];
    if (!in_array($status, $transitions[$oldStatus] ?? [], true)) {
        $pdo->rollBack();
        jsonFail('Trip status must follow the operational sequence. Refresh the page and try again.', 409);
    }
    if ($status === 'Cancelled' && !$notes) {
        $pdo->rollBack();
        jsonFail('Enter a cancellation reason before cancelling this trip.');
    }

    $uploadedDocuments = [];
    if ($status === 'In Transit') {
        if ($isTrackedWorkflow
            && ((int)$workflowState['current_step'] !== 8 || empty($workflowState['clearance_by']))) {
            $pdo->rollBack();
            jsonFail('Operations Head dispatch clearance is required before the truck can leave.', 409);
        }
        $departureInspection = $pdo->prepare(
            "SELECT COUNT(*) AS finding_count,
                    SUM(`condition` <> 'Good') AS non_good_count
             FROM vehicle_inspections vi
             JOIN vehicle_inspection_findings vif ON vif.inspection_id = vi.inspection_id
             WHERE vi.trip_id = ? AND vi.inspection_stage = 'Departure'"
        );
        $departureInspection->execute([$tripId]);
        $inspectionResult = $departureInspection->fetch(PDO::FETCH_ASSOC);
        if (!$inspectionResult || (int)$inspectionResult['finding_count'] < 24
            || (int)($inspectionResult['non_good_count'] ?? 0) !== 0) {
            $pdo->rollBack();
            jsonFail('A complete departure vehicle inspection with all standard parts in good condition is required before the truck can leave.', 409);
        }

        $clearance = $pdo->prepare(
            "SELECT result FROM maintenance_checklists
             WHERE dispatch_id = ? AND result = 'Passed'
             ORDER BY submitted_at DESC LIMIT 1"
        );
        $clearance->execute([(int)$tripRow['dispatch_id']]);
        if (!$clearance->fetchColumn()) {
            $pdo->rollBack();
            jsonFail('A passed pre-departure vehicle clearance is required before the truck can leave.', 409);
        }
    }

    if ($status === 'Unloading' && $isTrackedWorkflow) {
        $progress = $pdo->prepare(
            "SELECT 1 FROM trip_workflow_events
             WHERE dispatch_id = ? AND step_number = 10
             LIMIT 1"
        );
        $progress->execute([(int)$tripRow['dispatch_id']]);
        $delivery = $pdo->prepare(
            'SELECT 1 FROM trip_delivery_return_details WHERE trip_id = ?'
        );
        $delivery->execute([$tripId]);
        if ((int)$workflowState['current_step'] < 11
            || !$progress->fetchColumn()
            || !$delivery->fetchColumn()) {
            $pdo->rollBack();
            jsonFail('Record trip progress and delivery/return details before marking the trip as unloading.', 409);
        }
    }

    if ($status === 'Completed') {
        $returnInspection = $pdo->prepare(
            "SELECT COUNT(*) AS finding_count
             FROM vehicle_inspections vi
             JOIN vehicle_inspection_findings vif ON vif.inspection_id = vi.inspection_id
             WHERE vi.trip_id = ? AND vi.inspection_stage = 'Return'"
        );
        $returnInspection->execute([$tripId]);
        if ((int)$returnInspection->fetchColumn() < 24) {
            $pdo->rollBack();
            jsonFail('A complete return vehicle inspection is required before completing this trip.', 409);
        }
        if ($isTrackedWorkflow && (int)$workflowState['current_step'] < 12) {
            $pdo->rollBack();
            jsonFail('Complete the arrival checklist before submitting the completed trip record.', 409);
        }
    }

    if ($status === 'Completed') {
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
                    'Completed trip report attachment for ' . $tripRow['trip_number'],
                    'operations',
                    currentUserId()
                );
            }
        }
    }

    $departureSql = $status === 'In Transit' ? ', actual_departure_at = NOW()' : '';
    $arrivalSql = $status === 'Completed' ? ', actual_arrival = NOW()' : '';
    $pdo->prepare(
        "UPDATE trips
            SET status = :status,
                is_late = IF(expected_arrival < NOW() AND :status2 NOT IN ('Completed','Cancelled'), 1, 0)
                {$departureSql}{$arrivalSql}
          WHERE trip_id = :id"
    )->execute([':status' => $status, ':status2' => $status, ':id' => $tripId]);

    if (in_array($status, ['Completed', 'Cancelled'], true)) {
        $truckLock = $pdo->prepare('SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE');
        $truckLock->execute([(int)$tripRow['truck_id']]);
        $truckStatus = $truckLock->fetchColumn();
        if ($truckStatus === 'Deployed') {
            $pdo->prepare("UPDATE trucks SET status = 'Available' WHERE truck_id = ?")
                ->execute([(int)$tripRow['truck_id']]);
            recordTruckStatusHistory(
                $pdo,
                (int)$tripRow['truck_id'],
                'Deployed',
                'Available',
                "Trip {$tripRow['trip_number']} {$status}."
            );
        }
    }

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

    if ($isTrackedWorkflow) {
        if ($status === 'In Transit') {
            recordTripWorkflowEvent(
                $pdo,
                (int)$tripRow['dispatch_id'],
                $tripId,
                9,
                'truck-dispatched',
                currentUserId(),
                'Truck dispatched after Operations Head clearance.'
            );
        } elseif ($status === 'Completed') {
            recordTripWorkflowEvent(
                $pdo,
                (int)$tripRow['dispatch_id'],
                $tripId,
                13,
                'completed-record-submitted',
                currentUserId(),
                'Dispatcher submitted the completed trip record.'
            );
        } elseif ($status === 'Cancelled') {
            recordTripWorkflowEvent(
                $pdo,
                (int)$tripRow['dispatch_id'],
                $tripId,
                0,
                'trip-cancelled',
                currentUserId(),
                'Trip cancelled: ' . $notes
            );
        }
    }

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
