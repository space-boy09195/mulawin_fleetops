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
require_once __DIR__ . '/../includes/document_storage.php';

header('Content-Type: application/json');

// Admin only, by the validated role key (isAdmin), not a legacy role grant.
// Unauthorized responses match requireRole(): login check first, then a 403
// JSON reply for JSON/AJAX requests, otherwise the 403 page.
requireLogin();
if (!isAdmin()) {
    if (isJsonRequest()) {
        sendJsonAccessResponse(403, 'You do not have permission to perform this action.');
    }
    http_response_code(403);
    include __DIR__ . '/../pages/403.php';
    exit;
}
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

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM deleted_records WHERE archive_id = ? AND restored_at IS NULL FOR UPDATE");
        $stmt->execute([$archiveId]);
        $archived = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$archived) {
            $pdo->rollBack();
            jsonFail('Archive record not found, or it was already restored.', 404);
        }

        // A purged document no longer needs its files row or stored file,
        // unless another document still points at the same file.
        $physicalPath = null;
        if ($archived['original_table'] === 'documents') {
            $payload = softDeletePayload((string)$archived['record_data']);
            $fileId = (int)($payload['row']['file_id'] ?? 0);
            if ($fileId > 0) {
                $fileQuery = $pdo->prepare(
                    'SELECT f.file_id, f.stored_name, f.storage_path,
                            (SELECT COUNT(*) FROM documents d WHERE d.file_id = f.file_id) AS live_documents
                     FROM files f WHERE f.file_id = ? FOR UPDATE'
                );
                $fileQuery->execute([$fileId]);
                $fileRow = $fileQuery->fetch(PDO::FETCH_ASSOC);
                if ($fileRow && (int)$fileRow['live_documents'] === 0) {
                    $physicalPath = documentStorageFile((string)$fileRow['stored_name'], $fileRow['storage_path']);
                    // Other target tables (expenses, receipts, inspection
                    // photos, ...) can also reference a file; RESTRICT / SET
                    // NULL keys decide. A RESTRICT failure keeps the file.
                    try {
                        $pdo->exec('SAVEPOINT purge_file');
                        $pdo->prepare('DELETE FROM files WHERE file_id = ?')->execute([$fileId]);
                    } catch (PDOException $e) {
                        $pdo->exec('ROLLBACK TO SAVEPOINT purge_file');
                        $physicalPath = null;
                    }
                }
            }
        }

        if (!permanentlyDeleteArchive($pdo, $archiveId)) {
            $pdo->rollBack();
            jsonFail('Could not permanently delete this record.');
        }
        auditLog('PURGE', 'deleted_records', $archiveId, ['original_table' => $archived['original_table'], 'original_id' => (int)$archived['original_id']], null);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('recycle_bin_handler/purge: ' . $e->getMessage());
        jsonFail('Could not permanently delete this record.', 500);
    }

    // The database change is committed; remove the stored file last so a
    // failed transaction never loses a file that is still referenced.
    if ($physicalPath !== null && is_file($physicalPath) && !unlink($physicalPath)) {
        error_log('recycle_bin_handler: could not purge a document file from storage');
        jsonFail('The record was purged, but its stored file could not be removed. Contact an administrator.', 500);
    }
    jsonOk([], 'Permanently deleted.');
}

jsonFail('Unknown action.');
