<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE . '/login.php');
    exit;
}

enforceCsrf();

$username = strtolower(trim((string)($_POST['username'] ?? '')));
$password = (string)($_POST['password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

if ($username === '' || strlen($password) < 8 || $password !== $confirm) {
    header('Location: ' . APP_BASE . '/login.php?reset=invalid');
    exit;
}

$pdo = getDBConnection();
$generic = APP_BASE . '/login.php?reset=submitted';

try {
    $stmt = $pdo->prepare('SELECT user_id, role_id, is_active FROM users WHERE LOWER(username) = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !(int)$user['is_active'] || (int)$user['role_id'] === ROLE_HEAD_MANAGEMENT) {
        header('Location: ' . $generic);
        exit;
    }

    $recent = $pdo->prepare(
        "SELECT COUNT(*) FROM password_reset_requests
         WHERE user_id = ? AND requested_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $recent->execute([(int)$user['user_id']]);
    if ((int)$recent->fetchColumn() >= 3) {
        header('Location: ' . $generic);
        exit;
    }

    $pending = $pdo->prepare("SELECT request_id FROM password_reset_requests WHERE user_id = ? AND status = 'Pending' LIMIT 1");
    $pending->execute([(int)$user['user_id']]);
    if (!$pending->fetchColumn()) {
        $insert = $pdo->prepare(
            'INSERT INTO password_reset_requests (user_id, password_hash, requested_ip) VALUES (?, ?, ?)'
        );
        $insert->execute([
            (int)$user['user_id'],
            password_hash($password, PASSWORD_DEFAULT),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
        auditLog('PASSWORD_RESET_REQUEST', 'password_reset_requests', (int)$pdo->lastInsertId());
    }
} catch (PDOException $e) {
    error_log('forgot_password_request: ' . $e->getMessage());
}

header('Location: ' . $generic);
exit;
