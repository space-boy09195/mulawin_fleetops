<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_MAINTENANCE, ROLE_ACCOUNTING]);

$pdo    = getDBConnection();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Upload document ───────────────────────────────────────────────────────────
if ($action === 'upload') {

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errMsg = match($_FILES['file']['error'] ?? -1) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum allowed size.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default            => 'Upload failed. Please try again.',
        };
        echo json_encode(['success' => false, 'message' => $errMsg]);
        exit;
    }

    $docType    = trim($_POST['doc_type']    ?? '');
    $tripId     = (int)($_POST['trip_id']   ?? 0) ?: null;
    $description = trim($_POST['description'] ?? '') ?: null;

    $allowedTypes = [
        'OR/CR', 'Delivery Receipt', 'Waybill',
        'Maintenance Record', 'Billing Record',
        'Company Document', 'Other',
    ];

    if (!$docType || !in_array($docType, $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid document type.']);
        exit;
    }

    $file     = $_FILES['file'];
    $origName = basename($file['name']);
    $tmpPath  = $file['tmp_name'];
    $fileSize = (int)$file['size'];

    // Max 10 MB
    if ($fileSize > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File exceeds the 10 MB limit.']);
        exit;
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
        echo json_encode(['success' => false, 'message' => 'File type not allowed. Upload PDF, JPG, PNG, DOCX, or XLSX.']);
        exit;
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
        echo json_encode(['success' => false, 'message' => 'Failed to save file. Please try again.']);
        exit;
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

        echo json_encode(['success' => true, 'message' => 'Document uploaded successfully.', 'id' => $newId]);
    } catch (PDOException $e) {
        // Clean up orphaned file if DB insert fails
        if (file_exists($destPath)) unlink($destPath);
        error_log('document_handler/upload: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Delete document ───────────────────────────────────────────────────────────
if ($action === 'delete') {

    // Only Head Management and Accounting can delete
    if (!in_array(currentRoleId(), [ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING])) {
        echo json_encode(['success' => false, 'message' => 'You are not authorised to delete documents.']);
        exit;
    }

    $docId = (int)($_POST['document_id'] ?? 0);

    if (!$docId) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT document_id, stored_name, file_name FROM documents WHERE document_id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
        exit;
    }

    // Check not referenced in billing_documents
    $billingRef = $pdo->prepare("SELECT billing_id FROM billing_documents WHERE document_id = ? LIMIT 1");
    $billingRef->execute([$docId]);
    if ($billingRef->fetch()) {
        echo json_encode(['success' => false, 'message' => 'This document is linked to a billing record and cannot be deleted.']);
        exit;
    }

    try {
        $pdo->prepare("DELETE FROM documents WHERE document_id = ?")->execute([$docId]);

        // Remove physical file
        $physicalPath = dirname(__DIR__) . '/uploads/' . $doc['stored_name'];
        if (file_exists($physicalPath)) {
            unlink($physicalPath);
        }

        auditLog('DELETE_DOCUMENT', 'documents', $docId, ['file_name' => $doc['file_name']], null);

        echo json_encode(['success' => true, 'message' => 'Document deleted successfully.']);
    } catch (PDOException $e) {
        error_log('document_handler/delete: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);