<?php
// ============================================================
// includes/truck_records.php
// Truck profile data that the legacy trucks table held in columns and the
// target schema keeps in related tables:
//
//   legacy column                         target
//   mv_file_number, registration_expiry   truck_documents type 'CR'
//                                         (document_number, expiry_date)
//   insurance_policy_number,              truck_documents type 'Insurance'
//   insurance_provider, insurance_expiry  (document_number, notes, expiry_date)
//   warranty_expiry                       truck_documents type 'Other',
//                                         notes 'Warranty'
//   image_front/side/rear/top_path        files + documents (category
//                                         'Truck Photo', description = view)
//                                         + document_links (entity 'truck')
//   year_model / body_type / capacity_tons vehicle_year / body_details / capacity
//
// The newest row of each kind is the current value; older rows stay as
// history. truckProfileSelectSql() returns the legacy column names so pages
// and scripts keep their keys.
// ============================================================

const TRUCK_PHOTO_CATEGORY = 'Truck Photo';
const TRUCK_PHOTO_VIEWS = ['front', 'side', 'rear', 'top'];
const TRUCK_WARRANTY_NOTE = 'Warranty';

// Current value of one truck_documents field, as a correlated subquery on
// "trucks t". Each combination is a fixed literal, so nothing is
// interpolated into the SQL.
function truckDocumentSubquery(string $column, string $type, ?string $notes = null): string {
    $key = $column . '|' . $type . '|' . ($notes ?? '');
    $subqueries = [
        'document_number|CR|' => "(SELECT td.document_number FROM truck_documents td WHERE td.truck_id = t.truck_id AND td.document_type = 'CR' ORDER BY td.truck_document_id DESC LIMIT 1)",
        'expiry_date|CR|' => "(SELECT td.expiry_date FROM truck_documents td WHERE td.truck_id = t.truck_id AND td.document_type = 'CR' ORDER BY td.truck_document_id DESC LIMIT 1)",
        'notes|Insurance|' => "(SELECT td.notes FROM truck_documents td WHERE td.truck_id = t.truck_id AND td.document_type = 'Insurance' ORDER BY td.truck_document_id DESC LIMIT 1)",
        'document_number|Insurance|' => "(SELECT td.document_number FROM truck_documents td WHERE td.truck_id = t.truck_id AND td.document_type = 'Insurance' ORDER BY td.truck_document_id DESC LIMIT 1)",
        'expiry_date|Insurance|' => "(SELECT td.expiry_date FROM truck_documents td WHERE td.truck_id = t.truck_id AND td.document_type = 'Insurance' ORDER BY td.truck_document_id DESC LIMIT 1)",
        'expiry_date|Other|Warranty' => "(SELECT td.expiry_date FROM truck_documents td WHERE td.truck_id = t.truck_id AND td.document_type = 'Other' AND td.notes = 'Warranty' ORDER BY td.truck_document_id DESC LIMIT 1)",
    ];
    if (!isset($subqueries[$key])) {
        throw new InvalidArgumentException('Invalid truck document field.');
    }
    return $subqueries[$key];
}

function truckPhotoSubquery(string $view): string {
    if (!in_array($view, TRUCK_PHOTO_VIEWS, true)) {
        throw new InvalidArgumentException('Invalid truck photo view.');
    }
    return "(SELECT f.storage_path
              FROM document_links dl
              JOIN documents d ON d.document_id = dl.document_id
              JOIN files f ON f.file_id = d.file_id
              WHERE dl.entity_type = 'truck' AND dl.entity_id = t.truck_id
                AND d.document_category = '" . TRUCK_PHOTO_CATEGORY . "' AND d.description = '{$view}'
              ORDER BY d.document_id DESC LIMIT 1)";
}

/**
 * Column list for "FROM trucks t" with the legacy profile column names.
 */
function truckProfileColumnsSql(): string {
    return "
        t.truck_id, t.plate_number, t.unit_number, t.truck_type,
        t.chassis_number, t.engine_number, t.brand, t.model,
        t.vehicle_year AS year_model, t.body_details AS body_type,
        t.fuel_type, t.capacity AS capacity_tons, t.fuel_efficiency_km_per_liter,
        t.status, t.availability_status, t.current_assignment_id,
        " . truckDocumentSubquery('document_number', 'CR') . " AS mv_file_number,
        " . truckDocumentSubquery('expiry_date', 'CR') . " AS registration_expiry,
        " . truckDocumentSubquery('notes', 'Insurance') . " AS insurance_provider,
        " . truckDocumentSubquery('document_number', 'Insurance') . " AS insurance_policy_number,
        " . truckDocumentSubquery('expiry_date', 'Insurance') . " AS insurance_expiry,
        " . truckDocumentSubquery('expiry_date', 'Other', TRUCK_WARRANTY_NOTE) . " AS warranty_expiry,
        " . truckPhotoSubquery('front') . " AS image_front_path,
        " . truckPhotoSubquery('side') . " AS image_side_path,
        " . truckPhotoSubquery('rear') . " AS image_rear_path,
        " . truckPhotoSubquery('top') . " AS image_top_path";
}

/**
 * Current truck_documents row of a kind, locked, or null.
 */
function currentTruckDocument(PDO $pdo, int $truckId, string $type, ?string $notes = null): ?array {
    $sql = 'SELECT * FROM truck_documents WHERE truck_id = ? AND document_type = ?';
    $params = [$truckId, $type];
    if ($notes !== null) {
        $sql .= ' AND notes = ?';
        $params[] = $notes;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY truck_document_id DESC LIMIT 1 FOR UPDATE');
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Save registration, insurance and warranty details. A kind with no value
 * and no existing row is skipped; an existing row is updated in place.
 * Runs in the caller's transaction.
 */
function saveTruckDocuments(PDO $pdo, int $truckId, array $f): void {
    $kinds = [
        ['CR', null, $f['mv_file_number'] ?? null, $f['registration_expiry'] ?? null, null],
        ['Insurance', null, $f['insurance_policy_number'] ?? null, $f['insurance_expiry'] ?? null, $f['insurance_provider'] ?? null],
        ['Other', TRUCK_WARRANTY_NOTE, null, $f['warranty_expiry'] ?? null, TRUCK_WARRANTY_NOTE],
    ];
    foreach ($kinds as [$type, $matchNotes, $number, $expiry, $notes]) {
        $existing = currentTruckDocument($pdo, $truckId, $type, $matchNotes);
        $hasValue = $number !== null || $expiry !== null || ($type === 'Insurance' && $notes !== null);
        if (!$existing && !$hasValue) {
            continue;
        }
        if ($existing) {
            $pdo->prepare(
                'UPDATE truck_documents SET document_number = ?, expiry_date = ?, notes = ?
                 WHERE truck_document_id = ?'
            )->execute([$number, $expiry, $notes, (int)$existing['truck_document_id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO truck_documents (truck_id, document_type, document_number, expiry_date, notes)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$truckId, $type, $number, $expiry, $notes]);
        }
    }
}

/**
 * Record newly stored truck photos (paths under uploads/trucks/, already
 * moved and resized by ajax/trucks_handler.php) as files + documents +
 * document_links. $uploads: view => ['path' => ..., 'original_name',
 * 'mime_type', 'size'].
 */
function saveTruckPhotos(PDO $pdo, int $truckId, array $uploads, int $userId): void {
    foreach ($uploads as $view => $upload) {
        if (!in_array($view, TRUCK_PHOTO_VIEWS, true)) {
            throw new InvalidArgumentException('Invalid truck photo view.');
        }
        $path = (string)$upload['path'];
        if (!preg_match('~^uploads/trucks/([0-9a-f]{32}\.(?:jpg|png|webp))$~', $path, $m)) {
            throw new InvalidArgumentException('Invalid truck photo path.');
        }
        $absolute = dirname(__DIR__) . '/' . $path;
        $pdo->prepare(
            'INSERT INTO files (original_name, stored_name, storage_path, mime_type, file_size, checksum, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            mb_substr((string)$upload['original_name'], 0, 255, 'UTF-8'),
            $m[1],
            $path,
            (string)$upload['mime_type'],
            is_file($absolute) ? (int)filesize($absolute) : (int)$upload['size'],
            is_file($absolute) ? hash_file('sha256', $absolute) : null,
            $userId,
        ]);
        $fileId = (int)$pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO documents (file_id, document_category, description, visibility_scope, created_by)
             VALUES (?, ?, ?, 'all', ?)"
        )->execute([$fileId, TRUCK_PHOTO_CATEGORY, $view, $userId]);
        $documentId = (int)$pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO document_links (document_id, entity_type, entity_id) VALUES (?, 'truck', ?)"
        )->execute([$documentId, $truckId]);
    }
}
