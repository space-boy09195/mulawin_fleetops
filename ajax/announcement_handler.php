<?php
// ============================================================
// ajax/announcement_handler.php
// action=add    — Head Management only
// action=delete — Head Management only
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// Only Head Management can post or delete
if (currentRoleId() !== ROLE_HEAD_MANAGEMENT) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

$action = $_GET['action'] ?? '';
$pdo    = getDBConnection();

// ---- ADD --------------------------------------------------
if ($action === 'add') {
    $title    = trim($_POST['title']     ?? '');
    $body     = trim($_POST['body']      ?? '');
    $isPinned = isset($_POST['is_pinned']) ? 1 : 0;

    if ($title === '' || $body === '') {
        echo json_encode(['success' => false, 'message' => 'Title and message are required.']);
        exit;
    }
    if (mb_strlen($title) > 200) {
        echo json_encode(['success' => false, 'message' => 'Title is too long (max 200 characters).']);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO announcements (created_by, title, body, is_pinned)
         VALUES (:user, :title, :body, :pinned)"
    );
    $stmt->execute([
        ':user'   => currentUserId(),
        ':title'  => $title,
        ':body'   => $body,
        ':pinned' => $isPinned,
    ]);
    $newId = (int)$pdo->lastInsertId();
    auditLog('CREATE', 'announcements', $newId);

    echo json_encode(['success' => true, 'message' => 'Announcement posted.']);
    exit;
}

// ---- DELETE -----------------------------------------------
if ($action === 'delete') {
    $id = filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
        exit;
    }

    $pdo->prepare("DELETE FROM announcements WHERE announcement_id = :id")
        ->execute([':id' => $id]);

    auditLog('DELETE', 'announcements', $id);
    echo json_encode(['success' => true, 'message' => 'Deleted.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);