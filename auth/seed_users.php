<?php
// ============================================================
// auth/seed_users.php
// ONE-TIME script — run once to create test accounts
// DELETE THIS FILE from the server after running it!
// ============================================================

/*require_once __DIR__ . '/../config/database.php';

$pdo = getDBConnection();

// ---- Test users for each role ----------------------------
// Format: [username, full_name, email, plaintext_password, role_id]
$users = [
    ['admin',       'System Administrator',   'admin@mulawin.com',       'Admin@1234',  1],  // Head Management
    ['dispatcher1', 'Juan Dela Cruz',         'dispatcher@mulawin.com',  'Disp@1234',   2],  // Dispatcher
    ['maint1',      'Pedro Santos',           'maint@mulawin.com',       'Maint@1234',  3],  // Maintenance
    ['accounting1', 'Maria Garcia',           'acct@mulawin.com',        'Acct@1234',   4],  // Accounting
];

$sql = "INSERT IGNORE INTO users (role_id, username, full_name, email, password_hash)
        VALUES (:role_id, :username, :full_name, :email, :password_hash)";

$stmt = $pdo->prepare($sql);

foreach ($users as [$username, $full_name, $email, $password, $role_id]) {
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt->execute([
        ':role_id'       => $role_id,
        ':username'      => $username,
        ':full_name'     => $full_name,
        ':email'         => $email,
        ':password_hash' => $hash,
    ]);
    echo "Created user: $username (role_id: $role_id)<br>";
}

echo "<br><strong style='color:red'>⚠ DELETE THIS FILE NOW — it is a security risk!</strong>";
*/