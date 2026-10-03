<?php
// ============================================================
// ajax/submit_dispatch.php
// Creates a new dispatch_request row (status = Pending)
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
$billingClientId = filter_input(INPUT_POST, 'billing_client_id', FILTER_VALIDATE_INT) ?: null;
$originLocationId = filter_input(INPUT_POST, 'origin_location_id', FILTER_VALIDATE_INT) ?: null;
$destinationLocationId = filter_input(INPUT_POST, 'destination_location_id', FILTER_VALIDATE_INT) ?: null;
$bookingReference = optionalString('booking_reference', null, 100);
$waybillReference = optionalString('waybill_reference', null, 100);
$unitCountRaw = $_POST['unit_count'] ?? '';
$unitCount = $unitCountRaw === '' ? null : (is_numeric($unitCountRaw) ? (float)$unitCountRaw : null);
if ($unitCountRaw !== '' && ($unitCount === null || $unitCount <= 0 || $unitCount > 1000000)) {
    jsonFail('Unit count must be a positive number no greater than 1,000,000.');
}
$scheduledAt = requiredString('scheduled_at', 'Scheduled date/time');
$shift       = requiredEnum('shift', ['Day', 'Night'], 'Shift');
$instructionId = filter_input(INPUT_POST, 'instruction_id', FILTER_VALIDATE_INT) ?: null;
$isAdmin = currentRoleId() === ROLE_ADMIN;
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
enforceDispatcherScope($shift, (string)$truck['truck_type']);

$clientStmt = $pdo->prepare('SELECT client_id, client_name, parent_client_id FROM clients WHERE client_id = ? AND is_active = 1');
$clientStmt->execute([$clientId]);
$client = $clientStmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    jsonFail('Select a registered active client.');
}
$clientName = $client['client_name'];
$billingClientId ??= $client['parent_client_id'] !== null
    ? (int)$client['parent_client_id']
    : $clientId;
if ($billingClientId !== null) {
    $billingClient = $pdo->prepare('SELECT client_id FROM clients WHERE client_id = ? AND is_active = 1');
    $billingClient->execute([$billingClientId]);
    if (!$billingClient->fetchColumn()) {
        jsonFail('Select an active billing client.');
    }
}

$driverStmt = $pdo->prepare("SELECT full_name FROM employees WHERE employee_id = ? AND is_active = 1 AND license_number IS NOT NULL");
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
            'SELECT employee_id, license_number, position
             FROM employees WHERE employee_id = ? AND is_active = 1'
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
$crewIds = array_filter([$driverId, $secondDriverId, $helperId], static fn($id) => $id !== null);
if (count($crewIds) !== count(array_unique($crewIds))) {
    jsonFail('Each assigned crew member must be different.');
}

if ($originLocationId === null || $destinationLocationId === null) {
    jsonFail('Choose an origin and destination location for this dispatch.');
}
if ($originLocationId !== null) {
    $locations = $pdo->prepare(
        'SELECT location_id, location_type
         FROM client_locations
         WHERE location_id = ? AND client_id = ? AND is_active = 1'
    );
    $locations->execute([$originLocationId, $clientId]);
    $origin = $locations->fetch(PDO::FETCH_ASSOC);
    $locations->execute([$destinationLocationId, $clientId]);
    $destination = $locations->fetch(PDO::FETCH_ASSOC);
    if (!$origin || !$destination) {
        jsonFail('Choose active origin and destination locations belonging to the selected client.');
    }
    if (!in_array($origin['location_type'], ['Pickup', 'Both'], true)) {
        jsonFail('The selected origin location is not configured for pickup.');
    }
    if (!in_array($destination['location_type'], ['Delivery', 'Both'], true)) {
        jsonFail('The selected destination location is not configured for delivery.');
    }
}

// Ensure the selected route is approved and still available.
$route = findOrFail($pdo, 'routes', 'route_id', $routeId, 'Route not found.');
if ((string)($route['approval_status'] ?? 'Approved') !== 'Approved' || !(int)$route['is_active']) {
    jsonFail('The selected route is not approved for dispatch.');
}

$pdo->beginTransaction();
$requestKey = requestIdempotencyKey();
if ($requestKey === null) {
    $pdo->rollBack();
    jsonFail('Missing or invalid request idempotency key.', 400);
}
try {
    $instruction = null;
    if ($instructionId !== null) {
        $instructionQuery = $pdo->prepare(
            "SELECT instruction_id, client_id, route_id, scheduled_at, shift,
                    unit_count, status
             FROM dispatch_instructions
             WHERE instruction_id = ? FOR UPDATE"
        );
        $instructionQuery->execute([$instructionId]);
        $instruction = $instructionQuery->fetch(PDO::FETCH_ASSOC);
        if (!$instruction || $instruction['status'] !== 'Open') {
            $pdo->rollBack();
            jsonFail('This dispatch instruction is no longer available to encode.', 409);
        }
        if ((int)$instruction['client_id'] !== $clientId
            || (int)$instruction['route_id'] !== $routeId
            || $instruction['shift'] !== $shift
            || $instruction['scheduled_at'] !== $scheduledDate->format('Y-m-d H:i:s')) {
            $pdo->rollBack();
            jsonFail('Client, route, departure time, and shift must match the Operations Head instruction.', 409);
        }
    }

    lockDispatchResources($pdo, $truckId, $crewIds);
    assertDispatchResourcesAvailable($pdo, $truckId, $crewIds, $scheduledDate->format('Y-m-d H:i:s'));

    if (!claimIdempotencyKey($pdo, 'dispatch.create', $requestKey)) {
        $pdo->rollBack();
        jsonFail('This dispatch submission has already been processed.', 409);
    }
    $rateId = null;
    $rateAmount = null;
    $rateCurrency = null;
    $rateBasis = null;
    if ($originLocationId !== null) {
        $rateQuery = $pdo->prepare(
            "SELECT rate_id, rate_amount, currency, rate_basis
             FROM client_rates
             WHERE client_id = ?
               AND origin_location_id = ?
               AND destination_location_id = ?
               AND is_active = 1
               AND effective_from <= DATE(?)
               AND (effective_to IS NULL OR effective_to >= DATE(?))
             ORDER BY effective_from DESC, rate_id DESC
             LIMIT 1"
        );
        $rateQuery->execute([$clientId, $originLocationId, $destinationLocationId, $scheduledDate->format('Y-m-d'), $scheduledDate->format('Y-m-d')]);
        $rate = $rateQuery->fetch(PDO::FETCH_ASSOC);
        if ($rate) {
            $rateId = (int)$rate['rate_id'];
            $rateAmount = $rate['rate_amount'];
            $rateCurrency = $rate['currency'];
            $rateBasis = $rate['rate_basis'];
        }
    }
    $stmt = $pdo->prepare(
        "INSERT INTO dispatch_requests
           (truck_id, driver_id, second_driver_id, helper_id, route_id, requested_by, scheduled_at, shift,
              dispatch_instruction_id,
              expected_arrival, status, remarks, client_name, client_id, billing_client_id,
            origin_location_id, destination_location_id, client_rate_id, client_rate_amount,
            client_rate_currency, client_rate_basis, booking_reference, waybill_reference, unit_count)
         VALUES
             (:truck, :driver, :second_driver, :helper, :route, :user, :scheduled, :shift,
              :instruction_id, :expected_arrival,
            'Pending', :remarks, :client_name, :client_id, :billing_client_id,
            :origin_location_id, :destination_location_id, :client_rate_id, :client_rate_amount,
            :client_rate_currency, :client_rate_basis, :booking_reference, :waybill_reference, :unit_count)"
    );
    $stmt->execute([
        ':truck' => $truckId, ':driver' => $driverId, ':second_driver' => $secondDriverId,
        ':helper' => $helperId,
        ':route' => $routeId, ':user' => currentUserId(),
        ':scheduled' => $scheduledDate->format('Y-m-d H:i:s'),
        ':shift' => $shift, ':instruction_id' => $instructionId,
        ':expected_arrival' => $expectedArrival, ':remarks' => $remarks,
        ':client_name' => $clientName, ':client_id' => $clientId,
        ':billing_client_id' => $billingClientId,
        ':origin_location_id' => $originLocationId,
        ':destination_location_id' => $destinationLocationId,
        ':client_rate_id' => $rateId, ':client_rate_amount' => $rateAmount,
        ':client_rate_currency' => $rateCurrency,
        ':client_rate_basis' => $rateBasis,
        ':booking_reference' => $bookingReference,
        ':waybill_reference' => $waybillReference, ':unit_count' => $unitCount,
    ]);
    $newId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO trip_workflow_state (dispatch_id, current_step) VALUES (?, 1)'
    )->execute([$newId]);
    recordTripWorkflowEvent(
        $pdo,
        $newId,
        null,
        1,
        'request-prepared',
        currentUserId(),
        'Dispatcher prepared the trip request.'
    );
    recordTripWorkflowEvent(
        $pdo,
        $newId,
        null,
        2,
        'resources-checked',
        currentUserId(),
        'Truck and driver availability checked during submission.'
    );
    recordTripWorkflowEvent(
        $pdo,
        $newId,
        null,
        3,
        'trip-details-entered',
        currentUserId(),
        'Trip details entered and validated.'
    );
    recordTripWorkflowEvent(
        $pdo,
        $newId,
        null,
        4,
        'request-submitted',
        currentUserId(),
        'Trip request submitted for Operations Head approval.'
    );
    if ($instructionId !== null) {
        $encoded = $pdo->prepare(
            "UPDATE dispatch_instructions
             SET status = 'Encoded', encoded_by = ?, dispatch_id = ?, encoded_at = NOW()
             WHERE instruction_id = ? AND status = 'Open'"
        );
        $encoded->execute([currentUserId(), $newId, $instructionId]);
        if ($encoded->rowCount() !== 1) {
            throw new DomainException('This dispatch instruction was already encoded.');
        }
        auditLog('ENCODE_DISPATCH_INSTRUCTION', 'dispatch_instructions', $instructionId, ['status' => 'Open'], [
            'status' => 'Encoded',
            'dispatch_id' => $newId,
        ]);
    }
    $approvalId = createApprovalRequest(
        $pdo,
        'dispatch',
        'dispatch_request',
        $newId,
        currentUserId()
    );
    auditLog('CREATE', 'dispatch_requests', $newId, null, ['status' => 'Pending']);
    auditLog('CREATE', 'approval_requests', $approvalId, null, [
        'request_type' => 'dispatch',
        'entity_type' => 'dispatch_request',
        'entity_id' => $newId,
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof DomainException) {
        jsonFail($e->getMessage(), 409);
    }
    error_log('submit_dispatch: ' . $e->getMessage());
    jsonFail('Could not confirm the dispatch.', 500);
}

jsonOk(['dispatch_id' => $newId], 'Dispatch encoded and submitted to the Operations Head for approval.');
