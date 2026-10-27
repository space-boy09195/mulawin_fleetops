<?php
// ============================================================
// ajax/maintenance_handler.php
// Maintenance actions on the target schema:
//   maintenance_records (+ T-M1 next_due_date, T-M2 record_source, work-order
//   fields, links), preventive_maintenance_schedules (T-M2),
//   vehicle_inspections / inspection_findings (templates: T-N1),
//   maintenance_findings (general inspections).
// Locking order everywhere: truck row first, then trips / maintenance rows.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../app/core/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/shared/validate.php';
require_once __DIR__ . '/../app/shared/db_helpers.php';
require_once __DIR__ . '/../app/modules/fleet/truck_status_history.php';
require_once __DIR__ . '/../app/modules/trips/trip_workflow.php';
require_once __DIR__ . '/../app/modules/maintenance/inspections.php';
require_once __DIR__ . '/../app/modules/documents/document_upload.php';
require_once __DIR__ . '/../app/core/workflow_notifications.php';

header('Content-Type: application/json');

requirePermission('maintenance.manage');
requirePostMethod();
enforceCsrf();

require_once __DIR__ . '/../config/app.php';

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// An approved trip that is not finished holds the truck (legacy trips existed
// only after approval, so 'Pending Approval' does not count). Locks the trip.
function activeTripForTruck(PDO $pdo, int $truckId): ?int {
    $stmt = $pdo->prepare(
        "SELECT trip_id FROM trips
         WHERE truck_id = ? AND status NOT IN ('Pending Approval', 'Completed', 'Cancelled')
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([$truckId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

// Open repair work order of a truck (T-M2 record_source), locked.
function activeWorkOrderForTruck(PDO $pdo, int $truckId, ?int $excludeId = null): ?int {
    $sql = "SELECT maintenance_id FROM maintenance_records
            WHERE truck_id = ? AND record_source = 'work_order'
              AND status IN ('Reported', 'Scheduled', 'In Progress', 'Waiting for Parts')";
    $params = [$truckId];
    if ($excludeId !== null) {
        $sql .= ' AND maintenance_id <> ?';
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1 FOR UPDATE');
    $stmt->execute($params);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function lockTruckStatus(PDO $pdo, int $truckId): string|false {
    $stmt = $pdo->prepare('SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE');
    $stmt->execute([$truckId]);
    return $stmt->fetchColumn();
}

// Truck status + availability flag together (trip workflow statuses are
// never set here).
function setTruckMaintenanceStatus(PDO $pdo, int $truckId, string $from, string $to, string $reason): bool {
    if (!in_array($to, ['Available', 'Under Maintenance'], true)) {
        throw new InvalidArgumentException('Unsupported truck status.');
    }
    $update = $pdo->prepare(
        "UPDATE trucks SET status = ?, availability_status = IF(? = 'Available', 'Available', 'Unavailable')
         WHERE truck_id = ? AND status = ?"
    );
    $update->execute([$to, $to, $truckId, $from]);
    if ($update->rowCount() !== 1) {
        return false;
    }
    recordTruckStatusHistory($pdo, $truckId, $from, $to, $reason);
    return true;
}

if ($action === 'create_pm_schedule') {
    $truckId = requiredInt('truck_id', 'Truck', 1);
    $serviceName = requiredString('service_name', 'Service name', 160);
    $description = requiredString('description', 'Description', 5000);
    $intervalDays = requiredInt('interval_days', 'Service interval', 1, 3650);
    $nextDueDate = requiredDate('next_due_date', 'Next due date');

    try {
        $pdo->beginTransaction();
        $truckStatus = lockTruckStatus($pdo, $truckId);
        if ($truckStatus === false) {
            $pdo->rollBack();
            jsonFail('Truck not found.', 404);
        }
        if ($truckStatus === 'Unavailable') {
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
        $truckId = (int)$truckId;

        $truckStatus = lockTruckStatus($pdo, $truckId);
        $scheduleQuery = $pdo->prepare(
            'SELECT truck_id, service_name, description, interval_days, next_due_date,
                    status, (DATE(last_completed_at) = CURDATE()) AS completed_today
             FROM preventive_maintenance_schedules WHERE schedule_id = ? FOR UPDATE'
        );
        $scheduleQuery->execute([$scheduleId]);
        $schedule = $scheduleQuery->fetch(PDO::FETCH_ASSOC);
        if (!$schedule || (int)$schedule['truck_id'] !== $truckId) {
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
        if (activeTripForTruck($pdo, $truckId) !== null) {
            $pdo->rollBack();
            jsonFail('This truck has an active trip and cannot be marked serviced.', 409);
        }
        if (activeWorkOrderForTruck($pdo, $truckId) !== null) {
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
                (truck_id, maintenance_type, record_source, description, start_date,
                 actual_completion_date, next_due_date, status, completed_by, created_by)
             VALUES (?, 'Preventive', 'pm_service', ?, CURDATE(), CURDATE(), ?, 'Completed', ?, ?)"
        )->execute([$truckId, $recordDescription, $nextDueDate, currentUserId(), currentUserId()]);
        $recordId = (int)$pdo->lastInsertId();
        $scheduleUpdate = $pdo->prepare(
            "UPDATE preventive_maintenance_schedules
             SET next_due_date = ?, last_completed_at = NOW(), last_maintenance_id = ?
             WHERE schedule_id = ? AND status = 'Active'"
        );
        $scheduleUpdate->execute([$nextDueDate, $recordId, $scheduleId]);
        if ($scheduleUpdate->rowCount() !== 1) {
            throw new DomainException('The schedule changed; reload and try again.');
        }
        auditLog('COMPLETE_PM_SCHEDULE', 'preventive_maintenance_schedules', $scheduleId, [
            'next_due_date' => $schedule['next_due_date'] ?? null,
        ], [
            'maintenance_record_id' => $recordId,
            'next_due_date' => $nextDueDate,
        ]);
        $pdo->commit();
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
    $expectedDate = null;
    if ($expectedInput !== null) {
        $expected = DateTime::createFromFormat('!Y-m-d\TH:i', $expectedInput);
        $dateErrors = DateTime::getLastErrors();
        if (!$expected
            || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $expected->format('Y-m-d\TH:i') !== $expectedInput) {
            jsonFail('Expected completion must be a valid date and time.');
        }
        if ($expected < new DateTime()) {
            jsonFail('Expected completion cannot be in the past.');
        }
        // maintenance_records.expected_completion_date is a DATE.
        $expectedDate = $expected->format('Y-m-d');
    }

    try {
        $pdo->beginTransaction();
        $truckStatus = lockTruckStatus($pdo, $truckId);
        if ($truckStatus === false) {
            $pdo->rollBack();
            jsonFail('Truck not found.', 404);
        }
        if (!in_array($truckStatus, ['Available', 'Under Maintenance'], true)) {
            $pdo->rollBack();
            jsonFail('A repair work order requires an available or already-maintained truck.', 409);
        }
        if (activeTripForTruck($pdo, $truckId) !== null) {
            $pdo->rollBack();
            jsonFail('This truck has an active trip and cannot be placed under repair.', 409);
        }
        if (activeWorkOrderForTruck($pdo, $truckId) !== null) {
            $pdo->rollBack();
            jsonFail('This truck already has an active repair work order.', 409);
        }
        $pdo->prepare(
            "INSERT INTO maintenance_records
                (truck_id, maintenance_type, record_source, work_order_priority, reported_issue,
                 description, expected_completion_date, expected_available_date, status, created_by)
             VALUES (?, 'Repair', 'work_order', ?, ?, ?, ?, ?, 'Reported', ?)"
        )->execute([$truckId, $priority, $title, $description, $expectedDate, $expectedDate, currentUserId()]);
        $workOrderId = (int)$pdo->lastInsertId();
        if ($truckStatus === 'Available'
            && !setTruckMaintenanceStatus($pdo, $truckId, 'Available', 'Under Maintenance', "Repair work order #{$workOrderId} opened: {$title}.")) {
            throw new DomainException('Truck availability changed; reload and try again.');
        }
        auditLog('CREATE_REPAIR_WORK_ORDER', 'maintenance_records', $workOrderId, null, [
            'truck_id' => $truckId,
            'priority' => $priority,
            'expected_completion_date' => $expectedDate,
        ]);
        $pdo->commit();
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
    $nextStatus = requiredEnum('status', ['In Progress', 'Waiting for Parts', 'Completed', 'Cancelled'], 'Work order status');
    $closureNotes = optionalString('closure_notes', null, 1000);
    if (in_array($nextStatus, ['Completed', 'Cancelled'], true) && $closureNotes === null) {
        jsonFail('Add closure notes before completing or cancelling this work order.');
    }
    // Optional labour / other cost (replaces the recorded value; parts cost
    // comes from issued parts) and an optional finding for the job.
    $costInputs = [];
    foreach (['labor_cost' => 'Labour cost', 'other_cost' => 'Other cost'] as $costField => $costLabel) {
        $rawCost = trim((string)($_POST[$costField] ?? ''));
        if ($rawCost === '') {
            continue;
        }
        if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $rawCost)) {
            jsonFail($costLabel . ' must be zero or a positive amount with at most two decimal places.');
        }
        $costInputs[$costField] = round((float)$rawCost, 2);
    }
    $findingText = optionalString('finding', null, 1000);
    $findingSeverity = $findingText === null
        ? null
        : requiredEnum('finding_severity', ['Low', 'Medium', 'High', 'Critical'], 'Finding severity');

    try {
        $pdo->beginTransaction();
        $truckLookup = $pdo->prepare(
            "SELECT truck_id FROM maintenance_records WHERE maintenance_id = ? AND record_source = 'work_order'"
        );
        $truckLookup->execute([$workOrderId]);
        $truckId = $truckLookup->fetchColumn();
        if ($truckId === false) {
            $pdo->rollBack();
            jsonFail('Repair work order not found.', 404);
        }
        $truckId = (int)$truckId;
        $truckStatus = lockTruckStatus($pdo, $truckId);
        $orderQuery = $pdo->prepare(
            "SELECT maintenance_id, truck_id, status
             FROM maintenance_records WHERE maintenance_id = ? AND record_source = 'work_order' FOR UPDATE"
        );
        $orderQuery->execute([$workOrderId]);
        $workOrder = $orderQuery->fetch(PDO::FETCH_ASSOC);
        if (!$workOrder) {
            $pdo->rollBack();
            jsonFail('Repair work order not found.', 404);
        }
        if ((int)$workOrder['truck_id'] !== $truckId) {
            throw new DomainException('The work order truck changed; reload and try again.');
        }
        // Legacy Open = target Reported.
        if (!in_array($workOrder['status'], ['Reported', 'Scheduled', 'In Progress', 'Waiting for Parts'], true)) {
            $pdo->rollBack();
            jsonFail('This repair work order is already closed.', 409);
        }
        // Open (Reported or Scheduled, shown as Open) or Waiting for Parts.
        if ($nextStatus === 'In Progress' && !in_array($workOrder['status'], ['Reported', 'Scheduled', 'Waiting for Parts'], true)) {
            $pdo->rollBack();
            jsonFail('Only an open work order, or one waiting for parts, can be started.', 409);
        }
        if ($nextStatus === 'Waiting for Parts' && $workOrder['status'] !== 'In Progress') {
            $pdo->rollBack();
            jsonFail('Only a work order in progress can be put on hold for parts.', 409);
        }
        $closed = $nextStatus === 'Completed' || $nextStatus === 'Cancelled';
        if ($nextStatus === 'In Progress' || $nextStatus === 'Waiting for Parts') {
            $pdo->prepare(
                "UPDATE maintenance_records SET status = ?, start_date = COALESCE(start_date, CURDATE())
                 WHERE maintenance_id = ?"
            )->execute([$nextStatus, $workOrderId]);
        } else {
            $pdo->prepare(
                "UPDATE maintenance_records
                 SET status = ?, closed_at = NOW(), closure_notes = ?,
                     actual_completion_date = IF(? = 'Completed', CURDATE(), actual_completion_date),
                     completed_by = IF(? = 'Completed', ?, completed_by)
                 WHERE maintenance_id = ?"
            )->execute([$nextStatus, $closureNotes, $nextStatus, $nextStatus, currentUserId(), $workOrderId]);
        }
        foreach ($costInputs as $costField => $costValue) {
            // $costField is one of the two fixed column names above.
            $pdo->prepare(
                "UPDATE maintenance_records
                 SET {$costField} = ?, total_cost = labor_cost + parts_cost + other_cost
                 WHERE maintenance_id = ?"
            )->execute([$costValue, $workOrderId]);
        }
        if ($findingText !== null) {
            $pdo->prepare(
                'INSERT INTO maintenance_findings (maintenance_id, finding_type, description, severity)
                 VALUES (?, ?, ?, ?)'
            )->execute([$workOrderId, 'Work order finding', $findingText, $findingSeverity]);
        }

        if ($nextStatus === 'Completed'
            && $truckStatus === 'Under Maintenance'
            && activeWorkOrderForTruck($pdo, $truckId, $workOrderId) === null
            && activeTripForTruck($pdo, $truckId) === null) {
            setTruckMaintenanceStatus($pdo, $truckId, 'Under Maintenance', 'Available', "Repair work order #{$workOrderId} completed: {$closureNotes}.");
        }
        auditLog('UPDATE_REPAIR_WORK_ORDER', 'maintenance_records', $workOrderId, [
            'status' => $workOrder['status'],
        ], [
            'status' => $nextStatus,
            'closure_notes' => $closed ? $closureNotes : null,
            'costs' => $costInputs ?: null,
            'finding' => $findingText,
        ]);
        $pdo->commit();
        jsonOk([], $nextStatus === 'Completed'
            ? 'Work order completed. Truck availability was updated when safe.'
            : ($nextStatus === 'Cancelled'
                ? 'Work order cancelled. The truck remains under maintenance pending a safety review.'
                : ($nextStatus === 'Waiting for Parts'
                    ? 'Work order marked as waiting for parts.'
                    : 'Work order marked in progress.')));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) {
            jsonFail($e->getMessage(), 409);
        }
        error_log('maintenance_handler/update_work_order: ' . $e->getMessage());
        jsonFail('Could not update the repair work order.', 500);
    }
}

// Trip inspections happen at fixed workflow points (step map in
// includes/trip_workflow.php): departure checks after the departure packet
// (step 6, status Assigned); the arrival inspection after delivery (step 11,
// status Delivered).
function inspectionTripState(PDO $pdo, int $tripId, string $formType, bool $lock): ?array {
    $stmt = $pdo->prepare(
        'SELECT trip_id, truck_id, status FROM trips WHERE trip_id = ?' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$tripId]);
    $trip = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$trip) {
        return null;
    }
    $trip['step'] = tripWorkflowCurrentStep($pdo, $tripId);
    $trip['ready'] = $formType === 'inspection_return'
        ? ($trip['status'] === 'Delivered' && $trip['step'] >= 11)
        : ($trip['status'] === 'Assigned' && $trip['step'] >= 6);
    return $trip;
}

if ($action === 'start_form_timer') {
    $formType = requiredEnum('form_type', INSPECTION_FORM_TYPES, 'Form type');
    $targetId = requiredInt('target_id', 'Form target', 1);

    if ($formType === 'checklist') {
        // Target: the checklist belongs to the trip of an approved dispatch
        // request (target_id is the dispatch_request_id, as before).
        $target = $pdo->prepare(
            "SELECT trip_id FROM dispatch_requests WHERE dispatch_request_id = ? AND status = 'Approved'"
        );
        $target->execute([$targetId]);
        $checklistTripId = $target->fetchColumn();
        $template = inspectionTemplateForForm($pdo, 'checklist');
        $passed = false;
        if ($checklistTripId !== false && $template) {
            foreach (tripTemplateInspectionResults($pdo, (int)$checklistTripId, (int)$template['template_id']) as $row) {
                $passed = $passed || $row['overall_result'] === 'Passed';
            }
        }
        if ($checklistTripId === false || $passed) {
            jsonFail('Select an approved dispatch that does not already have a passed checklist.', 409);
        }
    } elseif ($formType === 'inspection_general') {
        $target = $pdo->prepare("SELECT truck_id FROM trucks WHERE truck_id = ? AND status <> 'Unavailable'");
        $target->execute([$targetId]);
        if (!$target->fetchColumn()) {
            jsonFail('Select an active truck for inspection.', 409);
        }
    } else {
        $trip = inspectionTripState($pdo, $targetId, $formType, false);
        $expectedStatus = $formType === 'inspection_departure' ? 'Assigned' : 'Delivered';
        if (!$trip || $trip['status'] !== $expectedStatus) {
            jsonFail("Select a trip in {$expectedStatus} status.", 409);
        }
        if ($formType === 'inspection_return') {
            $departure = $pdo->prepare(
                "SELECT inspection_id FROM vehicle_inspections
                 WHERE trip_id = ? AND inspection_stage = 'Departure' AND completed_at IS NOT NULL LIMIT 1"
            );
            $departure->execute([$targetId]);
            if (!$departure->fetchColumn()) {
                jsonFail('Complete the departure inspection before starting the return inspection.', 409);
            }
        }
    }
    if ($formType !== 'inspection_general' && inspectionTemplateForForm($pdo, $formType) === null) {
        jsonFail('This inspection form is not configured (no active inspection template). Ask an administrator to set up the inspection templates.', 409);
    }

    try {
        $token = startInspectionFormTimer($formType, $targetId);
        $minimum = minimumInspectionSeconds($formType === 'inspection_general' ? null : inspectionTemplateForForm($pdo, $formType), $formType);
        jsonOk(
            ['timer_token' => $token, 'minimum_seconds' => $minimum],
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
    if (($_POST['type'] ?? '') === 'Corrective') {
        $_POST['type'] = 'Repair'; // legacy form value
    }
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
    if ($cost !== null && $cost < 0) {
        jsonFail('Cost cannot be negative.');
    }

    // Verify truck exists
    findOrFail($pdo, 'trucks', 'truck_id', $truckId, 'Truck not found.');

    // Linked trip inspection must belong to this truck.
    if ($inspectionId) {
        $inspectionCheck = $pdo->prepare('SELECT inspection_id FROM vehicle_inspections WHERE inspection_id = ? AND truck_id = ?');
        $inspectionCheck->execute([$inspectionId, $truckId]);
        if (!$inspectionCheck->fetch()) {
            jsonFail('Inspection does not belong to the selected truck.');
        }
    }

    // Verify incident belongs to this truck if provided
    if ($incidentId) {
        $incCheck = $pdo->prepare("
            SELECT i.incident_id FROM trip_incidents i
            JOIN trips t ON i.trip_id = t.trip_id
            WHERE i.incident_id = ? AND COALESCE(i.truck_id, t.truck_id) = ?
        ");
        $incCheck->execute([$incidentId, $truckId]);
        if (!$incCheck->fetch()) {
            jsonFail('Incident does not belong to the selected truck.');
        }
    }

    // Legacy truck_status of the record -> target record status and dates.
    [$recordStatus, $newTruckStatus] = match ($truckStatus) {
        'Operational'           => ['Completed', 'Available'],
        'Scheduled Maintenance' => ['Scheduled', 'Under Maintenance'],
        default                 => ['In Progress', 'Under Maintenance'],
    };

    try {
        $pdo->beginTransaction();
        $currentTruckStatus = lockTruckStatus($pdo, $truckId);
        if ($currentTruckStatus === false) {
            $pdo->rollBack();
            jsonFail('Truck not found.', 404);
        }
        // This action always sets the truck status (Available or Under
        // Maintenance), so it is refused while the truck has an active trip.
        if (activeTripForTruck($pdo, $truckId) !== null || in_array($currentTruckStatus, ['Assigned', 'On Trip'], true)) {
            $pdo->rollBack();
            jsonFail('This truck has an active trip and its maintenance status cannot be changed. Complete or cancel the trip first.', 409);
        }
        if ($truckStatus === 'Operational' && activeWorkOrderForTruck($pdo, $truckId) !== null) {
            $pdo->rollBack();
            jsonFail('Complete or cancel the active work order before returning this truck to service.', 409);
        }

        $costValue = $cost ?? 0.0;
        $stmt = $pdo->prepare("
            INSERT INTO maintenance_records
                (truck_id, maintenance_type, record_source, description,
                 scheduled_date, start_date, actual_completion_date, next_due_date, status,
                 other_cost, total_cost, completed_by, created_by, incident_id, vehicle_inspection_id)
            VALUES (?, ?, 'record', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $truckId, $type, $description,
            $recordStatus === 'Scheduled' ? $datePerformed : null,
            $recordStatus === 'Scheduled' ? null : $datePerformed,
            $recordStatus === 'Completed' ? $datePerformed : null,
            $nextDue, $recordStatus,
            $costValue, $costValue,
            $recordStatus === 'Completed' ? currentUserId() : null,
            currentUserId(), $incidentId, $inspectionId,
        ]);
        $newId = (int)$pdo->lastInsertId();

        if ($currentTruckStatus !== $newTruckStatus) {
            if (!in_array($currentTruckStatus, ['Available', 'Under Maintenance', 'Unavailable'], true)) {
                throw new DomainException('Truck status changed; reload and try again.');
            }
            $update = $pdo->prepare(
                "UPDATE trucks SET status = ?, availability_status = IF(? = 'Available', 'Available', 'Unavailable')
                 WHERE truck_id = ? AND status = ?"
            );
            $update->execute([$newTruckStatus, $newTruckStatus, $truckId, $currentTruckStatus]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('Truck status changed; reload and try again.');
            }
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
                UPDATE trip_incidents
                SET resolved_at = NOW(), resolved_by = ?,
                    action_taken = COALESCE(action_taken, ?)
                WHERE incident_id = ? AND resolved_at IS NULL
            ")->execute([currentUserId(), "Resolved via maintenance record #$newId", $incidentId]);
        }

        auditLog('LOG_MAINTENANCE_RECORD', 'maintenance_records', $newId, null, [
            'truck_id'         => $truckId,
            'maintenance_type' => $type,
            'truck_status'     => $truckStatus,
            'record_status'    => $recordStatus,
            'date_performed'   => $datePerformed,
            'cost'             => $cost,
            'incident_id'      => $incidentId,
            'inspection_id'    => $inspectionId,
        ]);
        $pdo->commit();

        jsonOk(['id' => $newId], 'Maintenance record saved.');
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonFail($e->getMessage(), 409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('maintenance_handler/log_record: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Save vehicle inspection ───────────────────────────────────────────────────
if ($action === 'save_inspection') {
    $truckId = requiredInt('truck_id', 'Truck', 1);
    $date = requiredDate('inspection_date', 'Inspection date', true);
    $notes = optionalString('notes', null, 2000);
    if (($_POST['inspection_stage'] ?? '') === 'Arrival') {
        $_POST['inspection_stage'] = 'Return';
    }
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
            'category'  => $view,
            'name'      => $part,
            'condition' => $condition,
            'notes'     => $findingNotes === '' ? null : $findingNotes,
        ];
    }

    $formType = $stage === 'General'
        ? 'inspection_general'
        : ($stage === 'Departure' ? 'inspection_departure' : 'inspection_return');
    $template = $stage === 'General' ? null : inspectionTemplateForForm($pdo, $formType);
    if ($stage !== 'General') {
        if ($template === null) {
            jsonFail('This inspection form is not configured (no active inspection template).', 409);
        }
        // Every required (active) template part must be present and checked;
        // a departure inspection passes only when all of them are Good.
        foreach (inspectionTemplateItems($pdo, (int)$template['template_id']) as $itemKey => $item) {
            if (!(int)$item['is_required'] || !(int)$item['is_active']) {
                continue;
            }
            if (!isset($findingKeys[$itemKey])) {
                jsonFail('Departure and return inspections must include every standard truck part.');
            }
            if ($findingKeys[$itemKey] === 'Not Checked') {
                jsonFail('Check every standard truck part before saving this trip inspection.');
            }
            if ($stage === 'Departure' && $findingKeys[$itemKey] !== 'Good') {
                jsonFail('Departure inspection cannot pass while any standard truck part needs attention.');
            }
        }
    }

    // Optional photos of a trip (departure / return) inspection, stored as
    // files + inspection_photos (and listed with the trip's documents).
    // Every file is checked before anything is written.
    $photoUploads = [];
    $photoFiles = $_FILES['inspection_photos'] ?? null;
    if ($stage !== 'General' && is_array($photoFiles) && is_array($photoFiles['name'] ?? null)) {
        if (count($photoFiles['name']) > 8) {
            jsonFail('Attach up to 8 inspection photos.');
        }
        foreach (array_keys($photoFiles['name']) as $index) {
            $file = [];
            foreach (['name', 'tmp_name', 'error', 'size'] as $key) {
                $file[$key] = $photoFiles[$key][$index] ?? null;
            }
            if ($file['error'] === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                $photoUploads[] = inspectTruckImageUpload($file) + ['tmp_name' => $file['tmp_name']];
            } catch (InvalidArgumentException $e) {
                jsonFail('Inspection photo: ' . $e->getMessage());
            }
        }
    }
    $storedPhotoPaths = [];

    $timerToken = (string)($_POST['timer_token'] ?? '');
    $timerTarget = $tripId ?? $truckId;
    $timerStartedAt = null;
    try {
        $pdo->beginTransaction();
        if ($tripId !== null) {
            $trip = inspectionTripState($pdo, $tripId, $formType, true);
            $expectedStatus = $stage === 'Departure' ? 'Assigned' : 'Delivered';
            if (!$trip || $trip['status'] !== $expectedStatus || (int)$trip['truck_id'] !== $truckId) {
                $pdo->rollBack();
                jsonFail("The selected trip must be in {$expectedStatus} status and use this truck.");
            }
            if (!$trip['ready']) {
                $pdo->rollBack();
                jsonFail($stage === 'Departure'
                    ? 'Confirm the assignment and prepare trip documents and allowance before the departure inspection.'
                    : 'Record delivery and return details before the arrival inspection.', 409);
            }
            $already = tripTemplateInspectionResults($pdo, $tripId, (int)$template['template_id']);
            foreach ($already as $row) {
                $sameStage = $pdo->prepare('SELECT inspection_stage FROM vehicle_inspections WHERE inspection_id = ?');
                $sameStage->execute([(int)$row['inspection_id']]);
                if ($sameStage->fetchColumn() === ($stage === 'Departure' ? 'Departure' : 'Arrival')) {
                    $pdo->rollBack();
                    jsonFail('An inspection for this trip stage has already been submitted.', 409);
                }
            }
            if ($stage === 'Return') {
                $departure = $pdo->prepare(
                    "SELECT inspection_id FROM vehicle_inspections
                     WHERE trip_id = ? AND inspection_stage = 'Departure' AND completed_at IS NOT NULL LIMIT 1"
                );
                $departure->execute([$tripId]);
                if (!$departure->fetchColumn()) {
                    $pdo->rollBack();
                    jsonFail('Complete the trip departure inspection before recording its return inspection.');
                }
            }
        } else {
            if (lockTruckStatus($pdo, $truckId) === false) {
                $pdo->rollBack();
                jsonFail('Truck not found.', 404);
            }
        }
        $timerStartedAt = consumeInspectionFormTimer($timerToken, $formType, $timerTarget, minimumInspectionSeconds($template, $formType));

        if ($stage === 'General') {
            // General inspection: a completed 'Inspection' maintenance record
            // with one maintenance finding per part (T-M2 record_source).
            $pdo->prepare(
                "INSERT INTO maintenance_records
                    (truck_id, maintenance_type, record_source, description, start_date,
                     actual_completion_date, status, completed_by, created_by)
                 VALUES (?, 'Inspection', 'inspection', ?, ?, ?, 'Completed', ?, ?)"
            )->execute([$truckId, $notes ?? 'General vehicle inspection', $date, $date, currentUserId(), currentUserId()]);
            $inspectionId = (int)$pdo->lastInsertId();
            $findingStmt = $pdo->prepare(
                'INSERT INTO maintenance_findings (maintenance_id, finding_type, description, severity)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($validatedFindings as $finding) {
                $findingStmt->execute([
                    $inspectionId,
                    mb_substr($finding['category'] . ': ' . $finding['name'], 0, 80, 'UTF-8'),
                    $finding['condition'] . ($finding['notes'] !== null ? ' — ' . $finding['notes'] : ''),
                    inspectionConditionSeverity($finding['condition']),
                ]);
            }
            $auditEntity = 'maintenance_records';
        } else {
            $hasFlagged = array_filter($validatedFindings, static fn($f) => inspectionConditionResult($f['condition']) === 'Flagged');
            $overall = $hasFlagged ? 'Flagged' : 'Passed';
            $inspectionId = saveTripInspection(
                $pdo,
                $tripId,
                $truckId,
                $stage === 'Departure' ? 'Departure' : 'Arrival',
                (int)$template['template_id'],
                $validatedFindings,
                $overall,
                $notes,
                $timerStartedAt,
                currentUserId()
            );
            $photoInsert = $pdo->prepare(
                'INSERT INTO inspection_photos (inspection_id, file_id, photo_type)
                 SELECT ?, file_id, ? FROM documents WHERE document_id = ?'
            );
            foreach ($photoUploads as $photo) {
                $storedName = bin2hex(random_bytes(16)) . '.' . $photo['extension'];
                $destination = documentStorageDirectory() . DIRECTORY_SEPARATOR . $storedName;
                if (!move_uploaded_file($photo['tmp_name'], $destination)) {
                    throw new RuntimeException('Inspection photo could not be saved.');
                }
                $storedPhotoPaths[] = $destination;
                $documentId = recordStoredDocument(
                    $pdo, $photo, $storedName, $destination, 'Maintenance Record',
                    ($stage === 'Departure' ? 'Departure' : 'Return') . ' inspection photo',
                    'maintenance', currentUserId(), null, [['trip', $tripId]]
                );
                $photoInsert->execute([$inspectionId, $stage === 'Departure' ? 'Departure' : 'Arrival', $documentId]);
            }
            if ($stage === 'Departure') {
                if (recordPreDepartureReadyIfComplete($pdo, $tripId, currentUserId())) {
                    resolveTripHandoffs($pdo, $tripId, ['departure_checks']);
                    notifyTripHandoff($pdo, $tripId, 'clearance');
                }
            } elseif (tripArrivalInspectionComplete($pdo, $tripId)) {
                // Step 12; the truck is back: Delivered -> Returned.
                recordTripWorkflowEvent(
                    $pdo,
                    $tripId,
                    12,
                    'arrival-inspection-completed',
                    currentUserId(),
                    'Complete return vehicle inspection submitted.',
                    'Delivered',
                    'Returned'
                );
                transitionTripStatus($pdo, $tripId, ['Delivered'], 'Returned', ['return_time = COALESCE(return_time, NOW())']);
                resolveTripHandoffs($pdo, $tripId, ['return_inspection']);
                notifyTripHandoff($pdo, $tripId, 'returned');
            }
            $auditEntity = 'vehicle_inspections';
        }
        auditLog('SAVE_VEHICLE_INSPECTION', $auditEntity, $inspectionId, null, [
            'truck_id' => $truckId,
            'trip_id' => $tripId,
            'inspection_stage' => $stage,
            'photos' => count($photoUploads),
        ]);
        $pdo->commit();
        jsonOk([], 'Vehicle inspection saved.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($storedPhotoPaths as $storedPath) {
            if (is_file($storedPath)) unlink($storedPath);
        }
        if ($timerStartedAt !== null) {
            restoreInspectionFormTimer($timerToken, $formType, $timerTarget, $timerStartedAt);
        }
        if ($e instanceof DomainException) {
            jsonFail($e->getMessage(), 409);
        }
        error_log('maintenance_handler/save_inspection: ' . $e->getMessage());
        jsonFail('Could not save vehicle inspection.', 500);
    }
}

// ── Submit pre-trip checklist ─────────────────────────────────────────────────
// Target: a departure vehicle inspection on the 'Pre-trip checklist'
// template (T-N1). dispatch_id is the dispatch_request_id; the checklist is
// stored against its trip.
if ($action === 'submit_checklist') {

    $dispatchId = requiredInt('dispatch_id', 'Dispatch', 1);
    $notes      = optionalString('notes', null, 2000);

    $template = inspectionTemplateForForm($pdo, 'checklist');
    if ($template === null) {
        jsonFail('The pre-trip checklist is not configured (no active checklist template).', 409);
    }

    $findings = [];
    $allPassed = true;
    foreach (CHECKLIST_ITEM_FIELDS as $field => $label) {
        $ok = isset($_POST[$field]) && $_POST[$field] === '1';
        $allPassed = $allPassed && $ok;
        $findings[] = [
            'category'  => 'Checklist',
            'name'      => $label,
            'condition' => $ok ? 'Good' : 'Needs Attention',
            'notes'     => null,
        ];
    }
    $result = $allPassed ? 'Passed' : 'Failed';

    $timerToken = (string)($_POST['timer_token'] ?? '');
    $timerStartedAt = null;
    try {
        $pdo->beginTransaction();
        $lockedDispatch = $pdo->prepare(
            "SELECT dr.dispatch_request_id, dr.trip_id, t.truck_id
             FROM dispatch_requests dr
             JOIN trips t ON t.trip_id = dr.trip_id
             WHERE dr.dispatch_request_id = ? AND dr.status = 'Approved' FOR UPDATE"
        );
        $lockedDispatch->execute([$dispatchId]);
        $dispatch = $lockedDispatch->fetch(PDO::FETCH_ASSOC);
        if (!$dispatch) {
            $pdo->rollBack();
            jsonFail('Dispatch not found or not approved.', 404);
        }
        $tripId = (int)$dispatch['trip_id'];
        $trip = inspectionTripState($pdo, $tripId, 'checklist', true);
        if (!$trip || !$trip['ready']) {
            $pdo->rollBack();
            jsonFail('Confirm the assignment and prepare trip documents and allowance before the pre-departure checklist.', 409);
        }
        foreach (tripTemplateInspectionResults($pdo, $tripId, (int)$template['template_id']) as $row) {
            if ($row['overall_result'] === 'Passed') {
                $pdo->rollBack();
                jsonFail('A passed checklist has already been submitted for this dispatch.', 409);
            }
        }
        $timerStartedAt = consumeInspectionFormTimer($timerToken, 'checklist', $dispatchId, minimumInspectionSeconds($template, 'checklist'));

        // A failed checklist is kept and may be repeated after corrective work.
        $newId = saveTripInspection(
            $pdo,
            $tripId,
            (int)$dispatch['truck_id'],
            'Departure',
            (int)$template['template_id'],
            $findings,
            $result,
            $notes,
            $timerStartedAt,
            currentUserId()
        );
        if (recordPreDepartureReadyIfComplete($pdo, $tripId, currentUserId())) {
            resolveTripHandoffs($pdo, $tripId, ['departure_checks']);
            notifyTripHandoff($pdo, $tripId, 'clearance');
        }

        auditLog('SUBMIT_CHECKLIST', 'vehicle_inspections', $newId, null, [
            'dispatch_id' => $dispatchId,
            'trip_id'     => $tripId,
            'result'      => $result,
        ]);
        $pdo->commit();

        jsonOk(
            ['result' => $result, 'id' => $newId],
            "Checklist submitted — result: <strong>$result</strong>."
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($timerStartedAt !== null) {
            restoreInspectionFormTimer($timerToken, 'checklist', $dispatchId, $timerStartedAt);
        }
        if ($e instanceof DomainException) {
            jsonFail($e->getMessage(), 409);
        }
        error_log('maintenance_handler/submit_checklist: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Unknown action ────────────────────────────────────────────────────────────
jsonFail('Unknown action.');
