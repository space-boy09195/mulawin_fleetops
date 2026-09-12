<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db_helpers.php';

requireLogin();

$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$documentId) {
    http_response_code(400);
    exit('Invalid document.');
}

$scopes = match (currentRoleId()) {
    ROLE_HEAD_MANAGEMENT => null,
    ROLE_DISPATCHER      => ['operations'],
    ROLE_ACCOUNTING      => ['operations', 'accounting'],
    ROLE_MAINTENANCE     => ['maintenance'],
    default              => '',
};

$sql = 'SELECT file_name, stored_name FROM documents WHERE document_id = ?';
$params = [$documentId];
if ($scopes !== null) {
    $placeholders = implode(',', array_fill(0, count($scopes), '?'));
    $sql .= " AND (visibility_scope = 'all' OR visibility_scope IN ($placeholders))";
    $params = array_merge($params, $scopes);
}
$stmt = getDBConnection()->prepare($sql);
$stmt->execute($params);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

$path = dirname(__DIR__) . '/uploads/' . basename($document['stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: inline; filename="' . addcslashes($document['file_name'], "\"\\") . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
