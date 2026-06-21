<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
$pdo = getDBConnection();
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_MAINTENANCE]);

$action = $_POST['action'] ?? '';

// ─── Log a new incident ───────────────────────────────────────────────────────
if ($action === 'log') {

    // Only dispatchers and head management may log
    if (!in_array(currentRoleId(), [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER])) {
        echo json_encode(['success' => false, 'message' => 'You are not authorised to log incidents.']);
        exit;
    }

    $tripId      = (int)($_POST['trip_id']     ?? 0);
    $type        = trim($_POST['type']          ?? '');
    $description = trim($_POST['description']   ?? '');

    $allowedTypes = ['Vehicle Breakdown', 'Item Damage', 'Delay', 'Other'];

    if (!$tripId || !$type || !$description) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }

    if (!in_array($type, $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Invalid incident type.']);
        exit;
    }

    // Verify the trip exists and is active
    $tripStmt = $pdo->prepare("SELECT id, status FROM trips WHERE id = ?");
    $tripStmt->execute([$tripId]);
    $trip = $tripStmt->fetch(PDO::FETCH_ASSOC);

    if (!$trip) {
        echo json_encode(['success' => false, 'message' => 'Trip not found.']);
        exit;
    }

    if (!in_array($trip['status'], ['approved', 'in_progress'])) {
        echo json_encode(['success' => false, 'message' => 'Incidents can only be logged against active trips.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO incidents (trip_id, type, description, status, reported_by, reported_at)
            VALUES (?, ?, ?, 'open', ?, NOW())
        ");
        $stmt->execute([$tripId, $type, $description, currentUserId()]);
        $newId = $pdo->lastInsertId();

        auditLog('LOG_INCIDENT', 'incidents', $newId, null, [
            'trip_id'     => $tripId,
            'type'        => $type,
            'description' => $description,
        ]);

        echo json_encode(['success' => true, 'message' => 'Incident logged successfully.', 'id' => $newId]);
    } catch (PDOException $e) {
        error_log('log_incident error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ─── Resolve an existing incident ────────────────────────────────────────────
if ($action === 'resolve') {

    $incidentId = (int)($_POST['incident_id'] ?? 0);

    if (!$incidentId) {
        echo json_encode(['success' => false, 'message' => 'Invalid incident ID.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, status FROM incidents WHERE id = ?");
    $stmt->execute([$incidentId]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$incident) {
        echo json_encode(['success' => false, 'message' => 'Incident not found.']);
        exit;
    }

    if ($incident['status'] === 'resolved') {
        echo json_encode(['success' => false, 'message' => 'This incident is already resolved.']);
        exit;
    }

    try {
        $old = ['status' => 'open'];

        $upd = $pdo->prepare("
            UPDATE incidents
            SET status = 'resolved', resolved_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$incidentId]);

        auditLog('RESOLVE_INCIDENT', 'incidents', $incidentId, $old, ['status' => 'resolved']);

        echo json_encode(['success' => true, 'message' => 'Incident marked as resolved.']);
    } catch (PDOException $e) {
        error_log('resolve_incident error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ─── Unknown action ───────────────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);