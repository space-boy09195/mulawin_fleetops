<?php
// ============================================================
// includes/workflow_events.php
// Target workflow_events table: one row per status change or workflow step
// of any record (entity_type + entity_id). Used for trip workflow history
// and truck status history, which have no dedicated target tables.
// ============================================================

const WORKFLOW_EVENT_ENTITY_TYPES = ['trip', 'truck', 'dispatch_request', 'maintenance', 'trip_incident', 'trip_completion'];

function recordWorkflowEvent(
    PDO $pdo,
    string $entityType,
    int $entityId,
    string $eventType,
    ?string $oldStatus,
    ?string $newStatus,
    ?string $remarks = null,
    ?int $performedBy = null
): void {
    if (!in_array($entityType, WORKFLOW_EVENT_ENTITY_TYPES, true)) {
        throw new InvalidArgumentException('Unsupported workflow event entity type.');
    }
    if ($eventType === '' || strlen($eventType) > 80) {
        throw new InvalidArgumentException('Workflow event type must be 1-80 characters.');
    }
    $performedBy ??= (function_exists('currentUserId') ? (currentUserId() ?: null) : null);
    $pdo->prepare(
        'INSERT INTO workflow_events
            (entity_type, entity_id, event_type, old_status, new_status, performed_by, remarks)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $entityType,
        $entityId,
        $eventType,
        $oldStatus === null ? null : mb_substr($oldStatus, 0, 80, 'UTF-8'),
        $newStatus === null ? null : mb_substr($newStatus, 0, 80, 'UTF-8'),
        $performedBy,
        $remarks,
    ]);
}
