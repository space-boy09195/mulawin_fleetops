<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');
requirePostMethod();
enforceCsrf();

$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'request') {
    requireRole([ROLE_DISPATCHER]);
    $name = requiredString('route_name', 'Route name', 150);
    $origin = requiredString('origin', 'Origin', 150);
    $destination = requiredString('destination', 'Destination', 150);
    $distance = optionalFloat('distance_km');
    $requestNotes = optionalString('request_notes');
    if ($distance !== null && $distance < 0) {
        jsonFail('Distance cannot be negative.');
    }
    if ($distance !== null && $distance > 100000) {
        jsonFail('Distance is outside the allowed range.');
    }
    if (existsWhere($pdo, 'routes', 'route_name', $name)) {
        jsonFail('A route with that name already exists.');
    }
    $stmt = $pdo->prepare("
        INSERT INTO routes (route_name, origin, destination, distance_km, request_notes, is_active, approval_status, requested_by)
        VALUES (?, ?, ?, ?, ?, 0, 'Pending', ?)
    ");
    $stmt->execute([$name, $origin, $destination, $distance, $requestNotes, currentUserId()]);
    $id = (int)$pdo->lastInsertId();
    auditLog('REQUEST_ROUTE', 'routes', $id, null, ['route_name' => $name]);
    jsonOk(['id' => $id], 'Route request submitted for Head Management approval.');
}

if ($action === 'review') {
    requireRole([ROLE_HEAD_MANAGEMENT]);
    $routeId = requiredInt('route_id', 'Route', 1);
    $status = requiredEnum('status', ['Approved', 'Rejected'], 'Status');

    try {
        $pdo->beginTransaction();

        // FOR UPDATE: same reasoning as review_dispatch.php — without a row
        // lock here, two concurrent reviews of the same request could both
        // pass the status check below before either commits.
        $stmt = $pdo->prepare("SELECT * FROM routes WHERE route_id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$routeId]);
        $route = $stmt->fetch();

        if (!$route) {
            $pdo->rollBack();
            jsonFail('Route not found.', 404);
        }
        if (($route['approval_status'] ?? '') !== 'Pending') {
            $pdo->rollBack();
            jsonFail('This route request has already been reviewed.', 409);
        }

        $pdo->prepare("UPDATE routes SET approval_status = ?, is_active = ? WHERE route_id = ?")
            ->execute([$status, $status === 'Approved' ? 1 : 0, $routeId]);

        $pdo->commit();

        auditLog('REVIEW_ROUTE_REQUEST', 'routes', $routeId, ['approval_status' => $route['approval_status']], ['approval_status' => $status]);
        jsonOk([], "Route request {$status}.");
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('route_request_handler/review: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

jsonFail('Unknown action.');
