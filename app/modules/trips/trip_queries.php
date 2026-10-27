<?php
// ============================================================
// includes/trip_queries.php
// One SELECT for "a trip with its dispatch request" on the target schema.
//
// Target model (decision D2): the trip row is created when the dispatcher
// submits, with status 'Pending Approval'; dispatch_requests is the approval
// record for that trip (one per trip). Trip data lives on trips.
//
// Columns keep the names the pages and scripts already use (dispatch_id,
// scheduled_at, expected_arrival, actual_departure_at, actual_arrival,
// is_late, requested_by, remarks, ...), so views read the same keys as
// before. The SQL is a fixed string; callers append only their own
// placeholder-bound WHERE / ORDER BY.
// ============================================================

require_once __DIR__ . '/../../shared/metrics.php';

// 'remarks' keeps the legacy meaning: the dispatcher's submission remarks
// (approval_history 'Submitted' row) until the reviewer's notes replace them.
function tripSelectColumnsSql(): string {
    return "
        t.trip_id, t.trip_number, t.status, t.shift, t.trip_type,
        t.client_id, t.route_id, t.booking_id, t.nomination_id,
        t.truck_id, t.driver_id, t.second_driver_id, t.helper_id, t.dispatcher_id,
        t.origin AS trip_origin, t.destination AS trip_destination,
        t.scheduled_departure AS scheduled_at,
        t.scheduled_arrival   AS expected_arrival,
        t.departure_time      AS actual_departure_at,
        " . tripArrivalSql('t') . " AS actual_arrival,
        t.arrival_time, t.completion_time, t.return_time, t.completion_notes,
        t.created_at, t.updated_at,
        CASE WHEN " . tripLateSql('t') . " OR " . tripOverdueSql('t') . " THEN 1 ELSE 0 END AS is_late,
        dr.dispatch_request_id AS dispatch_id,
        dr.status       AS dispatch_status,
        dr.submitted_by AS requested_by,
        dr.submitted_at AS requested_at,
        dr.reviewed_by  AS approved_by_user_id,
        dr.reviewed_at,
        dr.review_notes,
        COALESCE(dr.review_notes, (
            SELECT ah.remarks
            FROM approval_requests ar
            JOIN approval_history ah ON ah.approval_request_id = ar.approval_request_id
            WHERE ar.entity_type = 'dispatch_request' AND ar.entity_id = dr.dispatch_request_id
              AND ah.action = 'Submitted'
            ORDER BY ah.approval_history_id
            LIMIT 1
        )) AS remarks,
        c.client_name,
        r.route_name,
        COALESCE(r.origin, t.origin)           AS origin,
        COALESCE(r.destination, t.destination) AS destination,
        r.distance AS distance_km,
        tr.plate_number, tr.unit_number, tr.brand, tr.model, tr.truck_type,
        cb.booking_reference, cb.quantity AS unit_count,
        e_d.full_name  AS driver_name,
        e_sd.full_name AS second_driver_name,
        e_h.full_name  AS helper_name,
        COALESCE(e_req.full_name, u_req.username) AS requested_by_name,
        COALESCE(e_apr.full_name, u_apr.username) AS approved_by_name";
}

// FROM + joins for tripSelectColumnsSql(). employees.full_name is the T-E1
// compatibility column.
function tripFromSql(): string {
    return "
        FROM trips t
        LEFT JOIN dispatch_requests dr ON dr.trip_id = t.trip_id
        JOIN clients c            ON c.client_id = t.client_id
        LEFT JOIN routes r        ON r.route_id = t.route_id
        LEFT JOIN trucks tr       ON tr.truck_id = t.truck_id
        LEFT JOIN client_bookings cb ON cb.booking_id = t.booking_id
        LEFT JOIN employees e_d   ON e_d.employee_id = t.driver_id
        LEFT JOIN employees e_sd  ON e_sd.employee_id = t.second_driver_id
        LEFT JOIN employees e_h   ON e_h.employee_id = t.helper_id
        LEFT JOIN users u_req     ON u_req.user_id = dr.submitted_by
        LEFT JOIN employees e_req ON e_req.employee_id = u_req.employee_id
        LEFT JOIN users u_apr     ON u_apr.user_id = dr.reviewed_by
        LEFT JOIN employees e_apr ON e_apr.employee_id = u_apr.employee_id";
}

/**
 * "Operational" trips: the ones the legacy schema had (legacy trips existed
 * only after approval). Excludes trips still awaiting approval and trips
 * whose dispatch request was rejected or cancelled. $trip / $dispatch are
 * fixed aliases from the calling query.
 */
function tripOperationalSql(string $trip = 't', string $dispatch = 'dr'): string {
    $t = metricAlias($trip);
    $d = metricAlias($dispatch);
    return "({$t}.status <> 'Pending Approval' AND ({$d}.dispatch_request_id IS NULL OR {$d}.status = 'Approved'))";
}

function tripSelectSql(): string {
    return 'SELECT ' . tripSelectColumnsSql() . tripFromSql();
}

/**
 * Trip row (same keys as tripSelectSql) locked FOR UPDATE on trips and
 * dispatch_requests, or null. Call inside the caller's transaction.
 */
function lockTripForWorkflow(PDO $pdo, int $tripId): ?array {
    $lock = $pdo->prepare('SELECT trip_id FROM trips WHERE trip_id = ? FOR UPDATE');
    $lock->execute([$tripId]);
    if (!$lock->fetchColumn()) {
        return null;
    }
    $pdo->prepare('SELECT dispatch_request_id FROM dispatch_requests WHERE trip_id = ? FOR UPDATE')
        ->execute([$tripId]);
    $stmt = $pdo->prepare(tripSelectSql() . ' WHERE t.trip_id = ?');
    $stmt->execute([$tripId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * employees.employee_id linked to a user (users.employee_id), or null.
 */
function employeeIdForUser(PDO $pdo, int $userId): ?int {
    $stmt = $pdo->prepare('SELECT employee_id FROM users WHERE user_id = ?');
    $stmt->execute([$userId]);
    $id = $stmt->fetchColumn();
    return ($id === false || $id === null) ? null : (int)$id;
}
