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
    if ($distance !== null && $distance < 0) {
        jsonFail('Distance cannot be negative.');
    }
    if (existsWhere($pdo, 'routes', 'route_name', $name)) {
        jsonFail('A route with that name already exists.');
    }
    $stmt = $pdo->prepare("
        INSERT INTO routes (route_name, origin, destination, distance_km, is_active, approval_status, requested_by)
        VALUES (?, ?, ?, ?, 0, 'Pending', ?)
    ");
    $stmt->execute([$name, $origin, $destination, $distance, currentUserId()]);
    $id = (int)$pdo->lastInsertId();
    auditLog('REQUEST_ROUTE', 'routes', $id, null, ['route_name' => $name]);
    jsonOk(['id' => $id], 'Route request submitted for Head Management approval.');
}

if ($action === 'review') {
    requireRole([ROLE_HEAD_MANAGEMENT]);
    $routeId = requiredInt('route_id', 'Route', 1);
    $status = requiredEnum('status', ['Approved', 'Rejected'], 'Status');
    $route = findOrFail($pdo, 'routes', 'route_id', $routeId, 'Route not found.');
    $pdo->prepare("UPDATE routes SET approval_status = ?, is_active = ? WHERE route_id = ?")
        ->execute([$status, $status === 'Approved' ? 1 : 0, $routeId]);
    auditLog('REVIEW_ROUTE_REQUEST', 'routes', $routeId, ['approval_status' => $route['approval_status']], ['approval_status' => $status]);
    jsonOk([], "Route request {$status}.");
}

jsonFail('Unknown action.');
