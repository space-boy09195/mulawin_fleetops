<?php

function findActiveTripCompletionReport(PDO $pdo, int $tripId, bool $forUpdate = false): ?array {
    $sql = "SELECT report_id, status, reported_at
            FROM trip_completion_reports
            WHERE trip_id = ? AND status IN ('Pending', 'Acknowledged')
            ORDER BY reported_at DESC
            LIMIT 1";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tripId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
