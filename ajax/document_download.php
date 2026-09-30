<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/document_storage.php';
require_once __DIR__ . '/../includes/audit.php';

requirePermission('documents.download');

$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$documentId) {
    http_response_code(400);
    exit('Invalid document.');
}

$scopes = currentUserHasAnyPermission(['legacy.role.1']) ? null : [];
if ($scopes !== null) {
    if (currentUserHasAnyPermission(['legacy.role.2'])) $scopes[] = 'operations';
    if (currentUserHasAnyPermission(['legacy.role.3'])) $scopes[] = 'maintenance';
    if (currentUserHasAnyPermission(['legacy.role.4'])) $scopes[] = 'accounting';
}

$sql = 'SELECT file_name, stored_name, file_path FROM documents WHERE document_id = ?';
$params = [$documentId];
if ($scopes !== null) {
    if ($scopes) {
        $placeholders = implode(',', array_fill(0, count($scopes), '?'));
        $sql .= " AND (visibility_scope = 'all' OR visibility_scope IN ($placeholders))";
        $params = array_merge($params, $scopes);
    } else {
        $sql .= " AND visibility_scope = 'all'";
    }
}
$stmt = getDBConnection()->prepare($sql);
$stmt->execute($params);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

$path = documentStorageFile($document['stored_name'], $document['file_path']);
if (!is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

auditLog('DOWNLOAD', 'documents', $documentId, null, ['file_name' => $document['file_name']]);
$downloadName = str_replace(["\r", "\n"], '_', $document['file_name']);
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, "\"\\") . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
