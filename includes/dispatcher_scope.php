<?php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';

// Dispatcher scope: the truck types a dispatcher may encode.
//
// Business rule (2026-10-26): eligibility depends on the assigned truck
// type only. A dispatcher may handle Day and Night trips of their truck
// types. The assignment's shift is still returned by dispatcherScopeResult()
// for information (Dispatcher Reports uses it), but no access check or
// work list filters by it.
//
// dispatcherScopeResult() has three explicit outcomes:
//   ['status' => 'none']    not a dispatcher (Admin, Operations Head, or a
//                           role without dispatch permissions).
//   ['status' => 'scoped', 'shift' => ..., 'truck_types' => [...]]
//   ['status' => 'denied']  a dispatcher whose scope cannot be established:
//                           no employee link, no or several applicable
//                           assignments, a type mismatch, an unrecognized
//                           role holding dispatch permissions, or a
//                           database error. Never unrestricted.
//
// Target schema: the role is identified by roles.system_key, and the scope
// comes from the single active dispatcher_assignments row of the employee
// linked through users.employee_id that covers both today and the
// scheduled date. The four legacy role names apply only when the role has
// no system key and the legacy schema is in use (no dispatcher_assignments
// table); on the target schema role names are never used.

const DISPATCHER_SCOPE_KEYS = [
    'car_carrier_dispatcher' => [
        'type' => 'CAR_CARRIER',
        'truck_types' => ['Car Carrier'],
    ],
    'container_wing_van_dispatcher' => [
        'type' => 'CONTAINER_WING_VAN',
        'truck_types' => ['Container', 'Wing Van'],
    ],
];

const DISPATCHER_SCOPE_LEGACY_ROLES = [
    'Car Carrier Dispatcher - Day' => [
        'shift' => 'Day',
        'truck_types' => ['Car Carrier'],
    ],
    'Car Carrier Dispatcher - Night' => [
        'shift' => 'Night',
        'truck_types' => ['Car Carrier'],
    ],
    'Container & Wing Van Dispatcher - Day' => [
        'shift' => 'Day',
        'truck_types' => ['Container', 'Wing Van'],
    ],
    'Container & Wing Van Dispatcher - Night' => [
        'shift' => 'Night',
        'truck_types' => ['Container', 'Wing Van'],
    ],
];

// A role holding any of these is subject to dispatcher scope unless it is
// Admin or Operations Head.
const DISPATCHER_SCOPE_PERMISSIONS = ['trips.create', 'trips.assign', 'dispatch.instructions.encode'];

// $scheduledDate (Y-m-d) is the dispatch date the assignment must also
// cover. Without it, only today is checked (page filtering).
function dispatcherScopeResult(?string $scheduledDate = null): array
{
    static $results = [];
    $cacheKey = $scheduledDate ?? '';
    if (!isset($results[$cacheKey])) {
        $results[$cacheKey] = resolveDispatcherScope($scheduledDate);
    }
    return $results[$cacheKey];
}

function resolveDispatcherScope(?string $scheduledDate): array
{
    if (isAdmin()) {
        return ['status' => 'none'];
    }
    $roleKey = currentRoleKey();
    if ($roleKey === 'operations_head') {
        return ['status' => 'none'];
    }

    try {
        $pdo = getDBConnection();
        if ($roleKey !== null && isset(DISPATCHER_SCOPE_KEYS[$roleKey])) {
            return dispatcherAssignmentScope($pdo, DISPATCHER_SCOPE_KEYS[$roleKey], $scheduledDate);
        }

        if ($roleKey === null && !dispatcherAssignmentsTableExists($pdo)) {
            // Legacy schema: no system key and no assignments table.
            $nameQuery = $pdo->prepare('SELECT role_name FROM roles WHERE role_id = ?');
            $nameQuery->execute([currentRoleId()]);
            $roleName = (string)$nameQuery->fetchColumn();
            if (isset(DISPATCHER_SCOPE_LEGACY_ROLES[$roleName])) {
                return ['status' => 'scoped'] + DISPATCHER_SCOPE_LEGACY_ROLES[$roleName];
            }
            if ($roleName === 'Operations Head') {
                return ['status' => 'none'];
            }
        }

        if (currentUserHasAnyPermission(DISPATCHER_SCOPE_PERMISSIONS)) {
            error_log(sprintf(
                'Dispatcher scope denied for user_id %d: role_id %d holds dispatch permissions but is not a recognized dispatcher role.',
                currentUserId(),
                currentRoleId()
            ));
            return ['status' => 'denied'];
        }
        return ['status' => 'none'];
    } catch (Throwable $e) {
        error_log('Dispatcher scope denied for user_id ' . currentUserId() . ': ' . $e->getMessage());
        return ['status' => 'denied'];
    }
}

function dispatcherAssignmentsTableExists(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute(['dispatcher_assignments']);
    return (int)$stmt->fetchColumn() > 0;
}

function dispatcherAssignmentScope(PDO $pdo, array $role, ?string $scheduledDate): array
{
    $userId = currentUserId();
    $today = date('Y-m-d');
    $date = $scheduledDate ?? $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        error_log('Dispatcher scope denied for user_id ' . $userId . ': invalid scheduled date.');
        return ['status' => 'denied'];
    }

    $link = $pdo->prepare('SELECT employee_id FROM users WHERE user_id = ?');
    $link->execute([$userId]);
    $employeeId = (int)$link->fetchColumn();
    if ($employeeId <= 0) {
        error_log('Dispatcher scope denied for user_id ' . $userId . ': no linked employee (users.employee_id).');
        return ['status' => 'denied'];
    }

    // Applicable: active and covering both today and the scheduled date.
    // Exactly one is required; LIMIT 2 is enough to detect ambiguity.
    $assignments = $pdo->prepare(
        'SELECT dispatcher_role_type, shift
         FROM dispatcher_assignments
         WHERE employee_id = ? AND is_active = 1
           AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)
           AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)
         LIMIT 2'
    );
    $assignments->execute([$employeeId, $today, $today, $date, $date]);
    $rows = $assignments->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1) {
        error_log(sprintf(
            'Dispatcher scope denied for user_id %d: %s applicable dispatcher assignment for %s.',
            $userId,
            $rows ? 'more than one' : 'no',
            $date
        ));
        return ['status' => 'denied'];
    }
    if ($rows[0]['dispatcher_role_type'] !== $role['type']) {
        error_log('Dispatcher scope denied for user_id ' . $userId . ': assignment type does not match the dispatcher role.');
        return ['status' => 'denied'];
    }

    return [
        'status' => 'scoped',
        'shift' => (string)$rows[0]['shift'],
        'truck_types' => $role['truck_types'],
    ];
}

// Compatibility view for page filters: [] when no scope applies, the truck
// types otherwise. It has no 'shift' key, so pages that add a shift filter
// only when 'shift' is set no longer filter by shift. A denied dispatcher
// gets a scope that matches no truck type (and 'denied'), so filters built
// from it return nothing.
function dispatcherScope(): array
{
    $result = dispatcherScopeResult();
    if ($result['status'] === 'scoped') {
        return ['truck_types' => $result['truck_types']];
    }
    if ($result['status'] === 'denied') {
        return ['truck_types' => [''], 'denied' => true];
    }
    return [];
}

// Read filter for listings: " AND ..." limiting rows to the signed-in
// dispatcher's truck types (Day and Night alike), or '' when no scope
// applies (Admin, Operations Head, other roles). A denied dispatcher gets a
// condition that matches nothing. $truckTypeExpr is a fixed column
// reference from the calling query; the values are added to $params as
// named placeholders. $shiftExpr is still checked but no longer used.
function dispatcherScopeReadSql(string $truckTypeExpr, string $shiftExpr, array &$params): string
{
    foreach ([$truckTypeExpr, $shiftExpr] as $expr) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $expr)) {
            throw new InvalidArgumentException('Invalid scope column.');
        }
    }
    $scope = dispatcherScope();
    if (!$scope) {
        return '';
    }
    if (!empty($scope['denied'])) {
        return ' AND 1 = 0';
    }
    $typeKeys = [];
    foreach (array_values($scope['truck_types']) as $index => $truckType) {
        $key = ':dscopeType' . $index;
        $typeKeys[] = $key;
        $params[$key] = $truckType;
    }
    return " AND {$truckTypeExpr} IN (" . implode(', ', $typeKeys) . ")";
}

// The 403 message for an action outside the scope, or null when allowed.
// $shift is not checked: a dispatcher may handle Day and Night trips of
// their truck types. The parameter stays so callers need no change.
function dispatcherScopeViolation(string $shift, string $truckType, ?string $scheduledDate = null): ?string
{
    $result = dispatcherScopeResult($scheduledDate);
    if ($result['status'] === 'none') {
        return null;
    }
    if ($result['status'] !== 'scoped') {
        return 'This dispatcher account does not have one active dispatcher assignment for this date. Ask an administrator to check the assignment.';
    }
    if (!in_array($truckType, $result['truck_types'], true)) {
        return 'This dispatcher account cannot encode the selected truck category.';
    }
    return null;
}

function enforceDispatcherScope(string $shift, string $truckType, ?string $scheduledDate = null): void
{
    $violation = dispatcherScopeViolation($shift, $truckType, $scheduledDate);
    if ($violation !== null) {
        jsonFail($violation, 403);
    }
}
