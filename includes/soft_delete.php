<?php
// ============================================================
// includes/soft_delete.php
// Shared "soft delete" archive system.
//
// Instead of a hard DELETE, records are snapshotted into
// `deleted_records` (full row, as JSON) and only then removed from
// their original table. This means:
//   - Nothing is ever permanently lost by a normal delete action
//   - There's always a record of who deleted it and when
//   - Anything can be restored later from the Recycle Bin page
//
// Use archiveAndDelete() in place of a raw "DELETE FROM ..." query.
// ============================================================

// Snapshots a row into deleted_records, then removes it from its
// original table. Returns true on success, false if the row wasn't
// found or the operation failed (errors are logged, not thrown).
function archiveAndDelete(PDO $pdo, string $table, string $idColumn, int $id, int $deletedByUserId, string $deletedByName): bool {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$idColumn` = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            return false;
        }

        $pdo->prepare("
            INSERT INTO deleted_records
                (original_table, original_id, record_data, deleted_by, deleted_by_name)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$table, $id, json_encode($row), $deletedByUserId, $deletedByName]);

        $pdo->prepare("DELETE FROM `$table` WHERE `$idColumn` = ?")->execute([$id]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("archiveAndDelete failed for {$table}#{$id}: " . $e->getMessage());
        return false;
    }
}

// Re-inserts an archived record back into its original table using the
// exact same column values it had when deleted, then marks the archive
// entry as restored (kept for history, not removed).
function restoreRecord(PDO $pdo, int $archiveId): array {
    $stmt = $pdo->prepare("SELECT * FROM deleted_records WHERE archive_id = ? AND restored_at IS NULL");
    $stmt->execute([$archiveId]);
    $archived = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$archived) {
        return ['success' => false, 'message' => 'Archive record not found, or it was already restored.'];
    }

    $table = $archived['original_table'];
    $data  = json_decode($archived['record_data'], true);

    if (!is_array($data)) {
        return ['success' => false, 'message' => 'Archived data is corrupted and cannot be restored.'];
    }

    $columns      = array_keys($data);
    $columnList   = implode(', ', array_map(fn($c) => "`$c`", $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO `$table` ($columnList) VALUES ($placeholders)")
            ->execute(array_values($data));

        $pdo->prepare("UPDATE deleted_records SET restored_at = NOW() WHERE archive_id = ?")
            ->execute([$archiveId]);

        $pdo->commit();
        return ['success' => true, 'message' => 'Record restored successfully.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("restoreRecord failed for archive#{$archiveId}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Could not restore this record — a related item it depends on may no longer exist, or its original ID is now in use.',
        ];
    }
}

// Permanently removes an archived entry (the safety net itself gets
// deleted here — no further recovery possible after this).
function permanentlyDeleteArchive(PDO $pdo, int $archiveId): bool {
    return $pdo->prepare("DELETE FROM deleted_records WHERE archive_id = ?")->execute([$archiveId]);
}

// Produces a short, human-readable one-line summary of an archived
// record for display in the Recycle Bin list, based on which table it
// came from. Falls back to a generic label for anything not covered.
function summarizeArchivedRecord(string $table, array $data): string {
    switch ($table) {
        case 'announcements':
            return $data['title'] ?? 'Untitled announcement';
        case 'documents':
            return $data['file_name'] ?? 'Unnamed file';
        default:
            return "{$table} record";
    }
}