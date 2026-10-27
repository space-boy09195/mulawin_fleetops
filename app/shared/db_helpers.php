<?php
// ============================================================
// includes/db_helpers.php
// Small, reusable query helpers for common patterns that were
// being hand-written slightly differently in each ajax/*.php
// handler (fetch-by-id-or-fail, uniqueness checks). This is
// NOT an ORM — still plain PDO and prepared statements, just
// without re-typing the same three lines in 16 different files.
//
// Table and column names cannot be bound as parameters, so every
// identifier these helpers interpolate must appear in
// DB_HELPER_IDENTIFIERS (target schema + approved drafts). Anything else is
// a programming error and throws LogicException before any SQL is built.
// ============================================================

const DB_HELPER_IDENTIFIERS = [
    'announcements'       => ['announcement_id'],
    'billings'            => ['billing_id', 'invoice_number', 'trip_id'],
    'client_locations'    => ['location_id'],
    'client_rates'        => ['rate_id'],
    'clients'             => ['client_id', 'client_name'],
    'employees'           => ['employee_id', 'user_id', 'employee_code'],
    'expense_records'     => ['expense_id'],
    'maintenance_records' => ['maintenance_id'],
    'office_supplies'     => ['supply_id', 'item_name'],
    'parts'               => ['part_id', 'part_number'],
    'payroll_records'     => ['payroll_record_id'],
    'purchase_orders'     => ['purchase_order_id', 'po_number'],
    'recruitment_records' => ['recruitment_id'],
    'roles'               => ['role_id'],
    'routes'              => ['route_id', 'route_name'],
    'suppliers'           => ['supplier_id', 'supplier_name'],
    'trip_incidents'      => ['incident_id'],
    'trips'               => ['trip_id', 'trip_number'],
    'trucks'              => ['truck_id', 'plate_number', 'unit_number', 'chassis_number', 'engine_number'],
    'users'               => ['user_id', 'username', 'email'],
];

function dbHelperAssertIdentifier(string $table, string ...$columns): void {
    if (!isset(DB_HELPER_IDENTIFIERS[$table])) {
        throw new LogicException("Table {$table} is not allowlisted for db_helpers.");
    }
    foreach ($columns as $column) {
        if (!in_array($column, DB_HELPER_IDENTIFIERS[$table], true)) {
            throw new LogicException("Column {$table}.{$column} is not allowlisted for db_helpers.");
        }
    }
}

/**
 * Fetch a single row by its primary key, or send a JSON 404
 * failure and stop execution if it doesn't exist.
 *
 * Example:
 *   $truck = findOrFail($pdo, 'trucks', 'truck_id', $truckId, 'Truck not found.');
 */
function findOrFail(PDO $pdo, string $table, string $idColumn, int $id, string $notFoundMessage = 'Record not found.'): array {
    dbHelperAssertIdentifier($table, $idColumn);
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
    dbHelperAssertIdentifier($table, $column);
    $sql = "SELECT 1 FROM `$table` WHERE `$column` = ?";
    $params = [$value];

    if ($excludeId !== null && $excludeIdColumn !== null) {
        dbHelperAssertIdentifier($table, $excludeIdColumn);
        $sql .= " AND `$excludeIdColumn` != ?";
        $params[] = $excludeId;
    }

    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetch();
}
