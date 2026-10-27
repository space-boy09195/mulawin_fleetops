<?php
// ============================================================
// ajax/notifications_handler.php
// action=clear — marks the signed-in user's unread notifications as read
// (silences the bell badge; nothing is deleted)
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/shared/validate.php';

header('Content-Type: application/json');

requireLogin();
requirePostMethod();
enforceCsrf();

if (($_POST['action'] ?? $_GET['action'] ?? '') !== 'clear') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

try {
    $stmt = getDBConnection()->prepare(
        'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0'
    );
    $stmt->execute([currentUserId()]);
    echo json_encode(['success' => true, 'message' => 'Notifications cleared.']);
} catch (PDOException $e) {
    error_log('Unable to clear notifications: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not clear notifications.']);
}
