<?php

function recordTripWorkflowEvent(
    PDO $pdo,
    int $dispatchId,
    ?int $tripId,
    int $stepNumber,
    string $eventKey,
    int $actorUserId,
    ?string $details = null
): void {
    if ($stepNumber < 0 || $stepNumber > 13 || $eventKey === '') {
        throw new InvalidArgumentException('Invalid trip workflow event.');
    }

    $state = $pdo->prepare('SELECT 1 FROM trip_workflow_state WHERE dispatch_id = ?');
    $state->execute([$dispatchId]);
    if (!$state->fetchColumn()) {
        throw new DomainException('Tracked trip workflow state is missing.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO trip_workflow_events
            (dispatch_id, trip_id, step_number, event_key, actor_user_id, details)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE event_id = LAST_INSERT_ID(event_id)'
    );
    $insert->execute([$dispatchId, $tripId, $stepNumber, $eventKey, $actorUserId, $details]);

    $advance = $pdo->prepare(
        'UPDATE trip_workflow_state
         SET current_step = GREATEST(current_step, ?),
             trip_id = COALESCE(trip_id, ?)
         WHERE dispatch_id = ?'
    );
    $advance->execute([$stepNumber, $tripId, $dispatchId]);
}

function recordPreDepartureReadyIfComplete(
    PDO $pdo,
    int $dispatchId,
    int $tripId,
    int $actorUserId
): bool {
    $stateQuery = $pdo->prepare(
        'SELECT current_step FROM trip_workflow_state WHERE dispatch_id = ? FOR UPDATE'
    );
    $stateQuery->execute([$dispatchId]);
    $currentStep = $stateQuery->fetchColumn();
    if ($currentStep === false || (int)$currentStep < 6) {
        return false;
    }

    $checklist = $pdo->prepare(
        "SELECT 1 FROM maintenance_checklists
         WHERE dispatch_id = ? AND result = 'Passed'
         LIMIT 1"
    );
    $checklist->execute([$dispatchId]);
    if (!$checklist->fetchColumn()) {
        return false;
    }

    $inspection = $pdo->prepare(
        "SELECT COUNT(*) AS finding_count,
                SUM(vif.`condition` <> 'Good') AS non_good_count
         FROM vehicle_inspections vi
         JOIN vehicle_inspection_findings vif ON vif.inspection_id = vi.inspection_id
         WHERE vi.trip_id = ? AND vi.inspection_stage = 'Departure'"
    );
    $inspection->execute([$tripId]);
    $result = $inspection->fetch(PDO::FETCH_ASSOC);
    if (!$result || (int)$result['finding_count'] < 24
        || (int)($result['non_good_count'] ?? 0) !== 0) {
        return false;
    }

    recordTripWorkflowEvent(
        $pdo,
        $dispatchId,
        $tripId,
        7,
        'pre-departure-checks-passed',
        $actorUserId,
        'Passed pre-trip checklist and complete all-good departure inspection.'
    );
    return true;
}
