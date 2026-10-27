<?php
// ============================================================
// ajax/dispatch_instructions_handler.php
// Operations Head dispatch instructions on the target schema.
//
// An instruction is a client_bookings row (T-C1 adds route_id,
// scheduled_departure, shift, created_by). Status: Pending = open in the
// Dispatcher queue, Confirmed = encoded into a trip (trips.booking_id),
// Cancelled. booking_reference (NOT NULL, UNIQUE) is generated as
// INS-<booking_id>. The legacy batch table has no target counterpart: a
// batch is saved as individual bookings in one transaction.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../app/core/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/shared/validate.php';
require_once __DIR__ . '/../app/core/idempotency.php';
require_once __DIR__ . '/../app/core/notifications.php';
require_once __DIR__ . '/../app/core/workflow_notifications.php';

header('Content-Type: application/json');
requirePermission('dispatch.instructions.manage');
requirePostMethod();
enforceCsrf();

$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

/**
 * Validate one instruction (form fields or a batch entry). Returns the
 * normalized entry or throws InvalidArgumentException with $label in the
 * message.
 */
function validateDispatchInstruction(PDO $pdo, array $entry, string $label): array {
    $clientId = filter_var($entry['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $routeId = filter_var($entry['route_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($clientId === false || $routeId === false) {
        throw new InvalidArgumentException("Select an active client and approved route for {$label}.");
    }

    $shift = $entry['shift'] ?? '';
    if (!in_array($shift, ['Day', 'Night'], true)) {
        throw new InvalidArgumentException("Choose Day or Night shift for {$label}.");
    }
    $truckType = $entry['truck_type'] ?? '';
    if (!in_array($truckType, TRUCK_CATEGORIES, true)) {
        throw new InvalidArgumentException("Choose the truck type for {$label}.");
    }

    $scheduledAtRaw = trim((string)($entry['scheduled_at'] ?? ''));
    $scheduledAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $scheduledAtRaw);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$scheduledAt || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))) {
        throw new InvalidArgumentException("Enter a valid scheduled departure for {$label}.");
    }
    if ($scheduledAt->getTimestamp() < time()) {
        throw new InvalidArgumentException(ucfirst($label) . ' cannot use a passed departure time.');
    }

    // client_bookings.quantity is an unsigned integer (default 1).
    $unitCountRaw = trim((string)($entry['unit_count'] ?? ''));
    $quantity = 1;
    if ($unitCountRaw !== '') {
        if (!preg_match('/^\d{1,6}$/', $unitCountRaw) || (int)$unitCountRaw < 1) {
            throw new InvalidArgumentException("Unit count for {$label} must be a whole number from 1 to 999,999.");
        }
        $quantity = (int)$unitCountRaw;
    }

    $notes = trim((string)($entry['instruction_notes'] ?? ''));
    if (mb_strlen($notes) > 1000) {
        throw new InvalidArgumentException("Instructions for {$label} must be 1,000 characters or fewer.");
    }

    $client = $pdo->prepare("SELECT client_name FROM clients WHERE client_id = ? AND status = 'Active'");
    $client->execute([$clientId]);
    if (!$client->fetchColumn()) {
        throw new InvalidArgumentException("Select an active client for {$label}.");
    }
    // A requested route stays inactive until approved, so active = approved.
    $route = $pdo->prepare('SELECT origin, destination FROM routes WHERE route_id = ? AND is_active = 1');
    $route->execute([$routeId]);
    $routeRow = $route->fetch(PDO::FETCH_ASSOC);
    if (!$routeRow) {
        throw new InvalidArgumentException("Select an active, approved route for {$label}.");
    }

    // Optional client booking / nomination reference (container trips). It
    // is kept in client_bookings.booking_reference; blank = INS-<id>.
    $clientReference = trim((string)($entry['client_booking_reference'] ?? ''));
    if ($clientReference !== '') {
        if (mb_strlen($clientReference) > 100 || !preg_match('/^[\p{L}\p{N} ._\/#-]+$/u', $clientReference)
            || preg_match('/^(INS|TMP)-/i', $clientReference)) {
            throw new InvalidArgumentException("The client booking reference for {$label} must be up to 100 letters, numbers, spaces or . _ / # - characters.");
        }
        if ($truckType !== 'Container') {
            throw new InvalidArgumentException("A client booking reference applies to container trips ({$label}).");
        }
        $duplicate = $pdo->prepare('SELECT 1 FROM client_bookings WHERE booking_reference = ? LIMIT 1');
        $duplicate->execute([$clientReference]);
        if ($duplicate->fetchColumn()) {
            throw new InvalidArgumentException("The client booking reference for {$label} is already recorded.");
        }
    }

    return [
        'client_reference' => $clientReference === '' ? null : $clientReference,
        'client_id'    => $clientId,
        'route_id'     => $routeId,
        'origin'       => $routeRow['origin'],
        'destination'  => $routeRow['destination'],
        'shift'        => $shift,
        'truck_type'   => $truckType,
        'scheduled_at' => $scheduledAt,
        'quantity'     => $quantity,
        'notes'        => $notes === '' ? null : $notes,
    ];
}

function insertDispatchInstruction(PDO $pdo, array $entry): int {
    $scheduledAt = $entry['scheduled_at'];
    // Unique placeholder first, then the readable reference from the new ID.
    $pdo->prepare(
        "INSERT INTO client_bookings
            (client_id, route_id, booking_reference, booking_date, origin, destination,
             truck_type, requested_date, scheduled_departure, shift, quantity, status,
             notes, created_by)
         VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)"
    )->execute([
        $entry['client_id'],
        $entry['route_id'],
        'TMP-' . bin2hex(random_bytes(12)),
        $entry['origin'],
        $entry['destination'],
        $entry['truck_type'],
        $scheduledAt->format('Y-m-d'),
        $scheduledAt->format('Y-m-d H:i:s'),
        $entry['shift'],
        $entry['quantity'],
        $entry['notes'],
        currentUserId(),
    ]);
    $bookingId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE client_bookings SET booking_reference = ? WHERE booking_id = ?')
        ->execute([$entry['client_reference'] ?? sprintf('INS-%06d', $bookingId), $bookingId]);
    auditLog('CREATE_DISPATCH_INSTRUCTION', 'client_bookings', $bookingId, null, [
        'booking_reference' => $entry['client_reference'] ?? sprintf('INS-%06d', $bookingId),
        'client_id'    => $entry['client_id'],
        'route_id'     => $entry['route_id'],
        'truck_type'   => $entry['truck_type'],
        'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
        'shift'        => $entry['shift'],
    ]);
    return $bookingId;
}

if ($action === 'create_batch' || $action === 'create') {
    try {
        if ($action === 'create_batch') {
            $entries = json_decode((string)($_POST['entries'] ?? ''), true);
            if (!is_array($entries) || count($entries) < 2 || count($entries) > 25) {
                jsonFail('A dispatch batch must contain between 2 and 25 trip instructions.');
            }
            $validatedEntries = [];
            foreach (array_values($entries) as $index => $entry) {
                if (!is_array($entry)) {
                    jsonFail('Trip item ' . ($index + 1) . ' is invalid.');
                }
                $validatedEntries[] = validateDispatchInstruction($pdo, $entry, 'trip item ' . ($index + 1));
            }
        } else {
            $validatedEntries = [validateDispatchInstruction($pdo, $_POST, 'this instruction')];
        }
    } catch (InvalidArgumentException $e) {
        jsonFail($e->getMessage());
    }

    $requestKey = requestIdempotencyKey();
    if ($requestKey === null) {
        jsonFail('Missing or invalid request idempotency key.', 400);
    }

    $pdo->beginTransaction();
    try {
        $idempotencyAction = $action === 'create_batch' ? 'dispatch.instruction.batch.create' : 'dispatch.instruction.create';
        if (!claimIdempotencyKey($pdo, $idempotencyAction, $requestKey)) {
            $pdo->rollBack();
            jsonFail($action === 'create_batch'
                ? 'This dispatch batch has already been submitted.'
                : 'This dispatch instruction has already been submitted.', 409);
        }
        $bookingIds = [];
        foreach ($validatedEntries as $entry) {
            $bookingIds[] = insertDispatchInstruction($pdo, $entry);
        }

        if ($action === 'create_batch') {
            $message = count($bookingIds) . ' dispatch instructions are ready to encode.';
        } else {
            $message = ucfirst(strtolower($validatedEntries[0]['shift'])) . ' shift dispatch instruction '
                . sprintf('INS-%06d', $bookingIds[0]) . ' is ready to encode.';
        }
        // Only the dispatchers who encode these truck types are notified.
        notifyDispatchersForTruckTypes(
            $pdo,
            array_values(array_unique(array_column($validatedEntries, 'truck_type'))),
            $action === 'create_batch' ? 'Dispatch list ready' : 'Dispatch instruction ready',
            $message,
            $action === 'create_batch' ? null : $bookingIds[0]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof DomainException) {
            jsonFail($e->getMessage(), 409);
        }
        error_log('dispatch_instructions_handler/' . $action . ': ' . $e->getMessage());
        jsonFail($action === 'create_batch' ? 'Could not send the dispatch batch.' : 'Could not send the dispatch instruction.', 500);
    }

    if ($action === 'create_batch') {
        jsonOk(
            ['booking_ids' => $bookingIds, 'instruction_count' => count($bookingIds)],
            'Dispatch batch sent to the Dispatcher queue.'
        );
    }
    jsonOk(['instruction_id' => $bookingIds[0]], 'Dispatch instruction sent to the Dispatcher queue.');
}

if ($action === 'cancel') {
    $instructionId = requiredInt('instruction_id', 'Instruction', 1);
    $pdo->beginTransaction();
    try {
        $cancel = $pdo->prepare(
            "UPDATE client_bookings SET status = 'Cancelled'
             WHERE booking_id = ? AND status = 'Pending'
               AND scheduled_departure IS NOT NULL"
        );
        $cancel->execute([$instructionId]);
        if ($cancel->rowCount() !== 1) {
            $pdo->rollBack();
            jsonFail('Only an unencoded instruction can be cancelled.', 409);
        }
        resolveDispatchInstructionNotices($pdo, $instructionId);
        $truckTypeQuery = $pdo->prepare('SELECT truck_type FROM client_bookings WHERE booking_id = ?');
        $truckTypeQuery->execute([$instructionId]);
        resolveDispatchListNotices($pdo, (string)$truckTypeQuery->fetchColumn());
        auditLog('CANCEL_DISPATCH_INSTRUCTION', 'client_bookings', $instructionId, ['status' => 'Pending'], ['status' => 'Cancelled']);
        $pdo->commit();
        jsonOk([], 'Dispatch instruction cancelled.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('dispatch_instructions_handler/cancel: ' . $e->getMessage());
        jsonFail('Could not cancel the dispatch instruction.', 500);
    }
}

jsonFail('Unknown action.');
