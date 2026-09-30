<?php

require_once __DIR__ . '/../config/app.php';

function documentStorageDirectory(): string {
    $configuredPath = (string)DOCUMENT_STORAGE_PATH;
    if ($configuredPath === '') {
        throw new RuntimeException('Document storage path is not configured.');
    }

    $path = str_starts_with($configuredPath, DIRECTORY_SEPARATOR)
        || preg_match('/^[A-Za-z]:[\\\\\\/]/', $configuredPath)
        ? $configuredPath
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . $configuredPath;
    $path = rtrim($path, '\\/');
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Document storage directory could not be created.');
    }
    $resolved = realpath($path);
    if ($resolved === false || !is_writable($resolved)) {
        throw new RuntimeException('Document storage directory is not writable.');
    }
    return $resolved;
}

function documentStorageFile(string $storedName, ?string $legacyReference = null): string {
    if ($storedName === '' || basename($storedName) !== $storedName) {
        throw new InvalidArgumentException('Invalid stored document name.');
    }

    $configuredFile = null;
    try {
        $configuredFile = documentStorageDirectory() . DIRECTORY_SEPARATOR . $storedName;
        if (is_file($configuredFile)) {
            return $configuredFile;
        }
    } catch (RuntimeException $e) {
        if ($legacyReference === null || !str_starts_with($legacyReference, 'uploads/')) {
            throw $e;
        }
    }

    if ($legacyReference !== null && str_starts_with($legacyReference, 'uploads/')) {
        $legacyFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $storedName;
        if (is_file($legacyFile)) {
            return $legacyFile;
        }
    }
    if ($configuredFile !== null) {
        return $configuredFile;
    }
    throw new RuntimeException('Document storage path is unavailable.');
}

function documentStorageReference(string $storedName): string {
    if ($storedName === '' || basename($storedName) !== $storedName) {
        throw new InvalidArgumentException('Invalid stored document name.');
    }
    return 'storage://documents/' . $storedName;
}
