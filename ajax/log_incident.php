<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_MAINTENANCE]);

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Log a new incident ────────────────────────────────────────────────────────
if ($action === 'log') {

    if (!in_array(currentRoleId(), [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER])) {
        echo json_encode(['success' => false, 'message' => 'You are not authorised to log incidents.']);
        exit;
    }

    $tripId      = (int)($_POST['trip_id']    ?? 0);
    $type        = trim($_POST['type']        ?? '');
    $description = trim($_POST['description'] ?? '');

    $allowedTypes = ['Vehicle Breakdown', 'Item Damage', 'Delay', 'Other'];

    if (!$tripId || !$type || !$description) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }

    if (!in_array($type, $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Invalid incident type.']);
        exit;
    }

    // Verify trip exists and is still active
    $tripStmt = $pdo->prepare("SELECT trip_id, status FROM trips WHERE trip_id = ?");
    $tripStmt->execute([$tripId]);
    $trip = $tripStmt->fetch(PDO::FETCH_ASSOC);

    if (!$trip) {
        echo json_encode(['success' => false, 'message' => 'Trip not found.']);
        exit;
    }

    if (in_array($trip['status'], ['Completed', 'Cancelled'])) {
        echo json_encode(['success' => false, 'message' => 'Incidents can only be logged against active trips.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO incidents (trip_id, incident_type, description, reported_by, reported_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$tripId, $type, $description, currentUserId()]);
        $newId = (int)$pdo->lastInsertId();

        auditLog('LOG_INCIDENT', 'incidents', $newId, null, [
            'trip_id'       => $tripId,
            'incident_type' => $type,
            'description'   => $description,
        ]);

        echo json_encode(['success' => true, 'message' => 'Incident logged successfully.', 'id' => $newId]);
    } catch (PDOException $e) {
        error_log('log_incident/log: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Resolve an existing incident ──────────────────────────────────────────────
if ($action === 'resolve') {

    $incidentId      = (int)($_POST['incident_id']     ?? 0);
    $resolutionNotes = trim($_POST['resolution_notes'] ?? '');

    if (!$incidentId) {
        echo json_encode(['success' => false, 'message' => 'Invalid incident ID.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT incident_id, resolved_at FROM incidents WHERE incident_id = ?");
    $stmt->execute([$incidentId]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$incident) {
        echo json_encode(['success' => false, 'message' => 'Incident not found.']);
        exit;
    }

    if (!empty($incident['resolved_at'])) {
        echo json_encode(['success' => false, 'message' => 'This incident is already resolved.']);
        exit;
    }

    try {
        $upd = $pdo->prepare("
            UPDATE incidents
            SET resolution_notes = ?,
                resolved_at      = NOW()
            WHERE incident_id = ?
        ");
        $upd->execute([
            $resolutionNotes ?: null,
            $incidentId,
        ]);

        auditLog('RESOLVE_INCIDENT', 'incidents', $incidentId,
            ['resolved_at' => null],
            ['resolved_at' => date('Y-m-d H:i:s'), 'resolution_notes' => $resolutionNotes]
        );

        echo json_encode(['success' => true, 'message' => 'Incident marked as resolved.']);
    } catch (PDOException $e) {
        error_log('log_incident/resolve: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);