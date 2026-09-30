<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/idempotency.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json');
requirePermission('dispatch.instructions.manage');
requirePostMethod();
enforceCsrf();

$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'create_batch') {
    $entries = json_decode((string)($_POST['entries'] ?? ''), true);
    if (!is_array($entries) || count($entries) < 2 || count($entries) > 25) {
        jsonFail('A dispatch batch must contain between 2 and 25 trip instructions.');
    }

    $validatedEntries = [];
    $clientQuery = $pdo->prepare(
        'SELECT client_name FROM clients WHERE client_id = ? AND is_active = 1'
    );
    $routeQuery = $pdo->prepare(
        "SELECT route_name FROM routes
         WHERE route_id = ? AND is_active = 1 AND approval_status = 'Approved'"
    );

    foreach ($entries as $entry) {
        $rowNumber = count($validatedEntries) + 1;
        if (!is_array($entry)) {
            jsonFail("Trip item {$rowNumber} is invalid.");
        }
        $clientId = filter_var($entry['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $routeId = filter_var($entry['route_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($clientId === false || $routeId === false) {
            jsonFail("Select an active client and approved route for trip item {$rowNumber}.");
        }

        $shift = $entry['shift'] ?? '';
        if (!in_array($shift, ['Day', 'Night'], true)) {
            jsonFail("Choose Day or Night shift for trip item {$rowNumber}.");
        }

        $scheduledAtRaw = trim((string)($entry['scheduled_at'] ?? ''));
        $scheduledAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $scheduledAtRaw);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$scheduledAt || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))) {
            jsonFail("Enter a valid scheduled departure for trip item {$rowNumber}.");
        }
        if ($scheduledAt->getTimestamp() < time()) {
            jsonFail("Trip item {$rowNumber} cannot use a passed departure time.");
        }

        $unitCountRaw = trim((string)($entry['unit_count'] ?? ''));
        $unitCount = $unitCountRaw === '' ? null : (is_numeric($unitCountRaw) ? (float)$unitCountRaw : null);
        if ($unitCountRaw !== '' && ($unitCount === null || !is_finite($unitCount) || $unitCount <= 0 || $unitCount > 999999.99)) {
            jsonFail("Unit count for trip item {$rowNumber} must be positive and no greater than 999,999.99.");
        }

        $notes = trim((string)($entry['instruction_notes'] ?? ''));
        if (mb_strlen($notes) > 1000) {
            jsonFail("Instructions for trip item {$rowNumber} must be 1,000 characters or fewer.");
        }

        $clientQuery->execute([$clientId]);
        if (!$clientQuery->fetchColumn()) {
            jsonFail("Select an active client for trip item {$rowNumber}.");
        }
        $routeQuery->execute([$routeId]);
        if (!$routeQuery->fetchColumn()) {
            jsonFail("Select an active, approved route for trip item {$rowNumber}.");
        }

        $validatedEntries[] = [
            'client_id' => $clientId,
            'route_id' => $routeId,
            'shift' => $shift,
            'scheduled_at' => $scheduledAt,
            'unit_count' => $unitCount,
            'instruction_notes' => $notes === '' ? null : $notes,
        ];
    }

    $requestKey = requestIdempotencyKey();
    if ($requestKey === null) {
        jsonFail('Missing or invalid request idempotency key.', 400);
    }

    $pdo->beginTransaction();
    try {
        if (!claimIdempotencyKey($pdo, 'dispatch.instruction.batch.create', $requestKey)) {
            $pdo->rollBack();
            jsonFail('This dispatch batch has already been submitted.', 409);
        }

        $pdo->prepare(
            'INSERT INTO dispatch_instruction_batches (created_by, instruction_count)
             VALUES (?, ?)'
        )->execute([currentUserId(), count($validatedEntries)]);
        $batchId = (int)$pdo->lastInsertId();
        $insert = $pdo->prepare(
            "INSERT INTO dispatch_instructions
                (batch_id, shift_date, shift, client_id, route_id, scheduled_at, unit_count,
                 instruction_notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($validatedEntries as $entry) {
            $scheduledAt = $entry['scheduled_at'];
            $insert->execute([
                $batchId,
                $scheduledAt->format('Y-m-d'),
                $entry['shift'],
                $entry['client_id'],
                $entry['route_id'],
                $scheduledAt->format('Y-m-d H:i:s'),
                $entry['unit_count'],
                $entry['instruction_notes'],
                currentUserId(),
            ]);
            $instructionId = (int)$pdo->lastInsertId();
            auditLog('CREATE_DISPATCH_INSTRUCTION', 'dispatch_instructions', $instructionId, null, [
                'batch_id' => $batchId,
                'client_id' => $entry['client_id'],
                'route_id' => $entry['route_id'],
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                'shift' => $entry['shift'],
            ]);
        }

        notifyUsersWithPermission(
            $pdo,
            'dispatch.instructions.encode',
            'Dispatch list ready',
            count($validatedEntries) . ' dispatch instructions are ready to encode (Batch #' . $batchId . ').',
            APP_BASE . '/pages/dispatch_inbox.php'
        );
        auditLog('CREATE_DISPATCH_INSTRUCTION_BATCH', 'dispatch_instruction_batches', $batchId, null, [
            'instruction_count' => count($validatedEntries),
        ]);
        $pdo->commit();
        jsonOk(
            ['batch_id' => $batchId, 'instruction_count' => count($validatedEntries)],
            'Dispatch batch sent to the Dispatcher queue.'
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof DomainException) {
            jsonFail($e->getMessage(), 409);
        }
        error_log('dispatch_instructions_handler/create_batch: ' . $e->getMessage());
        jsonFail('Could not send the dispatch batch.', 500);
    }
}

if ($action === 'create') {
    $clientId = requiredInt('client_id', 'Client', 1);
    $routeId = requiredInt('route_id', 'Route', 1);
    $shift = requiredEnum('shift', ['Day', 'Night'], 'Shift');
    $scheduledAtRaw = requiredString('scheduled_at', 'Scheduled departure');
    $unitCountRaw = trim((string)($_POST['unit_count'] ?? ''));
    $unitCount = $unitCountRaw === '' ? null : (is_numeric($unitCountRaw) ? (float)$unitCountRaw : null);
    $notes = optionalString('instruction_notes', null, 1000);

    if ($unitCountRaw !== '' && ($unitCount === null || $unitCount <= 0 || $unitCount > 999999.99)) {
        jsonFail('Unit count must be a positive number no greater than 999,999.99.');
    }
    $scheduledAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $scheduledAtRaw);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$scheduledAt || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))) {
        jsonFail('Enter a valid scheduled departure date and time.');
    }
    if ($scheduledAt->getTimestamp() < time()) {
        jsonFail('A dispatch instruction cannot use a passed date or time.');
    }

    $client = $pdo->prepare('SELECT client_name FROM clients WHERE client_id = ? AND is_active = 1');
    $client->execute([$clientId]);
    if (!$client->fetchColumn()) {
        jsonFail('Select an active client.');
    }
    $route = $pdo->prepare(
        "SELECT route_name FROM routes
         WHERE route_id = ? AND is_active = 1 AND approval_status = 'Approved'"
    );
    $route->execute([$routeId]);
    if (!$route->fetchColumn()) {
        jsonFail('Select an active, approved route.');
    }

    $requestKey = requestIdempotencyKey();
    if ($requestKey === null) {
        jsonFail('Missing or invalid request idempotency key.', 400);
    }

    $pdo->beginTransaction();
    try {
        if (!claimIdempotencyKey($pdo, 'dispatch.instruction.create', $requestKey)) {
            $pdo->rollBack();
            jsonFail('This dispatch instruction has already been submitted.', 409);
        }
        $pdo->prepare(
            "INSERT INTO dispatch_instructions
                (shift_date, shift, client_id, route_id, scheduled_at, unit_count,
                 instruction_notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $scheduledAt->format('Y-m-d'),
            $shift,
            $clientId,
            $routeId,
            $scheduledAt->format('Y-m-d H:i:s'),
            $unitCount,
            $notes,
            currentUserId(),
        ]);
        $instructionId = (int)$pdo->lastInsertId();
        notifyUsersWithPermission(
            $pdo,
            'dispatch.instructions.encode',
            'Dispatch instruction ready',
            ucfirst(strtolower($shift)) . ' shift dispatch instruction #' . $instructionId . ' is ready to encode.',
            APP_BASE . '/pages/dispatch_inbox.php'
        );
        auditLog('CREATE_DISPATCH_INSTRUCTION', 'dispatch_instructions', $instructionId, null, [
            'client_id' => $clientId,
            'route_id' => $routeId,
            'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            'shift' => $shift,
        ]);
        $pdo->commit();
        jsonOk(['instruction_id' => $instructionId], 'Dispatch instruction sent to the Dispatcher queue.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof DomainException) {
            jsonFail($e->getMessage(), 409);
        }
        error_log('dispatch_instructions_handler/create: ' . $e->getMessage());
        jsonFail('Could not send the dispatch instruction.', 500);
    }
}

if ($action === 'cancel') {
    $instructionId = requiredInt('instruction_id', 'Instruction', 1);
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare(
            "SELECT status FROM dispatch_instructions
             WHERE instruction_id = ? FOR UPDATE"
        );
        $find->execute([$instructionId]);
        $status = $find->fetchColumn();
        if ($status !== 'Open') {
            $pdo->rollBack();
            jsonFail('Only an unencoded instruction can be cancelled.', 409);
        }
        $pdo->prepare(
            "UPDATE dispatch_instructions SET status = 'Cancelled'
             WHERE instruction_id = ?"
        )->execute([$instructionId]);
        auditLog('CANCEL_DISPATCH_INSTRUCTION', 'dispatch_instructions', $instructionId, ['status' => 'Open'], ['status' => 'Cancelled']);
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
