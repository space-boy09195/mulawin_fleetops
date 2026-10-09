<?php
// ============================================================
// auth/seed_users.php
// ONE-TIME script — run once to create test accounts
// DELETE THIS FILE from the server after running it!
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    // Detect schema: old schema has users.full_name, new schema has users.employee_id
    $columns = $pdo->query('DESCRIBE users')->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($columns, 'Field');
    $hasEmployeeId = in_array('employee_id', $colNames);
    $hasFullNameInUsers = in_array('full_name', $colNames);

    // Old schema: users has full_name column (NOT NULL)
    // New schema: users has no full_name, has employee_id instead

// Schema detection complete - proceed with user creation
    $pdo = getDBConnection();
    // Format: [username, full_name, email, plaintext_password, role_id]
    $users = [
        ['admin', 'System Administrator', 'admin@mulawin.com', 'Admin@1234', 1],
    ];

    // New schema: users table has no full_name column;
    // full_name is computed from employees table via LEFT JOIN.
    // Insert user without full_name, then create linked employee record.
    $sql = "INSERT IGNORE INTO users (role_id, username, email, password_hash, is_active, auth_version)
            VALUES (:role_id, :username, :email, :password_hash, 1, 1)";

    $stmt = $pdo->prepare($sql);

    foreach ($users as [$username, $full_name, $email, $password, $role_id]) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt->execute([
            ':role_id'       => $role_id,
            ':username'      => $username,
            ':email'         => $email,
            ':password_hash' => $hash,
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Create linked employee record
        $empFullName = $full_name ?? $username;
        $empStmt = $pdo->prepare(
            "INSERT INTO employees (user_id, employee_code, full_name, position, is_active, employment_type)
             VALUES (:user_id, :employee_code, :full_name, :position, 1, 'Employee')"
        );
        $empStmt->execute([
            ':user_id'       => $newId,
            ':employee_code' => 'EMP-' . sprintf('%04d', $newId),
            ':full_name'     => $empFullName,
            ':position'      => 'System Administrator',
        ]);

        $empId = (int)$pdo->lastInsertId(); // after employees INSERT
        $pdo->prepare('UPDATE users SET employee_id = ? WHERE user_id = ?')->execute([$empId, $newId]);

        echo "Created user: $username (role_id: $role_id), employee linked.<br>";
    }

    echo "<br><strong style='color:red'>⚠ DELETE THIS FILE NOW — it is a security risk!</strong>";

} catch (PDOException $e) {
    die("Error inserting user: " . $e->getMessage());
}