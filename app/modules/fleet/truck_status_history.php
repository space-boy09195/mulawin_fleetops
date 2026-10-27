<?php
// Truck status history on the target schema: workflow_events rows with
// entity_type 'truck' and event_type 'truck_status' (there is no
// truck_status_history table in the target).

require_once __DIR__ . '/../../../includes/workflow_events.php';

const TRUCK_STATUS_EVENT_TYPE = 'truck_status';

function recordTruckStatusHistory(
    PDO $pdo,
    int $truckId,
    ?string $previousStatus,
    string $newStatus,
    string $reason,
    ?int $changedBy = null
): void {
    if ($previousStatus === $newStatus) {
        return;
    }
    $reason = mb_substr(trim($reason), 0, 500);
    if ($reason === '') {
        throw new InvalidArgumentException('A reason is required for a truck status change.');
    }
    recordWorkflowEvent(
        $pdo,
        'truck',
        $truckId,
        TRUCK_STATUS_EVENT_TYPE,
        $previousStatus,
        $newStatus,
        $reason,
        $changedBy ?? currentUserId()
    );
}
