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

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── Restore ────────────────────────────────────────────────────────────────
if ($action === 'restore') {
    $archiveId = (int)($_POST['archive_id'] ?? 0);
    if (!$archiveId) {
        echo json_encode(['success' => false, 'message' => 'Invalid archive ID.']);
        exit;
    }

    $result = restoreRecord($pdo, $archiveId);

    if ($result['success']) {
        auditLog('RESTORE', 'deleted_records', $archiveId);
    }

    echo json_encode($result);
    exit;
}

// ── Permanently delete ────────────────────────────────────────────────────────
if ($action === 'purge') {
    $archiveId = (int)($_POST['archive_id'] ?? 0);
    if (!$archiveId) {
        echo json_encode(['success' => false, 'message' => 'Invalid archive ID.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM deleted_records WHERE archive_id = ? AND restored_at IS NULL");
    $stmt->execute([$archiveId]);
    $archived = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$archived) {
        echo json_encode(['success' => false, 'message' => 'Archive record not found, or it was already restored.']);
        exit;
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
        echo json_encode(['success' => true, 'message' => 'Permanently deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not permanently delete this record.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);