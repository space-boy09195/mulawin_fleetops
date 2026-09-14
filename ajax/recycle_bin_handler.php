<?php
// ============================================================
// ajax/recycle_bin_handler.php
// action=restore — Head Management only
// action=purge   — Head Management only (permanent, no further recovery)
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/soft_delete.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT]);
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Restore ────────────────────────────────────────────────────────────────
if ($action === 'restore') {
    $archiveId = requiredInt('archive_id', 'Archive ID', 1);

    $result = restoreRecord($pdo, $archiveId);

    if ($result['success']) {
        auditLog('RESTORE', 'deleted_records', $archiveId);
    }

    echo json_encode($result);
    exit;
}

// ── Permanently delete ────────────────────────────────────────────────────────
if ($action === 'purge') {
    $archiveId = requiredInt('archive_id', 'Archive ID', 1);

    $stmt = $pdo->prepare("SELECT * FROM deleted_records WHERE archive_id = ? AND restored_at IS NULL");
    $stmt->execute([$archiveId]);
    $archived = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$archived) {
        jsonFail('Archive record not found, or it was already restored.', 404);
    }

    // Documents keep their physical file on disk until this point — a
    // permanent purge is the point of no return, so the file goes too.
    if ($archived['original_table'] === 'documents') {
        $data = json_decode($archived['record_data'], true);
        if (is_array($data) && !empty($data['stored_name'])) {
            $physicalPath = dirname(__DIR__) . '/uploads/' . $data['stored_name'];
            if (file_exists($physicalPath)) {
                unlink($physicalPath);
            }
        }
    }

    $ok = permanentlyDeleteArchive($pdo, $archiveId);

    if ($ok) {
        auditLog('PURGE', 'deleted_records', $archiveId);
        jsonOk([], 'Permanently deleted.');
    }

    jsonFail('Could not permanently delete this record.');
}

jsonFail('Unknown action.');
