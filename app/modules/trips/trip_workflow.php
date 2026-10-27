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

require_once __DIR__ . '/../../../includes/workflow_events.php';
require_once __DIR__ . '/../../../config/enums.php';

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

// Trip Allowance statuses that block departure. A required allowance must be
// fully disbursed: Finance's create_disbursement sets 'Disbursed' only when
// the recorded payments reach the approved amount ('Processing' is a partial
// payment). 'Settled' also blocks: no code sets it (liquidation is not
// implemented), so a settled request cannot be verified as fully paid.
// Rejected, Cancelled and Draft requests are not active and do not block.
const TRIP_ALLOWANCE_DEPARTURE_BLOCKING_STATUSES = ['Pending Approval', 'Approved', 'Processing', 'Settled'];

/**
 * The 409 message when one of the trip's Trip Allowance requests blocks
 * departure, or null. $requests: rows with request_number and status.
 */
function tripAllowanceDepartureMessage(array $requests): ?string {
    foreach ($requests as $request) {
        $status = (string)$request['status'];
        if (!in_array($status, TRIP_ALLOWANCE_DEPARTURE_BLOCKING_STATUSES, true)) {
            continue;
        }
        $message = 'A required trip allowance must be fully disbursed by Finance before departure. Trip Allowance request '
            . $request['request_number'] . ' is ' . $status . '.';
        if ($status === 'Settled') {
            $message .= ' A settled request cannot be verified as fully paid yet; ask an Admin to check it.';
        }
        return $message;
    }
    return null;
}

/**
 * Departure check for the trip's Trip Allowance requests. Call inside the
 * departure transaction after lockTripForWorkflow(): the locking read keeps
 * these requests (and new requests for this trip) from changing until the
 * transaction ends.
 */
function tripAllowanceDepartureBlocker(PDO $pdo, int $tripId): ?string {
    $stmt = $pdo->prepare(
        "SELECT request_number, status
         FROM fund_requests
         WHERE trip_id = ? AND request_type = 'Trip Allowance'
         ORDER BY fund_request_id
         FOR UPDATE"
    );
    $stmt->execute([$tripId]);
    return tripAllowanceDepartureMessage($stmt->fetchAll(PDO::FETCH_ASSOC));
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

/**
 * Recorded-events text for a step-6 event (decoded JSON details). New
 * events store attachment_count; older events stored a document packet
 * status (documents_status / documents_notes) and are shown as recorded.
 */
function tripPreparationEventText(array $details): string {
    if (array_key_exists('attachment_count', $details)) {
        $text = 'Attachments: ' . (int)$details['attachment_count'];
    } else {
        $text = 'Documents: ' . ($details['documents_status'] ?? '—')
            . (!empty($details['documents_notes']) ? ' — ' . $details['documents_notes'] : '');
    }
    return $text . '. Allowance: ' . ($details['allowance_status'] ?? '—')
        . (!empty($details['allowance_notes']) ? ' — ' . $details['allowance_notes'] : '')
        . '.';
}

/**
 * "What should I do next?" guide for the Trip Workflow page. Read-only: it
 * only describes the next action of the existing workflow (the same status
 * and step conditions the step cards and handlers use) and never changes
 * anything.
 *
 * $facts:
 *   departure, arrival      tripInspectionSummary() results
 *   pending_allowance       request_number of a Trip Allowance request still
 *                           'Pending Approval', or null
 *   allowance_departure     tripAllowanceDepartureMessage() for the trip's
 *                           Trip Allowance requests, or null
 *   is_owner                the viewer submitted this trip
 *   is_admin                the viewer is Admin
 *   can_review              approvals.review
 *   can_assign              trips.assign
 *   can_update              trips.update
 *   can_clear               dispatch.clear
 *   can_inspect             maintenance.manage and the Maintenance entry
 *                           buttons are shown to this role
 *
 * Returns ['role', 'where', 'can_act' ('yes' | 'override' | 'no'),
 *          'blockers' (list of strings), 'links' (list of page keys),
 *          'note' (string or null), 'task' (short name of the next action,
 *          or null when there is none)].
 */
function tripNextAction(string $status, int $currentStep, array $facts): array {
    $dispatcher = 'Dispatcher who submitted this trip';
    // A step of the submitting dispatcher: Admin may act only as an override.
    $ownerCanAct = static function (bool $permission) use ($facts): string {
        if (!$permission) {
            return 'no';
        }
        if (!empty($facts['is_owner'])) {
            return 'yes';
        }
        return !empty($facts['is_admin']) ? 'override' : 'no';
    };
    $next = [
        'role'     => null,
        'where'    => '',
        'can_act'  => 'no',
        'blockers' => [],
        'links'    => [],
        'note'     => null,
        'task'     => null,
    ];

    if ($status === 'Pending Approval' && $currentStep === 4) {
        $next['role'] = 'Operations Head';
        $next['task'] = 'Approve or reject the truck and crew';
        $next['where'] = 'Approvals page → Dispatch Requests card → approve or reject this trip.';
        $next['can_act'] = empty($facts['can_review']) ? 'no' : (!empty($facts['is_admin']) ? 'override' : 'yes');
        $next['links'] = ['approvals'];
    } elseif ($status === 'Approved' && $currentStep === 4) {
        $next['role'] = $dispatcher;
        $next['task'] = 'Confirm truck and driver (Step 5)';
        $next['where'] = 'Step 5 card on this page → “Confirm truck and driver”.';
        $next['can_act'] = $ownerCanAct(!empty($facts['can_assign']));
    } elseif ($status === 'Assigned' && $currentStep === 5) {
        $next['role'] = $dispatcher;
        $next['task'] = 'Record departure preparation (Step 6)';
        $next['where'] = 'Step 6 card on this page → “Record departure preparation”.';
        $next['can_act'] = $ownerCanAct(!empty($facts['can_assign']));
        if (!empty($facts['pending_allowance'])) {
            $next['blockers'][] = 'Trip Allowance request ' . $facts['pending_allowance']
                . ' is awaiting approval. Step 6 can be recorded after it is approved or rejected.';
        }
    } elseif ($status === 'Assigned' && $currentStep === 6) {
        $next['role'] = 'Maintenance';
        $next['task'] = 'Complete the pre-trip checklist and departure inspection (Step 7)';
        $next['where'] = 'Maintenance → Pre-Departure Inspection → complete both sections, “Pre-Trip Checklist” and “Departure Inspection”. Step 7 is recorded automatically when both pass.';
        $next['can_act'] = empty($facts['can_inspect']) ? 'no' : 'yes';
        $next['links'] = ['pre_departure'];
        $departure = $facts['departure'] ?? ['required' => 0, 'covered' => 0, 'flagged' => 0];
        if ((int)$departure['required'] === 0) {
            $next['blockers'][] = 'No active departure inspection template is configured.';
        } elseif ((int)$departure['covered'] < (int)$departure['required']) {
            $next['blockers'][] = 'Departure checks: ' . (int)$departure['covered'] . ' of '
                . (int)$departure['required'] . ' required items passed.';
        }
        if ((int)($departure['flagged'] ?? 0) > 0) {
            $next['blockers'][] = (int)$departure['flagged'] . ' flagged departure finding(s).';
        }
    } elseif ($status === 'Ready for Dispatch' && $currentStep === 7) {
        $next['role'] = 'Operations Head';
        $next['task'] = 'Provide dispatch clearance (Step 8)';
        $next['where'] = 'Step 8 card on this page → “Provide dispatch clearance”.';
        $next['can_act'] = empty($facts['can_clear']) ? 'no' : 'yes';
    } elseif ($status === 'Dispatched' && $currentStep === 8) {
        $next['role'] = $dispatcher;
        $next['task'] = 'Record the departure (Step 9)';
        $next['where'] = 'Trip Monitoring → pencil (Update status) → New Status “In Progress” → “Save Update”. This records the departure.';
        $next['can_act'] = $ownerCanAct(!empty($facts['can_update']));
        $next['links'] = ['trip_monitor'];
        if (!empty($facts['allowance_departure'])) {
            $next['blockers'][] = $facts['allowance_departure'];
        }
    } elseif ($status === 'In Progress' && $currentStep === 9) {
        $next['role'] = $dispatcher;
        $next['task'] = 'Record a progress update (Step 10)';
        $next['where'] = 'Step 10 card on this page → “Record progress update”.';
        $next['can_act'] = $ownerCanAct(!empty($facts['can_update']));
    } elseif ($status === 'In Progress' && $currentStep === 10) {
        $next['role'] = $dispatcher;
        $next['task'] = 'Save delivery and return details (Step 11)';
        $next['where'] = 'Step 11 card on this page → “Save delivery and return details”. More progress updates are optional.';
        $next['can_act'] = $ownerCanAct(!empty($facts['can_update']));
    } elseif ($status === 'In Progress' && $currentStep === 11) {
        $next['role'] = $dispatcher;
        $next['task'] = 'Mark the trip Delivered';
        $next['where'] = 'Trip Monitoring → pencil (Update status) → New Status “Delivered” → “Save Update”.';
        $next['can_act'] = $ownerCanAct(!empty($facts['can_update']));
        $next['links'] = ['trip_monitor'];
    } elseif ($status === 'Delivered' && $currentStep === 11) {
        $next['role'] = 'Maintenance';
        $next['task'] = 'Complete the return inspection (Step 12)';
        $next['where'] = 'Maintenance → Return Inspection → “Start Return Inspection”. The trip becomes Returned automatically.';
        $next['can_act'] = empty($facts['can_inspect']) ? 'no' : 'yes';
        $next['links'] = ['return_inspection'];
        $arrival = $facts['arrival'] ?? ['required' => 0, 'covered' => 0];
        if ((int)$arrival['required'] === 0) {
            $next['blockers'][] = 'No active arrival inspection template is configured.';
        } elseif ((int)$arrival['covered'] < (int)$arrival['required']) {
            $next['blockers'][] = 'Arrival inspection: ' . (int)$arrival['covered'] . ' of '
                . (int)$arrival['required'] . ' required items recorded.';
        }
    } elseif ($status === 'Returned' && $currentStep === 12) {
        $next['role'] = $dispatcher;
        $next['task'] = 'Submit the completed trip (Step 13)';
        $next['where'] = 'Trip Monitoring → pencil (Update status) → New Status “Completed” → “Save Update”.';
        $next['can_act'] = $ownerCanAct(!empty($facts['can_update']));
        $next['links'] = ['trip_monitor'];
    } elseif ($status === 'Completed') {
        $next['note'] = 'The trip workflow is complete. Billing & Collection creates the client billing (Billing → “Create Billing”), and Payroll records the crew’s Trip Pay (Trip Pay page).';
        $next['links'] = ['billing', 'trip_pay'];
    } elseif ($status === 'Cancelled') {
        $next['note'] = 'This trip was cancelled. There is no further workflow action.';
    } else {
        $next['note'] = 'The trip status (' . $status . ') and its recorded steps do not match a known workflow stage. Ask an Admin to check this trip.';
    }
    return $next;
}
