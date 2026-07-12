<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT]);

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Shared field extractor ────────────────────────────────────────────────────
function extractTruckFields(): array {
    return [
        'plate_number'   => strtoupper(trim($_POST['plate_number']  ?? '')),
        'brand'          => trim($_POST['brand']          ?? ''),
        'model'          => trim($_POST['model']          ?? ''),
        'year_model'     => (int)($_POST['year_model']    ?? 0),
        'body_type'      => trim($_POST['body_type']      ?? '') ?: null,
        'fuel_type'      => trim($_POST['fuel_type']      ?? 'Diesel'),
        'capacity_tons'  => $_POST['capacity_tons'] !== '' ? (float)$_POST['capacity_tons'] : null,
        'chassis_number' => trim($_POST['chassis_number'] ?? '') ?: null,
        'engine_number'  => trim($_POST['engine_number']  ?? '') ?: null,
    ];
}

function validateTruckFields(array $f): ?string {
    if (!$f['plate_number'])              return 'Plate number is required.';
    if (!$f['brand'])                     return 'Brand is required.';
    if (!$f['model'])                     return 'Model is required.';
    if ($f['year_model'] < 1990 || $f['year_model'] > (int)date('Y') + 1)
                                          return 'Invalid year model.';
    $allowed = ['Diesel','Gasoline','LPG','Electric'];
    if (!in_array($f['fuel_type'], $allowed)) return 'Invalid fuel type.';
    if ($f['capacity_tons'] !== null && $f['capacity_tons'] < 0)
                                          return 'Capacity cannot be negative.';
    return null;
}

// ── Add truck ─────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $f = extractTruckFields();

    if ($err = validateTruckFields($f)) {
        echo json_encode(['success' => false, 'message' => $err]);
        exit;
    }

    // Unique plate
    $dup = $pdo->prepare("SELECT truck_id FROM trucks WHERE plate_number = ?");
    $dup->execute([$f['plate_number']]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'A truck with that plate number already exists.']);
        exit;
    }

    // Unique chassis if provided
    if ($f['chassis_number']) {
        $dupCh = $pdo->prepare("SELECT truck_id FROM trucks WHERE chassis_number = ?");
        $dupCh->execute([$f['chassis_number']]);
        if ($dupCh->fetch()) {
            echo json_encode(['success' => false, 'message' => 'A truck with that chassis number already exists.']);
            exit;
        }
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

        echo json_encode(['success' => true, 'message' => 'Truck added successfully.', 'id' => $newId]);
    } catch (PDOException $e) {
        error_log('trucks_handler/add: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Edit truck ────────────────────────────────────────────────────────────────
if ($action === 'edit') {
    $truckId = (int)($_POST['truck_id'] ?? 0);
    $status  = trim($_POST['status']   ?? '');
    $f       = extractTruckFields();

    if (!$truckId) {
        echo json_encode(['success' => false, 'message' => 'Invalid truck ID.']);
        exit;
    }

    if ($err = validateTruckFields($f)) {
        echo json_encode(['success' => false, 'message' => $err]);
        exit;
    }

    $allowedStatuses = ['Available', 'Deployed', 'Under Maintenance', 'Inactive'];
    if (!in_array($status, $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status.']);
        exit;
    }

    // Fetch old for audit
    $old = $pdo->prepare("SELECT * FROM trucks WHERE truck_id = ?");
    $old->execute([$truckId]);
    $oldData = $old->fetch(PDO::FETCH_ASSOC);
    if (!$oldData) {
        echo json_encode(['success' => false, 'message' => 'Truck not found.']);
        exit;
    }

    // Unique plate excluding self
    $dup = $pdo->prepare("SELECT truck_id FROM trucks WHERE plate_number = ? AND truck_id != ?");
    $dup->execute([$f['plate_number'], $truckId]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Another truck already has that plate number.']);
        exit;
    }

    // Unique chassis excluding self
    if ($f['chassis_number']) {
        $dupCh = $pdo->prepare("SELECT truck_id FROM trucks WHERE chassis_number = ? AND truck_id != ?");
        $dupCh->execute([$f['chassis_number'], $truckId]);
        if ($dupCh->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Another truck already has that chassis number.']);
            exit;
        }
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

        echo json_encode(['success' => true, 'message' => 'Truck updated successfully.']);
    } catch (PDOException $e) {
        error_log('trucks_handler/edit: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);