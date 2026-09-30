<?php

function attachDocumentToEntity(
    PDO $pdo,
    int $documentId,
    string $entityType,
    int $entityId,
    string $category,
    int $linkedBy,
    ?string $description = null
): int {
    $entityTables = [
        'trip' => ['trips', 'trip_id'],
        'truck' => ['trucks', 'truck_id'],
        'employee' => ['employees', 'employee_id'],
        'billing' => ['billings', 'billing_id'],
        'maintenance_record' => ['maintenance_records', 'record_id'],
    ];
    if (!isset($entityTables[$entityType])) {
        throw new InvalidArgumentException('Unsupported attachment entity type.');
    }
    if ($category === '' || mb_strlen($category) > 80) {
        throw new InvalidArgumentException('Attachment category must be between 1 and 80 characters.');
    }

    [$table, $idColumn] = $entityTables[$entityType];
    $entityQuery = $pdo->prepare("SELECT 1 FROM `$table` WHERE `$idColumn` = ? LIMIT 1");
    $entityQuery->execute([$entityId]);
    if (!$entityQuery->fetchColumn()) {
        throw new InvalidArgumentException('The attachment target does not exist.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare(
            'INSERT INTO attachments
                (entity_type, entity_id, category, description, linked_by)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$entityType, $entityId, $category, $description, $linkedBy]);
        $attachmentId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO attachment_versions
                (attachment_id, document_id, version_number, uploaded_by)
             VALUES (?, ?, 1, ?)'
        )->execute([$attachmentId, $documentId, $linkedBy]);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $attachmentId;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function addAttachmentVersion(PDO $pdo, int $attachmentId, int $documentId, int $uploadedBy): int {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $lock = $pdo->prepare('SELECT attachment_id FROM attachments WHERE attachment_id = ? FOR UPDATE');
        $lock->execute([$attachmentId]);
        if (!$lock->fetchColumn()) {
            throw new InvalidArgumentException('Attachment record not found.');
        }

        $versionQuery = $pdo->prepare(
            'SELECT COALESCE(MAX(version_number), 0)
             FROM attachment_versions
             WHERE attachment_id = ?'
        );
        $versionQuery->execute([$attachmentId]);
        $nextVersion = (int)$versionQuery->fetchColumn() + 1;

        $pdo->prepare(
            'INSERT INTO attachment_versions
                (attachment_id, document_id, version_number, uploaded_by)
             VALUES (?, ?, ?, ?)'
        )->execute([$attachmentId, $documentId, $nextVersion, $uploadedBy]);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $nextVersion;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
