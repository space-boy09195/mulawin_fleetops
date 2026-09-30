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

    $documentData = null;
    $physicalPath = null;
    if ($archived['original_table'] === 'documents') {
        $documentData = json_decode($archived['record_data'], true);
        if (is_array($documentData) && !empty($documentData['stored_name'])) {
            $physicalPath = documentStorageFile(
                (string)$documentData['stored_name'],
                $documentData['file_path'] ?? null
            );
        }
    }

    $ok = permanentlyDeleteArchive($pdo, $archiveId);

    if ($ok) {
        $fileCleanupFailed = false;
        if ($archived['original_table'] === 'payroll_records') {
            try {
                $pdo->prepare('DELETE FROM payroll_deductions WHERE payroll_id = ?')
                    ->execute([(int)$archived['original_id']]);
            } catch (PDOException $e) {
                $fileCleanupFailed = true;
                error_log('recycle_bin_handler: could not purge payroll deduction details: ' . $e->getMessage());
            }
        }
        if ($archived['original_table'] === 'documents') {
            $pdo->prepare('DELETE FROM attachment_versions WHERE document_id = ?')
                ->execute([(int)$archived['original_id']]);
            $pdo->exec(
                'DELETE a FROM attachments a
                 LEFT JOIN attachment_versions av ON av.attachment_id = a.attachment_id
                 WHERE av.attachment_id IS NULL'
            );
            if ($physicalPath !== null && is_file($physicalPath) && !unlink($physicalPath)) {
                $fileCleanupFailed = true;
                error_log('recycle_bin_handler: could not purge document file ' . $physicalPath);
            }
        }
        auditLog('PURGE', 'deleted_records', $archiveId);
        if ($fileCleanupFailed) {
            jsonFail('The record was purged, but related data could not be fully removed. Contact an administrator.', 500);
        }
        jsonOk([], 'Permanently deleted.');
    }

    jsonFail('Could not permanently delete this record.');
}

jsonFail('Unknown action.');
