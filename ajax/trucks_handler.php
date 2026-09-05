<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT]);
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Shared field extractor + validator ─────────────────────────────────────
// Uses the shared validate.php helpers, so a bad field fails the same way
// (same JSON shape, same trimming/casting rules) as every other handler.
function extractTruckFields(): array {
    return [
        'plate_number'   => strtoupper(requiredString('plate_number', 'Plate number', 20)),
        'brand'          => requiredString('brand', 'Brand', 100),
        'model'          => requiredString('model', 'Model', 100),
        'year_model'     => requiredInt('year_model', 'Year model', 1990, (int)date('Y') + 1),
        'body_type'      => optionalString('body_type'),
        'fuel_type'      => requiredEnum('fuel_type', TRUCK_FUEL_TYPES, 'Fuel type'),
        'capacity_tons'  => optionalFloat('capacity_tons'),
        'chassis_number' => optionalString('chassis_number'),
        'engine_number'  => optionalString('engine_number'),
    ];
}

// ── Add truck ─────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $f = extractTruckFields();

    if ($f['capacity_tons'] !== null && $f['capacity_tons'] < 0) {
        jsonFail('Capacity cannot be negative.');
    }

    if (existsWhere($pdo, 'trucks', 'plate_number', $f['plate_number'])) {
        jsonFail('A truck with that plate number already exists.');
    }
    if ($f['chassis_number'] && existsWhere($pdo, 'trucks', 'chassis_number', $f['chassis_number'])) {
        jsonFail('A truck with that chassis number already exists.');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO trucks
                (plate_number, chassis_number, engine_number, brand, model,
                 year_model, body_type, fuel_type, capacity_tons, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available')
        ");
        $stmt->execute([
            $f['plate_number'], $f['chassis_number'], $f['engine_number'],
            $f['brand'], $f['model'], $f['year_model'],
            $f['body_type'], $f['fuel_type'], $f['capacity_tons'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        auditLog('ADD_TRUCK', 'trucks', $newId, null, [
            'plate_number' => $f['plate_number'],
            'brand'        => $f['brand'],
            'model'        => $f['model'],
        ]);

        jsonOk(['id' => $newId], 'Truck added successfully.');
    } catch (PDOException $e) {
        error_log('trucks_handler/add: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Edit truck ────────────────────────────────────────────────────────────────
if ($action === 'edit') {
    $truckId = requiredInt('truck_id', 'Truck ID', 1);
    $status  = requiredEnum('status', TRUCK_STATUSES, 'Status');
    $f       = extractTruckFields();

    if ($f['capacity_tons'] !== null && $f['capacity_tons'] < 0) {
        jsonFail('Capacity cannot be negative.');
    }

    $oldData = findOrFail($pdo, 'trucks', 'truck_id', $truckId, 'Truck not found.');

    if (existsWhere($pdo, 'trucks', 'plate_number', $f['plate_number'], $truckId, 'truck_id')) {
        jsonFail('Another truck already has that plate number.');
    }
    if ($f['chassis_number'] && existsWhere($pdo, 'trucks', 'chassis_number', $f['chassis_number'], $truckId, 'truck_id')) {
        jsonFail('Another truck already has that chassis number.');
    }

    try {
        $pdo->prepare("
            UPDATE trucks SET
                plate_number   = ?, chassis_number = ?, engine_number  = ?,
                brand          = ?, model          = ?, year_model     = ?,
                body_type      = ?, fuel_type      = ?, capacity_tons  = ?,
                status         = ?
            WHERE truck_id     = ?
        ")->execute([
            $f['plate_number'], $f['chassis_number'], $f['engine_number'],
            $f['brand'], $f['model'], $f['year_model'],
            $f['body_type'], $f['fuel_type'], $f['capacity_tons'],
            $status, $truckId,
        ]);

        auditLog('EDIT_TRUCK', 'trucks', $truckId,
            ['plate_number' => $oldData['plate_number'], 'status' => $oldData['status']],
            ['plate_number' => $f['plate_number'],       'status' => $status]
        );

        jsonOk([], 'Truck updated successfully.');
    } catch (PDOException $e) {
        error_log('trucks_handler/edit: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

jsonFail('Unknown action.');