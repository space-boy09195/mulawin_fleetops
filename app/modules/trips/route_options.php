<?php
// ============================================================
// includes/route_options.php
// Route pickup/delivery options (route_locations) and the
// client <-> route links (client_routes).
// ============================================================

/**
 * Decodes a JSON list of {pickup, delivery} pairs from a POST field.
 * Returns unique, trimmed pairs; jsonFail()s on malformed input.
 */
function postedRouteOptions(string $key = 'options', bool $required = true): array {
    $decoded = json_decode((string)($_POST[$key] ?? '[]'), true);
    if (!is_array($decoded)) {
        jsonFail('Pickup and delivery options are invalid.');
    }
    $options = [];
    foreach ($decoded as $row) {
        $pickup = trim((string)($row['pickup'] ?? ''));
        $delivery = trim((string)($row['delivery'] ?? ''));
        if ($pickup === '' && $delivery === '') {
            continue;
        }
        if ($pickup === '' || $delivery === '') {
            jsonFail('Each option needs both a pickup and a delivery location.');
        }
        if (mb_strlen($pickup) > 255 || mb_strlen($delivery) > 255) {
            jsonFail('Pickup and delivery locations must be 255 characters or fewer.');
        }
        $options[mb_strtolower($pickup . "\0" . $delivery)] = ['pickup' => $pickup, 'delivery' => $delivery];
    }
    if ($required && !$options) {
        jsonFail('Add at least one pickup and delivery location.');
    }
    return array_values($options);
}

/** Decodes a JSON list of positive integer IDs from a POST field. */
function postedIdList(string $key): array {
    $decoded = json_decode((string)($_POST[$key] ?? '[]'), true);
    if (!is_array($decoded)) {
        jsonFail('The selected links are invalid.');
    }
    $ids = [];
    foreach ($decoded as $id) {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if ($id !== false && $id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

/** Replaces a route's options; routes.destination mirrors the first delivery. */
function syncRouteOptions(PDO $pdo, int $routeId, array $options): void {
    $pdo->prepare('DELETE FROM route_locations WHERE route_id = ?')->execute([$routeId]);
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO route_locations (route_id, pickup_location, delivery_location) VALUES (?, ?, ?)'
    );
    foreach ($options as $option) {
        $insert->execute([$routeId, $option['pickup'], $option['delivery']]);
    }
    if ($options) {
        $pdo->prepare('UPDATE routes SET destination = ? WHERE route_id = ?')
            ->execute([$options[0]['delivery'], $routeId]);
    }
}

/** Adds options to a route, ignoring pairs it already has. */
function addRouteOptions(PDO $pdo, int $routeId, array $options): void {
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO route_locations (route_id, pickup_location, delivery_location) VALUES (?, ?, ?)'
    );
    foreach ($options as $option) {
        $insert->execute([$routeId, $option['pickup'], $option['delivery']]);
    }
}

/** Replaces the clients linked to a route. */
function syncRouteClients(PDO $pdo, int $routeId, array $clientIds): void {
    $pdo->prepare('DELETE FROM client_routes WHERE route_id = ?')->execute([$routeId]);
    $insert = $pdo->prepare('INSERT IGNORE INTO client_routes (client_id, route_id) SELECT client_id, ? FROM clients WHERE client_id = ?');
    foreach ($clientIds as $clientId) {
        $insert->execute([$routeId, $clientId]);
    }
}

/** Replaces the routes linked to a client. */
function syncClientRoutes(PDO $pdo, int $clientId, array $routeIds): void {
    $pdo->prepare('DELETE FROM client_routes WHERE client_id = ?')->execute([$clientId]);
    $insert = $pdo->prepare('INSERT IGNORE INTO client_routes (client_id, route_id) SELECT ?, route_id FROM routes WHERE route_id = ?');
    foreach ($routeIds as $routeId) {
        $insert->execute([$clientId, $routeId]);
    }
}

/** The existing route for an origin (case-insensitive), or null. */
function findRouteByOrigin(PDO $pdo, string $origin): ?array {
    $stmt = $pdo->prepare('SELECT * FROM routes WHERE LOWER(TRIM(origin)) = LOWER(TRIM(?)) ORDER BY route_id LIMIT 1');
    $stmt->execute([$origin]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
