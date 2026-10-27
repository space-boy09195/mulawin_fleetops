<?php
// ============================================================
// includes/attachments.php
// Links documents to business records through the target document_links
// table (document_id, entity_type, entity_id; unique per triple). The legacy
// attachments / attachment_versions tables do not exist in the target:
// every upload is its own document, linked to as many records as needed.
// ============================================================

// entity_type => [table, primary key]. Only these record types can be
// linked; the identifiers come from this map, never from input.
const DOCUMENT_LINK_ENTITIES = [
    'trip'               => ['trips', 'trip_id'],
    'truck'              => ['trucks', 'truck_id'],
    'employee'           => ['employees', 'employee_id'],
    'billing'            => ['billings', 'billing_id'],
    'maintenance_record' => ['maintenance_records', 'maintenance_id'],
    'trip_incident'      => ['trip_incidents', 'incident_id'],
    'purchase_order'     => ['purchase_orders', 'purchase_order_id'],
];

/**
 * Link a document to a record. Linking the same pair twice is a no-op.
 */
function linkDocumentToEntity(PDO $pdo, int $documentId, string $entityType, int $entityId): void {
    if (!isset(DOCUMENT_LINK_ENTITIES[$entityType])) {
        throw new InvalidArgumentException('Unsupported attachment entity type.');
    }
    [$table, $idColumn] = DOCUMENT_LINK_ENTITIES[$entityType];
    $entityQuery = $pdo->prepare("SELECT 1 FROM `$table` WHERE `$idColumn` = ? LIMIT 1");
    $entityQuery->execute([$entityId]);
    if (!$entityQuery->fetchColumn()) {
        throw new InvalidArgumentException('The attachment target does not exist.');
    }

    $pdo->prepare(
        'INSERT INTO document_links (document_id, entity_type, entity_id)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE document_id = document_id'
    )->execute([$documentId, $entityType, $entityId]);
}

/**
 * True when a document is linked to at least one record of $entityType.
 */
function documentHasLink(PDO $pdo, int $documentId, string $entityType): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM document_links WHERE document_id = ? AND entity_type = ? LIMIT 1'
    );
    $stmt->execute([$documentId, $entityType]);
    return (bool)$stmt->fetchColumn();
}
