<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_MAINTENANCE]);

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Log maintenance record ────────────────────────────────────────────────────
if ($action === 'log_record') {

    $truckId      = (int)($_POST['truck_id']     ?? 0);
    $type         = trim($_POST['type']           ?? '');
    $truckStatus  = trim($_POST['truck_status']   ?? '');
    $description  = trim($_POST['description']    ?? '');
    $datePerformed = trim($_POST['date_performed'] ?? '');
    $nextDue      = trim($_POST['next_due_date']  ?? '') ?: null;
    $cost         = $_POST['cost'] !== '' ? (float)$_POST['cost'] : null;
    $incidentId   = (int)($_POST['incident_id']   ?? 0) ?: null;
    $inspectionId = (int)($_POST['inspection_id'] ?? 0) ?: null;

    $allowedTypes    = ['Preventive', 'Corrective', 'Inspection'];
    $allowedStatuses = ['Operational', 'Scheduled Maintenance', 'Under Repair'];

    if (!$truckId || !$type || !$truckStatus || !$description || !$datePerformed) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
        exit;
    }

    if (!in_array($type, $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Invalid maintenance type.']);
        exit;
    }

    if (!in_array($truckStatus, $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid truck status.']);
        exit;
    }

    // Validate date
    if (!isValidDate($datePerformed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
        exit;
    }
    if (isPassedDate($datePerformed)) {
        echo json_encode(['success' => false, 'message' => 'New maintenance records cannot use a passed date.']);
        exit;
    }
    if ($nextDue !== null && (!isValidDate($nextDue) || isPassedDate($nextDue))) {
        echo json_encode(['success' => false, 'message' => 'Next due date cannot be a passed date.']);
        exit;
    }

    // Verify truck exists
    $truckCheck = $pdo->prepare("SELECT truck_id FROM trucks WHERE truck_id = ?");
    $truckCheck->execute([$truckId]);
    if (!$truckCheck->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Truck not found.']);
        exit;
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
            echo json_encode(['success' => false, 'message' => 'Incident does not belong to the selected truck.']);
            exit;
        }

        if ($inspectionId) {
            $inspectionCheck = $pdo->prepare("SELECT inspection_id FROM vehicle_inspections WHERE inspection_id = ? AND truck_id = ?");
            $inspectionCheck->execute([$inspectionId, $truckId]);
            if (!$inspectionCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Inspection does not belong to the selected truck.']);
                exit;
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

        echo json_encode(['success' => true, 'message' => 'Maintenance record saved.', 'id' => $newId]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('maintenance_handler/log_record: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

if ($action === 'save_inspection') {
        $truckId = (int)($_POST['truck_id'] ?? 0);
        $date = trim($_POST['inspection_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '') ?: null;
        $findings = json_decode($_POST['findings'] ?? '[]', true);
        $allowedViews = ['Front', 'Side', 'Rear', 'Top'];
        $allowedConditions = ['Good', 'Needs Attention', 'Damaged', 'Missing', 'Leaking', 'Worn', 'Not Checked'];

        if (!$truckId || !isValidDate($date) || !is_array($findings)) {
            echo json_encode(['success' => false, 'message' => 'Vehicle, date, and valid inspection findings are required.']);
            exit;
        }
        if (isPassedDate($date)) {
            echo json_encode(['success' => false, 'message' => 'New inspections cannot use a passed date.']);
            exit;
        }

        $truckCheck = $pdo->prepare("SELECT truck_id FROM trucks WHERE truck_id = ?");
        $truckCheck->execute([$truckId]);
        if (!$truckCheck->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Truck not found.']);
            exit;
        }

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
                if (!in_array($view, $allowedViews, true) || !$part || !in_array($condition, $allowedConditions, true)) {
                    throw new InvalidArgumentException('Invalid inspection finding.');
                }
                $findingStmt->execute([$inspectionId, $view, $part, $condition, trim($finding['notes'] ?? '') ?: null]);
            }
            $pdo->commit();
            auditLog('SAVE_VEHICLE_INSPECTION', 'vehicle_inspections', $inspectionId, null, ['truck_id' => $truckId]);
            echo json_encode(['success' => true, 'message' => 'Vehicle inspection saved.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('maintenance_handler/save_inspection: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Could not save vehicle inspection.']);
        }
        exit;
    }

// ── Submit pre-trip checklist ─────────────────────────────────────────────────
if ($action === 'submit_checklist') {

        $dispatchId = (int)($_POST['dispatch_id'] ?? 0);
        $notes      = trim($_POST['notes']        ?? '') ?: null;

        if (!$dispatchId) {
            echo json_encode(['success' => false, 'message' => 'Please select a dispatch.']);
            exit;
        }
    // Verify dispatch exists and is approved
    $dispCheck = $pdo->prepare("
        SELECT dispatch_id, truck_id FROM dispatch_requests
        WHERE dispatch_id = ? AND status = 'Approved'
    ");
    $dispCheck->execute([$dispatchId]);
    $dispatch = $dispCheck->fetch(PDO::FETCH_ASSOC);

    if (!$dispatch) {
        echo json_encode(['success' => false, 'message' => 'Dispatch not found or not approved.']);
        exit;
    }

    // Guard: only one checklist per dispatch
    $dupCheck = $pdo->prepare("SELECT checklist_id FROM maintenance_checklists WHERE dispatch_id = ?");
    $dupCheck->execute([$dispatchId]);
    if ($dupCheck->fetch()) {
        echo json_encode(['success' => false, 'message' => 'A checklist has already been submitted for this dispatch.']);
        exit;
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

        echo json_encode([
            'success' => true,
            'message' => "Checklist submitted — result: <strong>$result</strong>.",
            'result'  => $result,
            'id'      => $newId,
        ]);
    } catch (PDOException $e) {
        error_log('maintenance_handler/submit_checklist: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);