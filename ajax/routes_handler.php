<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT]);

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Add route ─────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $name        = trim($_POST['route_name']   ?? '');
    $origin      = trim($_POST['origin']       ?? '');
    $destination = trim($_POST['destination']  ?? '');
    $distance    = $_POST['distance_km'] !== '' ? (float)$_POST['distance_km'] : null;

    if (!$name || !$origin || !$destination) {
        echo json_encode(['success' => false, 'message' => 'Route name, origin, and destination are required.']);
        exit;
    }

    if ($distance !== null && $distance < 0) {
        echo json_encode(['success' => false, 'message' => 'Distance cannot be negative.']);
        exit;
    }

    // Check for duplicate route name
    $dup = $pdo->prepare("SELECT route_id FROM routes WHERE route_name = ?");
    $dup->execute([$name]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'A route with that name already exists.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO routes (route_name, origin, destination, distance_km, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([$name, $origin, $destination, $distance]);
        $newId = (int)$pdo->lastInsertId();

        auditLog('ADD_ROUTE', 'routes', $newId, null, [
            'route_name'  => $name,
            'origin'      => $origin,
            'destination' => $destination,
        ]);

        echo json_encode(['success' => true, 'message' => 'Route added successfully.', 'id' => $newId]);
    } catch (PDOException $e) {
        error_log('routes_handler/add: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Edit route ────────────────────────────────────────────────────────────────
if ($action === 'edit') {
    $routeId     = (int)($_POST['route_id']    ?? 0);
    $name        = trim($_POST['route_name']   ?? '');
    $origin      = trim($_POST['origin']       ?? '');
    $destination = trim($_POST['destination']  ?? '');
    $distance    = $_POST['distance_km'] !== '' ? (float)$_POST['distance_km'] : null;

    if (!$routeId) {
        echo json_encode(['success' => false, 'message' => 'Invalid route ID.']);
        exit;
    }

    if (!$name || !$origin || !$destination) {
        echo json_encode(['success' => false, 'message' => 'Route name, origin, and destination are required.']);
        exit;
    }

    // Fetch old for audit
    $old = $pdo->prepare("SELECT * FROM routes WHERE route_id = ?");
    $old->execute([$routeId]);
    $oldData = $old->fetch(PDO::FETCH_ASSOC);
    if (!$oldData) {
        echo json_encode(['success' => false, 'message' => 'Route not found.']);
        exit;
    }

    // Unique name excluding self
    $dup = $pdo->prepare("SELECT route_id FROM routes WHERE route_name = ? AND route_id != ?");
    $dup->execute([$name, $routeId]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Another route already has that name.']);
        exit;
    }

    try {
        $pdo->prepare("
            UPDATE routes SET
                route_name   = ?,
                origin       = ?,
                destination  = ?,
                distance_km  = ?
            WHERE route_id   = ?
        ")->execute([$name, $origin, $destination, $distance, $routeId]);

        auditLog('EDIT_ROUTE', 'routes', $routeId,
            ['route_name' => $oldData['route_name']],
            ['route_name' => $name, 'origin' => $origin, 'destination' => $destination]
        );

        echo json_encode(['success' => true, 'message' => 'Route updated successfully.']);
    } catch (PDOException $e) {
        error_log('routes_handler/edit: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Toggle active/inactive ────────────────────────────────────────────────────
if ($action === 'toggle') {
    $routeId = (int)($_POST['route_id'] ?? 0);

    if (!$routeId) {
        echo json_encode(['success' => false, 'message' => 'Invalid route ID.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT route_id, route_name, is_active FROM routes WHERE route_id = ?");
    $stmt->execute([$routeId]);
    $route = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$route) {
        echo json_encode(['success' => false, 'message' => 'Route not found.']);
        exit;
    }

    // Prevent deactivating a route that has pending/approved dispatch requests
    if ($route['is_active']) {
        $inUse = $pdo->prepare("
            SELECT COUNT(*) FROM dispatch_requests
            WHERE route_id = ? AND status = 'Pending'
        ");
        $inUse->execute([$routeId]);
        if ((int)$inUse->fetchColumn() > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Cannot deactivate a route with pending dispatch requests.',
            ]);
            exit;
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

        echo json_encode([
            'success'    => true,
            'message'    => 'Route ' . ($newActive ? 'activated' : 'deactivated') . ' successfully.',
            'new_active' => $newActive,
        ]);
    } catch (PDOException $e) {
        error_log('routes_handler/toggle: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);