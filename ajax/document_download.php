<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../app/modules/documents/document_storage.php';
require_once __DIR__ . '/../app/modules/documents/document_upload.php';
require_once __DIR__ . '/../includes/audit.php';

requirePermission('documents.download');

$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$documentId) {
    http_response_code(400);
    exit('Invalid document.');
}

// A missing document and one outside the user's visibility scope get the
// same not-found response.
$params = [$documentId];
$visibility = documentVisibilitySql(documentVisibilityScopesForCurrentUser(), 'd', $params);
$stmt = getDBConnection()->prepare(
    "SELECT f.original_name AS file_name, f.stored_name, f.storage_path, f.mime_type
     FROM documents d
     JOIN files f ON f.file_id = d.file_id
     WHERE d.document_id = ? $visibility"
);
$stmt->execute($params);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

try {
    $path = documentStorageFile($document['stored_name'], $document['storage_path']);
} catch (Throwable $e) {
    error_log('document_download: ' . $e->getMessage());
    http_response_code(404);
    exit('File not found.');
}
if (!is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

auditLog('DOWNLOAD', 'documents', $documentId, null, ['file_name' => $document['file_name']]);
$downloadName = str_replace(["\r", "\n", '"', '\\'], '_', $document['file_name']);
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($document['file_name']));
header('Content-Length: ' . filesize($path));
readfile($path);
