<?php
// ============================================================
// includes/validate.php
// Centralized input validation + JSON response helpers.
// Include after session.php and before reading $_POST in any
// AJAX handler, so every endpoint validates and fails the
// same way instead of re-inventing checks per file.
// ============================================================

/**
 * Send a JSON failure response and stop execution.
 */
function jsonFail(string $message, int $httpCode = 400): never {
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

/**
 * Send a JSON success response and stop execution.
 * Extra keys (e.g. ['id' => $newId]) are merged in.
 */
function jsonOk(array $extra = [], string $message = ''): never {
    $payload = ['success' => true];
    if ($message !== '') {
        $payload['message'] = $message;
    }
    echo json_encode(array_merge($payload, $extra));
    exit;
}

/**
 * Call at the top of a handler to reject non-POST requests.
 */
function requirePostMethod(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonFail('Invalid request method.', 405);
    }
}

function fieldLabel(string $key, ?string $label): string {
    return $label ?? ucfirst(str_replace('_', ' ', $key));
}

/**
 * Required, trimmed string from $_POST. Fails the request if
 * missing/blank or longer than $maxLength.
 */
function requiredString(string $key, ?string $label = null, int $maxLength = 255): string {
    $value = trim($_POST[$key] ?? '');
    if ($value === '') {
        jsonFail(fieldLabel($key, $label) . ' is required.');
    }
    if (mb_strlen($value) > $maxLength) {
        jsonFail(fieldLabel($key, $label) . " must be $maxLength characters or fewer.");
    }
    return $value;
}

/**
 * Optional, trimmed string from $_POST. Returns $default when blank.
 */
function optionalString(string $key, ?string $default = null, int $maxLength = 255): ?string {
    $value = trim($_POST[$key] ?? '');
    if ($value === '') {
        return $default;
    }
    if (mb_strlen($value) > $maxLength) {
        jsonFail(fieldLabel($key, null) . " must be $maxLength characters or fewer.");
    }
    return $value;
}

/**
 * Required integer from $_POST, with optional min/max bounds.
 */
function requiredInt(string $key, ?string $label = null, ?int $min = null, ?int $max = null): int {
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        jsonFail(fieldLabel($key, $label) . ' must be a valid whole number.');
    }
    if ($min !== null && $value < $min) {
        jsonFail(fieldLabel($key, $label) . " must be at least $min.");
    }
    if ($max !== null && $value > $max) {
        jsonFail(fieldLabel($key, $label) . " must be at most $max.");
    }
    return $value;
}

/**
 * Optional float from $_POST (e.g. capacity_tons). Returns
 * $default when blank; fails if present but not numeric.
 */
function optionalFloat(string $key, ?float $default = null): ?float {
    $raw = $_POST[$key] ?? '';
    if ($raw === '' || $raw === null) {
        return $default;
    }
    if (!is_numeric($raw)) {
        jsonFail(fieldLabel($key, null) . ' must be a number.');
    }
    return (float) $raw;
}

/**
 * Required value that must be one of a fixed set of allowed
 * strings (e.g. status, fuel_type). Pair with config/enums.php
 * so the allowed list lives in one place, not copy-pasted per file.
 */
function requiredEnum(string $key, array $allowed, ?string $label = null): string {
    $value = trim($_POST[$key] ?? '');
    if (!in_array($value, $allowed, true)) {
        jsonFail(fieldLabel($key, $label) . ' must be one of: ' . implode(', ', $allowed) . '.');
    }
    return $value;
}

/**
 * Required numeric amount from $_POST that must be strictly
 * greater than zero (e.g. billing amount, payroll amount).
 */
function requiredPositiveFloat(string $key, ?string $label = null): float {
    $raw = $_POST[$key] ?? '';
    if ($raw === '' || $raw === null || !is_numeric($raw) || (float)$raw <= 0) {
        jsonFail(fieldLabel($key, $label) . ' must be greater than zero.');
    }
    return (float) $raw;
}

/**
 * Required date string (Y-m-d) from $_POST. Uses the same
 * isValidDate()/isPassedDate() helpers already defined in
 * includes/session.php, so "what counts as a valid/past date"
 * stays defined in exactly one place.
 * Pass $disallowPast = true to also reject dates before today
 * (e.g. new records), or false to allow any valid date (e.g.
 * editing/backdating existing records).
 */
function requiredDate(string $key, ?string $label = null, bool $disallowPast = false): string {
    $value = trim($_POST[$key] ?? '');
    if ($value === '' || !isValidDate($value)) {
        jsonFail(fieldLabel($key, $label) . ' must be a valid date.');
    }
    if ($disallowPast && isPassedDate($value)) {
        jsonFail(fieldLabel($key, $label) . ' cannot be a passed date.');
    }
    return $value;
}