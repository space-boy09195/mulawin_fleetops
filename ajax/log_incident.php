<?php
// ============================================================
// ajax/log_incident.php
// Trip problem reports on the target trip_incidents table.
//   log      incident_type / severity (target ENUMs), incident_date = now,
//            truck_id copied from the trip
//   resolve  action_taken = resolution notes, resolved_at / resolved_by
//            (T-I1); single winner under concurrency (resolved_at IS NULL)
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/workflow_events.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';

header('Content-Type: application/json');

// A dispatcher may report or resolve problems only on trips in their
// assigned truck types and shift (same 403 as the other trip endpoints).
function enforceIncidentTripScope(PDO $pdo, int $tripId): void {
    $stmt = $pdo->prepare(
        'SELECT t.shift, tr.truck_type FROM trips t
         LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
         WHERE t.trip_id = ?'
    );
    $stmt->execute([$tripId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['shift' => '', 'truck_type' => ''];
    $violation = dispatcherScopeViolation((string)$row['shift'], (string)$row['truck_type']);
    if ($violation !== null) {
        jsonFail($violation, 403);
    }
}

requireAnyPermission(['incidents.manage', 'legacy.role.3']);
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// Legacy form values kept working while pages are updated.
const LEGACY_INCIDENT_TYPE_MAP = [
    'Vehicle Breakdown' => 'Breakdown',
    'Item Damage'       => 'Damage',
];

// ── Log a new incident ────────────────────────────────────────────────────────
if ($action === 'log') {

    if (!currentUserHasAnyPermission(['incidents.manage'])) {
        jsonFail('You are not authorised to report trip problems.', 403);
    }

    $tripId = requiredInt('trip_id', 'Trip ID', 1);
    $rawType = (string)($_POST['type'] ?? '');
    if (isset(LEGACY_INCIDENT_TYPE_MAP[$rawType])) {
        $_POST['type'] = LEGACY_INCIDENT_TYPE_MAP[$rawType];
    }
    $type        = requiredEnum('type', INCIDENT_TYPES, 'Incident type');
    $severity    = ($_POST['severity'] ?? '') === '' ? 'Low' : requiredEnum('severity', INCIDENT_SEVERITIES, 'Severity');
    $description = requiredString('description', 'Description', 1000);

    // Verify trip exists and is still active
    $trip = findOrFail($pdo, 'trips', 'trip_id', $tripId, 'Trip not found.');
    enforceIncidentTripScope($pdo, $tripId);

    if (in_array($trip['status'], TRIP_TERMINAL_STATUSES, true) || $trip['status'] === 'Pending Approval') {
        jsonFail('Problems can only be reported against active trips.');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO trip_incidents
                (trip_id, truck_id, incident_type, description, incident_date, severity, reported_by)
            VALUES (?, ?, ?, ?, NOW(), ?, ?)
        ");
        $stmt->execute([$tripId, $trip['truck_id'], $type, $description, $severity, currentUserId()]);
        $newId = (int)$pdo->lastInsertId();

        auditLog('REPORT_TRIP_PROBLEM', 'trip_incidents', $newId, null, [
            'trip_id'       => $tripId,
            'incident_type' => $type,
            'severity'      => $severity,
            'description'   => $description,
        ]);
        $pdo->commit();

        jsonOk(['id' => $newId], 'Trip problem report submitted successfully.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('log_incident/log: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Resolve an existing incident ──────────────────────────────────────────────
if ($action === 'resolve') {

    $incidentId      = requiredInt('incident_id', 'Incident ID', 1);
    $resolutionNotes = optionalString('resolution_notes', null, 1000);

    $incident = findOrFail($pdo, 'trip_incidents', 'incident_id', $incidentId, 'Incident not found.');
    enforceIncidentTripScope($pdo, (int)$incident['trip_id']);

    if (!empty($incident['resolved_at'])) {
        jsonFail('This incident is already resolved.');
    }

    try {
        $pdo->beginTransaction();

        // Only an unresolved incident can be resolved, so a concurrent
        // resolution that loses the race changes nothing.
        $upd = $pdo->prepare("
            UPDATE trip_incidents
            SET action_taken = ?,
                resolved_at  = NOW(),
                resolved_by  = ?
            WHERE incident_id = ?
              AND resolved_at IS NULL
        ");
        $upd->execute([$resolutionNotes, currentUserId(), $incidentId]);

        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            jsonFail('This incident is already resolved.');
        }

        // The audit row is written in the same transaction as the update.
        auditLog('RESOLVE_TRIP_PROBLEM', 'trip_incidents', $incidentId,
            ['resolved_at' => null],
            ['resolved_at' => date('Y-m-d H:i:s'), 'resolution_notes' => $resolutionNotes]
        );

        $pdo->commit();

        jsonOk([], 'Trip problem marked as resolved.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('log_incident/resolve: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

jsonFail('Unknown action.');
