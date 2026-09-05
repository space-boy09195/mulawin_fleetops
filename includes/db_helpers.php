<?php
// ============================================================
// includes/db_helpers.php
// Small, reusable query helpers for common patterns that were
// being hand-written slightly differently in each ajax/*.php
// handler (fetch-by-id-or-fail, uniqueness checks). This is
// NOT an ORM — still plain PDO and prepared statements, just
// without re-typing the same three lines in 16 different files.
// ============================================================

/**
 * Fetch a single row by its primary key, or send a JSON 404
 * failure and stop execution if it doesn't exist.
 *
 * Example:
 *   $truck = findOrFail($pdo, 'trucks', 'truck_id', $truckId, 'Truck not found.');
 */
function findOrFail(PDO $pdo, string $table, string $idColumn, int $id, string $notFoundMessage = 'Record not found.'): array {
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$idColumn` = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonFail($notFoundMessage, 404);
    }
    return $row;
}

/**
 * Check whether a value already exists in a column, optionally
 * excluding the current row's own ID (for edit-uniqueness checks).
 *
 * Example:
 *   if (existsWhere($pdo, 'trucks', 'plate_number', $plate, $truckId, 'truck_id')) {
 *       jsonFail('Another truck already has that plate number.');
 *   }
 */
function existsWhere(PDO $pdo, string $table, string $column, mixed $value, ?int $excludeId = null, ?string $excludeIdColumn = null): bool {
    $sql = "SELECT 1 FROM `$table` WHERE `$column` = ?";
    $params = [$value];

    if ($excludeId !== null && $excludeIdColumn !== null) {
        $sql .= " AND `$excludeIdColumn` != ?";
        $params[] = $excludeId;
    }

    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetch();
}