<?php
// ============================================================
// includes/date_helpers.php
// Pure date-validation helpers. No side effects, no superglobal
// reads, no DB — safe to require directly in a unit test.
//
// Previously defined inline in includes/session.php, which starts
// the session and hardens cookies as soon as it's loaded. Moving
// these out means testing them doesn't require booting a session.
// session.php still requires this file, so every existing page
// that relied on these functions being available continues to
// work exactly as before.
// ============================================================

function isValidDate(string $date): bool {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function isPassedDate(string $date): bool {
    return isValidDate($date) && $date < date('Y-m-d');
}
