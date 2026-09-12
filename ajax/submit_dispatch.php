<?php
// ============================================================
// ajax/submit_dispatch.php
// Creates a new dispatch_request row (status = Pending)
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

requireRole([ROLE_DISPATCHER]);
requirePostMethod();
enforceCsrf();

$truckId     = requiredInt('truck_id', 'Truck', 1);
$routeId     = requiredInt('route_id', 'Route', 1);
$driverId    = requiredInt('driver_id', 'Driver', 1);
$helperId    = filter_input(INPUT_POST, 'helper_id', FILTER_VALIDATE_INT) ?: null;
$scheduledAt = requiredString('scheduled_at', 'Scheduled date/time');
$remarks     = optionalString('remarks');

// Validate scheduled_at is a valid datetime
if (!strtotime($scheduledAt)) {
    jsonFail('Invalid scheduled date.');
}
if (strtotime($scheduledAt) < time()) {
    jsonFail('New dispatches cannot use a passed date or time.');
}

$pdo = getDBConnection();

// A driver/helper may be scheduled again only after the current trip is done,
// and never twice on the same calendar day.
$availability = $pdo->prepare("
    SELECT dr.dispatch_id, dr.driver_id, dr.helper_id,
           e_d.full_name AS driver_name,
           e_h.full_name AS helper_name,
           dr.scheduled_at
    FROM dispatch_requests dr
    JOIN employees e_d ON e_d.employee_id = dr.driver_id
    LEFT JOIN employees e_h ON e_h.employee_id = dr.helper_id
    LEFT JOIN trips t ON t.dispatch_id = dr.dispatch_id
    WHERE dr.status IN ('Pending', 'Approved')
      AND DATE(dr.scheduled_at) = DATE(?)
      AND (t.trip_id IS NULL OR t.status NOT IN ('Completed', 'Cancelled'))
      AND (dr.driver_id = ? OR (? IS NOT NULL AND dr.helper_id = ?))
    LIMIT 1
");
$availability->execute([$scheduledAt, $driverId, $helperId, $helperId]);
$busy = $availability->fetch(PDO::FETCH_ASSOC);
if ($busy) {
    $person = (int)$busy['driver_id'] === $driverId
        ? $busy['driver_name']
        : $busy['helper_name'];
    if (!$person) {
        $person = $busy['helper_name'];
    }
    jsonFail($person . ' is already assigned to an active dispatch on that date.');
}

// Ensure the selected route is approved and still available.
$route = findOrFail($pdo, 'routes', 'route_id', $routeId, 'Route not found.');
if ((string)($route['approval_status'] ?? 'Approved') !== 'Approved' || !(int)$route['is_active']) {
    jsonFail('The selected route is not approved for dispatch.');
}

// Ensure truck is still Available
$truck = $pdo->prepare("SELECT status FROM trucks WHERE truck_id = :id LIMIT 1");
$truck->execute([':id' => $truckId]);
$truckRow = $truck->fetch();

if (!$truckRow || $truckRow['status'] !== 'Available') {
    jsonFail('Selected truck is no longer available.');
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "INSERT INTO dispatch_requests
           (truck_id, driver_id, helper_id, route_id, requested_by, approved_by, scheduled_at, status, remarks, reviewed_at)
         VALUES
           (:truck, :driver, :helper, :route, :user, :user, :scheduled, 'Approved', :remarks, NOW())"
    );
    $stmt->execute([
        ':truck' => $truckId, ':driver' => $driverId, ':helper' => $helperId,
        ':route' => $routeId, ':user' => currentUserId(),
        ':scheduled' => $scheduledAt, ':remarks' => $remarks,
    ]);
    $newId = (int)$pdo->lastInsertId();
    $year = date('Y');
    $countStmt = $pdo->query("SELECT COUNT(*) FROM trips WHERE YEAR(created_at) = " . (int)$year);
    $tripNumber = 'TRP-' . $year . '-' . str_pad((int)$countStmt->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO trips (dispatch_id, trip_number, status) VALUES (?, ?, 'Loading')")
        ->execute([$newId, $tripNumber]);
    $tripId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE trucks SET status = 'Deployed' WHERE truck_id = ?")->execute([$truckId]);

    $driverUser = $pdo->prepare("SELECT user_id FROM employees WHERE employee_id = ?");
    $driverUser->execute([$driverId]);
    if ($userId = $driverUser->fetchColumn()) {
        $pdo->prepare("INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)")
            ->execute([
                (int)$userId,
                'Dispatch confirmed',
                "You have been assigned to trip {$tripNumber}.",
                APP_BASE . '/pages/trip_monitor.php',
            ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('submit_dispatch: ' . $e->getMessage());
    jsonFail('Could not confirm the dispatch.', 500);
}

auditLog('CREATE', 'dispatch_requests', $newId, null, ['status' => 'Approved', 'trip_id' => $tripId]);
auditLog('CREATE', 'trips', $tripId, null, ['trip_number' => $tripNumber]);
jsonOk(['trip_id' => $tripId], 'Dispatch confirmed and driver notified.');
