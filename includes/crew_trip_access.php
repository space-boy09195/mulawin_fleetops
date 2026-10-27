<?php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/employee_profile.php';

// Helper trip isolation.
//
// A user whose role key is 'helper' may see only trips that
// name their own employee record as the helper (trips.helper_id). The employee comes from the
// existing user-employee link (employeeForCurrentUser: employees.user_id, one
// active profile); a user_id is never used as an employee_id. Helpers get no
// trips.view: the trip pages give them read access to their own trips only,
// and every write action keeps its own permission and ownership checks.

function currentUserIsHelper(): bool
{
    return currentRoleKey() === 'helper';
}

// 403 for trip access that is not allowed: JSON for AJAX, otherwise the page.
function denyTripAccess(): never
{
    if (isJsonRequest()) {
        sendJsonAccessResponse(403, 'You do not have permission to view this trip.');
    }
    http_response_code(403);
    include __DIR__ . '/../pages/403.php';
    exit;
}

// Guard for trip pages. Helpers get their employee_id back (results must be
// limited to it); a Helper without exactly one active employee profile is
// refused. Everyone else needs one of $permissions and gets null.
function tripPageAccess(array $permissions): ?int
{
    requireLogin();
    if (!currentUserIsHelper()) {
        requireAnyPermission($permissions);
        return null;
    }
    $employee = employeeForCurrentUser(getDBConnection());
    if ($employee === null) {
        denyTripAccess();
    }
    return (int)$employee['employee_id'];
}

// Refuses a Helper who is not the helper on this trip. A missing trip gets the
// same 403, so trip IDs cannot be probed.
function requireHelperAssignedToTrip(int $tripId, int $employeeId): void
{
    $check = getDBConnection()->prepare(
        'SELECT 1 FROM trips t WHERE t.trip_id = ? AND t.helper_id = ?'
    );
    $check->execute([$tripId, $employeeId]);
    if (!$check->fetchColumn()) {
        denyTripAccess();
    }
}

// Refuses a dispatcher whose assigned scope (truck type) does not
// cover this trip. Admin, Operations Head and other roles have no scope and
// pass. A trip outside the scope gets the same 403 as a missing trip.
function requireTripInDispatcherScope(?string $shift, ?string $truckType): void
{
    require_once __DIR__ . '/dispatcher_scope.php';
    if (dispatcherScopeViolation((string)$shift, (string)$truckType) !== null) {
        denyTripAccess();
    }
}

// For pages and endpoints that Helpers never use (dispatch board, exports).
function refuseHelpers(): void
{
    if (currentUserIsHelper()) {
        denyTripAccess();
    }
}
