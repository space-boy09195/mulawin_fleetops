<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/app_settings.php';
require_once __DIR__ . '/../includes/employee_profile.php';

header('Content-Type: application/json');

requirePermission('users.manage');
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

function assertSingleActiveUser(PDO $pdo, int $roleId, int $active, ?int $excludeUserId = null): void {
    // Only an active account can break the rule.
    if ($active !== 1) {
        return;
    }

    // The target role's is_single_holder flag decides whether the role may
    // have more than one active user. The role row is locked in the
    // caller's transaction, so concurrent assignments to it are serialized.
    $roleQuery = $pdo->prepare('SELECT is_single_holder FROM roles WHERE role_id = ? FOR UPDATE');
    $roleQuery->execute([$roleId]);
    $singleHolder = $roleQuery->fetchColumn();
    if ($singleHolder === false) {
        throw new DomainException('The selected role no longer exists.');
    }
    if ((int)$singleHolder !== 1) {
        return;
    }

    // Count the other active holders of the role. When editing, the edited
    // user is excluded, so moving or reactivating a user into a role that
    // another active user already holds is refused. FOR UPDATE keeps the
    // count stable until the caller's transaction ends.
    $sql = 'SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1';
    $params = [$roleId];
    if ($excludeUserId !== null) {
        $sql .= ' AND user_id <> ?';
        $params[] = $excludeUserId;
    }
    $countRole = $pdo->prepare($sql . ' FOR UPDATE');
    $countRole->execute($params);

    if ((int)$countRole->fetchColumn() > 0) {
        throw new DomainException('Only one active account is allowed for this role.');
    }
}

// ════════════════════════════════════════════════════════════════════════════
// USER ACTIONS
// ════════════════════════════════════════════════════════════════════════════

// ── Add user ──────────────────────────────────────────────────────────────────
if ($action === 'add_user') {
    // User accounts are Admin-only (validated system key), whatever role
    // holds users.manage. Employee-only actions below are not affected.
    if (!isAdmin()) {
        jsonFail('Only an Admin can manage user accounts.', 403);
    }

    $employeeId = requiredInt('employee_id', 'Employee', 1);
    $username = requiredString('username', 'Username', 80);
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

    // The target role must exist and be active. Only an Admin (validated
    // system key) may assign the role whose system key is 'admin'.
    $targetRole = roleRowForId($pdo, $roleId);
    if (!$targetRole) {
        jsonFail('Invalid role selected.');
    }
    if (!(int)$targetRole['is_active']) {
        jsonFail('This role is inactive and cannot be assigned.', 409);
    }
    if ($targetRole['system_key'] === 'admin' && !isAdmin()) {
        jsonFail('Only an Admin can assign the Admin role.', 403);
    }

    // Unique username and email
    if (existsWhere($pdo, 'users', 'username', $username)) {
        jsonFail('Username already taken.');
    }
    if (existsWhere($pdo, 'users', 'email', $email)) {
        jsonFail('Email already in use.');
    }

    $pdo->beginTransaction();
    try {
        assertSingleActiveUser($pdo, $roleId, 1);
        $employeeQuery = $pdo->prepare(
            "SELECT employee_id, user_id, first_name, middle_name, last_name, suffix
             FROM employees
             WHERE employee_id = ? AND employment_status = 'Active'
             FOR UPDATE"
        );
        $employeeQuery->execute([$employeeId]);
        $employee = $employeeQuery->fetch(PDO::FETCH_ASSOC);
        if (!$employee) {
            throw new DomainException('Select an active employee before creating an account.');
        }

        $linkedUserQuery = $pdo->prepare(
            'SELECT user_id
             FROM users
             WHERE employee_id = ? OR user_id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $linkedUserQuery->execute([$employeeId, $employee['user_id']]);
        if ($linkedUserQuery->fetchColumn()) {
            throw new DomainException('This employee already has a system account.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (role_id, employee_id, username, email, password_hash, is_active, auth_version)
            VALUES (?, ?, ?, ?, ?, 1, 1)
        ");
        $stmt->execute([$roleId, $employeeId, $username, $email, $hash]);
        $newId = (int)$pdo->lastInsertId();

        $linkEmployee = $pdo->prepare(
            'UPDATE employees SET user_id = ? WHERE employee_id = ? AND user_id IS NULL'
        );
        $linkEmployee->execute([$newId, $employeeId]);
        if ($linkEmployee->rowCount() !== 1) {
            throw new DomainException('This employee was linked to another account. Refresh the page and try again.');
        }

        auditLog('ADD_USER', 'users', $newId, null, [
            'username'    => $username,
            'employee_id' => $employeeId,
            'employee_name' => trim(implode(' ', array_filter([
                $employee['first_name'],
                $employee['middle_name'],
                $employee['last_name'],
                $employee['suffix'],
            ]))),
            'role_id'     => $roleId,
        ]);
        $pdo->commit();

        jsonOk(['id' => $newId], 'User created successfully.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('users_handler/add_user: ' . $e->getMessage());
        // 1205 = lock wait timeout, 1213 = deadlock: the whole transaction was
        // rolled back above, so nothing was saved and the request can be retried.
        if (in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
            jsonFail('Another change was being saved at the same time. Please try again.', 409);
        }
        jsonFail('A database error occurred. Please try again.', 500);
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonFail($e->getMessage(), 409);
    }
}

// ── Edit user ─────────────────────────────────────────────────────────────────
if ($action === 'edit_user') {
    if (!isAdmin()) {
        jsonFail('Only an Admin can manage user accounts.', 403);
    }

    $userId   = requiredInt('user_id', 'User', 1);
    $firstName  = requiredString('first_name', 'First name', 80);
    $middleName = optionalString('middle_name', null, 80);
    $lastName   = requiredString('last_name', 'Last name', 80);
    $suffix     = optionalString('suffix', null, 20);
    $username = requiredString('username', 'Username', 80);
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

    // Role checks use roles.system_key from the database, never a role ID.
    $currentRole = roleRowForId($pdo, (int)$oldData['role_id']);
    $targetRole  = roleRowForId($pdo, $roleId);
    if (!$targetRole) {
        jsonFail('Invalid role selected.');
    }
    $currentKey  = $currentRole['system_key'] ?? null;
    $roleChanges = $roleId !== (int)$oldData['role_id'];
    if ($roleChanges && $userId === currentUserId()) {
        jsonFail('You cannot change your own role.', 403);
    }
    if ($roleChanges && !(int)$targetRole['is_active']) {
        jsonFail('This role is inactive and cannot be assigned.', 409);
    }
    if ($currentKey === 'admin' && !isAdmin()) {
        jsonFail('Only an Admin can edit or deactivate an Admin account.', 403);
    }
    if ($targetRole['system_key'] === 'admin' && !isAdmin()) {
        jsonFail('Only an Admin can assign the Admin role.', 403);
    }
    $touchesAdmin = $currentKey === 'admin' || $targetRole['system_key'] === 'admin';

    // Unique checks excluding self
    if (existsWhere($pdo, 'users', 'username', $username, $userId, 'user_id')) {
        jsonFail('Username already taken.');
    }
    if (existsWhere($pdo, 'users', 'email', $email, $userId, 'user_id')) {
        jsonFail('Email already in use.');
    }

    $pdo->beginTransaction();
    try {
        // Lock order: the Admin role row, then the edited user, then the
        // single-holder count. Every change that can affect Admin membership
        // waits on the Admin role row first, so concurrent demotions are
        // serialized whatever the isolation level.
        if ($touchesAdmin) {
            $adminRoleLock = $pdo->prepare("SELECT role_id FROM roles WHERE system_key = 'admin' FOR UPDATE");
            $adminRoleLock->execute();
            if ($adminRoleLock->fetchColumn() === false) {
                $pdo->rollBack();
                jsonFail('The Admin role is not configured, so Admin membership cannot be changed.', 409);
            }
        }

        // Decide on fresh, locked values, not on the read made before the
        // transaction.
        $freshQuery = $pdo->prepare('SELECT role_id, is_active FROM users WHERE user_id = ? FOR UPDATE');
        $freshQuery->execute([$userId]);
        $freshUser = $freshQuery->fetch(PDO::FETCH_ASSOC);
        if (!$freshUser) {
            $pdo->rollBack();
            jsonFail('User not found.', 404);
        }
        if ((int)$freshUser['role_id'] !== (int)$oldData['role_id']
            || (int)$freshUser['is_active'] !== (int)$oldData['is_active']) {
            throw new DomainException('This account was changed by someone else. Reload and try again.');
        }

        // Never leave the system without an active Admin.
        $wasActiveAdmin = $currentKey === 'admin'
            && (int)($currentRole['is_active'] ?? 0) === 1
            && (int)$freshUser['is_active'] === 1;
        $staysActiveAdmin = $targetRole['system_key'] === 'admin'
            && (int)$targetRole['is_active'] === 1
            && $isActive === 1;
        if ($wasActiveAdmin && !$staysActiveAdmin) {
            $otherAdmins = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM users u
                 JOIN roles r ON r.role_id = u.role_id
                 WHERE r.system_key = 'admin' AND r.is_active = 1
                   AND u.is_active = 1 AND u.user_id <> ?
                 FOR UPDATE"
            );
            $otherAdmins->execute([$userId]);
            if ((int)$otherAdmins->fetchColumn() === 0) {
                throw new DomainException('At least one active Admin must remain. Assign another active Admin first.');
            }
        }

        assertSingleActiveUser($pdo, $roleId, $isActive, $userId);
        // UPDATE users table — do NOT set full_name (column does not exist in new schema)
        // full_name is maintained in the employees table instead
        $pdo->prepare("
            UPDATE users SET
                username = ?, email = ?,
                auth_version = auth_version + CASE
                    WHEN role_id <> ? OR is_active <> ? THEN 1 ELSE 0
                END,
                role_id   = ?, is_active = ?
            WHERE user_id = ?
        ")->execute([
            $username, $email,
            $roleId, $isActive, $roleId, $isActive, $userId,
        ]);

        // Update the linked employee's name parts. users.employee_id is the
        // link the user list displays; the older employees.user_id link is
        // still accepted when users.employee_id is not set.
        $emp = !empty($oldData['employee_id'])
            ? findOrFail($pdo, 'employees', 'employee_id', (int)$oldData['employee_id'], 'Employee not found.')
            : findOrFail($pdo, 'employees', 'user_id', $userId, 'Employee not found.');
        $pdo->prepare(
            'UPDATE employees SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?
             WHERE employee_id = ?'
        )->execute([
            $firstName, $middleName, $lastName, $suffix, $emp['employee_id'],
        ]);
        syncEmployeeCompatColumns($pdo, (int)$emp['employee_id']);

        // Audited inside the transaction: a role or status change is never
        // saved without its audit row.
        auditLog('EDIT_USER', 'users', $userId,
            [
                'first_name'  => $emp['first_name'] ?? null,
                'middle_name' => $emp['middle_name'] ?? null,
                'last_name'   => $emp['last_name'] ?? null,
                'suffix'      => $emp['suffix'] ?? null,
                'role_id'     => $oldData['role_id'],
                'is_active'   => $oldData['is_active'],
            ],
            [
                'first_name'  => $firstName,
                'middle_name' => $middleName,
                'last_name'   => $lastName,
                'suffix'      => $suffix,
                'role_id'     => $roleId,
                'is_active'   => $isActive,
            ]
        );
        $pdo->commit();

        jsonOk([], 'User updated successfully.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('users_handler/edit_user: ' . $e->getMessage());
        // 1205 = lock wait timeout, 1213 = deadlock: the whole transaction was
        // rolled back above, so nothing was saved and the request can be retried.
        if (in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
            jsonFail('Another change to this account was saved at the same time. Please try again.', 409);
        }
        jsonFail('A database error occurred. Please try again.', 500);
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonFail($e->getMessage(), 409);
    }
}

// ── Reset password ────────────────────────────────────────────────────────────
if ($action === 'reset_password') {
    if (!isAdmin()) {
        jsonFail('Only an Admin can manage user accounts.', 403);
    }
    $userId   = requiredInt('user_id', 'User', 1);
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm  = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';
    if ($password === '') {
        jsonFail('New password is required.');
    }
    if (strlen($password) < 8) {
        jsonFail('Password must be at least 8 characters.');
    }
    if ($password !== $confirm) {
        jsonFail('Passwords do not match.');
    }

    $targetUser = findOrFail($pdo, 'users', 'user_id', $userId, 'User not found.');
    $targetUserRole = roleRowForId($pdo, (int)$targetUser['role_id']);
    if (($targetUserRole['system_key'] ?? null) === 'admin' && !isAdmin()) {
        jsonFail('Only an Admin can reset an Admin account password.', 403);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->beginTransaction();
    try {
        // auth_version + 1 signs the user out of every existing session.
        $pdo->prepare(
            'UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE user_id = ?'
        )
            ->execute([$hash, $userId]);

        // Audited in the same transaction: an unaudited reset is rolled back.
        auditLog('RESET_PASSWORD', 'users', $userId, null, ['note' => 'Password reset by admin']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('users_handler/reset_password: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }

    jsonOk([], 'Password reset successfully.');
}

// ════════════════════════════════════════════════════════════════════════════
// EMPLOYEE ACTIONS
// ════════════════════════════════════════════════════════════════════════════

// Name-part lengths match the target employees columns; employee_code,
// license_type and resignation_reason match the S-36 migration. Over-long
// input is rejected with a clear message before any write.
function extractEmpFields(): array {
    return [
        'employee_code'  => optionalString('employee_code', null, 30),
        'first_name'     => optionalString('first_name', null, 80),
        'middle_name'    => optionalString('middle_name', null, 80),
        'last_name'      => optionalString('last_name', null, 80),
        'suffix'         => optionalString('suffix', null, 20),
        'position'       => optionalString('position'),
        'contact_number' => optionalString('contact_number'),
        'address'        => optionalString('address'),
        'license_number' => optionalString('license_number'),
        'license_expiry' => optionalString('license_expiry'),
        'license_type'   => optionalString('license_type', null, 20),
        'date_hired'     => optionalString('date_hired'),
        'employment_type' => requiredEnum('employment_type', ['Employee', 'Contractor'], 'Employment type'),
        'contractor_company' => optionalString('contractor_company', null, 150),
        'date_resigned' => optionalString('date_resigned', null, 10),
        'resignation_reason' => optionalString('resignation_reason', null, 500),
    ];
}

function validateEmpFields(array $f, bool $allowPassedDates = false): ?string {
    if (!$f['employee_code']) return 'Employee code is required.';
    if (!$f['first_name'])    return 'First name is required.';
    if (!$f['last_name'])     return 'Last name is required.';
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
    $norm = static fn($v): ?string => ($v = preg_replace('/\s+/', ' ', trim((string)($v ?? '')))) === '' ? null : $v;
    $first   = $norm($f['first_name'] ?? null);
    $middle  = $norm($f['middle_name'] ?? null);
    $last    = $norm($f['last_name'] ?? null);
    $suffix  = $norm($f['suffix'] ?? null);
    $contact = $norm($f['contact_number'] ?? null);

    if ($first === null || $last === null || $contact === null) {
        return false;
    }

    // Same person = same name parts (optional parts compared NULL-safely)
    // and the same contact number.
    $sql = "SELECT employee_id FROM employees
            WHERE first_name = ? AND last_name = ?
              AND middle_name <=> ? AND suffix <=> ?
              AND contact_number = ?";
    $params = [$first, $last, $middle, $suffix, $contact];
    if ($selfId !== null) {
        $sql .= " AND employee_id != ?";
        $params[] = $selfId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}

// A recorded resignation date always means Resigned; otherwise the form's
// active switch chooses Active or Inactive (target employment_status values).
function employmentStatusFor(?string $dateResigned, bool $active): string {
    if ($dateResigned !== null) {
        return 'Resigned';
    }
    return $active ? 'Active' : 'Inactive';
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
        // New employees have no active switch: Active unless already resigned.
        $status = employmentStatusFor($f['date_resigned'], true);
        $pdo->beginTransaction();
        $pdo->prepare("
            INSERT INTO employees
                (employee_code, first_name, middle_name, last_name, suffix,
                 position, contact_number, address,
                 license_number, license_expiry, license_type, employment_status, date_hired,
                 employment_type, contractor_company, date_resigned, resignation_reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $f['employee_code'], $f['first_name'], $f['middle_name'], $f['last_name'], $f['suffix'],
            $f['position'], $f['contact_number'], $f['address'],
            $f['license_number'], $f['license_expiry'], $f['license_type'],
            $status, $f['date_hired'], $f['employment_type'], $f['contractor_company'],
            $f['date_resigned'], $f['resignation_reason'],
        ]);
        $newId = (int)$pdo->lastInsertId();
        syncEmployeeCompatColumns($pdo, $newId);

        auditLog('ADD_EMPLOYEE', 'employees', $newId, null, [
            'employee_code'     => $f['employee_code'],
            'first_name'        => $f['first_name'],
            'middle_name'       => $f['middle_name'],
            'last_name'         => $f['last_name'],
            'suffix'            => $f['suffix'],
            'position'          => $f['position'],
            'employment_status' => $status,
        ]);
        $pdo->commit();

        jsonOk(['id' => $newId], 'Employee added successfully.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('users_handler/add_employee: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Edit employee ─────────────────────────────────────────────────────────────
if ($action === 'edit_employee') {

    $empId    = requiredInt('employee_id', 'Employee', 1);
    $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '1';
    $clearResignation = ($_POST['clear_resignation'] ?? '') === '1';
    $f        = extractEmpFields();

    if ($err = validateEmpFields($f, true)) {
        jsonFail($err);
    }

    $oldData = findOrFail($pdo, 'employees', 'employee_id', $empId, 'Employee not found.');

    // Resignation history is never erased by an ordinary edit. Clearing it
    // needs the explicit "Clear resignation record" option, and the previous
    // values are kept in the audit entry below.
    $oldDateResigned = $oldData['date_resigned'] ?? null;
    $oldReason       = $oldData['resignation_reason'] ?? null;
    if ($clearResignation) {
        if ($f['date_resigned'] !== null) {
            jsonFail('Remove the resignation date to clear the resignation record, or untick "Clear resignation record".');
        }
        $f['resignation_reason'] = null;
    } else {
        if ($oldDateResigned !== null && $f['date_resigned'] === null) {
            jsonFail('The resignation date cannot be removed by editing. Tick "Clear resignation record" to clear it on purpose.');
        }
        if ($oldReason !== null && $f['resignation_reason'] === null) {
            jsonFail('The resignation reason cannot be removed by editing. Tick "Clear resignation record" to clear it on purpose.');
        }
    }
    $status = employmentStatusFor($f['date_resigned'], $isActive);

    if (existsWhere($pdo, 'employees', 'employee_code', $f['employee_code'], $empId, 'employee_id')) {
        jsonFail('Employee code already in use.');
    }
    if (duplicateEmployeeExists($pdo, $f, $empId)) {
        jsonFail('An employee with this name and contact number already exists.');
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("
            UPDATE employees SET
                employee_code  = ?, first_name    = ?, middle_name   = ?,
                last_name      = ?, suffix        = ?, position      = ?,
                contact_number = ?, address       = ?, license_number = ?,
                license_expiry = ?, license_type  = ?, date_hired    = ?,
                employment_status = ?, employment_type = ?, contractor_company = ?,
                date_resigned = ?, resignation_reason = ?
            WHERE employee_id  = ?
        ")->execute([
            $f['employee_code'], $f['first_name'], $f['middle_name'],
            $f['last_name'], $f['suffix'], $f['position'],
            $f['contact_number'], $f['address'],
            $f['license_number'], $f['license_expiry'], $f['license_type'],
            $f['date_hired'], $status, $f['employment_type'], $f['contractor_company'],
            $f['date_resigned'], $f['resignation_reason'], $empId,
        ]);
        syncEmployeeCompatColumns($pdo, $empId);

        auditLog('EDIT_EMPLOYEE', 'employees', $empId,
            [
                'first_name'         => $oldData['first_name'] ?? null,
                'middle_name'        => $oldData['middle_name'] ?? null,
                'last_name'          => $oldData['last_name'] ?? null,
                'suffix'             => $oldData['suffix'] ?? null,
                'employment_status'  => $oldData['employment_status'] ?? null,
                'date_resigned'      => $oldDateResigned,
                'resignation_reason' => $oldReason,
            ],
            [
                'first_name'         => $f['first_name'],
                'middle_name'        => $f['middle_name'],
                'last_name'          => $f['last_name'],
                'suffix'             => $f['suffix'],
                'employment_status'  => $status,
                'date_resigned'      => $f['date_resigned'],
                'resignation_reason' => $f['resignation_reason'],
                'resignation_cleared' => $clearResignation && ($oldDateResigned !== null || $oldReason !== null),
            ]
        );
        $pdo->commit();

        jsonOk([], 'Employee updated successfully.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('users_handler/edit_employee: ' . $e->getMessage());
        jsonFail('A database error occurred. Please try again.', 500);
    }
}

// ── Unknown action ────────────────────────────────────────────────────────────
jsonFail('Unknown action.');
