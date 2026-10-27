<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../app/core/audit.php';
require_once __DIR__ . '/../app/core/soft_delete.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/shared/validate.php';
require_once __DIR__ . '/../app/shared/db_helpers.php';
require_once __DIR__ . '/../app/modules/documents/attachments.php';
require_once __DIR__ . '/../app/modules/documents/document_storage.php';
require_once __DIR__ . '/../app/modules/documents/document_upload.php';
require_once __DIR__ . '/../app/modules/documents/upload_validation.php';
require_once __DIR__ . '/../app/core/idempotency.php';

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
    if ($expiryDate !== null && !isValidDate($expiryDate)) {
        jsonFail('Expiry date must be a valid date.');
    }

    $file = $_FILES['file'];
    try {
        $upload = inspectDocumentUpload($file);
    } catch (InvalidArgumentException $e) {
        jsonFail($e->getMessage());
    }
    $origName = $upload['original_name'];

    try {
        $uploadDir = documentStorageDirectory() . DIRECTORY_SEPARATOR;
    } catch (Throwable $e) {
        error_log('document_handler/storage: ' . $e->getMessage());
        jsonFail('Document storage is unavailable. Please contact an administrator.', 500);
    }

    // Random stored filename to prevent collisions and enumeration
    $ext        = $upload['extension'];
    $storedName = sprintf('%s.%s', bin2hex(random_bytes(16)), $ext);
    $destPath   = $uploadDir . $storedName;
    $requestKey = requestIdempotencyKey();
    if ($requestKey === null) {
        jsonFail('Missing or invalid request idempotency key.', 400);
    }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        error_log('document_handler: move_uploaded_file failed for an upload');
        jsonFail('Failed to save file. Please try again.');
    }

    $pdo->beginTransaction();
    try {
        if (!claimIdempotencyKey($pdo, 'document.upload', $requestKey)) {
            $pdo->rollBack();
            if (is_file($destPath)) unlink($destPath);
            jsonFail('This document upload has already been processed.', 409);
        }
        $newId = recordStoredDocument(
            $pdo,
            $upload,
            $storedName,
            $destPath,
            $docType,
            $description,
            'all',
            currentUserId(),
            $expiryDate,
            $tripId !== null ? [['trip', $tripId]] : []
        );

        auditLog('UPLOAD_DOCUMENT', 'documents', $newId, null, [
            'file_name' => $origName,
            'doc_type'  => $docType,
            'file_size' => $upload['size'],
            'trip_id'   => $tripId,
        ]);
        $pdo->commit();

        jsonOk(['id' => $newId], 'Document uploaded successfully.');
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (file_exists($destPath)) unlink($destPath);
        jsonFail($e->getMessage());
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

    // Same visibility rule as document_download.php. A missing document and
    // one outside the user's scope get the same not-found response.
    $params = [$docId];
    $visibility = documentVisibilitySql(documentVisibilityScopesForCurrentUser(), 'd', $params);
    $docQuery = $pdo->prepare(
        "SELECT d.document_id, f.original_name AS file_name
         FROM documents d
         JOIN files f ON f.file_id = d.file_id
         WHERE d.document_id = ? $visibility"
    );
    $docQuery->execute($params);
    $doc = $docQuery->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        jsonFail('Document not found.', 404);
    }

    // Billing support documents stay with their billing record.
    if (documentHasLink($pdo, $docId, 'billing')) {
        jsonFail('This document is linked to a billing record and cannot be deleted.');
    }

    $deletedByName = $_SESSION['full_name'] ?? 'Unknown user';

    $pdo->beginTransaction();
    try {
        // The physical file and its files row stay, so a recycle-bin restore
        // can recover the archived document and its links.
        $archived = archiveAndDelete($pdo, 'documents', 'document_id', $docId, currentUserId(), $deletedByName, $doc['file_name']);
        if (!$archived) {
            $pdo->rollBack();
            jsonFail('Document not found or could not be deleted.', 404);
        }
        auditLog('DELETE_DOCUMENT', 'documents', $docId, ['file_name' => $doc['file_name']], null);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('document_handler/delete: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }

    jsonOk([], 'Document deleted. It can be restored from the Recycle Bin if needed.');
}

// ── Unknown action ────────────────────────────────────────────────────────────
jsonFail('Unknown action.');
