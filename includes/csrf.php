<?php
// ============================================================
// includes/csrf.php
// CSRF token generation and validation
// ============================================================

// ---- Generate a token (call once, store in session) --------
function generateCsrfToken(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

// ---- Emit a hidden input field for forms -------------------
function csrfInput(): string {
    $token = generateCsrfToken();
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . htmlspecialchars($token) . '">';
}

// ---- Validate incoming token (call in POST handlers) -------
function validateCsrfToken(): bool {
    $submitted = $_POST[CSRF_TOKEN_NAME] ?? '';
    $stored    = $_SESSION[CSRF_TOKEN_NAME] ?? '';
    return !empty($stored) && hash_equals($stored, $submitted);
}

// ---- Abort with 403 if CSRF check fails --------------------
function enforceCsrf(): void {
    if (!validateCsrfToken()) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Invalid CSRF token.']));
    }
}
