<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/soft_delete.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/document_storage.php';
require_once __DIR__ . '/../includes/upload_validation.php';
require_once __DIR__ . '/../includes/idempotency.php';

header('Content-Type: application/json');

requireLogin();
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Upload document ───────────────────────────────────────────────────────────
if ($action === 'upload') {
    requirePermission('documents.upload');

    if (empty($_FILES['file'])) jsonFail('No file was uploaded.');

    $docType     = requiredEnum('doc_type', DOCUMENT_TYPES, 'Document type');
    $tripId      = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT) ?: null;
    $description = optionalString('description');
    $expiryDate = optionalString('expiry_date', null, 10);
    if ($expiryDate !== null) {
        $parsedExpiry = DateTime::createFromFormat('Y-m-d', $expiryDate);
        if (!$parsedExpiry || $parsedExpiry->format('Y-m-d') !== $expiryDate) {
            jsonFail('Expiry date must be a valid date.');
        }
    }

    $file = $_FILES['file'];
    try {
        $upload = inspectDocumentUpload($file);
    } catch (InvalidArgumentException $e) {
        jsonFail($e->getMessage());
    }
    $origName = $upload['original_name'];
    $tmpPath  = $file['tmp_name'];
    $fileSize = $upload['size'];
    $mimeType = $upload['mime_type'];

    try {
        $uploadDir = documentStorageDirectory() . DIRECTORY_SEPARATOR;
    } catch (Throwable $e) {
        error_log('document_handler/storage: ' . $e->getMessage());
        jsonFail('Document storage is unavailable. Please contact an administrator.', 500);
    }

    // UUID-based stored filename to prevent collisions and enumeration
    $ext        = $upload['extension'];
    $storedName = sprintf('%s.%s', bin2hex(random_bytes(16)), $ext);
    $destPath   = $uploadDir . $storedName;
    $filePath   = documentStorageReference($storedName);
    $requestKey = requestIdempotencyKey();
    if ($requestKey === null) {
        jsonFail('Missing or invalid request idempotency key.', 400);
    }

    if (!move_uploaded_file($tmpPath, $destPath)) {
        error_log('document_handler: move_uploaded_file failed for ' . $origName);
        jsonFail('Failed to save file. Please try again.');
    }

    $pdo->beginTransaction();
    try {
        if (!claimIdempotencyKey($pdo, 'document.upload', $requestKey)) {
            $pdo->rollBack();
            if (is_file($destPath)) unlink($destPath);
            jsonFail('This document upload has already been processed.', 409);
        }
        // Requires db/document_expiry_migration.sql to have been applied — see
        // instructions/instructions.md, which now runs every db/*_migration.sql
        // file as a required setup step rather than a hand-picked subset.
        $stmt = $pdo->prepare("INSERT INTO documents (uploaded_by, trip_id, doc_type, file_name, stored_name, file_path, file_size, mime_type, description, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([currentUserId(), $tripId, $docType, $origName, $storedName, $filePath, $fileSize, $mimeType, $description, $expiryDate]);
        $newId = (int)$pdo->lastInsertId();
        if ($tripId !== null) {
            attachDocumentToEntity(
                $pdo,
                $newId,
                'trip',
                $tripId,
                $docType,
                currentUserId(),
                $description
            );
        }

        auditLog('UPLOAD_DOCUMENT', 'documents', $newId, null, [
            'file_name' => $origName,
            'doc_type'  => $docType,
            'file_size' => $fileSize,
            'trip_id'   => $tripId,
        ]);
        $pdo->commit();

        jsonOk(['id' => $newId], 'Document uploaded successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Clean up orphaned file if DB insert fails
        if (file_exists($destPath)) unlink($destPath);
        error_log('document_handler/upload: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Delete document ───────────────────────────────────────────────────────────
if ($action === 'delete') {
    requirePermission('documents.manage');

    $docId = requiredInt('document_id', 'Document ID', 1);

    $doc = findOrFail($pdo, 'documents', 'document_id', $docId, 'Document not found.');

    // Check not referenced in billing_documents
    $billingRef = $pdo->prepare("SELECT billing_id FROM billing_documents WHERE document_id = ? LIMIT 1");
    $billingRef->execute([$docId]);
    if ($billingRef->fetch()) {
        jsonFail('This document is linked to a billing record and cannot be deleted.');
    }

    $deletedByName = $_SESSION['full_name'] ?? 'Unknown user';

    $archived = archiveAndDelete($pdo, 'documents', 'document_id', $docId, currentUserId(), $deletedByName);

    if (!$archived) {
        jsonFail('Document not found or could not be deleted.', 404);
    }

    // The physical file stays in configured storage so a recycle-bin restore
    // can recover the archived document row.

    auditLog('DELETE_DOCUMENT', 'documents', $docId, ['file_name' => $doc['file_name']], null);

    jsonOk([], 'Document deleted. It can be restored from the Recycle Bin if needed.');
}

// ── Unknown action ────────────────────────────────────────────────────────────
jsonFail('Unknown action.');
