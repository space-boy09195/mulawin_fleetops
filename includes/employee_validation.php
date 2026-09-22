<?php
// ============================================================
// includes/employee_validation.php
// Pure employee-data validation. No superglobal reads except
// duplicateEmployeeExists()'s $pdo parameter (explicit, not a
// global) — safe to require directly in a unit test.
//
// Previously defined inline in ajax/users_handler.php, which runs
// requireRole()/requirePostMethod()/enforceCsrf() as soon as it's
// loaded — pulling that whole script in just to test these two
// functions isn't possible without also triggering that auth flow.
// users_handler.php now requires this file instead of defining
// these itself; behavior is unchanged.
// ============================================================

require_once __DIR__ . '/date_helpers.php';

function validateEmpFields(array $f, bool $allowPassedDates = false): ?string {
    if (!$f['employee_code']) return 'Employee code is required.';
    if (!$f['full_name'])     return 'Full name is required.';
    if (!$f['position'])      return 'Position is required.';

    $isDriver = strcasecmp(trim($f['position']), 'Driver') === 0;

    // License number and expiry must travel together regardless of position.
    $hasLic = $f['license_number'] || $f['license_expiry'];
    if ($hasLic && !$f['license_number']) return 'License number is required with expiry.';
    if ($hasLic && !$f['license_expiry']) return 'License expiry is required with license number.';

    // Drivers must have a complete license record on file.
    if ($isDriver) {
        if (!$f['license_number']) return 'License number is required for drivers.';
        if (!$f['license_expiry']) return 'License expiry is required for drivers.';
        if (!$f['license_type'])   return 'License type is required for drivers.';
        if (!$f['date_hired'])     return 'Date hired is required for drivers.';
    }

    // Philippine LTO license number format: X00-00-000000 (e.g. N01-12-123456).
    if ($f['license_number'] && !preg_match('/^[A-Za-z]\d{2}-\d{2}-\d{6}$/', $f['license_number']))
        return 'License number must be in the format X00-00-000000 (e.g. N01-12-123456).';

    if ($f['license_expiry'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['license_expiry']))
        return 'Invalid license expiry date.';
    if ($f['date_hired'] && !isValidDate($f['date_hired']))
        return 'Invalid date hired.';
    if ($f['date_hired'] && $f['date_hired'] > date('Y-m-d'))
        return 'Hire date cannot be in the future.';
    if (!$allowPassedDates && $f['license_expiry'] && isPassedDate($f['license_expiry']))
        return 'New employees cannot use a passed license expiry date.';
    return null;
}

function duplicateEmployeeExists(PDO $pdo, array $f, ?int $selfId = null): bool {
    $fullName = preg_replace('/\s+/', ' ', trim((string)($f['full_name'] ?? '')));
    $contact  = preg_replace('/\s+/', ' ', trim((string)($f['contact_number'] ?? '')));

    if ($fullName === '' || $contact === '') {
        return false;
    }

    $sql = "SELECT employee_id FROM employees WHERE full_name = ? AND contact_number = ?";
    $params = [$fullName, $contact];
    if ($selfId !== null) {
        $sql .= " AND employee_id != ?";
        $params[] = $selfId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}
