<?php
// ============================================================
// ajax/announcement_handler.php
// action=add    — Head Management only
// action=edit   — Head Management only
// action=delete — Head Management only
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/soft_delete.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// Only Head Management can post, edit, or delete
if (currentRoleId() !== ROLE_HEAD_MANAGEMENT) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

enforceCsrf();

$action = $_GET['action'] ?? '';
$pdo    = getDBConnection();

// Reads a pinned flag from POST as an actual value, not just presence.
// (isset() alone is unreliable here — some clients always send the field,
// e.g. as '0' when unchecked, which would make isset() true regardless.)
function readPinnedFlag(array $post): int {
    $raw = $post['is_pinned'] ?? '0';
    return in_array($raw, [1, '1', 'true', 'on'], true) ? 1 : 0;
}

// Validates the priority level, defaulting to 'medium' for anything unexpected.
function readPriority(array $post): string {
    $raw = $post['priority'] ?? 'medium';
    return in_array($raw, ['high', 'medium', 'low'], true) ? $raw : 'medium';
}

// ---- ADD --------------------------------------------------
if ($action === 'add') {
    $title    = trim($_POST['title']     ?? '');
    $body     = trim($_POST['body']      ?? '');
    $priority = readPriority($_POST);
    $isPinned = readPinnedFlag($_POST);

    if ($title === '' || $body === '') {
        echo json_encode(['success' => false, 'message' => 'Title and message are required.']);
        exit;
    }
    if (mb_strlen($title) > 200) {
        echo json_encode(['success' => false, 'message' => 'Title is too long (max 200 characters).']);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO announcements (created_by, title, body, priority, is_pinned)
         VALUES (:user, :title, :body, :priority, :pinned)"
    );
    $stmt->execute([
        ':user'     => currentUserId(),
        ':title'    => $title,
        ':body'     => $body,
        ':priority' => $priority,
        ':pinned'   => $isPinned,
    ]);
    $newId = (int)$pdo->lastInsertId();
    auditLog('CREATE', 'announcements', $newId);

    echo json_encode(['success' => true, 'message' => 'Announcement posted.']);
    exit;
}

// ---- EDIT ---------------------------------------------------
if ($action === 'edit') {
    $id       = filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);
    $title    = trim($_POST['title'] ?? '');
    $body     = trim($_POST['body']  ?? '');
    $priority = readPriority($_POST);
    $isPinned = readPinnedFlag($_POST);

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
        exit;
    }
    if ($title === '' || $body === '') {
        echo json_encode(['success' => false, 'message' => 'Title and message are required.']);
        exit;
    }
    if (mb_strlen($title) > 200) {
        echo json_encode(['success' => false, 'message' => 'Title is too long (max 200 characters).']);
        exit;
    }

    $old = $pdo->prepare("SELECT title, body, priority, is_pinned FROM announcements WHERE announcement_id = :id");
    $old->execute([':id' => $id]);
    $oldData = $old->fetch(PDO::FETCH_ASSOC);

    if (!$oldData) {
        echo json_encode(['success' => false, 'message' => 'Announcement not found.']);
        exit;
    }

    $pdo->prepare(
        "UPDATE announcements
            SET title = :title, body = :body, priority = :priority, is_pinned = :pinned
          WHERE announcement_id = :id"
    )->execute([
        ':title'    => $title,
        ':body'     => $body,
        ':priority' => $priority,
        ':pinned'   => $isPinned,
        ':id'       => $id,
    ]);

    auditLog('UPDATE', 'announcements', $id, $oldData, [
        'title'     => $title,
        'body'      => $body,
        'priority'  => $priority,
        'is_pinned' => $isPinned,
    ]);

    echo json_encode(['success' => true, 'message' => 'Announcement updated.']);
    exit;
}

// ---- DELETE -----------------------------------------------
if ($action === 'delete') {
    $id = filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
        exit;
    }

    $deletedByName = $_SESSION['full_name'] ?? 'Unknown user';
    $archived = archiveAndDelete($pdo, 'announcements', 'announcement_id', $id, currentUserId(), $deletedByName);

    if (!$archived) {
        echo json_encode(['success' => false, 'message' => 'Announcement not found or could not be deleted.']);
        exit;
    }

    auditLog('DELETE', 'announcements', $id);
    echo json_encode(['success' => true, 'message' => 'Deleted. It can be restored from the Recycle Bin if needed.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);