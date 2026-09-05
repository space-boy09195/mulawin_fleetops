<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_MAINTENANCE]);
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Log a new incident ────────────────────────────────────────────────────────
if ($action === 'log') {

    if (!in_array(currentRoleId(), [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER], true)) {
        jsonFail('You are not authorised to log incidents.', 403);
    }

    $tripId      = requiredInt('trip_id', 'Trip ID', 1);
    $type        = requiredEnum('type', INCIDENT_TYPES, 'Incident type');
    $description = requiredString('description', 'Description', 1000);

    // Verify trip exists and is still active
    $trip = findOrFail($pdo, 'trips', 'trip_id', $tripId, 'Trip not found.');

    if (in_array($trip['status'], TRIP_TERMINAL_STATUSES, true)) {
        jsonFail('Incidents can only be logged against active trips.');
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

        jsonOk(['id' => $newId], 'Incident logged successfully.');
    } catch (PDOException $e) {
        error_log('log_incident/log: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Resolve an existing incident ──────────────────────────────────────────────
if ($action === 'resolve') {

    $incidentId      = requiredInt('incident_id', 'Incident ID', 1);
    $resolutionNotes = optionalString('resolution_notes', null, 1000);

    $incident = findOrFail($pdo, 'incidents', 'incident_id', $incidentId, 'Incident not found.');

    if (!empty($incident['resolved_at'])) {
        jsonFail('This incident is already resolved.');
    }

    try {
        $upd = $pdo->prepare("
            UPDATE incidents
            SET resolution_notes = ?,
                resolved_at      = NOW()
            WHERE incident_id = ?
        ");
        $upd->execute([$resolutionNotes, $incidentId]);

        auditLog('RESOLVE_INCIDENT', 'incidents', $incidentId,
            ['resolved_at' => null],
            ['resolved_at' => date('Y-m-d H:i:s'), 'resolution_notes' => $resolutionNotes]
        );

        jsonOk([], 'Incident marked as resolved.');
    } catch (PDOException $e) {
        error_log('log_incident/resolve: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

jsonFail('Unknown action.');
