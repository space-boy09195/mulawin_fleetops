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

function storeTruckImage(?array $file, ?string $existingPath = null): ?string {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existingPath;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)$file['size'] > 10 * 1024 * 1024) {
        jsonFail('Truck image must be a valid file no larger than 10 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        jsonFail('Truck image must be a JPG, PNG, or WebP file.');
    }
    $directory = dirname(__DIR__) . '/uploads/trucks/';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        jsonFail('Truck image directory could not be created.', 500);
    }
    $name = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . $name)) {
        jsonFail('Truck image could not be saved.', 500);
    }
    return 'uploads/trucks/' . $name;
}

function storeTruckViewImages(array $files, array $existing = []): array {
    $paths = $existing;
    foreach (['front', 'side', 'rear', 'top'] as $view) {
        if (isset($files[$view])) {
            $paths[$view] = storeTruckImage($files[$view], $paths[$view] ?? null);
        }
    }
    return $paths;
}

// ── Add truck ─────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $f = extractTruckFields();
    $images = storeTruckViewImages($_FILES['truck_images'] ?? []);

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
                 year_model, body_type, fuel_type, capacity_tons, image_path,
                 image_front_path, image_side_path, image_rear_path, image_top_path, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available')
        ");
        $stmt->execute([
            $f['plate_number'], $f['chassis_number'], $f['engine_number'],
            $f['brand'], $f['model'], $f['year_model'],
            $f['body_type'], $f['fuel_type'], $f['capacity_tons'],
            $images['front'] ?? null, $images['front'] ?? null, $images['side'] ?? null,
            $images['rear'] ?? null, $images['top'] ?? null,
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
    $images = storeTruckViewImages($_FILES['truck_images'] ?? [], [
        'front' => $oldData['image_front_path'] ?? $oldData['image_path'] ?? null,
        'side'  => $oldData['image_side_path'] ?? null,
        'rear'  => $oldData['image_rear_path'] ?? null,
        'top'   => $oldData['image_top_path'] ?? null,
    ]);

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
                body_type      = ?, fuel_type      = ?, capacity_tons  = ?, image_path = ?,
                image_front_path = ?, image_side_path = ?, image_rear_path = ?, image_top_path = ?,
                status         = ?
            WHERE truck_id     = ?
        ")->execute([
            $f['plate_number'], $f['chassis_number'], $f['engine_number'],
            $f['brand'], $f['model'], $f['year_model'],
            $f['body_type'], $f['fuel_type'], $f['capacity_tons'], $images['front'] ?? null,
            $images['front'] ?? null, $images['side'] ?? null, $images['rear'] ?? null, $images['top'] ?? null,
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
