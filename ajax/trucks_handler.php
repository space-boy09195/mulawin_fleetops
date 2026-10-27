<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../app/core/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/shared/validate.php';
require_once __DIR__ . '/../app/shared/db_helpers.php';
require_once __DIR__ . '/../app/modules/fleet/truck_status_history.php';
require_once __DIR__ . '/../app/modules/documents/upload_validation.php';
require_once __DIR__ . '/../app/modules/fleet/truck_records.php';

header('Content-Type: application/json');

// Truck master records (add/edit) need Admin (validated role key) or the
// fleet.manage permission (Operations Head). Truck status is not changed
// here; it changes only through ajax/update_truck_status.php. Unauthorized
// responses match requireRole(): login check first, then a 403 JSON reply
// for JSON/AJAX requests, otherwise the 403 page.
requireLogin();
if (!isAdmin() && !currentUserHasAnyPermission(['fleet.manage'])) {
    if (isJsonRequest()) {
        sendJsonAccessResponse(403, 'You do not have permission to perform this action.');
    }
    http_response_code(403);
    include __DIR__ . '/../pages/403.php';
    exit;
}
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Shared field extractor + validator ─────────────────────────────────────
// Uses the shared validate.php helpers, so a bad field fails the same way
// (same JSON shape, same trimming/casting rules) as every other handler.
function extractTruckFields(): array {
    return [
        'plate_number'   => strtoupper(requiredString('plate_number', 'Plate number', 30)),
        'brand'          => requiredString('brand', 'Brand', 80),
        'model'          => requiredString('model', 'Model', 80),
        'year_model'     => requiredInt('year_model', 'Year model', 1990, (int)date('Y') + 1),
        'body_type'      => optionalString('body_type'),
        'fuel_type'      => requiredEnum('fuel_type', TRUCK_FUEL_TYPES, 'Fuel type'),
        'capacity_tons'  => optionalFloat('capacity_tons'),
        'chassis_number' => optionalString('chassis_number', null, 100),
        'engine_number'  => optionalString('engine_number', null, 100),
        'unit_number'    => optionalString('unit_number', null, 50),
        'truck_type'     => requiredEnum('truck_type', TRUCK_CATEGORIES, 'Truck category'),
        'mv_file_number' => optionalString('mv_file_number', null, 100),
        'registration_expiry' => optionalString('registration_expiry', null, 10),
        'insurance_provider' => optionalString('insurance_provider', null, 150),
        'insurance_policy_number' => optionalString('insurance_policy_number', null, 100),
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

// Validates the submitted view images without moving them. The form posts
// truck_images[front] etc., which PHP delivers as
// $_FILES['truck_images']['name']['front'], so each view is regrouped here.
function inspectTruckViewImages(array $files): array {
    $uploads = [];
    foreach (['front', 'side', 'rear', 'top'] as $view) {
        $file = [];
        foreach (['name', 'tmp_name', 'error', 'size'] as $key) {
            $file[$key] = $files[$key][$view] ?? null;
        }
        if ($file['error'] === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        try {
            $uploads[$view] = inspectTruckImageUpload($file) + ['tmp_name' => $file['tmp_name']];
        } catch (InvalidArgumentException $e) {
            jsonFail(ucfirst($view) . ' view: ' . $e->getMessage());
        }
    }
    return $uploads;
}

// Moves already-inspected images into uploads/trucks/ under generated names.
// Returns [view => path, view => ['path','original_name','mime_type','size']]
// for the images stored by this request; on failure removes what it moved.
function storeTruckViewImages(array $uploads): array {
    if (!$uploads) {
        return [[], []];
    }
    $directory = dirname(__DIR__) . '/uploads/trucks/';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        jsonFail('Truck image directory could not be created.', 500);
    }
    $paths = [];
    $stored = [];
    foreach ($uploads as $view => $upload) {
        $name = bin2hex(random_bytes(16)) . '.' . $upload['extension'];
        if (!move_uploaded_file($upload['tmp_name'], $directory . $name)) {
            discardTruckImages(array_column($stored, 'path'));
            jsonFail('Truck image could not be saved.', 500);
        }
        resizeTruckImage($directory . $name, $upload['mime_type']);
        $paths[$view] = 'uploads/trucks/' . $name;
        $stored[$view] = [
            'path'          => $paths[$view],
            'original_name' => $upload['original_name'],
            'mime_type'     => $upload['mime_type'],
            'size'          => $upload['size'],
        ];
    }
    return [$paths, $stored];
}

// Removes images stored by this request when the truck record is not saved.
function discardTruckImages(array $paths): void {
    foreach ($paths as $path) {
        if (is_string($path) && preg_match('~^uploads/trucks/[0-9a-f]{32}\.(?:jpg|png|webp)$~', $path)) {
            $file = dirname(__DIR__) . '/' . $path;
            if (is_file($file)) unlink($file);
        }
    }
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

// ── Add truck ─────────────────────────────────────────────────────────────────
// Target columns: vehicle_year, body_details, capacity; registration,
// insurance and warranty go to truck_documents and photos to files /
// documents / document_links (includes/truck_records.php).
if ($action === 'add') {
    $f = extractTruckFields();
    validateTruckExpiryDates($f);
    $imageUploads = inspectTruckViewImages($_FILES['truck_images'] ?? []);

    if ($f['capacity_tons'] !== null && $f['capacity_tons'] < 0) {
        jsonFail('Capacity cannot be negative.');
    }

    if (existsWhere($pdo, 'trucks', 'plate_number', $f['plate_number'])) {
        jsonFail('A truck with that plate number already exists.');
    }
    if ($f['chassis_number'] && existsWhere($pdo, 'trucks', 'chassis_number', $f['chassis_number'])) {
        jsonFail('A truck with that chassis number already exists.');
    }
    if ($f['engine_number'] && existsWhere($pdo, 'trucks', 'engine_number', $f['engine_number'])) {
        jsonFail('A truck with that engine number already exists.');
    }
    validateUniqueTruckIdentifiers($pdo, $f);
    [$images, $storedUploads] = storeTruckViewImages($imageUploads);

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO trucks
                (plate_number, chassis_number, engine_number, brand, model,
                 vehicle_year, body_details, fuel_type, capacity, status,
                 availability_status, unit_number, truck_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available', 'Available', ?, ?)
        ");
        $stmt->execute([
            $f['plate_number'], $f['chassis_number'], $f['engine_number'],
            $f['brand'], $f['model'], $f['year_model'],
            $f['body_type'], $f['fuel_type'], $f['capacity_tons'],
            $f['unit_number'], $f['truck_type'],
        ]);
        $newId = (int)$pdo->lastInsertId();
        saveTruckDocuments($pdo, $newId, $f);
        saveTruckPhotos($pdo, $newId, $storedUploads, currentUserId());
        recordTruckStatusHistory(
            $pdo,
            $newId,
            null,
            'Available',
            'Truck registered and added to fleet.'
        );
        auditLog('ADD_TRUCK', 'trucks', $newId, null, [
            'plate_number' => $f['plate_number'],
            'brand'        => $f['brand'],
            'model'        => $f['model'],
        ]);
        $pdo->commit();

        jsonOk(['id' => $newId], 'Truck added successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        discardTruckImages(array_column($storedUploads, 'path'));
        error_log('trucks_handler/add: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Edit truck ────────────────────────────────────────────────────────────────
if ($action === 'edit') {
    $truckId = requiredInt('truck_id', 'Truck ID', 1);
    // Editing master fields never changes truck status. Status changes go only
    // through ajax/update_truck_status.php (fleet.status.update, active-trip
    // guard). A submitted status must equal the current one, so a changed or
    // stale form value is refused instead of being silently ignored.
    $requestedStatus = optionalString('status', null, 50);
    $f       = extractTruckFields();
    validateTruckExpiryDates($f);

    if ($f['capacity_tons'] !== null && $f['capacity_tons'] < 0) {
        jsonFail('Capacity cannot be negative.');
    }

    $oldData = findOrFail($pdo, 'trucks', 'truck_id', $truckId, 'Truck not found.');
    $imageUploads = inspectTruckViewImages($_FILES['truck_images'] ?? []);

    if (existsWhere($pdo, 'trucks', 'plate_number', $f['plate_number'], $truckId, 'truck_id')) {
        jsonFail('Another truck already has that plate number.');
    }
    if ($f['chassis_number'] && existsWhere($pdo, 'trucks', 'chassis_number', $f['chassis_number'], $truckId, 'truck_id')) {
        jsonFail('Another truck already has that chassis number.');
    }
    if ($f['engine_number'] && existsWhere($pdo, 'trucks', 'engine_number', $f['engine_number'], $truckId, 'truck_id')) {
        jsonFail('Another truck already has that engine number.');
    }
    validateUniqueTruckIdentifiers($pdo, $f, $truckId);
    [$images, $storedUploads] = storeTruckViewImages($imageUploads);

    try {
        $pdo->beginTransaction();
        $statusLock = $pdo->prepare('SELECT status, truck_type FROM trucks WHERE truck_id = ? FOR UPDATE');
        $statusLock->execute([$truckId]);
        $locked = $statusLock->fetch(PDO::FETCH_ASSOC);
        if ($locked === false) {
            $pdo->rollBack();
            discardTruckImages(array_column($storedUploads, 'path'));
            jsonFail('Truck not found.', 404);
        }
        $lockedStatus = (string)$locked['status'];
        if ($requestedStatus !== null && $requestedStatus !== $lockedStatus) {
            $pdo->rollBack();
            discardTruckImages(array_column($storedUploads, 'path'));
            jsonFail('Truck status cannot be changed while editing truck details. Use the status action on the Fleet Status page instead.');
        }
        // The truck type decides trip type and dispatcher scope; it cannot
        // change while the truck is held by a trip.
        if ($locked['truck_type'] !== $f['truck_type'] && in_array($lockedStatus, ['Assigned', 'On Trip'], true)) {
            $pdo->rollBack();
            discardTruckImages(array_column($storedUploads, 'path'));
            jsonFail('The truck category cannot change while the truck is assigned to a trip.', 409);
        }
        $pdo->prepare("
            UPDATE trucks SET
                plate_number   = ?, chassis_number = ?, engine_number  = ?,
                brand          = ?, model          = ?, vehicle_year   = ?,
                body_details   = ?, fuel_type      = ?, capacity       = ?,
                unit_number    = ?, truck_type     = ?
            WHERE truck_id     = ?
        ")->execute([
            $f['plate_number'], $f['chassis_number'], $f['engine_number'],
            $f['brand'], $f['model'], $f['year_model'],
            $f['body_type'], $f['fuel_type'], $f['capacity_tons'],
            $f['unit_number'], $f['truck_type'], $truckId,
        ]);
        saveTruckDocuments($pdo, $truckId, $f);
        saveTruckPhotos($pdo, $truckId, $storedUploads, currentUserId());
        auditLog('EDIT_TRUCK', 'trucks', $truckId,
            ['plate_number' => $oldData['plate_number'], 'status' => $oldData['status']],
            ['plate_number' => $f['plate_number'], 'status' => $lockedStatus, 'new_photos' => array_keys($storedUploads)]
        );
        $pdo->commit();

        jsonOk([], 'Truck updated successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        discardTruckImages(array_column($storedUploads, 'path'));
        error_log('trucks_handler/edit: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

jsonFail('Unknown action.');
