<?php
// ============================================================
// ajax/update_trip_status.php
// AJAX endpoint — moves a trip to its next operational status and logs a
// trip_updates row.
// Method : POST
// Params : trip_id, status, location_note, notes (+ optional delivery_receipt /
//          waybill files when completing)
// Returns: JSON { success: bool, message: string }
//
// Target transitions (see includes/trip_workflow.php for the step map):
//   Dispatched           -> In Progress   departure (needs clearance + passed
//                                         departure checks); truck On Trip
//   In Progress          -> Delivered     needs a progress update and the
//                                         delivery/return details (step 11)
//   Delivered / Returned -> Completed     needs the complete arrival
//                                         inspection (step 12); releases the
//                                         truck and crew
//   Approved .. Delivered -> Cancelled    reason required; releases the truck
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
require_once __DIR__ . '/../includes/trip_queries.php';
require_once __DIR__ . '/../includes/dispatch_assignment.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';

header('Content-Type: application/json');

requirePermission('trips.update');
requirePostMethod();
enforceCsrf();

$tripId   = requiredInt('trip_id', 'Trip ID', 1);
$status   = requiredEnum('status', ['In Progress', 'Delivered', 'Completed', 'Cancelled'], 'Status');
$location = optionalString('location_note', null, 255);
$notes    = optionalString('notes');

const TRIP_STATUS_TRANSITIONS = [
    'In Progress' => ['Dispatched'],
    'Delivered'   => ['In Progress'],
    'Completed'   => ['Delivered', 'Returned'],
    'Cancelled'   => ['Approved', 'Assigned', 'Ready for Dispatch', 'Dispatched', 'In Progress', 'Delivered'],
];
// trip_updates.update_type recorded for each new status.
const TRIP_STATUS_UPDATE_TYPES = [
    'In Progress' => 'Departure',
    'Delivered'   => 'Delivered',
    'Completed'   => 'Return',
    'Cancelled'   => 'Other',
];

$pdo = getDBConnection();
$requestKey = requestIdempotencyKey();
if ($requestKey === null) {
    jsonFail('Missing or invalid request idempotency key.', 400);
}

$uploadedDocuments = [];
try {
    $pdo->beginTransaction();
    if (!claimIdempotencyKey($pdo, 'trip.status.' . $tripId, $requestKey)) {
        $pdo->rollBack();
        jsonFail('This trip update has already been processed.', 409);
    }

    $tripRow = lockTripForWorkflow($pdo, $tripId);
    if (!$tripRow) {
        $pdo->rollBack();
        jsonFail('Trip not found.', 404);
    }
    // The trip owner is the dispatcher who submitted it
    // (dispatch_requests.submitted_by). A missing owner never matches, so
    // only an audited Admin override can proceed.
    $tripOwnerId = (int)($tripRow['requested_by'] ?? 0);
    $notTripOwner = $tripOwnerId <= 0 || $tripOwnerId !== currentUserId();
    if ($notTripOwner && !isAdmin()) {
        $pdo->rollBack();
        jsonFail('Only the dispatcher who submitted this trip can update its workflow.', 403);
    }
    // Dispatcher scope (truck category and shift) also applies here, so a
    // trip ID outside the scope is refused. Admin and Operations Head have
    // no dispatcher scope.
    $scopeViolation = dispatcherScopeViolation(
        (string)($tripRow['shift'] ?? ''),
        (string)($tripRow['truck_type'] ?? '')
    );
    if ($scopeViolation !== null) {
        $pdo->rollBack();
        jsonFail($scopeViolation, 403);
    }
    $ownerOverride = $notTripOwner;
    $oldStatus = (string)$tripRow['status'];
    if (!in_array($oldStatus, TRIP_STATUS_TRANSITIONS[$status], true)) {
        $pdo->rollBack();
        jsonFail('Trip status must follow the operational sequence. Refresh the page and try again.', 409);
    }
    if ($status === 'Cancelled' && !$notes) {
        $pdo->rollBack();
        jsonFail('Enter a cancellation reason before cancelling this trip.');
    }

    // The reason is required and tracking starts only after the routine
    // input checks above, before any workflow check or write.
    if ($ownerOverride) {
        $overrideBypassed = [
            'restriction'   => 'trip_owner',
            'owner_user_id' => $tripOwnerId,
        ];
        $overrideReason = adminOverrideReason();
        if ($overrideReason === null) {
            adminOverrideRefused('trips', $tripId, $overrideBypassed);
            jsonFail('An Admin override reason (1-500 characters) is required to update a trip submitted by another dispatcher.', 422);
        }
        adminOverrideBegin('trips', $tripId, $overrideBypassed, $overrideReason);
    }

    $currentStep = tripWorkflowCurrentStep($pdo, $tripId);
    $truckId = (int)($tripRow['truck_id'] ?? 0);
    $crewIds = array_values(array_filter([
        $tripRow['driver_id'], $tripRow['second_driver_id'], $tripRow['helper_id'],
    ], static fn($id) => $id !== null));

    if ($status === 'In Progress') {
        $clearance = $pdo->prepare(
            "SELECT 1 FROM dispatch_clearances WHERE trip_id = ? AND status = 'Cleared'"
        );
        $clearance->execute([$tripId]);
        if ($currentStep < 8 || !$clearance->fetchColumn()) {
            $pdo->rollBack();
            jsonFail('Operations Head dispatch clearance is required before the truck can leave.', 409);
        }
        if (!tripDepartureChecksPassed($pdo, $tripId)) {
            $pdo->rollBack();
            jsonFail('A complete departure vehicle inspection with every required item in good condition is required before the truck can leave.', 409);
        }
    }

    if ($status === 'Delivered') {
        $progress = $pdo->prepare(
            "SELECT 1 FROM trip_updates WHERE trip_id = ? AND update_type = 'In Transit' LIMIT 1"
        );
        $progress->execute([$tripId]);
        $delivery = $pdo->prepare('SELECT 1 FROM trip_deliveries WHERE trip_id = ?');
        $delivery->execute([$tripId]);
        if ($currentStep < 11 || !$progress->fetchColumn() || !$delivery->fetchColumn()) {
            $pdo->rollBack();
            jsonFail('Record trip progress and delivery/return details before marking the trip as delivered.', 409);
        }
    }

    if ($status === 'Completed') {
        if (!tripArrivalInspectionComplete($pdo, $tripId)) {
            $pdo->rollBack();
            jsonFail('A complete arrival vehicle inspection is required before completing this trip.', 409);
        }
        if ($currentStep < 12) {
            $pdo->rollBack();
            jsonFail('Complete the arrival checklist before submitting the completed trip record.', 409);
        }
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

    $extraSet = match ($status) {
        'In Progress' => ['departure_time = NOW()'],
        'Delivered'   => ['arrival_time = COALESCE(arrival_time, NOW())'],
        'Completed'   => ['completion_time = NOW()', 'return_time = COALESCE(return_time, NOW())'],
        default       => [],
    };
    transitionTripStatus($pdo, $tripId, [$oldStatus], $status, $extraSet);

    if ($status === 'In Progress') {
        if ($truckId > 0) {
            $truckUpdate = $pdo->prepare(
                "UPDATE trucks SET status = 'On Trip', availability_status = 'Unavailable' WHERE truck_id = ? AND status = 'Assigned'"
            );
            $truckUpdate->execute([$truckId]);
            if ($truckUpdate->rowCount() === 1) {
                recordTruckStatusHistory($pdo, $truckId, 'Assigned', 'On Trip', "Trip {$tripRow['trip_number']} departed.");
            }
            $pdo->prepare(
                "UPDATE truck_assignments SET assignment_status = 'Assigned'
                 WHERE trip_id = ? AND truck_id = ? AND assignment_status = 'Reserved'"
            )->execute([$tripId, $truckId]);
        }
        setCrewAvailability($pdo, $crewIds, 'Available', 'On Trip');
    }

    if ($status === 'Completed') {
        $completionReports = $pdo->prepare(
            "SELECT report_id
             FROM trip_completion_reports
             WHERE trip_id = ? AND status = 'Pending'
             FOR UPDATE"
        );
        $completionReports->execute([$tripId]);
        $acknowledge = $pdo->prepare(
            "UPDATE trip_completion_reports
             SET status = 'Acknowledged', reviewed_by = ?, reviewed_at = NOW(),
                 reviewer_note = 'Official trip completion recorded.'
             WHERE report_id = ? AND status = 'Pending'"
        );
        foreach ($completionReports->fetchAll(PDO::FETCH_COLUMN) as $reportId) {
            $acknowledge->execute([currentUserId(), (int)$reportId]);
            if ($acknowledge->rowCount() === 1) {
                auditLog(
                    'ACKNOWLEDGE_TRIP_COMPLETION_REPORT',
                    'trip_completion_reports',
                    (int)$reportId,
                    ['status' => 'Pending'],
                    ['status' => 'Acknowledged', 'reviewed_by' => currentUserId()]
                );
            }
        }
        if ($tripRow['booking_id'] !== null) {
            $pdo->prepare(
                "UPDATE client_bookings SET status = 'Completed' WHERE booking_id = ? AND status = 'Confirmed'"
            )->execute([(int)$tripRow['booking_id']]);
        }
    }

    if (in_array($status, ['Completed', 'Cancelled'], true)) {
        if ($truckId > 0) {
            $previous = releaseTruckForTrip($pdo, $truckId, $tripId, $status === 'Completed' ? 'Released' : 'Cancelled');
            if ($previous !== null) {
                recordTruckStatusHistory($pdo, $truckId, $previous, 'Available', "Trip {$tripRow['trip_number']} {$status}.");
            }
        }
        setCrewAvailability($pdo, $crewIds, 'On Trip', 'Available');
    }

    $description = $notes;
    if ($status === 'Cancelled') {
        $description = 'Trip cancelled: ' . $notes;
    }
    $pdo->prepare(
        'INSERT INTO trip_updates (trip_id, update_type, description, location_text, reported_by)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$tripId, TRIP_STATUS_UPDATE_TYPES[$status], $description, $location, currentUserId()]);

    if ($status === 'In Progress') {
        recordTripWorkflowEvent($pdo, $tripId, 9, 'truck-dispatched', currentUserId(),
            'Truck dispatched after Operations Head clearance.', $oldStatus, $status);
    } elseif ($status === 'Completed') {
        recordTripWorkflowEvent($pdo, $tripId, 13, 'completed-record-submitted', currentUserId(),
            'Dispatcher submitted the completed trip record.', $oldStatus, $status);
    } elseif ($status === 'Cancelled') {
        recordTripWorkflowEvent($pdo, $tripId, 0, 'trip-cancelled', currentUserId(),
            'Trip cancelled: ' . $notes, $oldStatus, $status);
    } else {
        recordWorkflowEvent($pdo, 'trip', $tripId, 'status-change', $oldStatus, $status, $notes, currentUserId());
    }

    auditLog('UPDATE', 'trips', $tripId, ['status' => $oldStatus], ['status' => $status]);
    foreach ($uploadedDocuments as $documentId) {
        auditLog('UPLOAD_DOCUMENT', 'documents', $documentId, null, [
            'trip_id' => $tripId,
            'source'  => 'completed_trip_report',
        ]);
    }
    if ($ownerOverride) {
        auditAdminOverride([
            'action'     => 'update_trip_status',
            'old_status' => $oldStatus,
            'new_status' => $status,
        ]);
    }
    $pdo->commit();
    if ($ownerOverride) adminOverrideSucceeded();

    jsonOk([], 'Trip updated.');
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonFail($e->getMessage());
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonFail($e->getMessage(), 409);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('update_trip_status: ' . $e->getMessage());
    jsonFail('Could not update the trip and its attachments.', 500);
}
