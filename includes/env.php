<?php
// ============================================================
// includes/env.php
// Load environment variables from .env file
// ============================================================

function loadEnv($filePath = null): void {
    if ($filePath === null) {
        $filePath = __DIR__ . '/../.env';
    }

    if (!file_exists($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE
        if (strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Skip if key is empty
        if (empty($key)) {
            continue;
        }

        // Remove quotes if present
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        // Set in $_ENV and putenv
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

/**
 * Get an environment variable, with optional default
 */
function env(string $key, mixed $default = null): mixed {
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}
