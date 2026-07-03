<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT]);

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ════════════════════════════════════════════════════════════════════════════
// USER ACTIONS
// ════════════════════════════════════════════════════════════════════════════

// ── Add user ──────────────────────────────────────────────────────────────────
if ($action === 'add_user') {

    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username']  ?? '');
    $email    = trim($_POST['email']     ?? '');
    $roleId   = (int)($_POST['role_id'] ?? 0);
    $password = $_POST['password']       ?? '';
    $confirm  = $_POST['confirm']        ?? '';

    if (!$fullName || !$username || !$email || !$roleId || !$password) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }

    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
        exit;
    }

    if ($password !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit;
    }

    // Check role exists
    $roleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_id = ?");
    $roleCheck->execute([$roleId]);
    if (!$roleCheck->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Invalid role selected.']);
        exit;
    }

    // Unique username and email
    $dupUser = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
    $dupUser->execute([$username]);
    if ($dupUser->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Username already taken.']);
        exit;
    }

    $dupEmail = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
    $dupEmail->execute([$email]);
    if ($dupEmail->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email already in use.']);
        exit;
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

        echo json_encode(['success' => true, 'message' => 'User created successfully.', 'id' => $newId]);
    } catch (PDOException $e) {
        error_log('users_handler/add_user: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Edit user ─────────────────────────────────────────────────────────────────
if ($action === 'edit_user') {

    $userId   = (int)($_POST['user_id']  ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username']  ?? '');
    $email    = trim($_POST['email']     ?? '');
    $roleId   = (int)($_POST['role_id'] ?? 0);
    $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;

    if (!$userId || !$fullName || !$username || !$email || !$roleId) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }

    // Prevent deactivating own account
    if ($userId === currentUserId() && !$isActive) {
        echo json_encode(['success' => false, 'message' => 'You cannot deactivate your own account.']);
        exit;
    }

    // Fetch old record for audit
    $old = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $old->execute([$userId]);
    $oldData = $old->fetch(PDO::FETCH_ASSOC);
    if (!$oldData) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    // Unique checks excluding self
    $dupUser = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
    $dupUser->execute([$username, $userId]);
    if ($dupUser->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Username already taken.']);
        exit;
    }

    $dupEmail = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
    $dupEmail->execute([$email, $userId]);
    if ($dupEmail->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email already in use.']);
        exit;
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

        echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
    } catch (PDOException $e) {
        error_log('users_handler/edit_user: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Reset password ────────────────────────────────────────────────────────────
if ($action === 'reset_password') {

    $userId   = (int)($_POST['user_id'] ?? 0);
    $password = $_POST['password']      ?? '';
    $confirm  = $_POST['confirm']       ?? '';

    if (!$userId || !$password) {
        echo json_encode(['success' => false, 'message' => 'User and new password are required.']);
        exit;
    }

    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
        exit;
    }

    if ($password !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit;
    }

    $check = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $check->execute([$userId]);
    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
            ->execute([$hash, $userId]);

        auditLog('RESET_PASSWORD', 'users', $userId, null, ['note' => 'Password reset by admin']);

        echo json_encode(['success' => true, 'message' => 'Password reset successfully.']);
    } catch (PDOException $e) {
        error_log('users_handler/reset_password: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// EMPLOYEE ACTIONS
// ════════════════════════════════════════════════════════════════════════════

function extractEmpFields(): array {
    return [
        'employee_code'  => trim($_POST['employee_code']  ?? ''),
        'full_name'      => trim($_POST['full_name']      ?? ''),
        'position'       => trim($_POST['position']       ?? ''),
        'contact_number' => trim($_POST['contact_number'] ?? '') ?: null,
        'address'        => trim($_POST['address']        ?? '') ?: null,
        'license_number' => trim($_POST['license_number'] ?? '') ?: null,
        'license_expiry' => trim($_POST['license_expiry'] ?? '') ?: null,
        'license_type'   => trim($_POST['license_type']   ?? '') ?: null,
        'date_hired'     => trim($_POST['date_hired']     ?? '') ?: null,
    ];
}

function validateEmpFields(array $f): ?string {
    if (!$f['employee_code']) return 'Employee code is required.';
    if (!$f['full_name'])     return 'Full name is required.';
    if (!$f['position'])      return 'Position is required.';
    $hasLic = $f['license_number'] || $f['license_expiry'];
    if ($hasLic && !$f['license_number']) return 'License number is required with expiry.';
    if ($hasLic && !$f['license_expiry']) return 'License expiry is required with license number.';
    if ($f['license_expiry'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['license_expiry']))
        return 'Invalid license expiry date.';
    if ($f['date_hired'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['date_hired']))
        return 'Invalid date hired.';
    return null;
}

// ── Add employee ──────────────────────────────────────────────────────────────
if ($action === 'add_employee') {

    $f = extractEmpFields();
    if ($err = validateEmpFields($f)) {
        echo json_encode(['success' => false, 'message' => $err]);
        exit;
    }

    $dup = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_code = ?");
    $dup->execute([$f['employee_code']]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Employee code already exists.']);
        exit;
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

        echo json_encode(['success' => true, 'message' => 'Employee added successfully.', 'id' => $newId]);
    } catch (PDOException $e) {
        error_log('users_handler/add_employee: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Edit employee ─────────────────────────────────────────────────────────────
if ($action === 'edit_employee') {

    $empId    = (int)($_POST['employee_id'] ?? 0);
    $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;
    $f        = extractEmpFields();

    if (!$empId) {
        echo json_encode(['success' => false, 'message' => 'Invalid employee ID.']);
        exit;
    }

    if ($err = validateEmpFields($f)) {
        echo json_encode(['success' => false, 'message' => $err]);
        exit;
    }

    $old = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
    $old->execute([$empId]);
    $oldData = $old->fetch(PDO::FETCH_ASSOC);
    if (!$oldData) {
        echo json_encode(['success' => false, 'message' => 'Employee not found.']);
        exit;
    }

    $dup = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_code = ? AND employee_id != ?");
    $dup->execute([$f['employee_code'], $empId]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Employee code already in use.']);
        exit;
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

        echo json_encode(['success' => true, 'message' => 'Employee updated successfully.']);
    } catch (PDOException $e) {
        error_log('users_handler/edit_employee: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
    }
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);