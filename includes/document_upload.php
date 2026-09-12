<?php

require_once __DIR__ . '/../config/enums.php';

function storeUploadedDocument(
    PDO $pdo,
    array $file,
    string $docType,
    ?int $tripId,
    ?string $description,
    string $visibilityScope,
    int $uploadedBy
): int {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The uploaded file could not be read.');
    }

    if ((int)$file['size'] > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('File exceeds the 10 MB limit.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowedMimes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new InvalidArgumentException('File type not allowed. Upload PDF, JPG, PNG, DOCX, or XLSX.');
    }
    if (!in_array($docType, DOCUMENT_TYPES, true)) {
        throw new InvalidArgumentException('Invalid document type.');
    }
    if (!in_array($visibilityScope, ['all', 'operations', 'maintenance', 'accounting'], true)) {
        throw new InvalidArgumentException('Invalid document visibility.');
    }

    $uploadDir = dirname(__DIR__) . '/uploads/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Upload directory could not be created.');
    }

    $originalName = basename((string)$file['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $storedName = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
    $destination = $uploadDir . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Failed to save the uploaded file.');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO documents
                (uploaded_by, trip_id, doc_type, file_name, stored_name, file_path,
                 file_size, mime_type, description, visibility_scope)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $uploadedBy,
            $tripId,
            $docType,
            $originalName,
            $storedName,
            'uploads/' . $storedName,
            (int)$file['size'],
            $mimeType,
            $description,
            $visibilityScope,
        ]);
    } catch (Throwable $e) {
        if (is_file($destination)) {
            unlink($destination);
        }
        throw $e;
    }

    return (int)$pdo->lastInsertId();
}
