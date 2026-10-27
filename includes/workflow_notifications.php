<?php
// ============================================================
// includes/workflow_notifications.php
// Handoff notifications: when a trip, allowance or dispatch-instruction step
// is done, the role or user who must act next gets an in-app notification
// (the notifications table, shown by the layout's bell on the next page
// load; there is no email, SMS or push delivery).
//
// Callers send these inside the transaction that made the change: a
// rolled-back change sends nothing, and every change can happen only once
// (conditional status updates, one event per step, idempotency keys), so a
// refresh or a replayed request sends nothing again. A notification never
// changes the workflow.
// ============================================================

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/../app/modules/trips/dispatcher_scope.php';

// Trip handoffs: who acts next and what they are told. 'owner' is the
// dispatcher who submitted the trip (dispatch_requests.submitted_by); a
// permission sends to the active users of every role holding it, except
// the excluded roles (Admin holds every permission but is not the
// responsible role).
const TRIP_HANDOFF_NOTICES = [
    'departure_checks' => [
        'permission' => 'maintenance.manage', 'exclude' => ['admin', 'operations_head'], 'entity' => 'trip_pre_departure',
        'title' => 'Departure checks needed',
        'message' => '%s is ready for the pre-trip checklist and the departure inspection.',
    ],
    'clearance' => [
        'permission' => 'dispatch.clear', 'exclude' => ['admin'], 'entity' => 'trip_workflow',
        'title' => 'Dispatch clearance needed',
        'message' => 'All departure checks passed for %s. Provide dispatch clearance.',
    ],
    'cleared' => [
        'owner' => true, 'entity' => 'trip_workflow',
        'title' => 'Cleared for departure',
        'message' => '%s has dispatch clearance. Record the departure when the truck leaves.',
    ],
    'return_inspection' => [
        'permission' => 'maintenance.manage', 'exclude' => ['admin', 'operations_head'], 'entity' => 'trip_return_inspection',
        'title' => 'Return inspection needed',
        'message' => '%s was delivered. Complete the return inspection.',
    ],
    'returned' => [
        'owner' => true, 'entity' => 'trip_workflow',
        'title' => 'Return inspection complete',
        'message' => '%s passed its return inspection. Submit the completed trip.',
    ],
    'allowance_disbursed' => [
        'owner' => true, 'entity' => 'trip_workflow',
        'title' => 'Trip allowance disbursed',
        'message' => 'Finance fully disbursed the trip allowance for %s; it no longer blocks the departure.',
    ],
];

/**
 * Notifies the active users of every active role that holds $permissionKey,
 * except the roles in $excludedRoleKeys. Returns the number notified.
 */
function notifyUsersWithRolePermission(
    PDO $pdo,
    string $permissionKey,
    array $excludedRoleKeys,
    string $title,
    string $message,
    string $type,
    ?string $entityType,
    ?int $entityId
): int {
    $params = [$permissionKey];
    $exclude = '';
    if ($excludedRoleKeys) {
        $exclude = ' AND (r.system_key IS NULL OR r.system_key NOT IN ('
            . implode(',', array_fill(0, count($excludedRoleKeys), '?')) . '))';
        $params = array_merge($params, array_values($excludedRoleKeys));
    }
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.user_id
         FROM users u
         JOIN roles r ON r.role_id = u.role_id AND r.is_active = 1
         JOIN role_permissions rp ON rp.role_id = r.role_id
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE u.is_active = 1 AND p.permission_key = ?" . $exclude
    );
    $stmt->execute($params);
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($userIds as $userId) {
        createNotification($pdo, (int)$userId, $title, $message, $type, $entityType, $entityId);
    }
    return count($userIds);
}

/**
 * Sends the TRIP_HANDOFF_NOTICES entry $handoff for a trip. Returns the
 * number of users notified.
 */
function notifyTripHandoff(PDO $pdo, int $tripId, string $handoff): int {
    $notice = TRIP_HANDOFF_NOTICES[$handoff] ?? null;
    if ($notice === null) {
        throw new InvalidArgumentException('Unknown trip handoff.');
    }
    $trip = $pdo->prepare(
        'SELECT t.trip_number, dr.submitted_by
         FROM trips t
         LEFT JOIN dispatch_requests dr ON dr.trip_id = t.trip_id
         WHERE t.trip_id = ?'
    );
    $trip->execute([$tripId]);
    $row = $trip->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }
    $message = sprintf($notice['message'], $row['trip_number']);
    if (!empty($notice['owner'])) {
        if (!$row['submitted_by']) {
            return 0;
        }
        createNotification($pdo, (int)$row['submitted_by'], $notice['title'], $message, 'trip_handoff', $notice['entity'], $tripId);
        return 1;
    }
    return notifyUsersWithRolePermission(
        $pdo, $notice['permission'], $notice['exclude'], $notice['title'], $message, 'trip_handoff', $notice['entity'], $tripId
    );
}

/**
 * Marks the unread notifications of the listed trip handoffs as read for
 * every recipient: that step is done (or the trip was cancelled), so the
 * notice is no longer a task. Returns the number marked read.
 */
function resolveTripHandoffs(PDO $pdo, int $tripId, array $handoffs): int {
    $titles = [];
    foreach ($handoffs as $handoff) {
        if (!isset(TRIP_HANDOFF_NOTICES[$handoff])) {
            throw new InvalidArgumentException('Unknown trip handoff.');
        }
        $titles[] = TRIP_HANDOFF_NOTICES[$handoff]['title'];
    }
    return resolveNotifications($pdo, 'trip_handoff', $tripId, $titles);
}

/**
 * True when the user's active role holds the permission (Admin holds every
 * permission, as in includes/session.php).
 */
function userHasRolePermission(PDO $pdo, int $userId, string $permissionKey): bool {
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM users u
         JOIN roles r ON r.role_id = u.role_id AND r.is_active = 1
         WHERE u.user_id = ? AND u.is_active = 1
           AND (r.system_key = 'admin' OR EXISTS (
                SELECT 1 FROM role_permissions rp
                JOIN permissions p ON p.permission_id = rp.permission_id
                WHERE rp.role_id = r.role_id AND p.permission_key = ?))"
    );
    $stmt->execute([$userId, $permissionKey]);
    return (bool)$stmt->fetchColumn();
}

/**
 * A fund request with what the notices need, or null. 'label' names the
 * exact record, e.g. "Trip Allowance FR-20261027015113-105 for TRP-2026-0016
 * (₱1,000.00)". 'trip_owner' is the dispatcher who submitted the trip.
 */
function fundRequestNoticeDetails(PDO $pdo, int $fundRequestId): ?array {
    $stmt = $pdo->prepare(
        'SELECT fr.fund_request_id, fr.request_number, fr.request_type, fr.amount, fr.status,
                fr.requested_by, fr.trip_id, t.trip_number,
                (SELECT MIN(dr.submitted_by) FROM dispatch_requests dr WHERE dr.trip_id = fr.trip_id) AS trip_owner
         FROM fund_requests fr
         LEFT JOIN trips t ON t.trip_id = fr.trip_id
         WHERE fr.fund_request_id = ?'
    );
    $stmt->execute([$fundRequestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        return null;
    }
    $request['label'] = $request['request_type'] . ' ' . $request['request_number']
        . ($request['trip_number'] ? ' for ' . $request['trip_number'] : '')
        . ' (₱' . number_format((float)$request['amount'], 2) . ')';
    return $request;
}

/**
 * Where the requester's notice links: the trip's workflow page when the
 * request is for a trip the requester may view (a dispatcher cannot open
 * the Finance page), otherwise the Finance page. Returns [entity type, id].
 */
function fundRequestRequesterLink(PDO $pdo, array $request): array {
    if ($request['trip_id'] && userHasRolePermission($pdo, (int)$request['requested_by'], 'trips.view')) {
        return ['trip_workflow', (int)$request['trip_id']];
    }
    return ['fund_request', (int)$request['fund_request_id']];
}

/**
 * A fund request was approved: Finance records the disbursement.
 */
function notifyFundRequestAwaitingDisbursement(PDO $pdo, int $fundRequestId): int {
    $request = fundRequestNoticeDetails($pdo, $fundRequestId);
    if (!$request) {
        return 0;
    }
    return notifyUsersWithRolePermission(
        $pdo,
        'finance.disbursements.manage',
        ['admin'],
        'Disbursement needed',
        $request['label'] . ' was approved. Record the disbursement on the Finance page (Record disbursement).',
        'fund_request',
        'fund_request',
        $fundRequestId
    );
}

/**
 * The approval decision on a fund request, for the person who asked for
 * it. $status: 'Approved' or 'Rejected'.
 */
function notifyFundRequestDecision(PDO $pdo, int $fundRequestId, string $status): int {
    $request = fundRequestNoticeDetails($pdo, $fundRequestId);
    if (!$request) {
        return 0;
    }
    [$entityType, $entityId] = fundRequestRequesterLink($pdo, $request);
    $message = $status === 'Approved'
        ? $request['label'] . ' was approved. Finance will record the disbursement.'
        : $request['label'] . ' was rejected.';
    createNotification($pdo, (int)$request['requested_by'], 'Fund request ' . strtolower($status), $message, 'approval', $entityType, $entityId);
    return 1;
}

/**
 * A disbursement was recorded against a fund request ($newStatus
 * 'Processing' = part paid, 'Disbursed' = fully paid). The requester is
 * told the amount; for a fully disbursed Trip Allowance the trip's
 * dispatcher gets the 'allowance_disbursed' handoff, and the requester is
 * not told twice when that is the same person. When fully disbursed, the
 * other Finance users' "Disbursement needed" notices are resolved.
 * $actorUserId (who recorded it) is not notified about their own action.
 */
function notifyFundRequestDisbursementRecorded(PDO $pdo, int $fundRequestId, float $amount, string $newStatus, int $actorUserId): int {
    $request = fundRequestNoticeDetails($pdo, $fundRequestId);
    if (!$request) {
        return 0;
    }
    $sent = 0;
    $fullyPaid = $newStatus === 'Disbursed';
    if ($fullyPaid) {
        resolveNotifications($pdo, 'fund_request', $fundRequestId, ['Disbursement needed']);
    }
    $ownerNotice = $fullyPaid && $request['request_type'] === 'Trip Allowance' && $request['trip_id'] && $request['trip_owner'];
    if ($ownerNotice) {
        $sent += notifyTripHandoff($pdo, (int)$request['trip_id'], 'allowance_disbursed');
    }
    $requesterId = (int)$request['requested_by'];
    $toldAsOwner = $ownerNotice && (int)$request['trip_owner'] === $requesterId;
    if (!$toldAsOwner && $requesterId !== $actorUserId) {
        [$entityType, $entityId] = fundRequestRequesterLink($pdo, $request);
        $message = 'Finance recorded a disbursement of ₱' . number_format($amount, 2) . ' for ' . $request['label'] . '. '
            . ($fullyPaid ? 'The request is now fully disbursed.' : 'Part of the approved amount is still to be disbursed.');
        createNotification($pdo, $requesterId, 'Disbursement recorded', $message, 'fund_request', $entityType, $entityId);
        $sent++;
    }
    return $sent;
}

/**
 * A payment voucher was approved: Finance records the disbursement (the
 * Finance page lists approved vouchers under Record disbursement).
 */
function notifyVoucherAwaitingDisbursement(PDO $pdo, int $voucherId): int {
    $stmt = $pdo->prepare('SELECT voucher_number, payee, amount FROM payment_vouchers WHERE voucher_id = ?');
    $stmt->execute([$voucherId]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) {
        return 0;
    }
    return notifyUsersWithRolePermission(
        $pdo,
        'finance.disbursements.manage',
        ['admin'],
        'Voucher disbursement needed',
        'Payment voucher ' . $voucher['voucher_number'] . ' for ' . $voucher['payee'] . ' (₱'
            . number_format((float)$voucher['amount'], 2) . ') was approved. Record the disbursement on the Finance page.',
        'payment_voucher',
        'payment_voucher',
        $voucherId
    );
}

/**
 * Dispatcher role keys that encode the given truck types.
 */
function dispatcherRoleKeysForTruckTypes(array $truckTypes): array {
    $roleKeys = [];
    foreach (DISPATCHER_SCOPE_KEYS as $roleKey => $scope) {
        if (array_intersect($truckTypes, $scope['truck_types'])) {
            $roleKeys[] = $roleKey;
        }
    }
    return $roleKeys;
}

/**
 * New dispatch instructions: notify the active users of the dispatcher roles
 * for those truck types. Returns the number notified. With $instructionId
 * (one instruction) the notice opens that instruction's encoding form and
 * is resolved when it is encoded or cancelled; a batch notice
 * ($instructionId null) opens the Dispatcher Queue.
 */
function notifyDispatchersForTruckTypes(PDO $pdo, array $truckTypes, string $title, string $message, ?int $instructionId = null): int {
    $roleKeys = dispatcherRoleKeysForTruckTypes($truckTypes);
    if (!$roleKeys) {
        return 0;
    }
    $stmt = $pdo->prepare(
        'SELECT u.user_id
         FROM users u
         JOIN roles r ON r.role_id = u.role_id AND r.is_active = 1
         WHERE u.is_active = 1 AND r.system_key IN (' . implode(',', array_fill(0, count($roleKeys), '?')) . ')'
    );
    $stmt->execute($roleKeys);
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($userIds as $userId) {
        createNotification($pdo, (int)$userId, $title, $message, 'dispatch_instruction', 'dispatch_instruction', $instructionId);
    }
    return count($userIds);
}

/**
 * An instruction was encoded or cancelled: its "ready to encode" notices
 * are no longer a task. Older notices saved without the instruction's ID
 * cannot be matched and stay as they are.
 */
function resolveDispatchInstructionNotices(PDO $pdo, int $instructionId): int {
    return resolveNotifications($pdo, 'dispatch_instruction', $instructionId, ['Dispatch instruction ready']);
}

/**
 * An instruction of $truckType was encoded or cancelled. A batch "Dispatch
 * list ready" notice has no instruction ID, so it is resolved per dispatcher
 * role: once none of the role's truck types has an open instruction left
 * (Pending with a scheduled departure, as on the Dispatcher Queue), that
 * role's batch notices are no longer a task. A role that still has an open
 * instruction keeps its notices. Returns the number marked read.
 */
function resolveDispatchListNotices(PDO $pdo, string $truckType): int {
    $resolved = 0;
    foreach (dispatcherRoleKeysForTruckTypes([$truckType]) as $roleKey) {
        $truckTypes = DISPATCHER_SCOPE_KEYS[$roleKey]['truck_types'];
        $open = $pdo->prepare(
            "SELECT COUNT(*) FROM client_bookings
             WHERE status = 'Pending' AND scheduled_departure IS NOT NULL
               AND truck_type IN (" . implode(',', array_fill(0, count($truckTypes), '?')) . ')'
        );
        $open->execute($truckTypes);
        if ((int)$open->fetchColumn() > 0) {
            continue;
        }
        $resolved += resolveUnlinkedNotificationsForRole($pdo, 'dispatch_instruction', 'Dispatch list ready', $roleKey);
    }
    return $resolved;
}
