<?php
// ============================================================
// ajax/client_master_data_handler.php
// Client locations (target client_locations: name, address, contact,
// is_active) and rate cards (target client_rates, see
// includes/client_rates.php).
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../app/modules/finance/client_rates.php';

header('Content-Type: application/json');
requirePermission('clients.manage');
requirePostMethod();
enforceCsrf();

$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'save_location') {
    $locationId = filter_input(INPUT_POST, 'location_id', FILTER_VALIDATE_INT) ?: null;
    $clientId = requiredInt('client_id', 'Client', 1);
    $name = requiredString('location_name', 'Location name', 150);
    $address = requiredString('address', 'Address', 1000);
    $contact = optionalString('contact_person', null, 150);
    $phone = optionalString('contact_number', null, 50);

    findOrFail($pdo, 'clients', 'client_id', $clientId, 'Client not found.');
    if ($locationId !== null) {
        $current = $pdo->prepare('SELECT client_id FROM client_locations WHERE location_id = ?');
        $current->execute([$locationId]);
        if ((int)$current->fetchColumn() !== $clientId) {
            jsonFail('Location not found for this client.', 404);
        }
    }

    $duplicate = $pdo->prepare(
        'SELECT location_id FROM client_locations
         WHERE client_id = ? AND LOWER(location_name) = LOWER(?)
           AND location_id <> COALESCE(?, 0)'
    );
    $duplicate->execute([$clientId, $name, $locationId]);
    if ($duplicate->fetchColumn()) {
        jsonFail('A location with that name already exists for this client.', 409);
    }

    try {
        $pdo->beginTransaction();
        if ($locationId !== null) {
            $pdo->prepare(
                'UPDATE client_locations
                 SET location_name = ?, address = ?, contact_person = ?, contact_number = ?
                 WHERE location_id = ? AND client_id = ?'
            )->execute([$name, $address, $contact, $phone, $locationId, $clientId]);
            auditLog('UPDATE_CLIENT_LOCATION', 'client_locations', $locationId, null, ['location_name' => $name]);
            $pdo->commit();
            jsonOk([], 'Client location updated.');
        }
        $pdo->prepare(
            'INSERT INTO client_locations (client_id, location_name, address, contact_person, contact_number)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$clientId, $name, $address, $contact, $phone]);
        $newId = (int)$pdo->lastInsertId();
        auditLog('CREATE_CLIENT_LOCATION', 'client_locations', $newId, null, ['client_id' => $clientId, 'location_name' => $name]);
        $pdo->commit();
        jsonOk(['id' => $newId], 'Client location added.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('client_master_data_handler/save_location: ' . $e->getMessage());
        jsonFail('Could not save the location.', 500);
    }
}

if ($action === 'toggle_location') {
    $locationId = requiredInt('location_id', 'Location', 1);
    $location = findOrFail($pdo, 'client_locations', 'location_id', $locationId, 'Location not found.');
    $newState = (int)!((int)$location['is_active']);
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE client_locations SET is_active = ? WHERE location_id = ?')->execute([$newState, $locationId]);
        auditLog('TOGGLE_CLIENT_LOCATION', 'client_locations', $locationId, ['is_active' => $location['is_active']], ['is_active' => $newState]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('client_master_data_handler/toggle_location: ' . $e->getMessage());
        jsonFail('Could not update the location.', 500);
    }
    jsonOk(['is_active' => $newState], 'Client location status updated.');
}

if ($action === 'save_rate') {
    $rateId = filter_input(INPUT_POST, 'rate_id', FILTER_VALIDATE_INT) ?: null;
    $clientId = requiredInt('client_id', 'Client', 1);
    $routeId = filter_input(INPUT_POST, 'route_id', FILTER_VALIDATE_INT) ?: null;
    $truckType = requiredEnum('truck_type', TRUCK_CATEGORIES, 'Truck type');
    $rateType = requiredEnum('rate_type', CLIENT_RATE_TYPES, 'Rate type');
    $amount = requiredPositiveFloat('rate_amount', 'Rate amount');
    $effectiveFrom = requiredDate('effective_from', 'Effective from');
    $effectiveTo = optionalString('effective_to', null, 10);
    if ($effectiveTo !== null && (!isValidDate($effectiveTo) || $effectiveTo < $effectiveFrom)) {
        jsonFail('Effective-to date must be a valid date on or after the start date.');
    }
    if ($rateType !== 'Fixed' && $routeId === null) {
        jsonFail('Choose the route for a route or distance rate.');
    }

    findOrFail($pdo, 'clients', 'client_id', $clientId, 'Client not found.');
    if ($routeId !== null) {
        $route = $pdo->prepare('SELECT distance FROM routes WHERE route_id = ? AND is_active = 1');
        $route->execute([$routeId]);
        $distance = $route->fetchColumn();
        if ($distance === false) {
            jsonFail('Choose an active route.');
        }
        if ($rateType === 'Distance' && ($distance === null || (float)$distance <= 0)) {
            jsonFail('A distance rate needs a route with a recorded distance.');
        }
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('SELECT client_id FROM clients WHERE client_id = ? FOR UPDATE')->execute([$clientId]);
        if (overlappingClientRate($pdo, $clientId, $routeId, $truckType, $effectiveFrom, $effectiveTo, $rateId) !== null) {
            $pdo->rollBack();
            jsonFail('An active rate already covers part of this effective date range.', 409);
        }
        if ($rateId !== null) {
            $old = $pdo->prepare('SELECT client_id FROM client_rates WHERE rate_id = ? FOR UPDATE');
            $old->execute([$rateId]);
            if ((int)$old->fetchColumn() !== $clientId) {
                $pdo->rollBack();
                jsonFail('Rate not found for this client.', 404);
            }
            $pdo->prepare(
                'UPDATE client_rates
                 SET route_id = ?, truck_type = ?, rate_type = ?, rate_amount = ?,
                     effective_date = ?, end_date = ?
                 WHERE rate_id = ? AND client_id = ?'
            )->execute([$routeId, $truckType, $rateType, $amount, $effectiveFrom, $effectiveTo, $rateId, $clientId]);
            auditLog('UPDATE_CLIENT_RATE', 'client_rates', $rateId, null, [
                'client_id' => $clientId, 'route_id' => $routeId, 'truck_type' => $truckType,
                'rate_type' => $rateType, 'rate_amount' => $amount,
                'effective_date' => $effectiveFrom, 'end_date' => $effectiveTo,
            ]);
            $pdo->commit();
            jsonOk([], 'Client rate updated.');
        }
        $pdo->prepare(
            'INSERT INTO client_rates
                (client_id, route_id, truck_type, rate_type, rate_amount, effective_date, end_date, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([$clientId, $routeId, $truckType, $rateType, $amount, $effectiveFrom, $effectiveTo]);
        $newId = (int)$pdo->lastInsertId();
        auditLog('CREATE_CLIENT_RATE', 'client_rates', $newId, null, [
            'client_id' => $clientId, 'route_id' => $routeId, 'truck_type' => $truckType,
            'rate_type' => $rateType, 'rate_amount' => $amount,
            'effective_date' => $effectiveFrom, 'end_date' => $effectiveTo,
        ]);
        $pdo->commit();
        jsonOk(['id' => $newId], 'Client rate added.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('client_master_data_handler/save_rate: ' . $e->getMessage());
        jsonFail('Could not save the rate.', 500);
    }
}

if ($action === 'toggle_rate') {
    $rateId = requiredInt('rate_id', 'Rate', 1);
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT * FROM client_rates WHERE rate_id = ? FOR UPDATE');
        $lock->execute([$rateId]);
        $rate = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$rate) {
            $pdo->rollBack();
            jsonFail('Rate not found.', 404);
        }
        $newState = (int)!((int)$rate['is_active']);
        if ($newState === 1 && overlappingClientRate(
            $pdo, (int)$rate['client_id'], $rate['route_id'] === null ? null : (int)$rate['route_id'],
            (string)$rate['truck_type'], (string)$rate['effective_date'], $rate['end_date'], $rateId
        ) !== null) {
            $pdo->rollBack();
            jsonFail('This rate overlaps an active rate. Adjust its dates before reactivating.', 409);
        }
        $pdo->prepare('UPDATE client_rates SET is_active = ? WHERE rate_id = ?')->execute([$newState, $rateId]);
        auditLog('TOGGLE_CLIENT_RATE', 'client_rates', $rateId, ['is_active' => $rate['is_active']], ['is_active' => $newState]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('client_master_data_handler/toggle_rate: ' . $e->getMessage());
        jsonFail('Could not update the rate.', 500);
    }
    jsonOk(['is_active' => $newState], 'Client rate status updated.');
}

jsonFail('Unknown action.');
