<?php

function lockDispatchResources(PDO $pdo, int $truckId, array $employeeIds): void
{
    $truck = $pdo->prepare('SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE');
    $truck->execute([$truckId]);
    if ($truck->fetchColumn() !== 'Available') {
        throw new DomainException('Selected truck is no longer available.');
    }

    $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
    sort($employeeIds, SORT_NUMERIC);
    $employee = $pdo->prepare(
        'SELECT employee_id, is_active
         FROM employees
         WHERE employee_id = ?
         FOR UPDATE'
    );
    foreach ($employeeIds as $employeeId) {
        $employee->execute([$employeeId]);
        $row = $employee->fetch(PDO::FETCH_ASSOC);
        if (!$row || !(int)$row['is_active']) {
            throw new DomainException('A selected crew member is no longer active.');
        }
    }
}

function assertDispatchResourcesAvailable(
    PDO $pdo,
    int $truckId,
    array $employeeIds,
    string $scheduledAt,
    ?int $excludeDispatchId = null
): void {
    $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
    $crewConditions = [];
    $params = [$scheduledAt, $excludeDispatchId ?? 0, $truckId];

    foreach (['driver_id', 'second_driver_id', 'helper_id'] as $column) {
        if ($employeeIds) {
            $crewConditions[] = 'dr.' . $column . ' IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')';
            array_push($params, ...$employeeIds);
        }
    }

    $sql = "
        SELECT dr.dispatch_id, dr.truck_id, dr.driver_id, dr.second_driver_id, dr.helper_id
        FROM dispatch_requests dr
        WHERE dr.status IN ('Pending', 'Approved')
          AND dr.scheduled_at IS NOT NULL
          AND DATE(dr.scheduled_at) = DATE(?)
          AND dr.dispatch_id <> ?
          AND (
            dr.truck_id = ?
            " . ($crewConditions ? ' OR ' . implode(' OR ', $crewConditions) : '') . "
          )
        ORDER BY dr.dispatch_id
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
