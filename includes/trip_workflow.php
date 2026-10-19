<?php
// ============================================================
// includes/trip_workflow.php
// Trip workflow on the target schema.
//
// The legacy trip_workflow_state / trip_workflow_events tables do not exist
// in the target. Each workflow step is a workflow_events row
// (entity_type 'trip', entity_id = trip_id, event_type = step key). The
// current step is the highest step recorded for the trip, so it can never
// disagree with the history. trips.status carries the operational state:
//
//   step  event key                          trips.status after the step
//   1-4   request-prepared ... submitted     Pending Approval
//   4     request-approved                   Approved
//   5     assignment-confirmed               Assigned
//   6     departure-packet-prepared          Assigned
//   7     pre-departure-checks-passed        Ready for Dispatch
//   8     operations-dispatch-clearance      Dispatched (dispatch_clearances)
//   9     truck-dispatched                   In Progress (departure_time)
//   10    progress-update-<trip_updates id>  In Progress (trip_updates)
//   11    delivery-return-details-recorded   In Progress (trip_deliveries/returns)
//         (status 'Delivered' is set by the dispatcher afterwards)
//   12    arrival-inspection-completed       Returned
//   13    completed-record-submitted         Completed
//   0     trip-cancelled / request-rejected  Cancelled
//
// Callers lock the trip row (lockTripForWorkflow) before using these
// functions, which serializes all workflow writes for one trip.
// ============================================================

require_once __DIR__ . '/workflow_events.php';
require_once __DIR__ . '/../config/enums.php';

const TRIP_WORKFLOW_STEPS = [
    'request-prepared'                 => 1,
    'resources-checked'                => 2,
    'trip-details-entered'             => 3,
    'request-submitted'                => 4,
    'request-approved'                 => 4,
    'assignment-confirmed'             => 5,
    'departure-packet-prepared'        => 6,
    'pre-departure-checks-passed'      => 7,
    'operations-dispatch-clearance'    => 8,
    'truck-dispatched'                 => 9,
    'delivery-return-details-recorded' => 11,
    'arrival-inspection-completed'     => 12,
    'completed-record-submitted'       => 13,
    'trip-cancelled'                   => 0,
    'request-rejected'                 => 0,
];

function tripWorkflowStepForKey(string $eventKey): ?int {
    if (isset(TRIP_WORKFLOW_STEPS[$eventKey])) {
        return TRIP_WORKFLOW_STEPS[$eventKey];
    }
    if (preg_match('/^progress-update-\d+$/', $eventKey)) {
        return 10;
    }
    return null;
}

/**
 * Highest workflow step recorded for a trip (0 when none).
 */
function tripWorkflowCurrentStep(PDO $pdo, int $tripId): int {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT event_type FROM workflow_events
         WHERE entity_type = 'trip' AND entity_id = ?"
    );
    $stmt->execute([$tripId]);
    $step = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
        $step = max($step, tripWorkflowStepForKey((string)$key) ?? 0);
    }
    return $step;
}

function tripWorkflowEventExists(PDO $pdo, int $tripId, string $eventKey): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM workflow_events
         WHERE entity_type = 'trip' AND entity_id = ? AND event_type = ? LIMIT 1"
    );
    $stmt->execute([$tripId, $eventKey]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Record a workflow step once per trip (a repeated key is a no-op, like the
 * legacy unique (dispatch_id, event_key)). Returns false when it already
 * existed.
 */
function recordTripWorkflowEvent(
    PDO $pdo,
    int $tripId,
    int $stepNumber,
    string $eventKey,
    int $actorUserId,
    ?string $details = null,
    ?string $oldStatus = null,
    ?string $newStatus = null
): bool {
    $expected = tripWorkflowStepForKey($eventKey);
    if ($expected === null || $expected !== $stepNumber) {
        throw new InvalidArgumentException('Invalid trip workflow event.');
    }
    if (tripWorkflowEventExists($pdo, $tripId, $eventKey)) {
        return false;
    }
    recordWorkflowEvent($pdo, 'trip', $tripId, $eventKey, $oldStatus, $newStatus, $details, $actorUserId);
    return true;
}

/**
 * Move a trip from one of $fromStatuses to $toStatus. Throws when the trip
 * is not in an allowed status (a concurrent change, or a replayed request).
 * $extraSet: fixed "column = value-expression" fragments from the caller's
 * code (never input), e.g. ['departure_time = NOW()'].
 */
function transitionTripStatus(PDO $pdo, int $tripId, array $fromStatuses, string $toStatus, array $extraSet = []): void {
    if (!in_array($toStatus, TRIP_STATUSES, true)) {
        throw new InvalidArgumentException('Unknown trip status.');
    }
    $allowedExtra = [
        'departure_time = NOW()',
        'arrival_time = NOW()',
        'completion_time = NOW()',
        'return_time = NOW()',
        'arrival_time = COALESCE(arrival_time, NOW())',
        'return_time = COALESCE(return_time, NOW())',
    ];
    foreach ($extraSet as $fragment) {
        if (!in_array($fragment, $allowedExtra, true)) {
            throw new InvalidArgumentException('Unsupported trip update.');
        }
    }
    $placeholders = implode(',', array_fill(0, count($fromStatuses), '?'));
    $set = 'status = ?' . ($extraSet ? ', ' . implode(', ', $extraSet) : '');
    $stmt = $pdo->prepare("UPDATE trips SET {$set} WHERE trip_id = ? AND status IN ($placeholders)");
    $stmt->execute(array_merge([$toStatus, $tripId], $fromStatuses));
    if ($stmt->rowCount() !== 1) {
        throw new DomainException('The trip status changed in the meantime. Refresh the page and try again.');
    }
}

/**
 * Required items of the active templates for an inspection stage that are
 * not yet covered for this trip. Departure: each required item needs a
 * 'Good' finding in a completed, Passed departure inspection. Arrival: each
 * required item needs a finding (any result) in a completed arrival
 * inspection. Returns null when no active template/item exists for the
 * stage (not configured: callers fail closed).
 */
function tripInspectionMissingItems(PDO $pdo, int $tripId, string $stage): ?int {
    if (!in_array($stage, ['Departure', 'Arrival'], true)) {
        throw new InvalidArgumentException('Unknown inspection stage.');
    }
    $required = $pdo->prepare(
        'SELECT COUNT(*)
         FROM inspection_items ii
         JOIN inspection_templates it ON it.template_id = ii.template_id
         WHERE it.inspection_stage = ? AND it.is_active = 1
           AND ii.is_active = 1 AND ii.is_required = 1'
    );
    $required->execute([$stage]);
    if ((int)$required->fetchColumn() === 0) {
        return null;
    }
    $resultRule = $stage === 'Departure'
        ? "AND vi.overall_result = 'Passed' AND f.result = 'Good'"
        : '';
    $missing = $pdo->prepare(
        "SELECT COUNT(*)
         FROM inspection_items ii
         JOIN inspection_templates it ON it.template_id = ii.template_id
         WHERE it.inspection_stage = ? AND it.is_active = 1
           AND ii.is_active = 1 AND ii.is_required = 1
           AND NOT EXISTS (
               SELECT 1
               FROM inspection_findings f
               JOIN vehicle_inspections vi ON vi.inspection_id = f.inspection_id
               WHERE vi.trip_id = ? AND vi.inspection_stage = ?
                 AND vi.completed_at IS NOT NULL
                 AND f.inspection_item_id = ii.inspection_item_id
                 $resultRule
           )"
    );
    $missing->execute([$stage, $tripId, $stage]);
    return (int)$missing->fetchColumn();
}

function tripDepartureChecksPassed(PDO $pdo, int $tripId): bool {
    return tripInspectionMissingItems($pdo, $tripId, 'Departure') === 0;
}

function tripArrivalInspectionComplete(PDO $pdo, int $tripId): bool {
    return tripInspectionMissingItems($pdo, $tripId, 'Arrival') === 0;
}

/**
 * After a departure inspection is saved: when the departure packet is
 * prepared (step 6) and every departure check passed, record step 7 and
 * move the trip to 'Ready for Dispatch'.
 */
function recordPreDepartureReadyIfComplete(PDO $pdo, int $tripId, int $actorUserId): bool {
    $status = $pdo->prepare('SELECT status FROM trips WHERE trip_id = ? FOR UPDATE');
    $status->execute([$tripId]);
    if ($status->fetchColumn() !== 'Assigned') {
        return false;
    }
    if (tripWorkflowCurrentStep($pdo, $tripId) < 6 || !tripDepartureChecksPassed($pdo, $tripId)) {
        return false;
    }
    transitionTripStatus($pdo, $tripId, ['Assigned'], 'Ready for Dispatch');
    recordTripWorkflowEvent(
        $pdo,
        $tripId,
        7,
        'pre-departure-checks-passed',
        $actorUserId,
        'All required departure inspection items passed.',
        'Assigned',
        'Ready for Dispatch'
    );
    return true;
}

/**
 * Inspection progress for a trip stage, for display:
 *   ['required' => N, 'covered' => covered required items,
 *    'flagged' => flagged findings in completed inspections,
 *    'completed_inspections' => count]
 */
function tripInspectionSummary(PDO $pdo, int $tripId, string $stage): array {
    if (!in_array($stage, ['Departure', 'Arrival'], true)) {
        throw new InvalidArgumentException('Unknown inspection stage.');
    }
    $missing = tripInspectionMissingItems($pdo, $tripId, $stage);
    $required = $pdo->prepare(
        'SELECT COUNT(*)
         FROM inspection_items ii
         JOIN inspection_templates it ON it.template_id = ii.template_id
         WHERE it.inspection_stage = ? AND it.is_active = 1
           AND ii.is_active = 1 AND ii.is_required = 1'
    );
    $required->execute([$stage]);
    $requiredCount = (int)$required->fetchColumn();
    $done = $pdo->prepare(
        "SELECT COUNT(DISTINCT vi.inspection_id) AS inspections,
                COALESCE(SUM(f.result = 'Flagged'), 0) AS flagged
         FROM vehicle_inspections vi
         LEFT JOIN inspection_findings f ON f.inspection_id = vi.inspection_id
         WHERE vi.trip_id = ? AND vi.inspection_stage = ? AND vi.completed_at IS NOT NULL"
    );
    $done->execute([$tripId, $stage]);
    $row = $done->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'required'              => $requiredCount,
        'covered'               => $missing === null ? 0 : $requiredCount - $missing,
        'flagged'               => (int)($row['flagged'] ?? 0),
        'completed_inspections' => (int)($row['inspections'] ?? 0),
    ];
}

// Labels used by trip_workflow_handler.php save_delivery_return for values
// the target has no column for (kept as labelled lines in the notes).
const TRIP_DELIVERY_EXTRA_LABELS = [
    'delivery_receipt_number' => 'Delivery receipt',
    'co_load_reference'       => 'Co-load reference',
    'delivered_unit_count'    => 'Delivered units',
];

/**
 * Split stored delivery/return notes back into the form fields. Only the
 * trailing labelled lines written by save_delivery_return are recognised.
 */
function tripDeliveryFormValues(?array $delivery, ?array $return): ?array {
    if ($delivery === null && $return === null) {
        return null;
    }
    $values = [
        'delivery_notes' => '',
        'return_notes' => '',
        'return_location' => '',
        'shared_waybill_reference' => $delivery['waybill_number'] ?? '',
        'received_by' => $delivery['received_by'] ?? '',
    ];
    $lines = preg_split('/\R/', (string)($delivery['delivery_notes'] ?? ''));
    $labels = array_flip(TRIP_DELIVERY_EXTRA_LABELS);
    while ($lines && preg_match('/^(Delivery receipt|Co-load reference|Delivered units): (.*)$/', (string)end($lines), $m)) {
        $values[$labels[$m[1]]] = $m[2];
        array_pop($lines);
    }
    $values['delivery_notes'] = implode("\n", $lines);
    $returnLines = preg_split('/\R/', (string)($return['return_notes'] ?? ''));
    if ($returnLines && preg_match('/^Return location: (.*)$/', (string)end($returnLines), $m)) {
        $values['return_location'] = $m[1];
        array_pop($returnLines);
    }
    $values['return_notes'] = implode("\n", $returnLines);
    return $values;
}
