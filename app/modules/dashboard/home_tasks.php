<?php
// ============================================================
// includes/home_tasks.php
// "Work to Address" items on the Home page: actions that wait for the
// signed-in user. Each item is built from existing workflow data and rules
// (no new assignment table): the trip workflow's next step
// (tripNextAction), open dispatch instructions, and approved fund requests
// waiting for disbursement. An item disappears as soon as its record moves
// on, is completed or is cancelled, because it is rebuilt from the current
// status on every page load.
//
// Urgency uses only stored dates: a scheduled departure that has passed
// before the truck left, or a trip past its ETA (the existing is_late
// rule). No deadline is invented.
// ============================================================

require_once __DIR__ . '/../trips/trip_workflow.php';

// Trip statuses before the truck leaves; their due time is the scheduled
// departure.
const HOME_TASK_PRE_DEPARTURE_STATUSES = ['Approved', 'Assigned', 'Ready for Dispatch', 'Dispatched'];

/**
 * Home item for a trip whose next step this viewer can do now, or null.
 * Pending Approval is left out: Home already shows "Awaiting your approval".
 * Admin acting as an override is not a task (can_act 'override').
 * $trip: tripSelectSql() row (trip_id, trip_number, status, plate_number,
 * scheduled_at, is_late). URLs are relative to APP_BASE.
 */
function tripHomeTask(array $trip, int $currentStep, array $facts, int $now): ?array {
    $status = (string)$trip['status'];
    if ($status === 'Pending Approval') {
        return null;
    }
    $next = tripNextAction($status, $currentStep, $facts);
    if ($next['can_act'] !== 'yes' || $next['task'] === null) {
        return null;
    }
    $tripId = (int)$trip['trip_id'];
    $url = $next['role'] === 'Maintenance'
        ? ($status === 'Delivered'
            ? '/pages/maintenance.php?view=return&trip_id=' . $tripId . '&inspection_stage=Return#inspectionModal'
            : '/pages/maintenance.php?view=pre_departure&trip_id=' . $tripId)
        : '/pages/trip_workflow.php?trip_id=' . $tripId;

    $preDeparture = in_array($status, HOME_TASK_PRE_DEPARTURE_STATUSES, true);
    $departure = !empty($trip['scheduled_at']) ? strtotime((string)$trip['scheduled_at']) : false;
    $urgent = null;
    if ($preDeparture && $departure !== false && $departure < $now) {
        $urgent = 'Scheduled departure has passed';
    } elseif (!$preDeparture && (int)($trip['is_late'] ?? 0) === 1) {
        $urgent = 'Past ETA';
    }
    $detail = $trip['trip_number'] . ' · ' . ($trip['plate_number'] ?? 'No truck') . ' · ' . $status;
    if ($preDeparture && $departure !== false) {
        $detail .= ' · Departs ' . date('M j, g:i A', $departure);
    }
    return [
        'trip_id' => $tripId,
        'title'   => $next['task'],
        'detail'  => $detail,
        'url'     => $url,
        'urgent'  => $urgent,
    ];
}

/**
 * Home item for an open (Pending) dispatch instruction the viewer may
 * encode. $instruction: booking_id, booking_reference, truck_type, shift,
 * scheduled_departure. Encoding a passed departure time is refused by
 * submit_dispatch.php, so such an instruction is flagged instead.
 */
function dispatchInstructionHomeTask(array $instruction, int $now): array {
    $departure = strtotime((string)$instruction['scheduled_departure']);
    $passed = $departure !== false && $departure < $now;
    return [
        'title'  => 'Encode dispatch instruction',
        'detail' => $instruction['booking_reference'] . ' · ' . $instruction['truck_type'] . ' · '
            . $instruction['shift'] . ' shift · Departs ' . ($departure !== false ? date('M j, g:i A', $departure) : '—'),
        'url'    => '/pages/dispatch.php?instruction_id=' . (int)$instruction['booking_id'],
        'urgent' => $passed ? 'Scheduled departure has passed; ask the Operations Head to cancel or re-plan it' : null,
    ];
}

/**
 * Home item for an approved fund request waiting for disbursement.
 * $request: request_number, request_type, remaining, trip_number,
 * trip_status, scheduled_departure (trip columns may be null). Urgent when
 * a Trip Allowance's trip is still before departure and its scheduled
 * departure has passed: the allowance now blocks the departure.
 */
function fundDisbursementHomeTask(array $request, int $now): array {
    $detail = $request['request_number'] . ' · ' . $request['request_type']
        . (!empty($request['trip_number']) ? ' · ' . $request['trip_number'] : '')
        . ' · ₱' . number_format((float)$request['remaining'], 2) . ' remaining';
    $departure = !empty($request['scheduled_departure']) ? strtotime((string)$request['scheduled_departure']) : false;
    $waitingTrip = $request['request_type'] === 'Trip Allowance'
        && in_array((string)($request['trip_status'] ?? ''), HOME_TASK_PRE_DEPARTURE_STATUSES, true);
    if ($waitingTrip && $departure !== false) {
        $detail .= ' · Trip departs ' . date('M j, g:i A', $departure);
    }
    return [
        'title'  => 'Record disbursement',
        'detail' => $detail,
        'url'    => '/pages/finance.php',
        'urgent' => $waitingTrip && $departure !== false && $departure < $now
            ? 'Trip departure time has passed; the trip cannot leave until this is disbursed'
            : null,
    ];
}

/**
 * Urgent items first, then the original order.
 */
function sortHomeTasks(array $tasks): array {
    $urgent = array_values(array_filter($tasks, static fn(array $task): bool => !empty($task['urgent'])));
    $other = array_values(array_filter($tasks, static fn(array $task): bool => empty($task['urgent'])));
    return array_merge($urgent, $other);
}
