<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/truck_status_history.php';

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
        'unit_number'    => optionalString('unit_number', null, 30),
        'truck_type'     => requiredEnum('truck_type', TRUCK_CATEGORIES, 'Truck category'),
        'mv_file_number' => optionalString('mv_file_number', null, 50),
        'registration_expiry' => optionalString('registration_expiry', null, 10),
        'insurance_provider' => optionalString('insurance_provider', null, 150),
        'insurance_policy_number' => optionalString('insurance_policy_number', null, 80),
        'insurance_expiry' => optionalString('insurance_expiry', null, 10),
        'warranty_expiry' => optionalString('warranty_expiry', null, 10),
    ];
}

function validateTruckExpiryDates(array $fields): void {
    foreach ([
        'registration_expiry' => 'Registration expiry',
        'insurance_expiry' => 'Insurance expiry',
        'warranty_expiry' => 'Warranty expiry',
    ] as $key => $label) {
        if ($fields[$key] !== null && !isValidDate($fields[$key])) {
            jsonFail($label . ' must be a valid date.');
        }
    }
}

function validateUniqueTruckIdentifiers(PDO $pdo, array $fields, ?int $truckId = null): void {
    if ($fields['unit_number'] !== null && existsWhere($pdo, 'trucks', 'unit_number', $fields['unit_number'], $truckId, 'truck_id')) {
        jsonFail('Another truck already has that unit number.');
    }
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
    $destPath = $directory . $name;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        jsonFail('Truck image could not be saved.', 500);
    }
    resizeTruckImage($destPath, $mime);
    return 'uploads/trucks/' . $name;
}

// Cap the longest side and re-encode so a phone photo (often 3000px+, several
// MB) doesn't get served at full size for a 42x32px table thumbnail. Also
// auto-rotates JPEGs per their EXIF orientation tag, since phone cameras store
// portrait shots "sideways" with a rotation flag rather than rotating the
// pixels — without this they'd display on their side everywhere in the app.
// Best-effort only: if GD isn't available or decoding fails, the original
// upload is left in place rather than failing the whole request.
function resizeTruckImage(string $path, string $mime): void {
    if (!extension_loaded('gd')) return;

    $maxDimension = 1600;
    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        default      => null,
    };
    if (!$image) return;

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $orientation = $exif['Orientation'] ?? 1;
        $image = match ($orientation) {
            3       => imagerotate($image, 180, 0),
            6       => imagerotate($image, -90, 0),
            8       => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    $width  = imagesx($image);
    $height = imagesy($image);
    if (max($width, $height) > $maxDimension) {
        $scale     = $maxDimension / max($width, $height);
        $newWidth  = (int)round($width * $scale);
        $newHeight = (int)round($height * $scale);
        $resized   = imagecreatetruecolor($newWidth, $newHeight);
        if ($mime !== 'image/jpeg') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);
        $image = $resized;
    }

    match ($mime) {
        'image/jpeg' => imagejpeg($image, $path, 82),
        'image/png'  => imagepng($image, $path, 6),
        'image/webp' => imagewebp($image, $path, 82),
        default      => null,
    };
    imagedestroy($image);
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
    validateTruckExpiryDates($f);
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
    validateUniqueTruckIdentifiers($pdo, $f);

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO trucks
                (plate_number, chassis_number, engine_number, brand, model,
                 year_model, body_type, fuel_type, capacity_tons, image_path,
                 image_front_path, image_side_path, image_rear_path, image_top_path, status,
                 unit_number, truck_type, mv_file_number, registration_expiry,
                 insurance_provider, insurance_policy_number, insurance_expiry, warranty_expiry)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $f['plate_number'], $f['chassis_number'], $f['engine_number'],
            $f['brand'], $f['model'], $f['year_model'],
            $f['body_type'], $f['fuel_type'], $f['capacity_tons'],
            $images['front'] ?? null, $images['front'] ?? null, $images['side'] ?? null,
            $images['rear'] ?? null, $images['top'] ?? null,
            $f['unit_number'], $f['truck_type'], $f['mv_file_number'], $f['registration_expiry'],
            $f['insurance_provider'], $f['insurance_policy_number'], $f['insurance_expiry'], $f['warranty_expiry'],
        ]);
        $newId = (int)$pdo->lastInsertId();
        recordTruckStatusHistory(
            $pdo,
            $newId,
            null,
            'Available',
            'Truck registered and added to fleet.'
        );
        $pdo->commit();

        auditLog('ADD_TRUCK', 'trucks', $newId, null, [
            'plate_number' => $f['plate_number'],
            'brand'        => $f['brand'],
            'model'        => $f['model'],
        ]);

        jsonOk(['id' => $newId], 'Truck added successfully.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('trucks_handler/add: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('trucks_handler/add: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Edit truck ────────────────────────────────────────────────────────────────
if ($action === 'edit') {
    $truckId = requiredInt('truck_id', 'Truck ID', 1);
    $status  = requiredEnum('status', TRUCK_STATUSES, 'Status');
    $statusReason = optionalString('status_reason', null, 500);
    $f       = extractTruckFields();
    validateTruckExpiryDates($f);

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
    validateUniqueTruckIdentifiers($pdo, $f, $truckId);

    try {
        $pdo->beginTransaction();
        $statusLock = $pdo->prepare('SELECT status FROM trucks WHERE truck_id = ? FOR UPDATE');
        $statusLock->execute([$truckId]);
        $lockedStatus = $statusLock->fetchColumn();
        if ($lockedStatus === false) {
            $pdo->rollBack();
            jsonFail('Truck not found.', 404);
        }
        if ($lockedStatus !== $status && $statusReason === null) {
            $pdo->rollBack();
            jsonFail('Explain why the truck status is changing.');
        }
        $pdo->prepare("
            UPDATE trucks SET
                plate_number   = ?, chassis_number = ?, engine_number  = ?,
                brand          = ?, model          = ?, year_model     = ?,
                body_type      = ?, fuel_type      = ?, capacity_tons  = ?, image_path = ?,
                image_front_path = ?, image_side_path = ?, image_rear_path = ?, image_top_path = ?,
                status         = ?, unit_number = ?, truck_type = ?, mv_file_number = ?,
                registration_expiry = ?, insurance_provider = ?, insurance_policy_number = ?,
                insurance_expiry = ?, warranty_expiry = ?
            WHERE truck_id     = ?
        ")->execute([
            $f['plate_number'], $f['chassis_number'], $f['engine_number'],
            $f['brand'], $f['model'], $f['year_model'],
            $f['body_type'], $f['fuel_type'], $f['capacity_tons'], $images['front'] ?? null,
            $images['front'] ?? null, $images['side'] ?? null, $images['rear'] ?? null, $images['top'] ?? null,
            $status, $f['unit_number'], $f['truck_type'], $f['mv_file_number'], $f['registration_expiry'],
            $f['insurance_provider'], $f['insurance_policy_number'], $f['insurance_expiry'], $f['warranty_expiry'], $truckId,
        ]);
        if ($lockedStatus !== $status) {
            recordTruckStatusHistory(
                $pdo,
                $truckId,
                (string)$lockedStatus,
                $status,
                $statusReason
            );
        }
        $pdo->commit();

        auditLog('EDIT_TRUCK', 'trucks', $truckId,
            ['plate_number' => $oldData['plate_number'], 'status' => $oldData['status']],
            ['plate_number' => $f['plate_number'], 'status' => $status, 'status_reason' => $statusReason]
        );

        jsonOk([], 'Truck updated successfully.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('trucks_handler/edit: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('trucks_handler/edit: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

jsonFail('Unknown action.');
