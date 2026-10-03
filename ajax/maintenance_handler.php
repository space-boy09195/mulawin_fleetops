<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/truck_status_history.php';
require_once __DIR__ . '/../includes/trip_workflow.php';

header('Content-Type: application/json');

requirePermission('maintenance.manage');
requirePostMethod();
enforceCsrf();

require_once __DIR__ . '/../config/app.php';

function consumeMaintenanceFormTimer(PDO $pdo, string $token, string $formType, int $targetId): void {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new DomainException('Start the form timer before submitting.');
    }
    $timerQuery = $pdo->prepare(
        'SELECT timer_id, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS elapsed_seconds,
                (expires_at <= NOW()) AS is_expired, consumed_at
         FROM maintenance_form_timers
         WHERE token_hash = ? AND user_id = ? AND form_type = ? AND target_id = ?
         FOR UPDATE'
    );
    $timerQuery->execute([hash('sha256', $token), currentUserId(), $formType, $targetId]);
    $timer = $timerQuery->fetch(PDO::FETCH_ASSOC);
    if (!$timer || $timer['consumed_at'] !== null) {
        throw new DomainException('This form timer is invalid or has already been used. Start again.');
    }
    if ((int)$timer['is_expired'] === 1) {
        throw new DomainException('This form timer expired. Start again before submitting.');
    }
    $elapsed = (int)$timer['elapsed_seconds'];
    if ($elapsed < MAINTENANCE_FORM_MIN_DURATION_SECONDS) {
        $remaining = MAINTENANCE_FORM_MIN_DURATION_SECONDS - $elapsed;
        throw new DomainException("Please wait {$remaining} more seconds before submitting.");
    }
    $pdo->prepare(
        'UPDATE maintenance_form_timers SET consumed_at = NOW()
         WHERE timer_id = ? AND consumed_at IS NULL'
    )->execute([(int)$timer['timer_id']]);
}

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'create_pm_schedule') {
    $truckId = requiredInt('truck_id', 'Truck', 1);
    $serviceName = requiredString('service_name', 'Service name', 160);
    $description = requiredString('description', 'Description', 5000);
    $intervalDays = requiredInt('interval_days', 'Service interval', 1, 3650);
    $nextDueDate = requiredDate('next_due_date', 'Next due date');

    try {
        $pdo->beginTransaction();
        $truckQuery = $pdo->prepare('SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE');
        $truckQuery->execute([$truckId]);
        $truckStatus = $truckQuery->fetchColumn();
        if ($truckStatus === false) {
            $pdo->rollBack();
            jsonFail('Truck not found.', 404);
        }
        if ($truckStatus === 'Inactive') {
            $pdo->rollBack();
            jsonFail('Preventive schedules cannot be created for inactive trucks.', 409);
        }
        $pdo->prepare(
            'INSERT INTO preventive_maintenance_schedules
                (truck_id, created_by, service_name, description, interval_days, next_due_date)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$truckId, currentUserId(), $serviceName, $description, $intervalDays, $nextDueDate]);
        $scheduleId = (int)$pdo->lastInsertId();
        auditLog('CREATE_PM_SCHEDULE', 'preventive_maintenance_schedules', $scheduleId, null, [
            'truck_id' => $truckId,
            'service_name' => $serviceName,
            'interval_days' => $intervalDays,
            'next_due_date' => $nextDueDate,
        ]);
        $pdo->commit();
        jsonOk(['schedule_id' => $scheduleId], 'Preventive maintenance schedule created.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('maintenance_handler/create_pm_schedule: ' . $e->getMessage());
        jsonFail('Could not create the preventive maintenance schedule.', 500);
    }
}

if ($action === 'update_pm_schedule') {
    $scheduleId = requiredInt('schedule_id', 'Schedule', 1);
    $nextStatus = requiredEnum('status', ['Active', 'Paused', 'Archived'], 'Schedule status');

    try {
        $pdo->beginTransaction();
        $scheduleQuery = $pdo->prepare(
            'SELECT status FROM preventive_maintenance_schedules
             WHERE schedule_id = ? FOR UPDATE'
        );
        $scheduleQuery->execute([$scheduleId]);
        $currentStatus = $scheduleQuery->fetchColumn();
        if ($currentStatus === false) {
            $pdo->rollBack();
            jsonFail('Preventive maintenance schedule not found.', 404);
        }
        $validTransition = ($currentStatus === 'Active' && in_array($nextStatus, ['Paused', 'Archived'], true))
            || ($currentStatus === 'Paused' && in_array($nextStatus, ['Active', 'Archived'], true));
        if (!$validTransition) {
            $pdo->rollBack();
            jsonFail('That preventive maintenance schedule status change is not allowed.', 409);
        }
        $pdo->prepare(
            'UPDATE preventive_maintenance_schedules SET status = ? WHERE schedule_id = ?'
        )->execute([$nextStatus, $scheduleId]);
        auditLog('UPDATE_PM_SCHEDULE', 'preventive_maintenance_schedules', $scheduleId, [
            'status' => $currentStatus,
        ], [
            'status' => $nextStatus,
        ]);
        $pdo->commit();
        jsonOk([], "Preventive maintenance schedule {$nextStatus}.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('maintenance_handler/update_pm_schedule: ' . $e->getMessage());
        jsonFail('Could not update the preventive maintenance schedule.', 500);
    }
}

if ($action === 'complete_pm_schedule') {
    $scheduleId = requiredInt('schedule_id', 'Schedule', 1);

    try {
        $pdo->beginTransaction();
        $truckLookup = $pdo->prepare(
            'SELECT truck_id FROM preventive_maintenance_schedules WHERE schedule_id = ?'
        );
        $truckLookup->execute([$scheduleId]);
        $truckId = $truckLookup->fetchColumn();
        if ($truckId === false) {
            $pdo->rollBack();
            jsonFail('Preventive maintenance schedule not found.', 404);
        }

        $truckQuery = $pdo->prepare('SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE');
        $truckQuery->execute([(int)$truckId]);
        $truckStatus = $truckQuery->fetchColumn();
        $scheduleQuery = $pdo->prepare(
            'SELECT truck_id, service_name, description, interval_days, next_due_date,
                    status, (DATE(last_completed_at) = CURDATE()) AS completed_today
             FROM preventive_maintenance_schedules WHERE schedule_id = ? FOR UPDATE'
        );
        $scheduleQuery->execute([$scheduleId]);
        $schedule = $scheduleQuery->fetch(PDO::FETCH_ASSOC);
        if (!$schedule || (int)$schedule['truck_id'] !== (int)$truckId) {
            $pdo->rollBack();
            jsonFail('Preventive maintenance schedule not found.', 404);
        }
        if ($schedule['status'] !== 'Active') {
            $pdo->rollBack();
            jsonFail('Only an active preventive maintenance schedule can be completed.', 409);
        }
        if ($truckStatus !== 'Available') {
            $pdo->rollBack();
            jsonFail('Preventive service can only be recorded while the truck is Available.', 409);
        }
        if ((int)$schedule['completed_today'] === 1) {
            $pdo->rollBack();
            jsonFail('Service has already been recorded for this schedule today.', 409);
        }

        $activeTrip = $pdo->prepare(
            "SELECT t.trip_id
             FROM trips t
             JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
             WHERE dr.truck_id = ? AND t.status NOT IN ('Completed', 'Cancelled')
             LIMIT 1 FOR UPDATE"
        );
        $activeTrip->execute([(int)$truckId]);
        if ($activeTrip->fetchColumn()) {
            $pdo->rollBack();
            jsonFail('This truck has an active trip and cannot be marked serviced.', 409);
        }
        $activeWorkOrder = $pdo->prepare(
            "SELECT work_order_id FROM repair_work_orders
             WHERE truck_id = ? AND status IN ('Open', 'In Progress')
             LIMIT 1 FOR UPDATE"
        );
        $activeWorkOrder->execute([(int)$truckId]);
        if ($activeWorkOrder->fetchColumn()) {
            $pdo->rollBack();
            jsonFail("Complete the truck's active repair work order before recording scheduled service.", 409);
        }

        $dueDateQuery = $pdo->prepare('SELECT DATE_ADD(CURDATE(), INTERVAL ? DAY)');
        $dueDateQuery->execute([(int)$schedule['interval_days']]);
        $nextDueDate = $dueDateQuery->fetchColumn();
        if (!is_string($nextDueDate)) {
            throw new RuntimeException('Could not calculate the next preventive service due date.');
        }
        $recordDescription = 'Scheduled service: ' . $schedule['service_name'] . "\n" . $schedule['description'];
        $pdo->prepare(
            "INSERT INTO maintenance_records
                (truck_id, performed_by, maintenance_type, truck_status, description,
                 date_performed, next_due_date)
             VALUES (?, ?, 'Preventive', 'Operational', ?, CURDATE(), ?)"
        )->execute([(int)$truckId, currentUserId(), $recordDescription, $nextDueDate]);
        $recordId = (int)$pdo->lastInsertId();
        $scheduleUpdate = $pdo->prepare(
            'UPDATE preventive_maintenance_schedules
             SET next_due_date = ?, last_completed_at = NOW(), last_maintenance_record_id = ?
             WHERE schedule_id = ? AND status = \'Active\''
        );
        $scheduleUpdate->execute([$nextDueDate, $recordId, $scheduleId]);
        if ($scheduleUpdate->rowCount() !== 1) {
            throw new DomainException('The schedule changed; reload and try again.');
        }
        $pdo->commit();
        auditLog('COMPLETE_PM_SCHEDULE', 'preventive_maintenance_schedules', $scheduleId, [
            'next_due_date' => $schedule['next_due_date'] ?? null,
        ], [
            'maintenance_record_id' => $recordId,
            'next_due_date' => $nextDueDate,
        ]);
        jsonOk([
            'maintenance_record_id' => $recordId,
            'next_due_date' => $nextDueDate,
        ], 'Scheduled service recorded; the next due date has been advanced.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) {
            jsonFail($e->getMessage(), 409);
        }
        error_log('maintenance_handler/complete_pm_schedule: ' . $e->getMessage());
        jsonFail('Could not record scheduled preventive service.', 500);
    }
}

if ($action === 'create_work_order') {
    $truckId = requiredInt('truck_id', 'Truck', 1);
    $title = requiredString('title', 'Work order title', 160);
    $description = requiredString('description', 'Description', 5000);
    $priority = requiredEnum('priority', ['Low', 'Normal', 'High', 'Urgent'], 'Priority');
    $expectedInput = optionalString('expected_completion_at', null, 16);
    $expectedAt = null;
    if ($expectedInput !== null) {
        $expectedDate = DateTime::createFromFormat('!Y-m-d\TH:i', $expectedInput);
        $dateErrors = DateTime::getLastErrors();
        if (!$expectedDate
            || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $expectedDate->format('Y-m-d\TH:i') !== $expectedInput) {
            jsonFail('Expected completion must be a valid date and time.');
        }
        if ($expectedDate < new DateTime()) {
            jsonFail('Expected completion cannot be in the past.');
        }
        $expectedAt = $expectedDate->format('Y-m-d H:i:s');
    }

    try {
        $pdo->beginTransaction();
        $truckQuery = $pdo->prepare('SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE');
        $truckQuery->execute([$truckId]);
        $truckStatus = $truckQuery->fetchColumn();
        if ($truckStatus === false) {
            $pdo->rollBack();
            jsonFail('Truck not found.', 404);
        }
        if (!in_array($truckStatus, ['Available', 'Under Maintenance'], true)) {
            $pdo->rollBack();
            jsonFail('A repair work order requires an available or already-maintained truck.', 409);
        }
        $activeTrip = $pdo->prepare(
            "SELECT t.trip_id
             FROM trips t
             JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
             WHERE dr.truck_id = ? AND t.status NOT IN ('Completed', 'Cancelled')
             LIMIT 1 FOR UPDATE"
        );
        $activeTrip->execute([$truckId]);
        if ($activeTrip->fetchColumn()) {
            $pdo->rollBack();
            jsonFail('This truck has an active trip and cannot be placed under repair.', 409);
        }
        $activeOrder = $pdo->prepare(
            "SELECT work_order_id FROM repair_work_orders
             WHERE truck_id = ? AND status IN ('Open', 'In Progress') LIMIT 1 FOR UPDATE"
        );
        $activeOrder->execute([$truckId]);
        if ($activeOrder->fetchColumn()) {
            $pdo->rollBack();
            jsonFail('This truck already has an active repair work order.', 409);
        }
        $pdo->prepare(
            'INSERT INTO repair_work_orders
                (truck_id, created_by, title, description, priority, expected_completion_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$truckId, currentUserId(), $title, $description, $priority, $expectedAt]);
        $workOrderId = (int)$pdo->lastInsertId();
        if ($truckStatus === 'Available') {
            $statusUpdate = $pdo->prepare(
                "UPDATE trucks SET status = 'Under Maintenance'
                 WHERE truck_id = ? AND status = 'Available'"
            );
            $statusUpdate->execute([$truckId]);
            if ($statusUpdate->rowCount() !== 1) {
                throw new DomainException('Truck availability changed; reload and try again.');
            }
            recordTruckStatusHistory(
                $pdo,
                $truckId,
                'Available',
                'Under Maintenance',
                "Repair work order #{$workOrderId} opened: {$title}."
            );
        }
        $pdo->commit();
        auditLog('CREATE_REPAIR_WORK_ORDER', 'repair_work_orders', $workOrderId, null, [
            'truck_id' => $truckId,
            'priority' => $priority,
            'expected_completion_at' => $expectedAt,
        ]);
        jsonOk(['work_order_id' => $workOrderId], 'Repair work order opened; truck placed under maintenance.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) {
            jsonFail($e->getMessage(), 409);
        }
        error_log('maintenance_handler/create_work_order: ' . $e->getMessage());
        jsonFail('Could not open the repair work order.', 500);
    }
}

if ($action === 'update_work_order') {
    $workOrderId = requiredInt('work_order_id', 'Work order', 1);
    $nextStatus = requiredEnum('status', ['In Progress', 'Completed', 'Cancelled'], 'Work order status');
    $closureNotes = optionalString('closure_notes', null, 1000);
    if ($nextStatus !== 'In Progress' && $closureNotes === null) {
        jsonFail('Add closure notes before completing or cancelling this work order.');
    }

    try {
        $pdo->beginTransaction();
        $truckLookup = $pdo->prepare('SELECT truck_id FROM repair_work_orders WHERE work_order_id = ?');
        $truckLookup->execute([$workOrderId]);
        $truckId = $truckLookup->fetchColumn();
        if ($truckId === false) {
            $pdo->rollBack();
            jsonFail('Repair work order not found.', 404);
        }
        $truckQuery = $pdo->prepare('SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE');
        $truckQuery->execute([(int)$truckId]);
        $truckStatus = $truckQuery->fetchColumn();
        $orderQuery = $pdo->prepare(
            'SELECT work_order_id, truck_id, status
             FROM repair_work_orders WHERE work_order_id = ? FOR UPDATE'
        );
        $orderQuery->execute([$workOrderId]);
        $workOrder = $orderQuery->fetch(PDO::FETCH_ASSOC);
        if (!$workOrder) {
            $pdo->rollBack();
            jsonFail('Repair work order not found.', 404);
        }
        if ((int)$workOrder['truck_id'] !== (int)$truckId) {
            throw new DomainException('The work order truck changed; reload and try again.');
        }
        if (!in_array($workOrder['status'], ['Open', 'In Progress'], true)) {
            $pdo->rollBack();
            jsonFail('This repair work order is already closed.', 409);
        }
        if ($nextStatus === 'In Progress' && $workOrder['status'] !== 'Open') {
            $pdo->rollBack();
            jsonFail('Only an open work order can be started.', 409);
        }
        $closed = $nextStatus === 'Completed' || $nextStatus === 'Cancelled';
        $pdo->prepare(
            'UPDATE repair_work_orders
             SET status = ?, closed_at = ?, closure_notes = ?
             WHERE work_order_id = ?'
        )->execute([
            $nextStatus,
            $closed ? date('Y-m-d H:i:s') : null,
            $closed ? $closureNotes : null,
            $workOrderId,
        ]);

        if ($nextStatus === 'Completed') {
            $otherActiveOrder = $pdo->prepare(
                "SELECT work_order_id FROM repair_work_orders
                 WHERE truck_id = ? AND work_order_id <> ?
                   AND status IN ('Open', 'In Progress') LIMIT 1 FOR UPDATE"
            );
            $otherActiveOrder->execute([(int)$workOrder['truck_id'], $workOrderId]);
            $activeTrip = $pdo->prepare(
                "SELECT t.trip_id FROM trips t
                 JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
                 WHERE dr.truck_id = ? AND t.status NOT IN ('Completed', 'Cancelled')
                 LIMIT 1 FOR UPDATE"
            );
            $activeTrip->execute([(int)$workOrder['truck_id']]);
            if (!$otherActiveOrder->fetchColumn() && !$activeTrip->fetchColumn() && $truckStatus === 'Under Maintenance') {
                $truckUpdate = $pdo->prepare(
                    "UPDATE trucks SET status = 'Available'
                     WHERE truck_id = ? AND status = 'Under Maintenance'"
                );
                $truckUpdate->execute([(int)$workOrder['truck_id']]);
                if ($truckUpdate->rowCount() === 1) {
                    recordTruckStatusHistory(
                        $pdo,
                        (int)$workOrder['truck_id'],
                        'Under Maintenance',
                        'Available',
                        "Repair work order #{$workOrderId} completed: {$closureNotes}."
                    );
                }
            }
        }
        $pdo->commit();
        auditLog('UPDATE_REPAIR_WORK_ORDER', 'repair_work_orders', $workOrderId, [
            'status' => $workOrder['status'],
        ], [
            'status' => $nextStatus,
            'closure_notes' => $closed ? $closureNotes : null,
        ]);
        jsonOk([], $nextStatus === 'Completed'
            ? 'Work order completed. Truck availability was updated when safe.'
            : ($nextStatus === 'Cancelled'
                ? 'Work order cancelled. The truck remains under maintenance pending a safety review.'
                : 'Work order marked in progress.'));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) {
            jsonFail($e->getMessage(), 409);
        }
        error_log('maintenance_handler/update_work_order: ' . $e->getMessage());
        jsonFail('Could not update the repair work order.', 500);
    }
}

if ($action === 'start_form_timer') {
    $formType = requiredEnum(
        'form_type',
        ['checklist', 'inspection_general', 'inspection_departure', 'inspection_return'],
        'Form type'
    );
    $targetId = requiredInt('target_id', 'Form target', 1);

    if ($formType === 'checklist') {
        $target = $pdo->prepare(
            "SELECT dr.dispatch_id FROM dispatch_requests dr
             WHERE dr.dispatch_id = ? AND dr.status = 'Approved'
               AND NOT EXISTS (
                 SELECT 1 FROM maintenance_checklists mc
                 WHERE mc.dispatch_id = dr.dispatch_id AND mc.result = 'Passed'
               )"
        );
        $target->execute([$targetId]);
        if (!$target->fetchColumn()) {
            jsonFail('Select an approved dispatch that does not already have a passed checklist.', 409);
        }
    } elseif ($formType === 'inspection_general') {
        $target = $pdo->prepare("SELECT truck_id FROM trucks WHERE truck_id = ? AND status <> 'Inactive'");
        $target->execute([$targetId]);
        if (!$target->fetchColumn()) {
            jsonFail('Select an active truck for inspection.', 409);
        }
    } else {
        $expectedStatus = $formType === 'inspection_departure' ? 'Loading' : 'Unloading';
        $tripQuery = $pdo->prepare(
            'SELECT t.trip_id, dr.truck_id
             FROM trips t
             JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
             WHERE t.trip_id = ? AND t.status = ?'
        );
        $tripQuery->execute([$targetId, $expectedStatus]);
        if (!$tripQuery->fetch(PDO::FETCH_ASSOC)) {
            jsonFail("Select a trip in {$expectedStatus} status.", 409);
        }
        if ($formType === 'inspection_return') {
            $departure = $pdo->prepare(
                "SELECT inspection_id FROM vehicle_inspections
                 WHERE trip_id = ? AND inspection_stage = 'Departure'"
            );
            $departure->execute([$targetId]);
            if (!$departure->fetchColumn()) {
                jsonFail('Complete the departure inspection before starting the return inspection.', 409);
            }
        }
    }

    try {
        $pdo->exec('DELETE FROM maintenance_form_timers WHERE expires_at < NOW()');
        $token = bin2hex(random_bytes(32));
        $pdo->prepare(
            'INSERT INTO maintenance_form_timers
                (token_hash, user_id, form_type, target_id, started_at, expires_at)
             VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 2 HOUR))'
        )->execute([hash('sha256', $token), currentUserId(), $formType, $targetId]);
        jsonOk(
            ['timer_token' => $token, 'minimum_seconds' => MAINTENANCE_FORM_MIN_DURATION_SECONDS],
            'Minimum completion time started.'
        );
    } catch (Throwable $e) {
        error_log('maintenance_handler/start_form_timer: ' . $e->getMessage());
        jsonFail('Could not start the minimum completion timer.', 500);
    }
}

// ── Log maintenance record ────────────────────────────────────────────────────
if ($action === 'log_record') {

    $truckId       = requiredInt('truck_id', 'Truck', 1);
    $type          = requiredEnum('type', MAINTENANCE_TYPES, 'Maintenance type');
    $truckStatus   = requiredEnum('truck_status', MAINTENANCE_TRUCK_STATUSES, 'Truck status');
    $description   = requiredString('description', 'Description', 1000);
    $datePerformed = requiredDate('date_performed', 'Date performed', true);
    $nextDue       = optionalString('next_due_date');
    $cost          = optionalFloat('cost');
    $incidentId    = filter_input(INPUT_POST, 'incident_id', FILTER_VALIDATE_INT) ?: null;
    $inspectionId  = filter_input(INPUT_POST, 'inspection_id', FILTER_VALIDATE_INT) ?: null;

    if ($nextDue !== null && (!isValidDate($nextDue) || isPassedDate($nextDue))) {
        jsonFail('Next due date cannot be a passed date.');
    }

    // Verify truck exists
    findOrFail($pdo, 'trucks', 'truck_id', $truckId, 'Truck not found.');

    // Verify any linked records belong to this truck.
    if ($inspectionId) {
        $inspectionCheck = $pdo->prepare("SELECT inspection_id FROM vehicle_inspections WHERE inspection_id = ? AND truck_id = ?");
        $inspectionCheck->execute([$inspectionId, $truckId]);
        if (!$inspectionCheck->fetch()) {
            jsonFail('Inspection does not belong to the selected truck.');
        }
    }

    // Verify incident belongs to this truck if provided
    if ($incidentId) {
        $incCheck = $pdo->prepare("
            SELECT i.incident_id FROM incidents i
            JOIN trips t              ON i.trip_id     = t.trip_id
            JOIN dispatch_requests dr ON t.dispatch_id = dr.dispatch_id
            WHERE i.incident_id = ? AND dr.truck_id = ?
        ");
        $incCheck->execute([$incidentId, $truckId]);
        if (!$incCheck->fetch()) {
            jsonFail('Incident does not belong to the selected truck.');
        }

    }

    try {
        $pdo->beginTransaction();
        $truckLock = $pdo->prepare('SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE');
        $truckLock->execute([$truckId]);
        $currentTruckStatus = $truckLock->fetchColumn();
        if ($currentTruckStatus === false) {
            $pdo->rollBack();
            jsonFail('Truck not found.', 404);
        }
        $activeWorkOrder = $pdo->prepare(
            "SELECT work_order_id FROM repair_work_orders
             WHERE truck_id = ? AND status IN ('Open', 'In Progress') LIMIT 1 FOR UPDATE"
        );
        $activeWorkOrder->execute([$truckId]);
        if ($truckStatus === 'Operational' && $activeWorkOrder->fetchColumn()) {
            $pdo->rollBack();
            jsonFail('Complete or cancel the active work order before returning this truck to service.', 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO maintenance_records
                (truck_id, performed_by, incident_id, inspection_id, maintenance_type, truck_status,
                 description, cost, date_performed, next_due_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $truckId, currentUserId(), $incidentId, $inspectionId,
            $type, $truckStatus, $description,
            $cost, $datePerformed, $nextDue,
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Update truck status to reflect current state
        $newTruckStatus = $truckStatus === 'Operational' ? 'Available' : 'Under Maintenance';
        $statusUpdate = $pdo->prepare("UPDATE trucks SET status = ? WHERE truck_id = ?");
        $statusUpdate->execute([$newTruckStatus, $truckId]);
        if ($currentTruckStatus !== $newTruckStatus) {
            recordTruckStatusHistory(
                $pdo,
                $truckId,
                (string)$currentTruckStatus,
                $newTruckStatus,
                "Maintenance record #{$newId}: {$description}."
            );
        }

        // If linked to an incident, mark it resolved
        if ($incidentId) {
            $pdo->prepare("
                UPDATE incidents
                SET resolved_at = NOW(),
                    resolution_notes = ?
                WHERE incident_id = ? AND resolved_at IS NULL
            ")->execute(["Resolved via maintenance record #$newId", $incidentId]);
        }

        $pdo->commit();

        auditLog('LOG_MAINTENANCE_RECORD', 'maintenance_records', $newId, null, [
            'truck_id'         => $truckId,
            'maintenance_type' => $type,
            'truck_status'     => $truckStatus,
            'date_performed'   => $datePerformed,
            'cost'             => $cost,
            'incident_id'      => $incidentId,
            'inspection_id'    => $inspectionId,
        ]);

        jsonOk(['id' => $newId], 'Maintenance record saved.');
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('maintenance_handler/log_record: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Save vehicle inspection ───────────────────────────────────────────────────
if ($action === 'save_inspection') {
    $truckId = requiredInt('truck_id', 'Truck', 1);
    $date = requiredDate('inspection_date', 'Inspection date', true);
    $notes = optionalString('notes', null, 2000);
    $stage = requiredEnum('inspection_stage', ['General', 'Departure', 'Return'], 'Inspection stage');
    $tripId = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT) ?: null;
    $findings = json_decode($_POST['findings'] ?? '[]', true);

    if ($stage === 'General' && $tripId !== null) {
        jsonFail('A general vehicle inspection cannot be linked to a trip.');
    }
    if ($stage !== 'General' && $tripId === null) {
        jsonFail('Select a trip for a departure or return inspection.');
    }
    if (!is_array($findings) || !$findings || count($findings) > 100) {
        jsonFail('Valid inspection findings are required.');
    }

    findOrFail($pdo, 'trucks', 'truck_id', $truckId, 'Truck not found.');

    $validatedFindings = [];
    $findingKeys = [];
    foreach ($findings as $finding) {
        if (!is_array($finding)) {
            jsonFail('Invalid inspection finding.');
        }
        $view = $finding['view'] ?? '';
        $part = trim((string)($finding['part'] ?? ''));
        $condition = $finding['condition'] ?? '';
        $findingNotes = trim((string)($finding['notes'] ?? ''));
        if (!in_array($view, INSPECTION_VIEWS, true)
            || $part === ''
            || mb_strlen($part) > 80
            || !in_array($condition, INSPECTION_CONDITIONS, true)
            || mb_strlen($findingNotes) > 255) {
            jsonFail('Invalid inspection finding.');
        }
        $key = $view . ':' . $part;
        if (isset($findingKeys[$key])) {
            jsonFail('Each vehicle part can appear only once per inspection.');
        }
        $findingKeys[$key] = $condition;
        $validatedFindings[] = [
            'view' => $view,
            'part' => $part,
            'condition' => $condition,
            'notes' => $findingNotes === '' ? null : $findingNotes,
        ];
    }

    $requiredParts = [
        'Front' => ['Windshield', 'Left Headlight', 'Right Headlight', 'Front Bumper', 'Left Front Tire', 'Right Front Tire'],
        'Side' => ['Left Mirror', 'Driver Door', 'Cargo Body', 'Fuel Tank', 'Left Rear Tire', 'Exhaust'],
        'Rear' => ['Rear Doors', 'Left Tail Light', 'Right Tail Light', 'Rear Bumper', 'Left Rear Tire', 'Right Rear Tire'],
        'Top' => ['Cab Roof', 'Cargo Roof', 'Left Side Panel', 'Right Side Panel', 'Fuel Tank', 'Rear Undercarriage'],
    ];
    if ($stage !== 'General') {
        foreach ($requiredParts as $view => $parts) {
            foreach ($parts as $part) {
                if (!isset($findingKeys[$view . ':' . $part])) {
                    jsonFail('Departure and return inspections must include every standard truck part.');
                }
                if ($findingKeys[$view . ':' . $part] === 'Not Checked') {
                    jsonFail('Check every standard truck part before saving this trip inspection.');
                }
                if ($stage === 'Departure' && $findingKeys[$view . ':' . $part] !== 'Good') {
                    jsonFail('Departure inspection cannot pass while any standard truck part needs attention.');
                }
            }
        }
    }

    try {
        $pdo->beginTransaction();
        if ($tripId !== null) {
            $tripQuery = $pdo->prepare(
                'SELECT t.status, t.dispatch_id, dr.truck_id
                 FROM trips t
                 JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
                 WHERE t.trip_id = ?
                 FOR UPDATE'
            );
            $tripQuery->execute([$tripId]);
            $trip = $tripQuery->fetch(PDO::FETCH_ASSOC);
            $expectedStatus = $stage === 'Departure' ? 'Loading' : 'Unloading';
            if (!$trip || $trip['status'] !== $expectedStatus || (int)$trip['truck_id'] !== $truckId) {
                $pdo->rollBack();
                jsonFail("The selected trip must be in {$expectedStatus} status and use this truck.");
            }
            $workflowQuery = $pdo->prepare(
                'SELECT current_step, assignment_confirmed_at, prepared_at
                 FROM trip_workflow_state WHERE dispatch_id = ? FOR UPDATE'
            );
            $workflowQuery->execute([(int)$trip['dispatch_id']]);
            $workflowState = $workflowQuery->fetch(PDO::FETCH_ASSOC);
            if ($workflowState) {
                if ($stage === 'Departure'
                    && ((int)$workflowState['current_step'] < 6
                        || $workflowState['assignment_confirmed_at'] === null
                        || $workflowState['prepared_at'] === null)) {
                    $pdo->rollBack();
                    jsonFail('Confirm the assignment and prepare trip documents and allowance before the departure inspection.', 409);
                }
                if ($stage === 'Return' && (int)$workflowState['current_step'] < 11) {
                    $pdo->rollBack();
                    jsonFail('Record delivery and return details before the arrival inspection.', 409);
                }
            }
            if ($stage === 'Return') {
                $departure = $pdo->prepare(
                    "SELECT inspection_id FROM vehicle_inspections
                     WHERE trip_id = ? AND inspection_stage = 'Departure'"
                );
                $departure->execute([$tripId]);
                if (!$departure->fetchColumn()) {
                    $pdo->rollBack();
                    jsonFail('Complete the trip departure inspection before recording its return inspection.');
                }
            }
        } else {
            $truckLock = $pdo->prepare('SELECT truck_id FROM trucks WHERE truck_id = ? FOR UPDATE');
            $truckLock->execute([$truckId]);
            if (!$truckLock->fetchColumn()) {
                $pdo->rollBack();
                jsonFail('Truck not found.', 404);
            }
        }
        $timerType = $stage === 'General'
            ? 'inspection_general'
            : ($stage === 'Departure' ? 'inspection_departure' : 'inspection_return');
        consumeMaintenanceFormTimer(
            $pdo,
            (string)($_POST['timer_token'] ?? ''),
            $timerType,
            $tripId ?? $truckId
        );
        $pdo->prepare(
            'INSERT INTO vehicle_inspections
                (truck_id, trip_id, inspection_stage, inspected_by, inspection_date, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$truckId, $tripId, $stage, currentUserId(), $date, $notes]);
        $inspectionId = (int)$pdo->lastInsertId();
        $findingStmt = $pdo->prepare("
            INSERT INTO vehicle_inspection_findings (inspection_id, view_name, part_name, `condition`, notes)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($validatedFindings as $finding) {
            $findingStmt->execute([
                $inspectionId,
                $finding['view'],
                $finding['part'],
                $finding['condition'],
                $finding['notes'],
            ]);
        }
        if ($stage === 'Departure') {
            $dispatchForTrip = $pdo->prepare('SELECT dispatch_id FROM trips WHERE trip_id = ?');
            $dispatchForTrip->execute([$tripId]);
            $dispatchId = (int)$dispatchForTrip->fetchColumn();
            if ($dispatchId > 0) {
                recordPreDepartureReadyIfComplete($pdo, $dispatchId, $tripId, currentUserId());
            }
        } elseif ($stage === 'Return') {
            $dispatchForTrip = $pdo->prepare('SELECT dispatch_id FROM trips WHERE trip_id = ?');
            $dispatchForTrip->execute([$tripId]);
            $dispatchId = (int)$dispatchForTrip->fetchColumn();
            if ($dispatchId > 0) {
                recordTripWorkflowEvent(
                    $pdo,
                    $dispatchId,
                    $tripId,
                    12,
                    'arrival-inspection-completed',
                    currentUserId(),
                    'Complete return vehicle inspection submitted.'
                );
            }
        }
        $pdo->commit();
        auditLog('SAVE_VEHICLE_INSPECTION', 'vehicle_inspections', $inspectionId, null, [
            'truck_id' => $truckId,
            'trip_id' => $tripId,
            'inspection_stage' => $stage,
        ]);
        jsonOk([], 'Vehicle inspection saved.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) {
            jsonFail($e->getMessage(), 409);
        }
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) {
            jsonFail('An inspection for this trip stage has already been submitted.', 409);
        }
        error_log('maintenance_handler/save_inspection: ' . $e->getMessage());
        jsonFail('Could not save vehicle inspection.', 500);
    }
}

// ── Submit pre-trip checklist ─────────────────────────────────────────────────
if ($action === 'submit_checklist') {

    $dispatchId = requiredInt('dispatch_id', 'Dispatch', 1);
    $notes      = optionalString('notes');

    // Verify dispatch exists and is approved
    $dispCheck = $pdo->prepare("
        SELECT dispatch_id, truck_id FROM dispatch_requests
        WHERE dispatch_id = ? AND status = 'Approved'
    ");
    $dispCheck->execute([$dispatchId]);
    $dispatch = $dispCheck->fetch(PDO::FETCH_ASSOC);

    if (!$dispatch) {
        jsonFail('Dispatch not found or not approved.', 404);
    }

    // A passed checklist is final; a failed checklist may be repeated after corrective work.
    $dupCheck = $pdo->prepare(
        "SELECT checklist_id FROM maintenance_checklists
         WHERE dispatch_id = ? AND result = 'Passed' LIMIT 1"
    );
    $dupCheck->execute([$dispatchId]);
    if ($dupCheck->fetch()) {
        jsonFail('A passed checklist has already been submitted for this dispatch.');
    }

    $checklistFields = ['lights_ok', 'tires_ok', 'tools_ok', 'medical_kit_ok',
                        'license_ok', 'or_cr_ok', 'waybill_ok', 'fuel_po_ok'];

    $itemValues = [];
    $allPassed  = true;
    foreach ($checklistFields as $field) {
        $val            = isset($_POST[$field]) && $_POST[$field] === '1' ? 1 : 0;
        $itemValues[]   = $val;
        if (!$val) $allPassed = false;
    }

    $result = $allPassed ? 'Passed' : 'Failed';

    // Get trip_id if one exists for this dispatch
    $tripRow = $pdo->prepare("SELECT trip_id FROM trips WHERE dispatch_id = ?");
    $tripRow->execute([$dispatchId]);
    $trip   = $tripRow->fetch(PDO::FETCH_ASSOC);
    $tripId = $trip ? $trip['trip_id'] : null;

    try {
        $pdo->beginTransaction();
        $lockedDispatch = $pdo->prepare(
            "SELECT dispatch_id, truck_id FROM dispatch_requests
             WHERE dispatch_id = ? AND status = 'Approved' FOR UPDATE"
        );
        $lockedDispatch->execute([$dispatchId]);
        $dispatch = $lockedDispatch->fetch(PDO::FETCH_ASSOC);
        if (!$dispatch) {
            $pdo->rollBack();
            jsonFail('Dispatch not found or not approved.', 404);
        }
        $workflowQuery = $pdo->prepare(
            'SELECT current_step, assignment_confirmed_at, prepared_at
             FROM trip_workflow_state WHERE dispatch_id = ? FOR UPDATE'
        );
        $workflowQuery->execute([$dispatchId]);
        $workflowState = $workflowQuery->fetch(PDO::FETCH_ASSOC);
        if ($workflowState
            && ((int)$workflowState['current_step'] < 6
                || $workflowState['assignment_confirmed_at'] === null
                || $workflowState['prepared_at'] === null)) {
            $pdo->rollBack();
            jsonFail('Confirm the assignment and prepare trip documents and allowance before the pre-departure checklist.', 409);
        }
        $dupCheck = $pdo->prepare(
            "SELECT checklist_id FROM maintenance_checklists
             WHERE dispatch_id = ? AND result = 'Passed' LIMIT 1"
        );
        $dupCheck->execute([$dispatchId]);
        if ($dupCheck->fetch()) {
            $pdo->rollBack();
            jsonFail('A passed checklist has already been submitted for this dispatch.', 409);
        }
        consumeMaintenanceFormTimer(
            $pdo,
            (string)($_POST['timer_token'] ?? ''),
            'checklist',
            $dispatchId
        );

        $stmt = $pdo->prepare("
            INSERT INTO maintenance_checklists
                (dispatch_id, truck_id, submitted_by, trip_id,
                 lights_ok, tires_ok, tools_ok, medical_kit_ok,
                 license_ok, or_cr_ok, waybill_ok, fuel_po_ok,
                 result, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array_merge(
            [$dispatchId, $dispatch['truck_id'], currentUserId(), $tripId],
            $itemValues,
            [$result, $notes]
        ));
        $newId = (int)$pdo->lastInsertId();
        if ($tripId !== null && $workflowState) {
            recordPreDepartureReadyIfComplete($pdo, $dispatchId, (int)$tripId, currentUserId());
        }
        $pdo->commit();

        auditLog('SUBMIT_CHECKLIST', 'maintenance_checklists', $newId, null, [
            'dispatch_id' => $dispatchId,
            'result'      => $result,
        ]);

        jsonOk(
            ['result' => $result, 'id' => $newId],
            "Checklist submitted — result: <strong>$result</strong>."
        );
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('maintenance_handler/submit_checklist: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonFail($e->getMessage(), 409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('maintenance_handler/submit_checklist: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Unknown action ────────────────────────────────────────────────────────────
jsonFail('Unknown action.');
