<?php
// ============================================================
// includes/soft_delete.php
// Shared "soft delete" archive system (target table deleted_records, T-S1).
//
// Instead of a hard DELETE, records are snapshotted into
// `deleted_records` (full row, as JSON) and only then removed from
// their original table. This means:
//   - Nothing is ever permanently lost by a normal delete action
//   - There's always a record of who deleted it and when
//   - Anything can be restored later from the Recycle Bin page
//
// Only tables listed in SOFT_DELETE_TABLES can be archived or restored.
// Each entry names the primary-key column and the child rows that the
// target foreign keys would remove with ON DELETE CASCADE; those children
// are archived with the parent and restored after it, so a restore brings
// back the complete record.
//
// record_data format:
//   {"__format":2,"row":{...},"children":{"table":[{...},...]}}
// Archives written before this format hold the bare row; restoreRecord()
// still accepts them.
// ============================================================

const SOFT_DELETE_TABLES = [
    'announcements' => [
        'id'       => 'announcement_id',
        'children' => [],
    ],
    'documents' => [
        'id'       => 'document_id',
        'children' => ['document_links' => 'document_id'],
    ],
    'payroll_records' => [
        'id'       => 'payroll_record_id',
        'children' => [
            'payroll_deductions'    => 'payroll_record_id',
            'payslips'              => 'payroll_record_id',
            'payroll_disbursements' => ['column' => 'reference_number', 'prefix' => 'PR-REC-'],
        ],
    ],
];

function softDeleteConfig(string $table): array {
    if (!isset(SOFT_DELETE_TABLES[$table])) {
        throw new InvalidArgumentException('Table is not enabled for soft delete: ' . $table);
    }
    return SOFT_DELETE_TABLES[$table];
}

// Column names of an allowlisted table, read from the live table so a
// restore never names a column the table does not have.
function softDeleteTableColumns(PDO $pdo, string $table): array {
    if (!isset(SOFT_DELETE_TABLES[$table]) && !softDeleteIsChildTable($table)) {
        throw new InvalidArgumentException('Table is not enabled for soft delete: ' . $table);
    }
    $stmt = $pdo->query("SELECT * FROM `$table` LIMIT 0");
    $columns = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) {
        $meta = $stmt->getColumnMeta($i);
        $columns[] = $meta['name'];
    }
    return $columns;
}


// WHERE clause + params selecting the child rows of one parent. A child spec
// is either the foreign-key column name, or ['column' => ..., 'prefix' => ...]
// for rows whose column holds "<prefix><id>" optionally followed by a space
// and free text (payroll disbursements reference PR-REC-<id>).
function softDeleteChildCondition($spec, int $id): array {
    if (is_string($spec)) {
        return ["`$spec` = ?", [$id]];
    }
    $column = $spec['column'];
    $reference = $spec['prefix'] . $id;
    return ["(`$column` = ? OR `$column` LIKE ?)", [$reference, $reference . ' %']];
}

function softDeleteIsChildTable(string $table): bool {
    foreach (SOFT_DELETE_TABLES as $config) {
        if (isset($config['children'][$table])) {
            return true;
        }
    }
    return false;
}

// Snapshots a row (and its cascading children) into deleted_records, then
// removes it from its original table. Returns true on success, false if the
// row wasn't found or the operation failed (errors are logged, not thrown).
// Joins the caller's transaction when one is open. $displayLabel is an
// optional Recycle Bin label (for example a document's file name, which the
// target keeps in files rather than in the archived row).
function archiveAndDelete(PDO $pdo, string $table, string $idColumn, int $id, int $deletedByUserId, string $deletedByName, ?string $displayLabel = null): bool {
    try {
        $config = softDeleteConfig($table);
    } catch (InvalidArgumentException $e) {
        error_log('archiveAndDelete refused: ' . $e->getMessage());
        return false;
    }
    if ($idColumn !== $config['id']) {
        error_log("archiveAndDelete refused: {$table} is keyed by {$config['id']}, not {$idColumn}.");
        return false;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `{$config['id']}` = ? FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        $children = [];
        foreach ($config['children'] as $childTable => $childSpec) {
            [$childWhere, $childParams] = softDeleteChildCondition($childSpec, $id);
            $childQuery = $pdo->prepare("SELECT * FROM `$childTable` WHERE $childWhere FOR UPDATE");
            $childQuery->execute($childParams);
            $children[$childTable] = $childQuery->fetchAll(PDO::FETCH_ASSOC);
        }

        $payload = json_encode(
            ['__format' => 2, 'row' => $row, 'children' => $children, 'display' => $displayLabel],
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        $pdo->prepare("
            INSERT INTO deleted_records
                (original_table, original_id, record_data, deleted_by, deleted_by_name)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$table, $id, $payload, $deletedByUserId, mb_substr($deletedByName, 0, 150, 'UTF-8')]);

        // Children go first so the parent DELETE never depends on CASCADE.
        foreach ($config['children'] as $childTable => $childSpec) {
            [$childWhere, $childParams] = softDeleteChildCondition($childSpec, $id);
            $pdo->prepare("DELETE FROM `$childTable` WHERE $childWhere")->execute($childParams);
        }
        $pdo->prepare("DELETE FROM `$table` WHERE `{$config['id']}` = ?")->execute([$id]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("archiveAndDelete failed for {$table}#{$id}: " . $e->getMessage());
        if (!$ownsTransaction) {
            // The caller's transaction may be unusable now; let it roll back.
            throw $e;
        }
        return false;
    }
}

// Decoded archive payload: ['row' => [...], 'children' => [table => rows]].
function softDeletePayload(string $recordData): ?array {
    $data = json_decode($recordData, true);
    if (!is_array($data)) {
        return null;
    }
    if (($data['__format'] ?? null) === 2) {
        return [
            'row'      => is_array($data['row'] ?? null) ? $data['row'] : [],
            'children' => is_array($data['children'] ?? null) ? $data['children'] : [],
        ];
    }
    return ['row' => $data, 'children' => []];
}

function softDeleteInsertRow(PDO $pdo, string $table, array $row): void {
    $allowed = array_flip(softDeleteTableColumns($pdo, $table));
    foreach (array_keys($row) as $column) {
        if (!is_string($column) || !isset($allowed[$column])) {
            throw new RuntimeException("Archived column {$table}.{$column} does not exist in the current schema.");
        }
    }
    if (!$row) {
        throw new RuntimeException('Archived row is empty.');
    }
    $columns      = array_keys($row);
    $columnList   = implode(', ', array_map(fn($c) => "`$c`", $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $pdo->prepare("INSERT INTO `$table` ($columnList) VALUES ($placeholders)")
        ->execute(array_values($row));
}

// Re-inserts an archived record (and its archived children) back into its
// original table using the exact same column values it had when deleted,
// then marks the archive entry as restored (kept for history, not removed).
function restoreRecord(PDO $pdo, int $archiveId): array {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM deleted_records WHERE archive_id = ? AND restored_at IS NULL FOR UPDATE");
        $stmt->execute([$archiveId]);
        $archived = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$archived) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Archive record not found, or it was already restored.'];
        }

        $table = (string)$archived['original_table'];
        if (!isset(SOFT_DELETE_TABLES[$table])) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Records from this table cannot be restored.'];
        }
        $config  = SOFT_DELETE_TABLES[$table];
        $payload = softDeletePayload((string)$archived['record_data']);
        if ($payload === null || !$payload['row']) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Archived data is corrupted and cannot be restored.'];
        }

        softDeleteInsertRow($pdo, $table, $payload['row']);
        foreach ($payload['children'] as $childTable => $rows) {
            if (!isset($config['children'][$childTable]) || !is_array($rows)) {
                throw new RuntimeException("Unexpected archived child table {$childTable}.");
            }
            foreach ($rows as $childRow) {
                if (!is_array($childRow)) {
                    throw new RuntimeException('Archived child row is corrupted.');
                }
                softDeleteInsertRow($pdo, $childTable, $childRow);
            }
        }

        $pdo->prepare("UPDATE deleted_records SET restored_at = NOW() WHERE archive_id = ?")
            ->execute([$archiveId]);

        $pdo->commit();
        return ['success' => true, 'message' => 'Record restored successfully.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
    $stmt = $pdo->prepare("DELETE FROM deleted_records WHERE archive_id = ? AND restored_at IS NULL");
    $stmt->execute([$archiveId]);
    return $stmt->rowCount() === 1;
}

// Produces a short, human-readable one-line summary of an archived
// record for display in the Recycle Bin list, based on which table it
// came from. Falls back to a generic label for anything not covered.
// $data may be a bare row (old archives) or the format-2 payload.
function summarizeArchivedRecord(string $table, array $data): string {
    if (($data['__format'] ?? null) === 2) {
        $row = is_array($data['row'] ?? null) ? $data['row'] : [];
        $data = $row + ['__display' => $data['display'] ?? null];
    }
    switch ($table) {
        case 'announcements':
            return (string)($data['title'] ?? 'Untitled announcement');
        case 'documents':
            return (string)($data['__display'] ?? $data['file_name'] ?? $data['description'] ?? $data['document_category'] ?? 'Unnamed file');
        case 'payroll_records':
            return 'Payroll record #' . (int)($data['payroll_record_id'] ?? $data['payroll_id'] ?? 0);
        default:
            return "{$table} record";
    }
}
