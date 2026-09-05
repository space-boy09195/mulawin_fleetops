<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_MAINTENANCE]);
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

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

        if ($inspectionId) {
            $inspectionCheck = $pdo->prepare("SELECT inspection_id FROM vehicle_inspections WHERE inspection_id = ? AND truck_id = ?");
            $inspectionCheck->execute([$inspectionId, $truckId]);
            if (!$inspectionCheck->fetch()) {
                jsonFail('Inspection does not belong to the selected truck.');
            }
        }
    }

    try {
        $pdo->beginTransaction();

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
        $pdo->prepare("UPDATE trucks SET status = ? WHERE truck_id = ?")
            ->execute([
                $truckStatus === 'Operational' ? 'Available' : 'Under Maintenance',
                $truckId,
            ]);

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
    $truckId  = requiredInt('truck_id', 'Truck', 1);
    $date     = requiredDate('inspection_date', 'Inspection date', true);
    $notes    = optionalString('notes');
    $findings = json_decode($_POST['findings'] ?? '[]', true);

    if (!is_array($findings)) {
        jsonFail('Valid inspection findings are required.');
    }

    findOrFail($pdo, 'trucks', 'truck_id', $truckId, 'Truck not found.');

    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO vehicle_inspections (truck_id, inspected_by, inspection_date, notes) VALUES (?, ?, ?, ?)")
            ->execute([$truckId, currentUserId(), $date, $notes]);
        $inspectionId = (int)$pdo->lastInsertId();
        $findingStmt = $pdo->prepare("
            INSERT INTO vehicle_inspection_findings (inspection_id, view_name, part_name, condition, notes)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($findings as $finding) {
            $view = $finding['view'] ?? '';
            $part = trim($finding['part'] ?? '');
            $condition = $finding['condition'] ?? 'Not Checked';
            if (!in_array($view, INSPECTION_VIEWS, true) || !$part || !in_array($condition, INSPECTION_CONDITIONS, true)) {
                throw new InvalidArgumentException('Invalid inspection finding.');
            }
            $findingStmt->execute([$inspectionId, $view, $part, $condition, trim($finding['notes'] ?? '') ?: null]);
        }
        $pdo->commit();
        auditLog('SAVE_VEHICLE_INSPECTION', 'vehicle_inspections', $inspectionId, null, ['truck_id' => $truckId]);
        jsonOk([], 'Vehicle inspection saved.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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

    // Guard: only one checklist per dispatch
    $dupCheck = $pdo->prepare("SELECT checklist_id FROM maintenance_checklists WHERE dispatch_id = ?");
    $dupCheck->execute([$dispatchId]);
    if ($dupCheck->fetch()) {
        jsonFail('A checklist has already been submitted for this dispatch.');
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

        auditLog('SUBMIT_CHECKLIST', 'maintenance_checklists', $newId, null, [
            'dispatch_id' => $dispatchId,
            'result'      => $result,
        ]);

        jsonOk(
            ['result' => $result, 'id' => $newId],
            "Checklist submitted — result: <strong>$result</strong>."
        );
    } catch (PDOException $e) {
        error_log('maintenance_handler/submit_checklist: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Unknown action ────────────────────────────────────────────────────────────
jsonFail('Unknown action.');
