<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/app_settings.php';

header('Content-Type: application/json');

requirePermission('users.manage');
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

function assertSingleActiveOperationsHead(PDO $pdo, int $roleId, int $active, ?int $excludeUserId = null): void {
    if ($active !== 1) {
        return;
    }
    $operationsRole = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Operations Head' FOR UPDATE");
    $operationsRole->execute();
    $operationsRoleId = $operationsRole->fetchColumn();
    if (!$operationsRoleId || (int)$operationsRoleId !== $roleId) {
        return;
    }

    $sql = 'SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1';
    $params = [$roleId];
    if ($excludeUserId !== null) {
        $sql .= ' AND user_id <> ?';
        $params[] = $excludeUserId;
    }
    $activeHeads = $pdo->prepare($sql);
    $activeHeads->execute($params);
    if ((int)$activeHeads->fetchColumn() > 0) {
        throw new DomainException('Only one active Operations Head account is allowed.');
    }
}

// ════════════════════════════════════════════════════════════════════════════
// USER ACTIONS
// ════════════════════════════════════════════════════════════════════════════

// ── Add user ──────────────────────────────────────────────────────────────────
if ($action === 'add_user') {

    $fullName = requiredString('full_name', 'Full name', 150);
    $username = requiredString('username', 'Username', 100);
    $email    = requiredString('email', 'Email', 150);
    $roleId   = requiredInt('role_id', 'Role', 1);
    if ($roleId === ROLE_ADMIN && currentRoleId() !== ROLE_ADMIN) {
        jsonFail('Only an Admin can assign the Admin role.', 403);
    }
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';

    if (!$password) {
        jsonFail('Password is required.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonFail('Invalid email address.');
    }
    $emailDomain = getAppSetting($pdo, 'company_email_domain', COMPANY_EMAIL_DOMAIN) ?? COMPANY_EMAIL_DOMAIN;
    if (!isCompanyEmail($email, $emailDomain)) {
        jsonFail('Use an email address ending in @' . $emailDomain . '.');
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

    $pdo->beginTransaction();
    try {
        assertSingleActiveOperationsHead($pdo, $roleId, 1);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (role_id, username, full_name, email, password_hash, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$roleId, $username, $fullName, $email, $hash]);
        $newId = (int)$pdo->lastInsertId();
        $pdo->commit();

        auditLog('ADD_USER', 'users', $newId, null, [
            'username'  => $username,
            'full_name' => $fullName,
            'role_id'   => $roleId,
        ]);

        jsonOk(['id' => $newId], 'User created successfully.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('users_handler/add_user: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonFail($e->getMessage(), 409);
    }
}

// ── Edit user ─────────────────────────────────────────────────────────────────
if ($action === 'edit_user') {

    $userId   = requiredInt('user_id', 'User', 1);
    $fullName = requiredString('full_name', 'Full name', 150);
    $username = requiredString('username', 'Username', 100);
    $email    = requiredString('email', 'Email', 150);
    $roleId   = requiredInt('role_id', 'Role', 1);
    if ($roleId === ROLE_ADMIN && currentRoleId() !== ROLE_ADMIN) {
        jsonFail('Only an Admin can assign the Admin role.', 403);
    }
    $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonFail('Invalid email address.');
    }
    $emailDomain = getAppSetting($pdo, 'company_email_domain', COMPANY_EMAIL_DOMAIN) ?? COMPANY_EMAIL_DOMAIN;
    if (!isCompanyEmail($email, $emailDomain)) {
        jsonFail('Use an email address ending in @' . $emailDomain . '.');
    }

    // Prevent deactivating own account
    if ($userId === currentUserId() && !$isActive) {
        jsonFail('You cannot deactivate your own account.');
    }

    // Fetch old record for audit
    $oldData = findOrFail($pdo, 'users', 'user_id', $userId, 'User not found.');
    if ((int)$oldData['role_id'] === ROLE_ADMIN && currentRoleId() !== ROLE_ADMIN) {
        jsonFail('Only an Admin can edit or deactivate an Admin account.', 403);
    }

    // Unique checks excluding self
    if (existsWhere($pdo, 'users', 'username', $username, $userId, 'user_id')) {
        jsonFail('Username already taken.');
    }
    if (existsWhere($pdo, 'users', 'email', $email, $userId, 'user_id')) {
        jsonFail('Email already in use.');
    }

    $pdo->beginTransaction();
    try {
        assertSingleActiveOperationsHead($pdo, $roleId, $isActive, $userId);
        $pdo->prepare("
            UPDATE users SET
                full_name = ?, username = ?, email = ?,
                auth_version = auth_version + CASE
                    WHEN role_id <> ? OR is_active <> ? THEN 1 ELSE 0
                END,
                role_id   = ?, is_active = ?
            WHERE user_id = ?
        ")->execute([
            $fullName, $username, $email,
            $roleId, $isActive, $roleId, $isActive, $userId,
        ]);
        $pdo->commit();

        auditLog('EDIT_USER', 'users', $userId,
            ['full_name' => $oldData['full_name'], 'role_id' => $oldData['role_id'], 'is_active' => $oldData['is_active']],
            ['full_name' => $fullName, 'role_id' => $roleId, 'is_active' => $isActive]
        );

        jsonOk([], 'User updated successfully.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('users_handler/edit_user: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonFail($e->getMessage(), 409);
    }
}

if ($action === 'review_password_reset') {
    $requestId = requiredInt('request_id', 'Request', 1);
    $status = requiredEnum('status', ['Approved', 'Rejected'], 'Status');
    $notes = optionalString('review_notes', null, 500);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT request_id, user_id, password_hash, status
             FROM password_reset_requests
             WHERE request_id = ? AND status = 'Pending'
             FOR UPDATE"
        );
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            $pdo->rollBack();
            jsonFail('Request not found or already reviewed.', 404);
        }

        if ($status === 'Approved') {
            $update = $pdo->prepare(
                'UPDATE users
                 SET password_hash = ?, auth_version = auth_version + 1
                 WHERE user_id = ? AND is_active = 1'
            );
            $update->execute([$request['password_hash'], $request['user_id']]);
            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                jsonFail('The user account is inactive or no longer exists.', 409);
            }
        }
        $pdo->prepare(
            'UPDATE password_reset_requests
             SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
             WHERE request_id = ?'
        )->execute([$status, currentUserId(), $notes, $requestId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('users_handler/review_password_reset: ' . $e->getMessage());
        jsonFail('Could not review the password request.', 500);
    }

    auditLog('PASSWORD_RESET_' . strtoupper($status), 'password_reset_requests', $requestId, null, ['user_id' => (int)$request['user_id']]);
    jsonOk([], "Password reset request {$status}.");
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

    $targetUser = findOrFail($pdo, 'users', 'user_id', $userId, 'User not found.');
    if ((int)$targetUser['role_id'] === ROLE_ADMIN && currentRoleId() !== ROLE_ADMIN) {
        jsonFail('Only an Admin can reset an Admin account password.', 403);
    }

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare(
            'UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE user_id = ?'
        )
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
        'employment_type' => requiredEnum('employment_type', ['Employee', 'Contractor'], 'Employment type'),
        'contractor_company' => optionalString('contractor_company', null, 150),
        'date_resigned' => optionalString('date_resigned', null, 10),
        'resignation_reason' => optionalString('resignation_reason', null, 500),
    ];
}

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
    if ($f['employment_type'] === 'Contractor' && !$f['contractor_company'])
        return 'Contractor company is required for contractors.';
    if ($f['date_resigned'] && !isValidDate($f['date_resigned']))
        return 'Invalid resignation date.';
    if ($f['date_resigned'] && $f['date_resigned'] > date('Y-m-d'))
        return 'Resignation date cannot be in the future.';
    if ($f['date_resigned'] && $f['date_hired'] && $f['date_resigned'] < $f['date_hired'])
        return 'Resignation date cannot be before the hire date.';
    if ($f['date_resigned'] && !$f['resignation_reason'])
        return 'A resignation reason is required when recording a resignation.';
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
        $isActive = $f['date_resigned'] === null ? 1 : 0;
        $pdo->prepare("
            INSERT INTO employees
                (employee_code, full_name, position, contact_number, address,
                 license_number, license_expiry, license_type, is_active, date_hired,
                 employment_type, contractor_company, date_resigned, resignation_reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $f['employee_code'], $f['full_name'], $f['position'],
            $f['contact_number'], $f['address'],
            $f['license_number'], $f['license_expiry'], $f['license_type'],
            $isActive, $f['date_hired'], $f['employment_type'], $f['contractor_company'],
            $f['date_resigned'], $f['resignation_reason'],
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
    if ($f['date_resigned'] !== null) {
        $isActive = 0;
    }

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
                is_active      = ?, employment_type = ?, contractor_company = ?,
                date_resigned = ?, resignation_reason = ?
            WHERE employee_id  = ?
        ")->execute([
            $f['employee_code'], $f['full_name'], $f['position'],
            $f['contact_number'], $f['address'],
            $f['license_number'], $f['license_expiry'], $f['license_type'],
            $f['date_hired'], $isActive, $f['employment_type'], $f['contractor_company'],
            $f['date_resigned'], $f['resignation_reason'], $empId,
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

// ── Unknown action ────────────────────────────────────────────────────────────
jsonFail('Unknown action.');
