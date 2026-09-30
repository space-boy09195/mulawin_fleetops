<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

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
    $type = requiredEnum('location_type', ['Pickup', 'Delivery', 'Both'], 'Location type');
    $address = requiredString('address', 'Address', 500);
    $contact = optionalString('contact_person', null, 150);
    $phone = optionalString('contact_number', null, 50);
    $notes = optionalString('notes', null, 1000);

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

    if ($locationId !== null) {
        $pdo->prepare(
            'UPDATE client_locations
             SET location_name = ?, location_type = ?, address = ?, contact_person = ?,
                 contact_number = ?, notes = ?
             WHERE location_id = ? AND client_id = ?'
        )->execute([$name, $type, $address, $contact, $phone, $notes, $locationId, $clientId]);
        auditLog('UPDATE_CLIENT_LOCATION', 'client_locations', $locationId, null, ['location_name' => $name]);
        jsonOk([], 'Client location updated.');
    }

    $pdo->prepare(
        'INSERT INTO client_locations
            (client_id, location_name, location_type, address, contact_person, contact_number, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$clientId, $name, $type, $address, $contact, $phone, $notes, currentUserId()]);
    $newId = (int)$pdo->lastInsertId();
    auditLog('CREATE_CLIENT_LOCATION', 'client_locations', $newId, null, ['client_id' => $clientId, 'location_name' => $name]);
    jsonOk(['id' => $newId], 'Client location added.');
}

if ($action === 'toggle_location') {
    $locationId = requiredInt('location_id', 'Location', 1);
    $location = findOrFail($pdo, 'client_locations', 'location_id', $locationId, 'Location not found.');
    $newState = (int)!((int)$location['is_active']);
    if ($newState === 0) {
        $rate = $pdo->prepare(
            'SELECT COUNT(*) FROM client_rates
             WHERE is_active = 1
               AND (origin_location_id = ? OR destination_location_id = ?)
               AND (effective_to IS NULL OR effective_to >= CURDATE())'
        );
        $rate->execute([$locationId, $locationId]);
        if ((int)$rate->fetchColumn() > 0) {
            jsonFail('End or deactivate active and future rates before deactivating this location.', 409);
        }
    }
    $pdo->prepare('UPDATE client_locations SET is_active = ? WHERE location_id = ?')->execute([$newState, $locationId]);
    auditLog('TOGGLE_CLIENT_LOCATION', 'client_locations', $locationId, ['is_active' => $location['is_active']], ['is_active' => $newState]);
    jsonOk(['is_active' => $newState], 'Client location status updated.');
}

if ($action === 'save_rate') {
    $rateId = filter_input(INPUT_POST, 'rate_id', FILTER_VALIDATE_INT) ?: null;
    $clientId = requiredInt('client_id', 'Client', 1);
    $originId = requiredInt('origin_location_id', 'Pickup location', 1);
    $destinationId = requiredInt('destination_location_id', 'Delivery location', 1);
    $service = requiredString('service_name', 'Service name', 100);
    $basis = requiredEnum('rate_basis', ['Per Trip', 'Per Ton', 'Per Kilometer', 'Per Unit'], 'Rate basis');
    $amount = requiredPositiveFloat('rate_amount', 'Rate amount');
    $currency = strtoupper(requiredString('currency', 'Currency', 3));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        jsonFail('Currency must be a three-letter code, such as PHP.');
    }
    $effectiveFrom = requiredDate('effective_from', 'Effective from');
    $effectiveTo = optionalString('effective_to', null, 10);
    $notes = optionalString('notes', null, 1000);
    if ($effectiveTo !== null && (!isValidDate($effectiveTo) || $effectiveTo < $effectiveFrom)) {
        jsonFail('Effective-to date must be a valid date on or after the start date.');
    }
    if ($originId === $destinationId) {
        jsonFail('Pickup and delivery locations must be different.');
    }

    $locations = $pdo->prepare(
        'SELECT COUNT(*) FROM client_locations
         WHERE client_id = ? AND is_active = 1 AND location_id IN (?, ?)'
    );
    $locations->execute([$clientId, $originId, $destinationId]);
    if ((int)$locations->fetchColumn() !== 2) {
        jsonFail('Choose active pickup and delivery locations belonging to this client.');
    }

    $overlap = $pdo->prepare(
        'SELECT rate_id FROM client_rates
         WHERE client_id = ? AND origin_location_id = ? AND destination_location_id = ?
           AND LOWER(service_name) = LOWER(?) AND rate_basis = ? AND is_active = 1
           AND effective_from <= COALESCE(?, \'9999-12-31\')
           AND COALESCE(effective_to, \'9999-12-31\') >= ?
           AND rate_id <> COALESCE(?, 0)
         LIMIT 1'
    );
    $overlap->execute([
        $clientId, $originId, $destinationId, $service, $basis,
        $effectiveTo, $effectiveFrom, $rateId,
    ]);
    if ($overlap->fetchColumn()) {
        jsonFail('An active rate already covers part of this effective date range.', 409);
    }

    if ($rateId !== null) {
        $old = $pdo->prepare('SELECT client_id FROM client_rates WHERE rate_id = ?');
        $old->execute([$rateId]);
        if ((int)$old->fetchColumn() !== $clientId) {
            jsonFail('Rate not found for this client.', 404);
        }
        $pdo->prepare(
            'UPDATE client_rates
             SET origin_location_id = ?, destination_location_id = ?, service_name = ?,
                 rate_basis = ?, rate_amount = ?, currency = ?, effective_from = ?,
                 effective_to = ?, notes = ?
             WHERE rate_id = ? AND client_id = ?'
        )->execute([
            $originId, $destinationId, $service, $basis, $amount, $currency,
            $effectiveFrom, $effectiveTo, $notes, $rateId, $clientId,
        ]);
        auditLog('UPDATE_CLIENT_RATE', 'client_rates', $rateId, null, [
            'client_id' => $clientId, 'rate_amount' => $amount, 'currency' => $currency,
            'effective_from' => $effectiveFrom, 'effective_to' => $effectiveTo,
        ]);
        jsonOk([], 'Client rate updated.');
    }

    $pdo->prepare(
        'INSERT INTO client_rates
            (client_id, origin_location_id, destination_location_id, service_name, rate_basis,
             rate_amount, currency, effective_from, effective_to, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $clientId, $originId, $destinationId, $service, $basis, $amount, $currency,
        $effectiveFrom, $effectiveTo, $notes, currentUserId(),
    ]);
    $newId = (int)$pdo->lastInsertId();
    auditLog('CREATE_CLIENT_RATE', 'client_rates', $newId, null, [
        'client_id' => $clientId, 'rate_amount' => $amount, 'currency' => $currency,
        'effective_from' => $effectiveFrom, 'effective_to' => $effectiveTo,
    ]);
    jsonOk(['id' => $newId], 'Client rate added.');
}

if ($action === 'toggle_rate') {
    $rateId = requiredInt('rate_id', 'Rate', 1);
    $rate = findOrFail($pdo, 'client_rates', 'rate_id', $rateId, 'Rate not found.');
    $newState = (int)!((int)$rate['is_active']);
    if ($newState === 1) {
        $conflict = $pdo->prepare(
            'SELECT rate_id FROM client_rates
             WHERE client_id = ? AND origin_location_id = ? AND destination_location_id = ?
               AND LOWER(service_name) = LOWER(?) AND rate_basis = ? AND is_active = 1
               AND effective_from <= COALESCE(?, \'9999-12-31\')
               AND COALESCE(effective_to, \'9999-12-31\') >= ?
               AND rate_id <> ? LIMIT 1'
        );
        $conflict->execute([
            $rate['client_id'], $rate['origin_location_id'], $rate['destination_location_id'],
            $rate['service_name'], $rate['rate_basis'], $rate['effective_to'],
            $rate['effective_from'], $rateId,
        ]);
        if ($conflict->fetchColumn()) {
            jsonFail('This rate overlaps an active rate. Adjust its dates before reactivating.', 409);
        }
    }
    $pdo->prepare('UPDATE client_rates SET is_active = ? WHERE rate_id = ?')->execute([$newState, $rateId]);
    auditLog('TOGGLE_CLIENT_RATE', 'client_rates', $rateId, ['is_active' => $rate['is_active']], ['is_active' => $newState]);
    jsonOk(['is_active' => $newState], 'Client rate status updated.');
}

jsonFail('Unknown action.');
