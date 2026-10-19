<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

requirePermission('routes.manage');
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Add route ─────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $name        = requiredString('route_name', 'Route name', 150);
    $origin      = requiredString('origin', 'Origin', 255);
    $destination = requiredString('destination', 'Destination', 255);
    $distance    = optionalFloat('distance_km');

    if ($distance !== null && $distance < 0) {
        jsonFail('Distance cannot be negative.');
    }

    if (existsWhere($pdo, 'routes', 'route_name', $name)) {
        jsonFail('A route with that name already exists.');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO routes (route_name, origin, destination, distance, is_active, created_by)
            VALUES (?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([$name, $origin, $destination, $distance, currentUserId()]);
        $newId = (int)$pdo->lastInsertId();

        auditLog('ADD_ROUTE', 'routes', $newId, null, [
            'route_name'  => $name,
            'origin'      => $origin,
            'destination' => $destination,
        ]);

        jsonOk(['id' => $newId], 'Route added successfully.');
    } catch (PDOException $e) {
        error_log('routes_handler/add: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Edit route ────────────────────────────────────────────────────────────────
if ($action === 'edit') {
    $routeId     = requiredInt('route_id', 'Route ID', 1);
    $name        = requiredString('route_name', 'Route name', 150);
    $origin      = requiredString('origin', 'Origin', 255);
    $destination = requiredString('destination', 'Destination', 255);
    $distance    = optionalFloat('distance_km');

    // Fetch old for audit
    $oldData = findOrFail($pdo, 'routes', 'route_id', $routeId, 'Route not found.');

    if (existsWhere($pdo, 'routes', 'route_name', $name, $routeId, 'route_id')) {
        jsonFail('Another route already has that name.');
    }

    try {
        $pdo->prepare("
            UPDATE routes SET
                route_name   = ?,
                origin       = ?,
                destination  = ?,
                distance     = ?
            WHERE route_id   = ?
        ")->execute([$name, $origin, $destination, $distance, $routeId]);

        auditLog('EDIT_ROUTE', 'routes', $routeId,
            ['route_name' => $oldData['route_name']],
            ['route_name' => $name, 'origin' => $origin, 'destination' => $destination]
        );

        jsonOk([], 'Route updated successfully.');
    } catch (PDOException $e) {
        error_log('routes_handler/edit: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Toggle active/inactive ────────────────────────────────────────────────────
if ($action === 'toggle') {
    $routeId = requiredInt('route_id', 'Route ID', 1);

    $route = findOrFail($pdo, 'routes', 'route_id', $routeId, 'Route not found.');

    // Prevent deactivating a route that has trips awaiting approval
    if ($route['is_active']) {
        $inUse = $pdo->prepare("
            SELECT COUNT(*) FROM trips
            WHERE route_id = ? AND status = 'Pending Approval'
        ");
        $inUse->execute([$routeId]);
        if ((int)$inUse->fetchColumn() > 0) {
            jsonFail('Cannot deactivate a route with pending dispatch requests.');
        }
    } else {
        // is_active is the target's only approval flag, so a requested route
        // must not be switched on around its route_request approval.
        $requestState = $pdo->prepare(
            "SELECT ar.status
             FROM approval_requests ar
             JOIN approval_workflows w ON w.workflow_id = ar.workflow_id
             WHERE w.workflow_key = 'route_request' AND ar.entity_type = 'route' AND ar.entity_id = ?
             ORDER BY ar.approval_request_id DESC
             LIMIT 1"
        );
        $requestState->execute([$routeId]);
        $latest = $requestState->fetchColumn();
        if ($latest === 'Pending') {
            jsonFail('This route request is still awaiting approval. Review it from the requests page.', 409);
        }
        if ($latest === 'Rejected') {
            jsonFail('This route request was rejected and cannot be activated. Submit a new route request instead.', 409);
        }
    }

    $newActive = $route['is_active'] ? 0 : 1;

    try {
        $pdo->prepare("UPDATE routes SET is_active = ? WHERE route_id = ?")
            ->execute([$newActive, $routeId]);

        auditLog('TOGGLE_ROUTE', 'routes', $routeId,
            ['is_active' => $route['is_active']],
            ['is_active' => $newActive]
        );

        jsonOk(
            ['new_active' => $newActive],
            'Route ' . ($newActive ? 'activated' : 'deactivated') . ' successfully.'
        );
    } catch (PDOException $e) {
        error_log('routes_handler/toggle: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

jsonFail('Unknown action.');
