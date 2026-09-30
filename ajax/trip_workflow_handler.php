<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/idempotency.php';
require_once __DIR__ . '/../includes/trip_workflow.php';

header('Content-Type: application/json');

requirePostMethod();
enforceCsrf();

$action = requiredEnum(
    'action',
    ['confirm_assignment', 'prepare_departure', 'departure_clearance', 'record_progress', 'save_delivery_return'],
    'Workflow action'
);
$permissionByAction = [
    'confirm_assignment' => 'trips.assign',
    'prepare_departure' => 'trips.assign',
    'departure_clearance' => 'dispatch.clear',
    'record_progress' => 'trips.update',
    'save_delivery_return' => 'trips.update',
];
requirePermission($permissionByAction[$action]);

$tripId = requiredInt('trip_id', 'Trip', 1);
$pdo = getDBConnection();
$requestKey = requestIdempotencyKey();
if ($requestKey === null) {
    jsonFail('Missing or invalid request idempotency key.', 400);
}

try {
    $pdo->beginTransaction();
    if (!claimIdempotencyKey($pdo, 'trip.workflow.' . $action . '.' . $tripId, $requestKey)) {
        $pdo->rollBack();
        jsonFail('This trip workflow action has already been processed.', 409);
    }

    $tripQuery = $pdo->prepare(
        'SELECT t.trip_id, t.dispatch_id, t.trip_number, t.status,
                dr.requested_by, dr.truck_id
         FROM trips t
         JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
         WHERE t.trip_id = ?
         FOR UPDATE'
    );
    $tripQuery->execute([$tripId]);
    $trip = $tripQuery->fetch(PDO::FETCH_ASSOC);
    if (!$trip) {
        $pdo->rollBack();
        jsonFail('Trip not found.', 404);
    }

    $stateQuery = $pdo->prepare(
        'SELECT * FROM trip_workflow_state WHERE dispatch_id = ? FOR UPDATE'
    );
    $stateQuery->execute([(int)$trip['dispatch_id']]);
    $state = $stateQuery->fetch(PDO::FETCH_ASSOC);
    if (!$state) {
        $pdo->rollBack();
        jsonFail('This trip predates the tracked workflow and cannot use these workflow actions.', 409);
    }
    if ($action !== 'departure_clearance'
        && currentRoleId() !== ROLE_ADMIN
        && (int)$trip['requested_by'] !== currentUserId()) {
        $pdo->rollBack();
        jsonFail('Only the dispatcher who submitted this trip can complete this workflow action.', 403);
    }
    if ($trip['status'] !== 'Loading' && in_array($action, ['confirm_assignment', 'prepare_departure', 'departure_clearance'], true)) {
        $pdo->rollBack();
        jsonFail('This trip is no longer awaiting departure preparation.', 409);
    }

    if ($action === 'confirm_assignment') {
        if ((int)$state['current_step'] !== 4 || $state['assignment_confirmed_at'] !== null) {
            $pdo->rollBack();
            jsonFail('The assignment can only be confirmed once after Operations Head approval.', 409);
        }
        $pdo->prepare(
            'UPDATE trip_workflow_state
             SET assignment_confirmed_by = ?, assignment_confirmed_at = NOW()
             WHERE dispatch_id = ?'
        )->execute([currentUserId(), (int)$trip['dispatch_id']]);
        recordTripWorkflowEvent(
            $pdo,
            (int)$trip['dispatch_id'],
            $tripId,
            5,
            'assignment-confirmed',
            currentUserId(),
            'Dispatcher confirmed the truck and driver assignment after approval.'
        );
        $message = 'Truck and driver assignment confirmed.';
        $auditAction = 'CONFIRM_TRIP_ASSIGNMENT';
        $auditDetails = ['dispatch_id' => (int)$trip['dispatch_id']];
    } elseif ($action === 'prepare_departure') {
        if ((int)$state['current_step'] !== 5 || $state['assignment_confirmed_at'] === null) {
            $pdo->rollBack();
            jsonFail('Confirm the approved truck and driver assignment before preparing departure.', 409);
        }

        $documentsStatus = requiredEnum('documents_status', ['Prepared', 'Not Required'], 'Document packet status');
        $documentsNotes = optionalString('documents_notes', null, 2000);
        if ($documentsStatus === 'Prepared') {
            $documentCount = $pdo->prepare(
                "SELECT COUNT(*) FROM documents
                 WHERE trip_id = ? AND visibility_scope IN ('all', 'operations')"
            );
            $documentCount->execute([$tripId]);
            if ((int)$documentCount->fetchColumn() < 1) {
                $pdo->rollBack();
                jsonFail('Upload at least one trip document before marking the packet prepared.');
            }
        } elseif ($documentsNotes === null || trim($documentsNotes) === '') {
            $pdo->rollBack();
            jsonFail('State why no additional trip documents are required.');
        }

        $allowanceStatus = requiredEnum('allowance_status', ['Approved', 'Not Required'], 'Allowance status');
        $allowanceNotes = optionalString('allowance_notes', null, 2000);
        $fundRequestId = null;
        if ($allowanceStatus === 'Approved') {
            $fundRequestId = requiredInt('fund_request_id', 'Approved Trip Allowance request', 1);
            $fundRequest = $pdo->prepare(
                "SELECT fund_request_id
                 FROM fund_requests
                 WHERE fund_request_id = ?
                   AND trip_id = ?
                   AND request_type = 'Trip Allowance'
                   AND status IN ('Approved', 'Disbursed')
                 FOR UPDATE"
            );
            $fundRequest->execute([$fundRequestId, $tripId]);
            if (!$fundRequest->fetchColumn()) {
                $pdo->rollBack();
                jsonFail('Select an approved or disbursed Trip Allowance request linked to this trip.');
            }
        } elseif ($allowanceNotes === null || trim($allowanceNotes) === '') {
            $pdo->rollBack();
            jsonFail('State why a trip allowance is not required.');
        }

        $pdo->prepare(
            'UPDATE trip_workflow_state
             SET documents_status = ?, documents_notes = ?,
                 allowance_status = ?, allowance_fund_request_id = ?,
                 allowance_notes = ?, prepared_by = ?, prepared_at = NOW()
             WHERE dispatch_id = ?'
        )->execute([
            $documentsStatus,
            $documentsNotes,
            $allowanceStatus,
            $fundRequestId,
            $allowanceNotes,
            currentUserId(),
            (int)$trip['dispatch_id'],
        ]);
        recordTripWorkflowEvent(
            $pdo,
            (int)$trip['dispatch_id'],
            $tripId,
            6,
            'departure-packet-prepared',
            currentUserId(),
            'Documents: ' . $documentsStatus
                . ($documentsNotes ? ' — ' . $documentsNotes : '')
                . '. Allowance: ' . $allowanceStatus
                . ($allowanceNotes ? ' — ' . $allowanceNotes : '')
                . '.'
        );
        $message = 'Trip documents and allowance requirements recorded.';
        $auditAction = 'PREPARE_TRIP_DEPARTURE';
        $auditDetails = [
            'documents_status' => $documentsStatus,
            'allowance_status' => $allowanceStatus,
            'fund_request_id' => $fundRequestId,
        ];
    } elseif ($action === 'departure_clearance') {
        if ((int)$state['current_step'] < 7 || $state['prepared_at'] === null) {
            $pdo->rollBack();
            jsonFail('A passed pre-departure checklist and complete departure inspection are required before clearance.', 409);
        }

        $checklist = $pdo->prepare(
            "SELECT 1 FROM maintenance_checklists
             WHERE dispatch_id = ? AND result = 'Passed' LIMIT 1"
        );
        $checklist->execute([(int)$trip['dispatch_id']]);
        $inspection = $pdo->prepare(
            "SELECT COUNT(*) AS finding_count,
                    SUM(vif.`condition` <> 'Good') AS non_good_count
             FROM vehicle_inspections vi
             JOIN vehicle_inspection_findings vif ON vif.inspection_id = vi.inspection_id
             WHERE vi.trip_id = ? AND vi.inspection_stage = 'Departure'"
        );
        $inspection->execute([$tripId]);
        $inspectionResult = $inspection->fetch(PDO::FETCH_ASSOC);
        if (!$checklist->fetchColumn() || !$inspectionResult
            || (int)$inspectionResult['finding_count'] < 24
            || (int)($inspectionResult['non_good_count'] ?? 0) !== 0) {
            $pdo->rollBack();
            jsonFail('Dispatch clearance is blocked until both required departure checks pass.', 409);
        }

        $clearanceNotes = optionalString('clearance_notes', null, 2000);
        $pdo->prepare(
            'UPDATE trip_workflow_state
             SET clearance_by = ?, clearance_at = NOW(), clearance_notes = ?
             WHERE dispatch_id = ?'
        )->execute([currentUserId(), $clearanceNotes, (int)$trip['dispatch_id']]);
        recordTripWorkflowEvent(
            $pdo,
            (int)$trip['dispatch_id'],
            $tripId,
            8,
            'operations-dispatch-clearance',
            currentUserId(),
            $clearanceNotes ?: 'Operations Head provided dispatch clearance.'
        );
        $message = 'Dispatch clearance recorded.';
        $auditAction = 'CLEAR_TRIP_FOR_DISPATCH';
        $auditDetails = ['dispatch_id' => (int)$trip['dispatch_id']];
    } elseif ($action === 'record_progress') {
        if ($trip['status'] !== 'In Transit' || (int)$state['current_step'] < 9) {
            $pdo->rollBack();
            jsonFail('Progress updates can be recorded after the truck has been dispatched.', 409);
        }
        $location = optionalString('location_note', null, 255);
        $notes = optionalString('notes', null, 4000);
        if (($location === null || trim($location) === '')
            && ($notes === null || trim($notes) === '')) {
            $pdo->rollBack();
            jsonFail('Enter a current location or progress update.');
        }
        $pdo->prepare(
            "INSERT INTO trip_updates (trip_id, updated_by, status, location_note, notes)
             VALUES (?, ?, 'In Transit', ?, ?)"
        )->execute([$tripId, currentUserId(), $location, $notes]);
        $updateId = (int)$pdo->lastInsertId();
        recordTripWorkflowEvent(
            $pdo,
            (int)$trip['dispatch_id'],
            $tripId,
            10,
            'progress-update-' . $updateId,
            currentUserId(),
            'Progress update #' . $updateId
                . ($location ? ' — Location: ' . $location : '')
                . ($notes ? ' — ' . $notes : '')
        );
        $message = 'Trip progress update recorded.';
        $auditAction = 'RECORD_TRIP_PROGRESS';
        $auditDetails = ['update_id' => $updateId];
    } else {
        if ($trip['status'] !== 'In Transit' || (int)$state['current_step'] < 10) {
            $pdo->rollBack();
            jsonFail('Enter at least one trip progress update before recording delivery and return details.', 409);
        }
        $progress = $pdo->prepare(
            'SELECT 1 FROM trip_workflow_events
             WHERE dispatch_id = ? AND step_number = 10 LIMIT 1'
        );
        $progress->execute([(int)$trip['dispatch_id']]);
        if (!$progress->fetchColumn()) {
            $pdo->rollBack();
            jsonFail('Enter at least one trip progress update before recording delivery and return details.', 409);
        }

        $deliveryNotes = requiredString('delivery_notes', 'Delivery details', 4000);
        $returnNotes = requiredString('return_notes', 'Return details', 4000);
        $returnLocation = optionalString('return_location', null, 255);
        $receiptNumber = optionalString('delivery_receipt_number', null, 100);
        $sharedWaybill = optionalString('shared_waybill_reference', null, 100);
        $coLoadReference = optionalString('co_load_reference', null, 100);
        $unitCountRaw = trim((string)($_POST['delivered_unit_count'] ?? ''));
        $unitCount = null;
        if ($unitCountRaw !== '') {
            if (!is_numeric($unitCountRaw) || (float)$unitCountRaw < 0 || (float)$unitCountRaw > 1000000) {
                $pdo->rollBack();
                jsonFail('Delivered unit count must be a number from 0 to 1,000,000.');
            }
            $unitCount = (float)$unitCountRaw;
        }

        $pdo->prepare(
            'INSERT INTO trip_delivery_return_details
                (trip_id, dispatch_id, delivered_unit_count, delivery_receipt_number,
                 shared_waybill_reference, co_load_reference, delivery_notes,
                 return_location, return_notes, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                delivered_unit_count = VALUES(delivered_unit_count),
                delivery_receipt_number = VALUES(delivery_receipt_number),
                shared_waybill_reference = VALUES(shared_waybill_reference),
                co_load_reference = VALUES(co_load_reference),
                delivery_notes = VALUES(delivery_notes),
                return_location = VALUES(return_location),
                return_notes = VALUES(return_notes),
                recorded_by = VALUES(recorded_by)'
        )->execute([
            $tripId,
            (int)$trip['dispatch_id'],
            $unitCount,
            $receiptNumber,
            $sharedWaybill,
            $coLoadReference,
            $deliveryNotes,
            $returnLocation,
            $returnNotes,
            currentUserId(),
        ]);
        recordTripWorkflowEvent(
            $pdo,
            (int)$trip['dispatch_id'],
            $tripId,
            11,
            'delivery-return-details-recorded',
            currentUserId(),
            'Delivery and return details recorded.'
        );
        $message = 'Delivery and return details recorded.';
        $auditAction = 'RECORD_TRIP_DELIVERY_RETURN';
        $auditDetails = [
            'delivered_unit_count' => $unitCount,
            'has_shared_waybill' => $sharedWaybill !== null,
            'has_co_load_reference' => $coLoadReference !== null,
        ];
    }

    $pdo->commit();
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonFail($e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('trip_workflow_handler: ' . $e->getMessage());
    jsonFail('Could not save the trip workflow action.', 500);
}

auditLog($auditAction, 'trips', $tripId, null, $auditDetails);
jsonOk([], $message);
