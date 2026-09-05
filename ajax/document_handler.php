<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/soft_delete.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_MAINTENANCE, ROLE_ACCOUNTING]);
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Upload document ───────────────────────────────────────────────────────────
if ($action === 'upload') {

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errMsg = match($_FILES['file']['error'] ?? -1) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum allowed size.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default            => 'Upload failed. Please try again.',
        };
        jsonFail($errMsg);
    }

    $docType     = requiredEnum('doc_type', DOCUMENT_TYPES, 'Document type');
    $tripId      = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT) ?: null;
    $description = optionalString('description');

    $file     = $_FILES['file'];
    $origName = basename($file['name']);
    $tmpPath  = $file['tmp_name'];
    $fileSize = (int)$file['size'];

    // Max 10 MB
    if ($fileSize > 10 * 1024 * 1024) {
        jsonFail('File exceeds the 10 MB limit.');
    }

    // MIME validation via finfo
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpPath);

    $allowedMimes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    if (!in_array($mimeType, $allowedMimes)) {
        jsonFail('File type not allowed. Upload PDF, JPG, PNG, DOCX, or XLSX.');
    }

    // Build upload directory: /uploads/ relative to project root
    $uploadDir = dirname(__DIR__) . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // UUID-based stored filename to prevent collisions and enumeration
    $ext        = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $storedName = sprintf('%s.%s', bin2hex(random_bytes(16)), $ext);
    $destPath   = $uploadDir . $storedName;
    $filePath   = 'uploads/' . $storedName; // relative path stored in DB

    if (!move_uploaded_file($tmpPath, $destPath)) {
        error_log('document_handler: move_uploaded_file failed for ' . $origName);
        jsonFail('Failed to save file. Please try again.');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO documents
                (uploaded_by, trip_id, doc_type, file_name, stored_name,
                 file_path, file_size, mime_type, description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            currentUserId(), $tripId, $docType,
            $origName, $storedName, $filePath,
            $fileSize, $mimeType, $description,
        ]);
        $newId = (int)$pdo->lastInsertId();

        auditLog('UPLOAD_DOCUMENT', 'documents', $newId, null, [
            'file_name' => $origName,
            'doc_type'  => $docType,
            'file_size' => $fileSize,
            'trip_id'   => $tripId,
        ]);

        jsonOk(['id' => $newId], 'Document uploaded successfully.');
    } catch (PDOException $e) {
        // Clean up orphaned file if DB insert fails
        if (file_exists($destPath)) unlink($destPath);
        error_log('document_handler/upload: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Delete document ───────────────────────────────────────────────────────────
if ($action === 'delete') {

    // Only Head Management and Accounting can delete
    if (!in_array(currentRoleId(), [ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING], true)) {
        jsonFail('You are not authorised to delete documents.', 403);
    }

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

    // Note: the physical file on disk is intentionally NOT removed here.
    // It stays in /uploads/ so a restore from the Recycle Bin still has a
    // file to point to. It's only removed if the archive entry is later
    // permanently deleted (see recycle_bin_handler.php).

    auditLog('DELETE_DOCUMENT', 'documents', $docId, ['file_name' => $doc['file_name']], null);

    jsonOk([], 'Document deleted. It can be restored from the Recycle Bin if needed.');
}

// ── Unknown action ────────────────────────────────────────────────────────────
jsonFail('Unknown action.');
