<?php

require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/document_storage.php';
require_once __DIR__ . '/upload_validation.php';

function storeUploadedDocument(
    PDO $pdo,
    array $file,
    string $docType,
    ?int $tripId,
    ?string $description,
    string $visibilityScope,
    int $uploadedBy
): int {
    $upload = inspectDocumentUpload($file);
    if (!in_array($docType, DOCUMENT_TYPES, true)) {
        throw new InvalidArgumentException('Invalid document type.');
    }
    if (!in_array($visibilityScope, ['all', 'operations', 'maintenance', 'accounting'], true)) {
        throw new InvalidArgumentException('Invalid document visibility.');
    }

    $uploadDir = documentStorageDirectory() . DIRECTORY_SEPARATOR;

    $originalName = $upload['original_name'];
    $mimeType = $upload['mime_type'];
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
            documentStorageReference($storedName),
            $upload['size'],
            $mimeType,
            $description,
            $visibilityScope,
        ]);
        $documentId = (int)$pdo->lastInsertId();
        if ($tripId !== null) {
            attachDocumentToEntity(
                $pdo,
                $documentId,
                'trip',
                $tripId,
                $docType,
                $uploadedBy,
                $description
            );
        }
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
