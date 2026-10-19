<?php
// ============================================================
// ajax/submit_dispatch.php
// Dispatcher submits a trip for Operations Head approval.
//
// Target schema (decision D2): the trip is created now, with status
// 'Pending Approval' and its trip number; dispatch_requests is its approval
// record and approval_requests (workflow 'dispatch') drives the decision.
// An Operations Head instruction is a client_bookings row (T-C1); encoding it
// moves the booking from Pending to Confirmed and links trips.booking_id.
// Everything below commits or rolls back together.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/trip_number.php';
require_once __DIR__ . '/../includes/approval_workflow.php';
require_once __DIR__ . '/../includes/idempotency.php';
require_once __DIR__ . '/../includes/dispatch_assignment.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';
require_once __DIR__ . '/../includes/trip_workflow.php';
require_once __DIR__ . '/../includes/trip_queries.php';

header('Content-Type: application/json');

requirePermission('trips.create');
requirePostMethod();
enforceCsrf();

$truckId     = requiredInt('truck_id', 'Truck', 1);
$routeId     = requiredInt('route_id', 'Route', 1);
$driverId    = requiredInt('driver_id', 'Driver', 1);
$helperId    = filter_input(INPUT_POST, 'helper_id', FILTER_VALIDATE_INT) ?: null;
$secondDriverId = filter_input(INPUT_POST, 'second_driver_id', FILTER_VALIDATE_INT) ?: null;
$clientId    = requiredInt('client_id', 'Client', 1);
$originLocationId = filter_input(INPUT_POST, 'origin_location_id', FILTER_VALIDATE_INT) ?: null;
$destinationLocationId = filter_input(INPUT_POST, 'destination_location_id', FILTER_VALIDATE_INT) ?: null;
$scheduledAt = requiredString('scheduled_at', 'Scheduled date/time');
$shift       = requiredEnum('shift', ['Day', 'Night'], 'Shift');
// instruction_id is the client_bookings.booking_id of an Operations Head
// instruction (form field name kept).
$instructionId = filter_input(INPUT_POST, 'instruction_id', FILTER_VALIDATE_INT) ?: null;
$isAdmin = isAdmin();
// Admin may submit without an Operations Head instruction; that is an
// override and needs a reason (checked after the routine validation below).
$adminWithoutInstruction = $isAdmin && $instructionId === null;
if (!$isAdmin) {
    requirePermission('dispatch.instructions.encode');
}
if (!$isAdmin && $instructionId === null) {
    jsonFail('Choose an open Operations Head instruction from the Dispatcher Queue.');
}
$expectedArrivalRaw = trim($_POST['expected_arrival'] ?? '');
$remarks     = optionalString('remarks');

// datetime-local values are interpreted in the configured application timezone.
$scheduledDate = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $scheduledAt);
$dateErrors = DateTimeImmutable::getLastErrors();
if (!$scheduledDate || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))) {
    jsonFail('Invalid scheduled date.');
}
if ($scheduledDate->getTimestamp() < time()) {
    jsonFail('New dispatches cannot use a passed date or time.');
}
$expectedArrival = null;
if ($expectedArrivalRaw !== '') {
    $expectedArrivalDate = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $expectedArrivalRaw);
    $arrivalErrors = DateTimeImmutable::getLastErrors();
    if (!$expectedArrivalDate || ($arrivalErrors !== false && ($arrivalErrors['warning_count'] || $arrivalErrors['error_count']))) {
        jsonFail('Expected arrival must be a valid date and time.');
    }
    if ($expectedArrivalDate <= $scheduledDate) {
        jsonFail('Expected arrival must be later than scheduled departure.');
    }
    $expectedArrival = $expectedArrivalDate->format('Y-m-d H:i:s');
}
$scheduledSql = $scheduledDate->format('Y-m-d H:i:s');

$pdo = getDBConnection();

$truckQuery = $pdo->prepare(
    "SELECT status, truck_type
     FROM trucks
     WHERE truck_id = ?"
);
$truckQuery->execute([$truckId]);
$truck = $truckQuery->fetch(PDO::FETCH_ASSOC);
if (!$truck || $truck['status'] !== 'Available') {
    jsonFail('The selected truck is no longer available.', 409);
}
enforceDispatcherScope($shift, (string)$truck['truck_type'], $scheduledDate->format('Y-m-d'));

$clientStmt = $pdo->prepare("SELECT client_id, client_name FROM clients WHERE client_id = ? AND status = 'Active'");
$clientStmt->execute([$clientId]);
$client = $clientStmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    jsonFail('Select a registered active client.');
}

$driverStmt = $pdo->prepare(
    "SELECT employee_id
     FROM employees
     WHERE employee_id = ? AND employment_status = 'Active' AND license_number IS NOT NULL"
);
$driverStmt->execute([$driverId]);
if (!$driverStmt->fetchColumn()) {
    jsonFail('The selected driver is not active or does not have a valid license.');
}
foreach ([
    'second driver' => $secondDriverId,
    'helper' => $helperId,
] as $crewLabel => $crewId) {
    if ($crewId !== null) {
        $crewStmt = $pdo->prepare(
            "SELECT employee_id, license_number, position
             FROM employees WHERE employee_id = ? AND employment_status = 'Active'"
        );
        $crewStmt->execute([$crewId]);
        $crew = $crewStmt->fetch(PDO::FETCH_ASSOC);
        if (!$crew) {
            jsonFail('The selected ' . $crewLabel . ' is not active.');
        }
        if ($crewLabel === 'second driver' && empty($crew['license_number'])) {
            jsonFail('The selected second driver must have a valid license.');
        }
    }
}
$crewIds = array_values(array_filter([$driverId, $secondDriverId, $helperId], static fn($id) => $id !== null));
if (count($crewIds) !== count(array_unique($crewIds))) {
    jsonFail('Each assigned crew member must be different.');
}

// Route must be active. In the target a requested route stays inactive until
// its route_request approval, so is_active = 1 means approved.
$route = findOrFail($pdo, 'routes', 'route_id', $routeId, 'Route not found.');
if (!(int)$route['is_active']) {
    jsonFail('The selected route is not approved for dispatch.');
}

// trips.origin / trips.destination: the selected client locations (name and
// address), otherwise the route's origin and destination.
$origin = (string)$route['origin'];
$destination = (string)$route['destination'];
if ($originLocationId === null || $destinationLocationId === null) {
    jsonFail('Choose an origin and destination location for this dispatch.');
}
$locations = $pdo->prepare(
    'SELECT location_id, location_name, address
     FROM client_locations
     WHERE location_id = ? AND client_id = ? AND is_active = 1'
);
$locations->execute([$originLocationId, $clientId]);
$originLocation = $locations->fetch(PDO::FETCH_ASSOC);
$locations->execute([$destinationLocationId, $clientId]);
$destinationLocation = $locations->fetch(PDO::FETCH_ASSOC);
if (!$originLocation || !$destinationLocation) {
    jsonFail('Choose active origin and destination locations belonging to the selected client.');
}
if ($originLocationId === $destinationLocationId) {
    jsonFail('Origin and destination must be different locations.');
}
$locationText = static fn(array $location): string => mb_substr(
    trim($location['location_name'] . ' — ' . preg_replace('/\s+/u', ' ', (string)$location['address'])),
    0,
    255,
    'UTF-8'
);
$origin = $locationText($originLocation);
$destination = $locationText($destinationLocation);

$overrideBypassed = ['restriction' => 'instruction_required'];
$overrideReason = null;
if ($adminWithoutInstruction) {
    $overrideReason = adminOverrideReason();
    if ($overrideReason === null) {
        adminOverrideRefused('trips', null, $overrideBypassed);
        jsonFail('An Admin override reason (1-500 characters) is required to submit a dispatch without an Operations Head instruction.', 422);
    }
}

$requestKey = requestIdempotencyKey();
if ($requestKey === null) {
    jsonFail('Missing or invalid request idempotency key.', 400);
}
$pdo->beginTransaction();
if ($adminWithoutInstruction) {
    // The new trip may roll back, so the event's entity ID stays null and the
    // trip ID is recorded as trip_id on success.
    adminOverrideBegin('trips', null, $overrideBypassed, $overrideReason);
}
try {
    $booking = null;
    if ($instructionId !== null) {
        $bookingQuery = $pdo->prepare(
            "SELECT booking_id, booking_reference, client_id, route_id, scheduled_departure,
                    shift, truck_type, quantity, status
             FROM client_bookings
             WHERE booking_id = ? FOR UPDATE"
        );
        $bookingQuery->execute([$instructionId]);
        $booking = $bookingQuery->fetch(PDO::FETCH_ASSOC);
        if (!$booking || $booking['status'] !== 'Pending') {
            $pdo->rollBack();
            jsonFail('This dispatch instruction is no longer available to encode.', 409);
        }
        if ((int)$booking['client_id'] !== $clientId
            || (int)$booking['route_id'] !== $routeId
            || $booking['shift'] !== $shift
            || $booking['scheduled_departure'] !== $scheduledSql) {
            $pdo->rollBack();
            jsonFail('Client, route, departure time, and shift must match the Operations Head instruction.', 409);
        }
        if ($booking['truck_type'] !== $truck['truck_type']) {
            $pdo->rollBack();
            jsonFail('The selected truck must be a ' . $booking['truck_type'] . ' as instructed by the Operations Head.', 409);
        }
    }

    lockDispatchResources($pdo, $truckId, $crewIds);
    assertDispatchResourcesAvailable($pdo, $truckId, $crewIds, $scheduledSql);

    if (!claimIdempotencyKey($pdo, 'dispatch.create', $requestKey)) {
        $pdo->rollBack();
        jsonFail('This dispatch submission has already been processed.', 409);
    }

    $tripNumber = nextTripNumber($pdo);
    $pdo->prepare(
        "INSERT INTO trips
            (trip_number, trip_type, client_id, booking_id, route_id, origin, destination,
             scheduled_departure, scheduled_arrival, truck_id, driver_id, second_driver_id,
             helper_id, dispatcher_id, shift, status)
         VALUES
            (:number, :trip_type, :client_id, :booking_id, :route_id, :origin, :destination,
             :departure, :arrival, :truck, :driver, :second_driver,
             :helper, :dispatcher, :shift, 'Pending Approval')"
    )->execute([
        ':number'        => $tripNumber,
        ':trip_type'     => $truck['truck_type'],
        ':client_id'     => $clientId,
        ':booking_id'    => $booking ? (int)$booking['booking_id'] : null,
        ':route_id'      => $routeId,
        ':origin'        => $origin,
        ':destination'   => $destination,
        ':departure'     => $scheduledSql,
        ':arrival'       => $expectedArrival,
        ':truck'         => $truckId,
        ':driver'        => $driverId,
        ':second_driver' => $secondDriverId,
        ':helper'        => $helperId,
        ':dispatcher'    => employeeIdForUser($pdo, currentUserId()),
        ':shift'         => $shift,
    ]);
    $tripId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO dispatch_requests (trip_id, submitted_by, status) VALUES (?, ?, 'Pending')"
    )->execute([$tripId, currentUserId()]);
    $dispatchId = (int)$pdo->lastInsertId();

    $actor = currentUserId();
    recordTripWorkflowEvent($pdo, $tripId, 1, 'request-prepared', $actor, 'Dispatcher prepared the trip request.');
    recordTripWorkflowEvent($pdo, $tripId, 2, 'resources-checked', $actor, 'Truck and driver availability checked during submission.');
    recordTripWorkflowEvent($pdo, $tripId, 3, 'trip-details-entered', $actor, 'Trip details entered and validated.');
    recordTripWorkflowEvent(
        $pdo,
        $tripId,
        4,
        'request-submitted',
        $actor,
        'Trip request submitted for Operations Head approval.' . ($remarks ? ' Remarks: ' . $remarks : ''),
        null,
        'Pending Approval'
    );

    if ($booking !== null) {
        $encoded = $pdo->prepare(
            "UPDATE client_bookings SET status = 'Confirmed'
             WHERE booking_id = ? AND status = 'Pending'"
        );
        $encoded->execute([(int)$booking['booking_id']]);
        if ($encoded->rowCount() !== 1) {
            throw new DomainException('This dispatch instruction was already encoded.');
        }
        auditLog('ENCODE_DISPATCH_INSTRUCTION', 'client_bookings', (int)$booking['booking_id'], ['status' => 'Pending'], [
            'status'  => 'Confirmed',
            'trip_id' => $tripId,
        ]);
    }

    $approvalId = createApprovalRequest(
        $pdo,
        'dispatch',
        'dispatch_request',
        $dispatchId,
        $actor,
        $remarks
    );
    auditLog('CREATE', 'trips', $tripId, null, [
        'trip_number' => $tripNumber,
        'status'      => 'Pending Approval',
        'booking_id'  => $booking ? (int)$booking['booking_id'] : null,
    ]);
    auditLog('CREATE', 'dispatch_requests', $dispatchId, null, ['status' => 'Pending', 'trip_id' => $tripId]);
    auditLog('CREATE', 'approval_requests', $approvalId, null, [
        'workflow_key' => 'dispatch',
        'entity_type'  => 'dispatch_request',
        'entity_id'    => $dispatchId,
    ]);
    if ($adminWithoutInstruction) {
        auditAdminOverride([
            'action'      => 'create_dispatch_without_instruction',
            'new_status'  => 'Pending Approval',
            'dispatch_id' => $dispatchId,
            'trip_id'     => $tripId,
        ], $tripId);
    }
    $pdo->commit();
    if ($adminWithoutInstruction) adminOverrideSucceeded();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof DomainException) {
        jsonFail($e->getMessage(), 409);
    }
    error_log('submit_dispatch: ' . $e->getMessage());
    if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
        jsonFail('Another dispatch was being saved at the same time. Nothing was saved; please try again.', 409);
    }
    jsonFail('Could not confirm the dispatch.', 500);
}

jsonOk(
    ['dispatch_id' => $dispatchId, 'trip_id' => $tripId, 'trip_number' => $tripNumber],
    'Dispatch encoded and submitted to the Operations Head for approval.'
);
