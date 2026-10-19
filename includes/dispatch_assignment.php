<?php
// ============================================================
// includes/dispatch_assignment.php
// Truck and crew locking + conflicting-assignment checks on the target
// schema. Assignments live on trips (truck_id, driver_id, second_driver_id
// (T-T1), helper_id). The rule is unchanged from the legacy version: a truck
// or crew member may hold only one open trip per scheduled date.
//
// Concurrency: lockDispatchResources() locks the truck and every crew row
// FOR UPDATE in a fixed order (truck first, then employees by ID) before
// assertDispatchResourcesAvailable() looks for conflicts, so two
// transactions assigning the same resource are serialized and the second one
// sees the first one's trip.
// ============================================================

// Trip statuses that still hold their truck and crew.
const TRIP_RESOURCE_HOLDING_STATUSES = [
    'Pending Approval', 'Approved', 'Assigned', 'Ready for Dispatch',
    'Dispatched', 'In Progress', 'Delivered',
];

/**
 * @param string|null $requiredTruckStatus truck status required to proceed,
 *        or null to only lock (re-assignment of a truck already held by the
 *        same trip)
 */
function lockDispatchResources(PDO $pdo, int $truckId, array $employeeIds, ?string $requiredTruckStatus = 'Available'): void
{
    $truck = $pdo->prepare('SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE');
    $truck->execute([$truckId]);
    $status = $truck->fetchColumn();
    if ($status === false || ($requiredTruckStatus !== null && $status !== $requiredTruckStatus)) {
        throw new DomainException('Selected truck is no longer available.');
    }

    $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
    sort($employeeIds, SORT_NUMERIC);
    $employee = $pdo->prepare(
        'SELECT employee_id, employment_status
         FROM employees
         WHERE employee_id = ?
         FOR UPDATE'
    );
    foreach ($employeeIds as $employeeId) {
        $employee->execute([$employeeId]);
        $row = $employee->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['employment_status'] !== 'Active') {
            throw new DomainException('A selected crew member is no longer active.');
        }
    }
}

function assertDispatchResourcesAvailable(
    PDO $pdo,
    int $truckId,
    array $employeeIds,
    string $scheduledAt,
    ?int $excludeTripId = null
): void {
    $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
    $statusPlaceholders = implode(',', array_fill(0, count(TRIP_RESOURCE_HOLDING_STATUSES), '?'));
    $params = array_merge(TRIP_RESOURCE_HOLDING_STATUSES, [$scheduledAt, $excludeTripId ?? 0, $truckId]);

    $crewConditions = [];
    foreach (['driver_id', 'second_driver_id', 'helper_id'] as $column) {
        if ($employeeIds) {
            $crewConditions[] = 't.' . $column . ' IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')';
            array_push($params, ...$employeeIds);
        }
    }

    $sql = "
        SELECT t.trip_id, t.truck_id, t.driver_id, t.second_driver_id, t.helper_id
        FROM trips t
        WHERE t.status IN ($statusPlaceholders)
          AND DATE(t.scheduled_departure) = DATE(?)
          AND t.trip_id <> ?
          AND (
            t.truck_id = ?
            " . ($crewConditions ? ' OR ' . implode(' OR ', $crewConditions) : '') . "
          )
        ORDER BY t.trip_id
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conflict = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conflict) {
        return;
    }

    if ((int)$conflict['truck_id'] === $truckId) {
        throw new DomainException('This truck is already reserved for another dispatch on that date.');
    }
    foreach (['driver_id', 'second_driver_id', 'helper_id'] as $column) {
        if ($conflict[$column] !== null && in_array((int)$conflict[$column], $employeeIds, true)) {
            throw new DomainException('A selected crew member is already assigned to another dispatch on that date.');
        }
    }
}

/**
 * Reserve a truck for an approved trip: truck_assignments row 'Reserved'
 * for the trip window and trucks.status 'Assigned' (the target value for the
 * legacy 'Deployed'). The truck row must already be locked and Available.
 * Returns the truck_assignment_id.
 */
function reserveTruckForTrip(PDO $pdo, int $truckId, int $tripId, string $from, ?string $until): int
{
    $pdo->prepare(
        "INSERT INTO truck_assignments (truck_id, trip_id, assigned_from, assigned_until, assignment_status)
         VALUES (?, ?, ?, ?, 'Reserved')"
    )->execute([$truckId, $tripId, $from, $until]);
    $assignmentId = (int)$pdo->lastInsertId();
    $update = $pdo->prepare(
        "UPDATE trucks SET status = 'Assigned', current_assignment_id = ?
         WHERE truck_id = ? AND status = 'Available'"
    );
    $update->execute([$assignmentId, $truckId]);
    if ($update->rowCount() !== 1) {
        throw new DomainException('The assigned truck is no longer Available.');
    }
    return $assignmentId;
}

/**
 * Release a trip's truck: open assignments for the trip become
 * $assignmentStatus ('Released' after a trip ends, 'Cancelled' when the trip
 * is cancelled), and the truck returns to Available when this trip was
 * holding it. Returns the truck's previous status, or null when the trip
 * was not holding the truck.
 */
function releaseTruckForTrip(PDO $pdo, int $truckId, int $tripId, string $assignmentStatus = 'Released'): ?string
{
    if (!in_array($assignmentStatus, ['Released', 'Cancelled'], true)) {
        throw new InvalidArgumentException('Invalid assignment status.');
    }
    $pdo->prepare(
        "UPDATE truck_assignments
         SET assignment_status = ?, assigned_until = COALESCE(assigned_until, NOW())
         WHERE trip_id = ? AND truck_id = ? AND assignment_status IN ('Reserved', 'Assigned')"
    )->execute([$assignmentStatus, $tripId, $truckId]);

    $truck = $pdo->prepare(
        'SELECT t.status, ta.trip_id AS current_trip_id
         FROM trucks t
         LEFT JOIN truck_assignments ta ON ta.truck_assignment_id = t.current_assignment_id
         WHERE t.truck_id = ? FOR UPDATE'
    );
    $truck->execute([$truckId]);
    $row = $truck->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $heldByTrip = in_array($row['status'], ['Assigned', 'On Trip'], true)
        && ($row['current_trip_id'] === null || (int)$row['current_trip_id'] === $tripId);
    if (!$heldByTrip) {
        return null;
    }
    $pdo->prepare(
        "UPDATE trucks SET status = 'Available', current_assignment_id = NULL WHERE truck_id = ?"
    )->execute([$truckId]);
    return (string)$row['status'];
}

/**
 * Crew availability (employees.availability_status). 'On Trip' while a trip
 * is under way, back to 'Available' afterwards. Only rows in $fromStatus are
 * changed, so leave or manual 'Unavailable' states are never overwritten.
 */
function setCrewAvailability(PDO $pdo, array $employeeIds, string $fromStatus, string $toStatus): void
{
    $allowed = ['Available', 'On Trip'];
    if (!in_array($fromStatus, $allowed, true) || !in_array($toStatus, $allowed, true)) {
        throw new InvalidArgumentException('Invalid crew availability change.');
    }
    $stmt = $pdo->prepare(
        'UPDATE employees SET availability_status = ? WHERE employee_id = ? AND availability_status = ?'
    );
    foreach (array_unique(array_filter(array_map('intval', $employeeIds))) as $employeeId) {
        $stmt->execute([$toStatus, $employeeId, $fromStatus]);
    }
}
