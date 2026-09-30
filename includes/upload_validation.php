<?php

function inspectDocumentUpload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The uploaded file could not be read.');
    }
    if (empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
        throw new InvalidArgumentException('The uploaded file is not a valid HTTP upload.');
    }
    if ((int)($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('File exceeds the 10 MB limit.');
    }

    $originalName = basename((string)($file['name'] ?? ''));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $allowed = [
        'application/pdf' => ['pdf' => 'pdf'],
        'image/jpeg' => ['jpg' => 'jpg', 'jpeg' => 'jpg'],
        'image/png' => ['png' => 'png'],
        'application/msword' => ['doc' => 'doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx' => 'docx'],
        'application/vnd.ms-excel' => ['xls' => 'xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx' => 'xlsx'],
    ];
    if (!isset($allowed[$mimeType]) || !isset($allowed[$mimeType][$extension])) {
        throw new InvalidArgumentException('File type and extension do not match an allowed document format.');
    }

    return [
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'extension' => $allowed[$mimeType][$extension],
        'size' => (int)$file['size'],
    ];
}
