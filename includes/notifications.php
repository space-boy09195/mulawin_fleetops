<?php

require_once __DIR__ . '/../config/database.php';

function createNotification(PDO $pdo, int $userId, string $title, string $message, ?string $link = null): void {
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $title, $message, $link]);
}

function notifyUsersWithPermission(
    PDO $pdo,
    string $permissionKey,
    string $title,
    string $message,
    ?string $link = null
): void {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT u.user_id
         FROM users u
         JOIN role_permissions rp ON rp.role_id = u.role_id
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE u.is_active = 1 AND p.permission_key = ?'
    );
    $stmt->execute([$permissionKey]);
    $insert = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        $insert->execute([(int)$userId, $title, $message, $link]);
    }
}
