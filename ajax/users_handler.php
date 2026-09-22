<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/employee_validation.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT]);
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ════════════════════════════════════════════════════════════════════════════
// USER ACTIONS
// ════════════════════════════════════════════════════════════════════════════

// ── Add user ──────────────────────────────────────────────────────────────────
if ($action === 'add_user') {

    $fullName = requiredString('full_name', 'Full name', 150);
    $username = requiredString('username', 'Username', 100);
    $email    = requiredString('email', 'Email', 150);
    $roleId   = requiredInt('role_id', 'Role', 1);
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';

    if (!$password) {
        jsonFail('Password is required.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonFail('Invalid email address.');
    }

    if (strlen($password) < 8) {
        jsonFail('Password must be at least 8 characters.');
    }

    if ($password !== $confirm) {
        jsonFail('Passwords do not match.');
    }

    // Check role exists
    findOrFail($pdo, 'roles', 'role_id', $roleId, 'Invalid role selected.');

    // Unique username and email
    if (existsWhere($pdo, 'users', 'username', $username)) {
        jsonFail('Username already taken.');
    }
    if (existsWhere($pdo, 'users', 'email', $email)) {
        jsonFail('Email already in use.');
    }

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (role_id, username, full_name, email, password_hash, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$roleId, $username, $fullName, $email, $hash]);
        $newId = (int)$pdo->lastInsertId();

        auditLog('ADD_USER', 'users', $newId, null, [
            'username'  => $username,
            'full_name' => $fullName,
            'role_id'   => $roleId,
        ]);

        jsonOk(['id' => $newId], 'User created successfully.');
    } catch (PDOException $e) {
        error_log('users_handler/add_user: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Edit user ─────────────────────────────────────────────────────────────────
if ($action === 'edit_user') {

    $userId   = requiredInt('user_id', 'User', 1);
    $fullName = requiredString('full_name', 'Full name', 150);
    $username = requiredString('username', 'Username', 100);
    $email    = requiredString('email', 'Email', 150);
    $roleId   = requiredInt('role_id', 'Role', 1);
    $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonFail('Invalid email address.');
    }

    // Prevent deactivating own account
    if ($userId === currentUserId() && !$isActive) {
        jsonFail('You cannot deactivate your own account.');
    }

    // Fetch old record for audit
    $oldData = findOrFail($pdo, 'users', 'user_id', $userId, 'User not found.');

    // Unique checks excluding self
    if (existsWhere($pdo, 'users', 'username', $username, $userId, 'user_id')) {
        jsonFail('Username already taken.');
    }
    if (existsWhere($pdo, 'users', 'email', $email, $userId, 'user_id')) {
        jsonFail('Email already in use.');
    }

    try {
        $pdo->prepare("
            UPDATE users SET
                full_name = ?, username = ?, email = ?,
                role_id   = ?, is_active = ?
            WHERE user_id = ?
        ")->execute([$fullName, $username, $email, $roleId, $isActive, $userId]);

        auditLog('EDIT_USER', 'users', $userId,
            ['full_name' => $oldData['full_name'], 'role_id' => $oldData['role_id'], 'is_active' => $oldData['is_active']],
            ['full_name' => $fullName, 'role_id' => $roleId, 'is_active' => $isActive]
        );

        jsonOk([], 'User updated successfully.');
    } catch (PDOException $e) {
        error_log('users_handler/edit_user: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Reset password ────────────────────────────────────────────────────────────
if ($action === 'reset_password') {

    $userId   = requiredInt('user_id', 'User', 1);
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';

    if (!$password) {
        jsonFail('New password is required.');
    }

    if (strlen($password) < 8) {
        jsonFail('Password must be at least 8 characters.');
    }

    if ($password !== $confirm) {
        jsonFail('Passwords do not match.');
    }

    findOrFail($pdo, 'users', 'user_id', $userId, 'User not found.');

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
            ->execute([$hash, $userId]);

        auditLog('RESET_PASSWORD', 'users', $userId, null, ['note' => 'Password reset by admin']);

        jsonOk([], 'Password reset successfully.');
    } catch (PDOException $e) {
        error_log('users_handler/reset_password: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ════════════════════════════════════════════════════════════════════════════
// EMPLOYEE ACTIONS
// ════════════════════════════════════════════════════════════════════════════

function extractEmpFields(): array {
    return [
        'employee_code'  => optionalString('employee_code'),
        'full_name'      => optionalString('full_name'),
        'position'       => optionalString('position'),
        'contact_number' => optionalString('contact_number'),
        'address'        => optionalString('address'),
        'license_number' => optionalString('license_number'),
        'license_expiry' => optionalString('license_expiry'),
        'license_type'   => optionalString('license_type'),
        'date_hired'     => optionalString('date_hired'),
    ];
}

// ── Add employee ──────────────────────────────────────────────────────────────
if ($action === 'add_employee') {

    $f = extractEmpFields();
    if ($err = validateEmpFields($f, false)) {
        jsonFail($err);
    }

    if (existsWhere($pdo, 'employees', 'employee_code', $f['employee_code'])) {
        jsonFail('Employee code already exists.');
    }
    if (duplicateEmployeeExists($pdo, $f)) {
        jsonFail('An employee with this name and contact number already exists.');
    }

    try {
        $pdo->prepare("
            INSERT INTO employees
                (employee_code, full_name, position, contact_number, address,
                 license_number, license_expiry, license_type, is_active, date_hired)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ")->execute([
            $f['employee_code'], $f['full_name'], $f['position'],
            $f['contact_number'], $f['address'],
            $f['license_number'], $f['license_expiry'], $f['license_type'],
            $f['date_hired'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        auditLog('ADD_EMPLOYEE', 'employees', $newId, null, [
            'employee_code' => $f['employee_code'],
            'full_name'     => $f['full_name'],
            'position'      => $f['position'],
        ]);

        jsonOk(['id' => $newId], 'Employee added successfully.');
    } catch (PDOException $e) {
        error_log('users_handler/add_employee: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Edit employee ─────────────────────────────────────────────────────────────
if ($action === 'edit_employee') {

    $empId    = requiredInt('employee_id', 'Employee', 1);
    $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;
    $f        = extractEmpFields();

    if ($err = validateEmpFields($f, true)) {
        jsonFail($err);
    }

    $oldData = findOrFail($pdo, 'employees', 'employee_id', $empId, 'Employee not found.');

    if (existsWhere($pdo, 'employees', 'employee_code', $f['employee_code'], $empId, 'employee_id')) {
        jsonFail('Employee code already in use.');
    }
    if (duplicateEmployeeExists($pdo, $f, $empId)) {
        jsonFail('An employee with this name and contact number already exists.');
    }

    try {
        $pdo->prepare("
            UPDATE employees SET
                employee_code  = ?, full_name     = ?, position      = ?,
                contact_number = ?, address       = ?, license_number = ?,
                license_expiry = ?, license_type  = ?, date_hired    = ?,
                is_active      = ?
            WHERE employee_id  = ?
        ")->execute([
            $f['employee_code'], $f['full_name'], $f['position'],
            $f['contact_number'], $f['address'],
            $f['license_number'], $f['license_expiry'], $f['license_type'],
            $f['date_hired'], $isActive, $empId,
        ]);

        auditLog('EDIT_EMPLOYEE', 'employees', $empId,
            ['full_name' => $oldData['full_name'], 'is_active' => $oldData['is_active']],
            ['full_name' => $f['full_name'], 'is_active' => $isActive]
        );

        jsonOk([], 'Employee updated successfully.');
    } catch (PDOException $e) {
        error_log('users_handler/edit_employee: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Batch import employees (CSV) ────────────────────────────────────────────
// Reuses validateEmpFields()/duplicateEmployeeExists() so an imported row
// goes through exactly the same rules as one typed into the Add Employee
// form — no separate, looser validation path for bulk data.
if ($action === 'batch_import_employees') {

    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        jsonFail('Please choose a CSV file to upload.');
    }
    if ($_FILES['csv_file']['size'] > 2 * 1024 * 1024) {
        jsonFail('CSV file must be 2 MB or smaller.');
    }

    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    if (!$handle) {
        jsonFail('Could not read the uploaded file.');
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        jsonFail('The CSV file appears to be empty.');
    }
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);

    $expectedColumns = [
        'employee_code', 'full_name', 'position', 'contact_number', 'address',
        'license_number', 'license_expiry', 'license_type', 'date_hired',
    ];
    $colIndex = [];
    foreach ($expectedColumns as $col) {
        $idx = array_search($col, $header, true);
        if ($idx !== false) $colIndex[$col] = $idx;
    }
    if (!isset($colIndex['employee_code'], $colIndex['full_name'], $colIndex['position'])) {
        fclose($handle);
        jsonFail('CSV header must include at least: employee_code, full_name, position.');
    }

    $imported = [];
    $errors   = [];
    $rowNum   = 1; // header was row 1

    while (($row = fgetcsv($handle)) !== false) {
        $rowNum++;
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
            continue; // skip blank lines
        }

        $get = fn($col) => isset($colIndex[$col], $row[$colIndex[$col]]) ? trim((string)$row[$colIndex[$col]]) : '';
        $f = [
            'employee_code'  => $get('employee_code') ?: null,
            'full_name'      => $get('full_name') ?: null,
            'position'       => $get('position') ?: null,
            'contact_number' => $get('contact_number') ?: null,
            'address'        => $get('address') ?: null,
            'license_number' => $get('license_number') ?: null,
            'license_expiry' => $get('license_expiry') ?: null,
            'license_type'   => $get('license_type') ?: null,
            'date_hired'     => $get('date_hired') ?: null,
        ];

        if ($err = validateEmpFields($f, true)) {
            $errors[] = "Row $rowNum: $err";
            continue;
        }
        if (existsWhere($pdo, 'employees', 'employee_code', $f['employee_code'])) {
            $errors[] = "Row $rowNum: Employee code '{$f['employee_code']}' already exists.";
            continue;
        }
        if (duplicateEmployeeExists($pdo, $f)) {
            $errors[] = "Row $rowNum: An employee named '{$f['full_name']}' with that contact number already exists.";
            continue;
        }

        try {
            $pdo->prepare("
                INSERT INTO employees
                    (employee_code, full_name, position, contact_number, address,
                     license_number, license_expiry, license_type, is_active, date_hired)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
            ")->execute([
                $f['employee_code'], $f['full_name'], $f['position'],
                $f['contact_number'], $f['address'],
                $f['license_number'], $f['license_expiry'], $f['license_type'],
                $f['date_hired'],
            ]);
            $imported[] = $f['employee_code'];
        } catch (PDOException $e) {
            error_log('users_handler/batch_import_employees row ' . $rowNum . ': ' . $e->getMessage());
            $errors[] = "Row $rowNum: A database error occurred for this row.";
        }
    }
    fclose($handle);

    if ($imported) {
        auditLog('BATCH_IMPORT_EMPLOYEES', 'employees', null, null, [
            'imported_count' => count($imported),
            'employee_codes' => $imported,
        ]);
    }

    jsonOk(
        ['imported' => count($imported), 'skipped' => count($errors), 'errors' => $errors],
        count($imported) . ' employee(s) imported' . ($errors ? ', ' . count($errors) . ' row(s) skipped.' : '.')
    );
}

// ── Unknown action ────────────────────────────────────────────────────────────
jsonFail('Unknown action.');
