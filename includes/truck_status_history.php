<?php

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
    $stmt = $pdo->prepare(
        'INSERT INTO truck_status_history
            (truck_id, previous_status, new_status, reason, changed_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $truckId,
        $previousStatus,
        $newStatus,
        $reason,
        $changedBy ?? currentUserId(),
    ]);
}
