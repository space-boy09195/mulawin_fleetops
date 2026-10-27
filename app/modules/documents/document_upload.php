<?php
// ============================================================
// includes/document_upload.php
// Stores an uploaded file in document storage and records it in the target
// tables: files (the physical file) -> documents (category, description,
// expiry, visibility) -> document_links (records it belongs to).
// ============================================================

require_once __DIR__ . '/../../../config/enums.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/document_storage.php';
require_once __DIR__ . '/upload_validation.php';

const DOCUMENT_VISIBILITY_SCOPES = ['all', 'operations', 'maintenance', 'accounting'];

/**
 * Insert files + documents rows for a file already moved into storage.
 * Runs on the caller's connection; the caller owns the transaction.
 *
 * @param array $links list of [entity_type, entity_id]
 */
function recordStoredDocument(
    PDO $pdo,
    array $upload,
    string $storedName,
    string $storedPath,
    string $docType,
    ?string $description,
    string $visibilityScope,
    int $uploadedBy,
    ?string $expiryDate = null,
    array $links = []
): int {
    if (!in_array($docType, DOCUMENT_TYPES, true)) {
        throw new InvalidArgumentException('Invalid document type.');
    }
    if (!in_array($visibilityScope, DOCUMENT_VISIBILITY_SCOPES, true)) {
        throw new InvalidArgumentException('Invalid document visibility.');
    }
    $checksum = is_file($storedPath) ? hash_file('sha256', $storedPath) : null;

    $pdo->prepare(
        'INSERT INTO files
            (original_name, stored_name, storage_path, mime_type, file_size, checksum, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        mb_substr($upload['original_name'], 0, 255, 'UTF-8'),
        $storedName,
        documentStorageReference($storedName),
        $upload['mime_type'],
        (int)$upload['size'],
        $checksum ?: null,
        $uploadedBy,
    ]);
    $fileId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO documents
            (file_id, document_category, description, expiry_date, visibility_scope, created_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$fileId, $docType, $description, $expiryDate, $visibilityScope, $uploadedBy]);
    $documentId = (int)$pdo->lastInsertId();

    foreach ($links as [$entityType, $entityId]) {
        linkDocumentToEntity($pdo, $documentId, (string)$entityType, (int)$entityId);
    }
    return $documentId;
}

function storeUploadedDocument(
    PDO $pdo,
    array $file,
    string $docType,
    ?int $tripId,
    ?string $description,
    string $visibilityScope,
    int $uploadedBy,
    ?string $expiryDate = null
): int {
    $upload = inspectDocumentUpload($file);
    if (!in_array($docType, DOCUMENT_TYPES, true)) {
        throw new InvalidArgumentException('Invalid document type.');
    }
    if (!in_array($visibilityScope, DOCUMENT_VISIBILITY_SCOPES, true)) {
        throw new InvalidArgumentException('Invalid document visibility.');
    }

    $uploadDir = documentStorageDirectory() . DIRECTORY_SEPARATOR;
    $extension = $upload['extension'];
    $storedName = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
    $destination = $uploadDir . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Failed to save the uploaded file.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $documentId = recordStoredDocument(
            $pdo,
            $upload,
            $storedName,
            $destination,
            $docType,
            $description,
            $visibilityScope,
            $uploadedBy,
            $expiryDate,
            $tripId !== null ? [['trip', $tripId]] : []
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_file($destination)) {
            unlink($destination);
        }
        throw $e;
    }

    return $documentId;
}

/**
 * Visibility scopes the signed-in user may see, or null for every scope.
 * Unchanged rule: Admin pages (legacy.role.1) see all; the legacy page
 * grants add operations / maintenance / accounting; everyone with
 * documents.view sees 'all'. The current roles hold no legacy grants, so
 * each scope also follows the permission of the role that needs it:
 *   operations   Operations Head (operations.dashboard.view) and Billing
 *                and Collection (billing.manage: delivery receipts and
 *                waybills attached at trip completion support billing)
 *   maintenance  maintenance.view (inspection photos, maintenance files)
 *   accounting   finance.view or billing.manage
 */
function documentVisibilityScopesForCurrentUser(): ?array {
    if (currentUserHasAnyPermission(['legacy.role.1'])) {
        return null;
    }
    $scopes = ['all'];
    if (currentUserHasAnyPermission(['legacy.role.2', 'operations.dashboard.view', 'billing.manage'])) $scopes[] = 'operations';
    if (currentUserHasAnyPermission(['legacy.role.3', 'maintenance.view'])) $scopes[] = 'maintenance';
    if (currentUserHasAnyPermission(['legacy.role.4', 'finance.view', 'billing.manage'])) $scopes[] = 'accounting';
    return $scopes;
}

/**
 * SQL condition (with "AND") restricting documents alias $alias to the
 * given scopes; appends the bound values to $params. NULL visibility is
 * treated as 'all' (target column is nullable).
 */
function documentVisibilitySql(?array $scopes, string $alias, array &$params): string {
    if ($scopes === null) {
        return '';
    }
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid table alias.');
    }
    $placeholders = implode(',', array_fill(0, count($scopes), '?'));
    foreach ($scopes as $scope) {
        $params[] = $scope;
    }
    return " AND COALESCE({$alias}.visibility_scope, 'all') IN ($placeholders)";
}
