<?php
// ============================================================
// ajax/trip_workflow_handler.php
// Trip workflow steps 5-11 on the target schema (step map in
// includes/trip_workflow.php):
//   confirm_assignment   Approved -> Assigned; truck assignment 'Assigned'
//   prepare_departure    attachment count + allowance recorded (step 6); may move
//                        to Ready for Dispatch when departure checks passed
//   departure_clearance  Ready for Dispatch -> Dispatched; dispatch_clearances
//   record_progress      trip_updates 'In Transit' (step 10)
//   save_delivery_return trip_deliveries + trip_returns (step 11)
//   reassign_resources   truck / crew / schedule change before confirmation
// The trip row is locked first, so all workflow writes for one trip are
// serialized; every status change is conditional (transitionTripStatus).
// ============================================================

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../app/core/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/shared/validate.php';
require_once __DIR__ . '/../app/core/idempotency.php';
require_once __DIR__ . '/../app/modules/trips/trip_workflow.php';
require_once __DIR__ . '/../app/modules/trips/trip_queries.php';
require_once __DIR__ . '/../app/modules/trips/dispatch_assignment.php';
require_once __DIR__ . '/../app/modules/trips/dispatcher_scope.php';
require_once __DIR__ . '/../app/modules/fleet/truck_status_history.php';
require_once __DIR__ . '/../app/core/workflow_notifications.php';

header('Content-Type: application/json');

requirePostMethod();
enforceCsrf();

$action = requiredEnum(
    'action',
    ['confirm_assignment', 'prepare_departure', 'departure_clearance', 'record_progress', 'save_delivery_return', 'reassign_resources'],
    'Workflow action'
);
$permissionByAction = [
    'confirm_assignment' => 'trips.assign',
    'prepare_departure' => 'trips.assign',
    'departure_clearance' => 'dispatch.clear',
    'record_progress' => 'trips.update',
    'save_delivery_return' => 'trips.update',
    'reassign_resources' => 'trips.assign',
];
requirePermission($permissionByAction[$action]);

$tripId = requiredInt('trip_id', 'Trip', 1);
$pdo = getDBConnection();
$requestKey = requestIdempotencyKey();
if ($requestKey === null) {
    jsonFail('Missing or invalid request idempotency key.', 400);
}

// Pre-departure statuses: the legacy single 'Loading' status.
const TRIP_PRE_DEPARTURE_STATUSES = ['Approved', 'Assigned', 'Ready for Dispatch'];

function workflowDateTime(string $raw, string $label): DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
        throw new InvalidArgumentException($label . ' must be a valid date and time.');
    }
    return $date;
}

try {
    $pdo->beginTransaction();
    if (!claimIdempotencyKey($pdo, 'trip.workflow.' . $action . '.' . $tripId, $requestKey)) {
        $pdo->rollBack();
        jsonFail('This trip workflow action has already been processed.', 409);
    }

    $trip = lockTripForWorkflow($pdo, $tripId);
    if (!$trip) {
        $pdo->rollBack();
        jsonFail('Trip not found.', 404);
    }
    if ($trip['dispatch_id'] === null) {
        $pdo->rollBack();
        jsonFail('This trip has no dispatch request and cannot use these workflow actions.', 409);
    }
    $currentStep = tripWorkflowCurrentStep($pdo, $tripId);

    // Departure clearance has no owner check (it is the Operations Head's
    // step, granted by dispatch.clear) and is never an Admin override.
    $notTripOwner = $action !== 'departure_clearance'
        && (int)$trip['requested_by'] !== currentUserId();
    if ($notTripOwner && !isAdmin()) {
        $pdo->rollBack();
        jsonFail('Only the dispatcher who submitted this trip can complete this workflow action.', 403);
    }
    // Dispatcher scope (truck category and shift) applies to every workflow
    // action on this trip, so a trip ID outside the scope is refused.
    // reassign_resources also checks the destination truck below.
    $scopeViolation = dispatcherScopeViolation(
        (string)($trip['shift'] ?? ''),
        (string)($trip['truck_type'] ?? '')
    );
    if ($scopeViolation !== null) {
        $pdo->rollBack();
        jsonFail($scopeViolation, 403);
    }
    $ownerOverride = $notTripOwner;
    if (in_array($action, ['confirm_assignment', 'prepare_departure', 'departure_clearance', 'reassign_resources'], true)
        && !in_array($trip['status'], TRIP_PRE_DEPARTURE_STATUSES, true)) {
        $pdo->rollBack();
        jsonFail('This trip is no longer awaiting departure preparation.', 409);
    }
    if ($action === 'reassign_resources' && tripWorkflowEventExists($pdo, $tripId, 'assignment-confirmed')) {
        $pdo->rollBack();
        jsonFail('The assignment is already confirmed and can no longer be changed here.', 409);
    }
    // Admin acting on another dispatcher's trip is an override. Tracking
    // starts after the shared state checks above. Per-action input checks
    // below still end a tracked request (recorded as ADMIN_OVERRIDE_FAILED).
    if ($ownerOverride) {
        $overrideBypassed = [
            'restriction'   => 'trip_owner',
            'owner_user_id' => (int)$trip['requested_by'],
        ];
        $overrideReason = adminOverrideReason();
        if ($overrideReason === null) {
            adminOverrideRefused('trips', $tripId, $overrideBypassed);
            jsonFail('An Admin override reason (1-500 characters) is required to act on a trip submitted by another dispatcher.', 422);
        }
        adminOverrideBegin('trips', $tripId, $overrideBypassed, $overrideReason);
    }

    if ($action === 'reassign_resources') {
        if ($trip['status'] !== 'Approved') {
            throw new DomainException('Resources can be changed only after approval and before the assignment is confirmed.');
        }
        $newTruckId = requiredInt('truck_id', 'Truck', 1);
        $newDriverId = requiredInt('driver_id', 'Driver', 1);
        $newSecondDriverId = filter_input(INPUT_POST, 'second_driver_id', FILTER_VALIDATE_INT) ?: null;
        $newHelperId = filter_input(INPUT_POST, 'helper_id', FILTER_VALIDATE_INT) ?: null;
        $scheduledDate = workflowDateTime(requiredString('scheduled_at', 'Scheduled date/time'), 'Scheduled date');
        $expectedArrivalRaw = trim($_POST['expected_arrival'] ?? '');
        $reassignReason = requiredString('reassign_reason', 'Reason for the change', 500);
        $expectedArrival = null;
        if ($expectedArrivalRaw !== '') {
            $expectedArrivalDate = workflowDateTime($expectedArrivalRaw, 'Expected arrival');
            if ($expectedArrivalDate <= $scheduledDate) {
                throw new InvalidArgumentException('Expected arrival must be later than scheduled departure.');
            }
            $expectedArrival = $expectedArrivalDate->format('Y-m-d H:i:s');
        }

        // Dispatcher scope applies to the destination truck, the trip's shift
        // and the new scheduled date, before any resource is locked or
        // changed. Admin and Operations Head have no dispatcher scope.
        $truckTypeQuery = $pdo->prepare('SELECT truck_type FROM trucks WHERE truck_id = ?');
        $truckTypeQuery->execute([$newTruckId]);
        $newTruckType = $truckTypeQuery->fetchColumn();
        if ($newTruckType === false) {
            throw new DomainException('Selected truck is no longer available.');
        }
        if ($newTruckType !== $trip['trip_type']) {
            throw new DomainException('The replacement truck must be a ' . $trip['trip_type'] . '.');
        }
        $scopeViolation = dispatcherScopeViolation(
            (string)($trip['shift'] ?? ''),
            (string)$newTruckType,
            $scheduledDate->format('Y-m-d')
        );
        if ($scopeViolation !== null) {
            $pdo->rollBack();
            jsonFail($scopeViolation, 403);
        }

        $oldTruckId = (int)$trip['truck_id'];
        $truckChanged = $newTruckId !== $oldTruckId;
        $crewIds = array_values(array_filter([$newDriverId, $newSecondDriverId, $newHelperId]));
        if (count($crewIds) !== count(array_unique($crewIds))) {
            throw new InvalidArgumentException('Each assigned crew member must be different.');
        }
        // A new truck must be Available; the current truck is already held
        // ('Assigned') by this trip, so only its lock is taken.
        lockDispatchResources($pdo, $newTruckId, $crewIds, $truckChanged ? 'Available' : null);
        $license = $pdo->prepare('SELECT license_number FROM employees WHERE employee_id = ?');
        foreach (array_filter([$newDriverId, $newSecondDriverId]) as $driverCheckId) {
            $license->execute([$driverCheckId]);
            if (!$license->fetchColumn()) {
                throw new DomainException('Every driver on the trip needs a valid license.');
            }
        }
        assertDispatchResourcesAvailable(
            $pdo,
            $newTruckId,
            $crewIds,
            $scheduledDate->format('Y-m-d H:i:s'),
            $tripId
        );

        if ($truckChanged) {
            $previous = releaseTruckForTrip($pdo, $oldTruckId, $tripId, 'Cancelled');
            if ($previous !== null) {
                recordTruckStatusHistory($pdo, $oldTruckId, $previous, 'Available', "Trip {$trip['trip_number']} reassigned to another truck.");
            }
        }
        $pdo->prepare(
            'UPDATE trips
             SET truck_id = ?, driver_id = ?, second_driver_id = ?, helper_id = ?,
                 scheduled_departure = ?, scheduled_arrival = ?
             WHERE trip_id = ?'
        )->execute([
            $newTruckId,
            $newDriverId,
            $newSecondDriverId,
            $newHelperId,
            $scheduledDate->format('Y-m-d H:i:s'),
            $expectedArrival,
            $tripId,
        ]);
        if ($truckChanged) {
            reserveTruckForTrip($pdo, $newTruckId, $tripId, $scheduledDate->format('Y-m-d H:i:s'), $expectedArrival);
            recordTruckStatusHistory($pdo, $newTruckId, 'Available', 'Assigned', "Truck reserved for {$trip['trip_number']} (reassignment).");
        } else {
            $pdo->prepare(
                "UPDATE truck_assignments SET assigned_from = ?, assigned_until = ?
                 WHERE trip_id = ? AND truck_id = ? AND assignment_status IN ('Reserved', 'Assigned')"
            )->execute([$scheduledDate->format('Y-m-d H:i:s'), $expectedArrival, $tripId, $newTruckId]);
        }

        $changeSummary = [];
        if ($truckChanged) $changeSummary[] = "truck #$oldTruckId → #$newTruckId";
        if ((int)$trip['driver_id'] !== $newDriverId) $changeSummary[] = 'driver changed';
        if ((int)($trip['second_driver_id'] ?? 0) !== (int)($newSecondDriverId ?? 0)) $changeSummary[] = 'second driver changed';
        if ((int)($trip['helper_id'] ?? 0) !== (int)($newHelperId ?? 0)) $changeSummary[] = 'helper changed';
        if ($trip['scheduled_at'] !== $scheduledDate->format('Y-m-d H:i:s')) $changeSummary[] = 'schedule changed';
        recordWorkflowEvent($pdo, 'trip', $tripId, 'resources-reassigned', null, null,
            implode('; ', $changeSummary) . ' — ' . $reassignReason, currentUserId());

        $message = 'Assignment and schedule updated.';
        $auditAction = 'REASSIGN_TRIP_RESOURCES';
        $auditDetails = [
            'dispatch_id' => (int)$trip['dispatch_id'],
            'changes'     => $changeSummary,
            'reason'      => $reassignReason,
        ];
    } elseif ($action === 'confirm_assignment') {
        if ($trip['status'] !== 'Approved' || $currentStep !== 4) {
            throw new DomainException('The assignment can only be confirmed once after Operations Head approval.');
        }
        transitionTripStatus($pdo, $tripId, ['Approved'], 'Assigned');
        $pdo->prepare(
            "UPDATE truck_assignments SET assignment_status = 'Assigned'
             WHERE trip_id = ? AND truck_id = ? AND assignment_status = 'Reserved'"
        )->execute([$tripId, (int)$trip['truck_id']]);
        recordTripWorkflowEvent(
            $pdo,
            $tripId,
            5,
            'assignment-confirmed',
            currentUserId(),
            'Dispatcher confirmed the truck and driver assignment after approval.',
            'Approved',
            'Assigned'
        );
        $message = 'Truck and driver assignment confirmed.';
        $auditAction = 'CONFIRM_TRIP_ASSIGNMENT';
        $auditDetails = ['dispatch_id' => (int)$trip['dispatch_id']];
    } elseif ($action === 'prepare_departure') {
        if ($trip['status'] !== 'Assigned' || $currentStep !== 5) {
            throw new DomainException('Confirm the approved truck and driver assignment before preparing departure.');
        }

        // Interim policy: no pre-departure document is mandatory, so trip
        // attachments are optional. Step 6 only records how many are linked
        // (the same documents the Step 6 card lists); it does not claim that
        // any document requirement was met.
        $attachmentCountQuery = $pdo->prepare(
            "SELECT COUNT(*)
             FROM document_links dl
             JOIN documents d ON d.document_id = dl.document_id
             WHERE dl.entity_type = 'trip' AND dl.entity_id = ?
               AND COALESCE(d.visibility_scope, 'all') IN ('all', 'operations')"
        );
        $attachmentCountQuery->execute([$tripId]);
        $attachmentCount = (int)$attachmentCountQuery->fetchColumn();

        // Driver Allowance stays a fund request (Trip Allowance), separate
        // from Trip Pay (trip_pay_records).
        $allowanceStatus = requiredEnum('allowance_status', ['Approved', 'Not Required'], 'Allowance status');
        $allowanceNotes = optionalString('allowance_notes', null, 2000);
        $fundRequestId = null;
        if ($allowanceStatus === 'Not Required') {
            $activeAllowance = $pdo->prepare(
                "SELECT fund_request_id
                 FROM fund_requests
                 WHERE trip_id = ? AND request_type = 'Trip Allowance'
                   AND status IN ('Pending Approval', 'Approved', 'Processing', 'Disbursed', 'Settled')
                 LIMIT 1
                 FOR UPDATE"
            );
            $activeAllowance->execute([$tripId]);
            if ($activeAllowance->fetchColumn()) {
                throw new DomainException('An active Trip Allowance request is linked to this trip. Wait for its decision and record the approved request, or have it rejected before marking an allowance as not required.');
            }
        }
        if ($allowanceStatus === 'Approved') {
            $fundRequestId = requiredInt('fund_request_id', 'Approved Trip Allowance request', 1);
            $fundRequest = $pdo->prepare(
                "SELECT fund_request_id
                 FROM fund_requests
                 WHERE fund_request_id = ?
                   AND trip_id = ?
                   AND request_type = 'Trip Allowance'
                   AND status IN ('Approved', 'Processing', 'Disbursed', 'Settled')
                 FOR UPDATE"
            );
            $fundRequest->execute([$fundRequestId, $tripId]);
            if (!$fundRequest->fetchColumn()) {
                throw new InvalidArgumentException('Select an approved or disbursed Trip Allowance request linked to this trip.');
            }
        } elseif ($allowanceNotes === null || trim($allowanceNotes) === '') {
            throw new InvalidArgumentException('State why a trip allowance is not required.');
        }

        // The step details are kept on the step-6 event as JSON so the
        // workflow page can show them (no trip_workflow_state table).
        $packet = json_encode([
            'attachment_count'          => $attachmentCount,
            'allowance_status'          => $allowanceStatus,
            'allowance_fund_request_id' => $fundRequestId,
            'allowance_notes'           => $allowanceNotes,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        recordTripWorkflowEvent(
            $pdo,
            $tripId,
            6,
            'departure-packet-prepared',
            currentUserId(),
            $packet
        );
        // Next: Operations Head clearance when every departure check has
        // already passed, otherwise Maintenance's departure checks.
        $readyForDispatch = recordPreDepartureReadyIfComplete($pdo, $tripId, currentUserId());
        notifyTripHandoff($pdo, $tripId, $readyForDispatch ? 'clearance' : 'departure_checks');
        $message = 'Departure preparation recorded.';
        $auditAction = 'PREPARE_TRIP_DEPARTURE';
        $auditDetails = [
            'attachment_count' => $attachmentCount,
            'allowance_status' => $allowanceStatus,
            'fund_request_id'  => $fundRequestId,
        ];
    } elseif ($action === 'departure_clearance') {
        if ($trip['status'] !== 'Ready for Dispatch' || $currentStep < 7 || !tripDepartureChecksPassed($pdo, $tripId)) {
            throw new DomainException('Dispatch clearance is blocked until the departure packet is prepared and every required departure check passes.');
        }

        $clearanceNotes = optionalString('clearance_notes', null, 2000);
        try {
            $pdo->prepare(
                "INSERT INTO dispatch_clearances (trip_id, cleared_by, status, remarks)
                 VALUES (?, ?, 'Cleared', ?)"
            )->execute([$tripId, currentUserId(), $clearanceNotes]);
        } catch (PDOException $e) {
            // uq_dispatch_clearance_trip: one clearance per trip.
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('Dispatch clearance was already recorded for this trip.');
            }
            throw $e;
        }
        transitionTripStatus($pdo, $tripId, ['Ready for Dispatch'], 'Dispatched');
        recordTripWorkflowEvent(
            $pdo,
            $tripId,
            8,
            'operations-dispatch-clearance',
            currentUserId(),
            $clearanceNotes ?: 'Operations Head provided dispatch clearance.',
            'Ready for Dispatch',
            'Dispatched'
        );
        resolveTripHandoffs($pdo, $tripId, ['clearance']);
        notifyTripHandoff($pdo, $tripId, 'cleared');
        $message = 'Dispatch clearance recorded.';
        $auditAction = 'CLEAR_TRIP_FOR_DISPATCH';
        $auditDetails = ['dispatch_id' => (int)$trip['dispatch_id']];
    } elseif ($action === 'record_progress') {
        if ($trip['status'] !== 'In Progress' || $currentStep < 9) {
            throw new DomainException('Progress updates can be recorded after the truck has been dispatched.');
        }
        $location = optionalString('location_note', null, 255);
        $notes = optionalString('notes', null, 4000);
        if (($location === null || trim($location) === '')
            && ($notes === null || trim($notes) === '')) {
            throw new InvalidArgumentException('Enter a current location or progress update.');
        }
        $pdo->prepare(
            "INSERT INTO trip_updates (trip_id, update_type, description, location_text, reported_by)
             VALUES (?, 'In Transit', ?, ?, ?)"
        )->execute([$tripId, $notes, $location, currentUserId()]);
        $updateId = (int)$pdo->lastInsertId();
        recordTripWorkflowEvent(
            $pdo,
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
        if ($trip['status'] !== 'In Progress' || $currentStep < 10) {
            throw new DomainException('Enter at least one trip progress update before recording delivery and return details.');
        }

        $deliveryNotes = requiredString('delivery_notes', 'Delivery details', 4000);
        $returnNotes = requiredString('return_notes', 'Return details', 4000);
        $returnLocation = optionalString('return_location', null, 255);
        $receivedBy = optionalString('received_by', null, 150);
        $receiptNumber = optionalString('delivery_receipt_number', null, 100);
        $sharedWaybill = optionalString('shared_waybill_reference', null, 100);
        $coLoadReference = optionalString('co_load_reference', null, 100);
        $unitCountRaw = trim((string)($_POST['delivered_unit_count'] ?? ''));
        $unitCount = null;
        if ($unitCountRaw !== '') {
            if (!is_numeric($unitCountRaw) || (float)$unitCountRaw < 0 || (float)$unitCountRaw > 1000000) {
                throw new InvalidArgumentException('Delivered unit count must be a number from 0 to 1,000,000.');
            }
            $unitCount = (float)$unitCountRaw;
        }
        $deliveredAtRaw = trim((string)($_POST['delivered_at'] ?? ''));
        $deliveredAt = $deliveredAtRaw === ''
            ? new DateTimeImmutable('now')
            : workflowDateTime($deliveredAtRaw, 'Delivery date and time');
        if ($deliveredAt->getTimestamp() > time() + 300) {
            throw new InvalidArgumentException('The delivery time cannot be in the future.');
        }

        // The target has no columns for the delivery receipt number, co-load
        // reference and delivered unit count; they are kept, labelled, in
        // delivery_notes so nothing the dispatcher entered is lost.
        $deliveryDetails = $deliveryNotes;
        foreach ([
            'Delivery receipt' => $receiptNumber,
            'Co-load reference' => $coLoadReference,
            'Delivered units' => $unitCount === null ? null : rtrim(rtrim(number_format($unitCount, 2, '.', ''), '0'), '.'),
        ] as $label => $value) {
            if ($value !== null && $value !== '') {
                $deliveryDetails .= "\n{$label}: {$value}";
            }
        }
        $returnDetails = $returnNotes . ($returnLocation ? "\nReturn location: {$returnLocation}" : '');

        $pdo->prepare(
            'INSERT INTO trip_deliveries
                (trip_id, delivery_date, delivery_time, received_by, delivery_notes, waybill_number, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                delivery_date = VALUES(delivery_date),
                delivery_time = VALUES(delivery_time),
                received_by = VALUES(received_by),
                delivery_notes = VALUES(delivery_notes),
                waybill_number = VALUES(waybill_number)'
        )->execute([
            $tripId,
            $deliveredAt->format('Y-m-d'),
            $deliveredAt->format('H:i:s'),
            $receivedBy,
            $deliveryDetails,
            $sharedWaybill,
            currentUserId(),
        ]);
        $pdo->prepare(
            'INSERT INTO trip_returns (trip_id, return_date, return_notes, created_by)
             VALUES (?, CURDATE(), ?, ?)
             ON DUPLICATE KEY UPDATE return_notes = VALUES(return_notes)'
        )->execute([$tripId, $returnDetails, currentUserId()]);
        // The actual arrival used by the on-time metric.
        $pdo->prepare('UPDATE trips SET arrival_time = ? WHERE trip_id = ?')
            ->execute([$deliveredAt->format('Y-m-d H:i:s'), $tripId]);
        recordTripWorkflowEvent(
            $pdo,
            $tripId,
            11,
            'delivery-return-details-recorded',
            currentUserId(),
            'Delivery and return details recorded.'
        );
        $message = 'Delivery and return details recorded.';
        $auditAction = 'RECORD_TRIP_DELIVERY_RETURN';
        $auditDetails = [
            'delivered_unit_count'  => $unitCount,
            'has_shared_waybill'    => $sharedWaybill !== null,
            'has_co_load_reference' => $coLoadReference !== null,
            'delivered_at'          => $deliveredAt->format('Y-m-d H:i:s'),
        ];
    }

    auditLog($auditAction, 'trips', $tripId, null, $auditDetails);
    if ($ownerOverride) {
        auditAdminOverride([
            'action'          => 'trip_workflow',
            'workflow_action' => $action,
        ]);
    }
    $pdo->commit();
    if ($ownerOverride) adminOverrideSucceeded();
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonFail($e->getMessage());
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonFail($e->getMessage(), 409);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('trip_workflow_handler: ' . $e->getMessage());
    jsonFail('Could not save the trip workflow action.', 500);
}

jsonOk([], $message);
