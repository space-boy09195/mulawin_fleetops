<?php

// Server-side upload checks shared by every upload entry point. Nothing here
// trusts the browser: the filename only supplies an extension that must be on
// the purpose's allowlist, the content type is detected from the bytes, and
// the two must agree. Callers move the file only after these checks pass and
// always store it under a generated name.

const UPLOAD_MAX_BYTES = 10 * 1024 * 1024;

// Refused as any inner part of a filename (e.g. "invoice.php.pdf"), so a server
// that picks a handler from any extension segment can never run the file. The
// last extension is already limited by each purpose's allowlist.
const UPLOAD_BLOCKED_INNER_EXTENSIONS = [
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps',
    'cgi', 'pl', 'py', 'rb', 'sh', 'asp', 'aspx', 'jsp', 'jspx', 'cfm',
    'exe', 'dll', 'bat', 'cmd', 'msi', 'vbs', 'js', 'jar', 'ps1', 'wsf', 'scr',
    'htm', 'html', 'xhtml', 'shtml', 'svg', 'htaccess', 'hta',
];

// Extension => [allowed detected MIME types, stored extension, image check].
const DOCUMENT_UPLOAD_TYPES = [
    'pdf' => [['application/pdf'], 'pdf', false],
    'jpg' => [['image/jpeg'], 'jpg', true],
    'jpeg' => [['image/jpeg'], 'jpg', true],
    'png' => [['image/png'], 'png', true],
    'doc' => [['application/msword'], 'doc', false],
    'docx' => [['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'docx', false],
    'xls' => [['application/vnd.ms-excel'], 'xls', false],
    'xlsx' => [['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], 'xlsx', false],
];

const TRUCK_IMAGE_UPLOAD_TYPES = [
    'jpg' => [['image/jpeg'], 'jpg', true],
    'jpeg' => [['image/jpeg'], 'jpg', true],
    'png' => [['image/png'], 'png', true],
    'webp' => [['image/webp'], 'webp', true],
];

// Validates one uploaded file against an extension allowlist and size limit.
// Throws InvalidArgumentException with a message that is safe to show the user
// (no server paths). Returns the cleaned original name, detected MIME type,
// the extension to store under, and the real size on disk.
function inspectUpload(array $file, array $allowedTypes, int $maxBytes, string $label): array {
    $limit = intdiv($maxBytes, 1024 * 1024) . ' MB';
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!is_int($error)) {
        throw new InvalidArgumentException('Upload one ' . $label . ' at a time.');
    }
    switch ($error) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            throw new InvalidArgumentException('No ' . $label . ' was uploaded.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new InvalidArgumentException(ucfirst($label) . ' exceeds the ' . $limit . ' limit.');
        case UPLOAD_ERR_PARTIAL:
            throw new InvalidArgumentException(ucfirst($label) . ' was only partly uploaded. Please try again.');
        default:
            error_log('upload_validation: PHP upload error code ' . $error);
            throw new InvalidArgumentException(ucfirst($label) . ' could not be received by the server. Please try again.');
    }

    $tmpName = $file['tmp_name'] ?? null;
    if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new InvalidArgumentException(ucfirst($label) . ' is not a valid HTTP upload.');
    }
    $size = filesize($tmpName);
    if ($size === false || $size === 0) {
        throw new InvalidArgumentException(ucfirst($label) . ' is empty.');
    }
    if ($size > $maxBytes) {
        throw new InvalidArgumentException(ucfirst($label) . ' exceeds the ' . $limit . ' limit.');
    }

    // Keep only the last path segment, whichever separator the client used.
    $originalName = trim(preg_replace('~^.*[/\\\\]~', '', (string)($file['name'] ?? '')));
    if ($originalName === '' || $originalName === '.' || $originalName === '..'
        || !mb_check_encoding($originalName, 'UTF-8')
        || preg_match('/[\x00-\x1F\x7F]/', $originalName)) {
        throw new InvalidArgumentException(ucfirst($label) . ' has an invalid file name.');
    }
    if (mb_strlen($originalName) > 255) {
        throw new InvalidArgumentException(ucfirst($label) . ' name is too long (255 characters at most).');
    }

    $parts = explode('.', strtolower($originalName));
    $extension = count($parts) > 1 ? array_pop($parts) : '';
    array_shift($parts);
    if (array_intersect($parts, UPLOAD_BLOCKED_INNER_EXTENSIONS)) {
        throw new InvalidArgumentException(ucfirst($label) . ' name has a disallowed extension, such as ".php", before its last extension.');
    }
    if (!isset($allowedTypes[$extension])) {
        $names = array_map('strtoupper', array_unique(array_column($allowedTypes, 1)));
        throw new InvalidArgumentException(ucfirst($label) . ' must be one of these file types: ' . implode(', ', $names) . '.');
    }

    [$mimeTypes, $storedExtension, $isImage] = $allowedTypes[$extension];
    $mimeType = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
    if (!in_array($mimeType, $mimeTypes, true)) {
        throw new InvalidArgumentException('File type and extension do not match an allowed ' . $label . ' format.');
    }
    if ($isImage) {
        $info = @getimagesize($tmpName);
        if ($info === false || ($info['mime'] ?? '') !== $mimeType || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidArgumentException(ucfirst($label) . ' is not a readable image.');
        }
    }

    return [
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'extension' => $storedExtension,
        'size' => $size,
    ];
}

function inspectDocumentUpload(array $file): array {
    return inspectUpload($file, DOCUMENT_UPLOAD_TYPES, UPLOAD_MAX_BYTES, 'file');
}

function inspectTruckImageUpload(array $file): array {
    return inspectUpload($file, TRUCK_IMAGE_UPLOAD_TYPES, UPLOAD_MAX_BYTES, 'truck image');
}
