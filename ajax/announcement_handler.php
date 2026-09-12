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
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

// Only Head Management can post, edit, or delete
requireRole([ROLE_HEAD_MANAGEMENT]);
requirePostMethod();
enforceCsrf();

// NOTE: action is read from $_GET here (not $_POST) — matches the
// existing frontend, which calls e.g. announcement_handler.php?action=add
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
    return in_array($raw, ANNOUNCEMENT_PRIORITIES, true) ? $raw : 'medium';
}

function readAudience(array $post): string {
    $raw = strtolower(trim($post['audience'] ?? 'all'));
    if (!in_array($raw, ANNOUNCEMENT_AUDIENCES, true)) {
        jsonFail('Audience must be one of: ' . implode(', ', ANNOUNCEMENT_AUDIENCES) . '.');
    }
    return $raw;
}

/**
 * Parse the datetime-local value sent by the browser and reject invalid or
 * already elapsed values. SQL receives a consistent DATETIME string.
 */
function readAnnouncementDateTime(array $post, string $key, string $label): string {
    $raw = trim($post[$key] ?? '');
    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $raw);
    $errors = DateTime::getLastErrors();
    if (
        $raw === ''
        || $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d\TH:i') !== $raw
    ) {
        jsonFail($label . ' must be a valid date and time.');
    }
    if ($date < new DateTime()) {
        jsonFail($label . ' cannot be in the past.');
    }
    return $date->format('Y-m-d H:i:s');
}

function readAnnouncementWindow(array $post): array {
    $startsAt = readAnnouncementDateTime($post, 'starts_at', 'Start date and time');
    $endsAt   = readAnnouncementDateTime($post, 'ends_at', 'End date and time');
    if (strtotime($endsAt) <= strtotime($startsAt)) {
        jsonFail('End date and time must be after the start date and time.');
    }
    return [$startsAt, $endsAt];
}

// ---- ADD --------------------------------------------------
if ($action === 'add') {
    $title    = requiredString('title', 'Title', 200);
    $body     = requiredString('body', 'Message');
    $priority = readPriority($_POST);
    $isPinned = readPinnedFlag($_POST);
    $audience = readAudience($_POST);
    [$startsAt, $endsAt] = readAnnouncementWindow($_POST);

    $stmt = $pdo->prepare(
        "INSERT INTO announcements
            (created_by, title, body, priority, is_pinned, audience, starts_at, ends_at)
         VALUES (:user, :title, :body, :priority, :pinned, :audience, :starts_at, :ends_at)"
    );
    $stmt->execute([
        ':user'     => currentUserId(),
        ':title'    => $title,
        ':body'     => $body,
        ':priority' => $priority,
        ':pinned'   => $isPinned,
        ':audience' => $audience,
        ':starts_at' => $startsAt,
        ':ends_at'   => $endsAt,
    ]);
    $newId = (int)$pdo->lastInsertId();
    auditLog('CREATE', 'announcements', $newId);

    jsonOk([], 'Announcement posted.');
}

// ---- EDIT ---------------------------------------------------
if ($action === 'edit') {
    $id       = requiredInt('announcement_id', 'Announcement ID', 1);
    $title    = requiredString('title', 'Title', 200);
    $body     = requiredString('body', 'Message');
    $priority = readPriority($_POST);
    $isPinned = readPinnedFlag($_POST);
    $audience = readAudience($_POST);
    [$startsAt, $endsAt] = readAnnouncementWindow($_POST);

    $oldData = findOrFail($pdo, 'announcements', 'announcement_id', $id, 'Announcement not found.');

    $pdo->prepare(
        "UPDATE announcements
            SET title = :title, body = :body, priority = :priority, is_pinned = :pinned,
                audience = :audience, starts_at = :starts_at, ends_at = :ends_at
          WHERE announcement_id = :id"
    )->execute([
        ':title'    => $title,
        ':body'     => $body,
        ':priority' => $priority,
        ':pinned'   => $isPinned,
        ':audience' => $audience,
        ':starts_at' => $startsAt,
        ':ends_at'   => $endsAt,
        ':id'       => $id,
    ]);

    auditLog('UPDATE', 'announcements', $id, $oldData, [
        'title'     => $title,
        'body'      => $body,
        'priority'  => $priority,
        'is_pinned' => $isPinned,
        'audience'  => $audience,
        'starts_at' => $startsAt,
        'ends_at'   => $endsAt,
    ]);

    jsonOk([], 'Announcement updated.');
}

// ---- DELETE -----------------------------------------------
if ($action === 'delete') {
    $id = requiredInt('announcement_id', 'Announcement ID', 1);

    $deletedByName = $_SESSION['full_name'] ?? 'Unknown user';
    $archived = archiveAndDelete($pdo, 'announcements', 'announcement_id', $id, currentUserId(), $deletedByName);

    if (!$archived) {
        jsonFail('Announcement not found or could not be deleted.', 404);
    }

    auditLog('DELETE', 'announcements', $id);
    jsonOk([], 'Deleted. It can be restored from the Recycle Bin if needed.');
}

jsonFail('Unknown action.');
