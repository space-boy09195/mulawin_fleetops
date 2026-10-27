<?php
// ============================================================
// includes/dashboard_metrics.php
// Shared dashboard figures on the target schema, so every role dashboard
// counts the same thing the same way (definitions in includes/metrics.php
// and includes/finance_records.php). Descriptive only.
//
//   pending dispatch   dispatch_requests.status 'Pending' (trip awaiting
//                      approval, B3 model)
//   active trip        trips.status in TRIP_ACTIVE_STATUSES
//   delayed trip       active trip past its scheduled arrival
//                      (tripOverdueSql) — "delayed" means overdue now
//   open maintenance   maintenance_records Reported / Scheduled /
//                      In Progress / Waiting for Parts
//   maintenance due    latest next_due_date per truck (T-M1) or an active
//                      preventive schedule (T-M2) within N days
//   open incident      trip_incidents.resolved_at IS NULL (T-I1)
//   low stock          active part with quantity_on_hand <= reorder_level
//   outstanding        billing total - collections, Issued / Partially
//                      Paid / Overdue billings; overdue = past due_date
//
// Callers pass optional scope SQL (fixed fragments built by the page from
// fixed aliases, values always bound as parameters).
// ============================================================

require_once __DIR__ . '/../../shared/metrics.php';
require_once __DIR__ . '/../finance/finance_records.php';
require_once __DIR__ . '/../../../config/enums.php';

const MAINTENANCE_OPEN_STATUSES = ['Reported', 'Scheduled', 'In Progress', 'Waiting for Parts'];
// TRUCK_STATUSES (target trucks.status ENUM) comes from config/enums.php.

function dashboardCount(PDO $pdo, string $sql, array $params = []): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/** Truck count per target status (every status present, zero when none). */
function dashboardFleetCounts(PDO $pdo): array {
    $counts = array_fill_keys(TRUCK_STATUSES, 0);
    foreach ($pdo->query('SELECT status, COUNT(*) AS cnt FROM trucks GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR) as $status => $cnt) {
        $counts[$status] = (int)$cnt;
    }
    return $counts;
}

/**
 * $scopeSql filters trips aliased t (e.g. "AND t.dispatcher_id = ?").
 */
function dashboardPendingDispatchCount(PDO $pdo, string $scopeSql = '', array $params = []): int {
    return dashboardCount($pdo,
        "SELECT COUNT(*) FROM dispatch_requests dr JOIN trips t ON t.trip_id = dr.trip_id
         LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
         WHERE dr.status = 'Pending' $scopeSql", $params);
}

function dashboardActiveTripCount(PDO $pdo, string $scopeSql = '', array $params = []): int {
    return dashboardCount($pdo,
        "SELECT COUNT(*) FROM trips t LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
         WHERE t.status IN (" . metricSqlList(TRIP_ACTIVE_STATUSES) . ") $scopeSql", $params);
}

function dashboardDelayedTripCount(PDO $pdo, string $scopeSql = '', array $params = []): int {
    return dashboardCount($pdo,
        "SELECT COUNT(*) FROM trips t LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
         WHERE " . tripOverdueSql('t') . " $scopeSql", $params);
}

/** Trips scheduled to depart in [from, to) grouped by status. */
function dashboardTripStatusCounts(PDO $pdo, string $from, string $to, string $scopeSql = '', array $params = []): array {
    $stmt = $pdo->prepare(
        "SELECT t.status, COUNT(*) AS cnt FROM trips t LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
         WHERE t.scheduled_departure >= ? AND t.scheduled_departure < ? $scopeSql
         GROUP BY t.status"
    );
    $stmt->execute(array_merge([$from, $to], $params));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * Delivery performance for trips scheduled in [from, to):
 * delivered, measurable, on_time, late, on_time_rate (null when nothing
 * is measurable).
 */
function dashboardDeliveryPerformance(PDO $pdo, string $from, string $to, string $scopeSql = '', array $params = []): array {
    $stmt = $pdo->prepare(
        "SELECT SUM(" . tripDeliveredSql('t') . ")  AS delivered,
                SUM(" . tripMeasurableSql('t') . ") AS measurable,
                SUM(" . tripOnTimeSql('t') . ")     AS on_time,
                SUM(" . tripLateSql('t') . ")       AS late
         FROM trips t LEFT JOIN trucks tr ON tr.truck_id = t.truck_id
         WHERE t.scheduled_departure >= ? AND t.scheduled_departure < ? $scopeSql"
    );
    $stmt->execute(array_merge([$from, $to], $params));
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $out = [
        'delivered'  => (int)($row['delivered'] ?? 0),
        'measurable' => (int)($row['measurable'] ?? 0),
        'on_time'    => (int)($row['on_time'] ?? 0),
        'late'       => (int)($row['late'] ?? 0),
    ];
    $out['on_time_rate'] = metricRate($out['on_time'], $out['measurable']);
    return $out;
}

/**
 * Fleet utilization for [from, to): distinct trucks on a trip scheduled in
 * the window (not cancelled / pending approval) over all trucks.
 */
function dashboardFleetUtilization(PDO $pdo, string $from, string $to): array {
    $total = dashboardCount($pdo, 'SELECT COUNT(*) FROM trucks');
    $used = dashboardCount($pdo,
        "SELECT COUNT(DISTINCT t.truck_id) FROM trips t
         WHERE t.truck_id IS NOT NULL AND t.status NOT IN ('Cancelled', 'Pending Approval')
           AND t.scheduled_departure >= ? AND t.scheduled_departure < ?", [$from, $to]);
    return ['used' => $used, 'total' => $total, 'rate' => metricRate($used, $total)];
}

/** Pending approval requests whose current step belongs to the role (all for Admin). */
function dashboardPendingApprovalCount(PDO $pdo, bool $isAdmin, int $roleId): int {
    $sql = "SELECT COUNT(*)
            FROM approval_requests ar
            JOIN approval_steps s ON s.approval_step_id = (
                SELECT s2.approval_step_id FROM approval_steps s2
                WHERE s2.approval_request_id = ar.approval_request_id AND s2.status = 'Pending'
                ORDER BY s2.step_number LIMIT 1
            )
            WHERE ar.status = 'Pending'";
    if ($isAdmin) {
        return dashboardCount($pdo, $sql);
    }
    return dashboardCount($pdo, $sql . ' AND s.approver_role_id = ?', [$roleId]);
}

function dashboardOpenMaintenanceCount(PDO $pdo): int {
    return dashboardCount($pdo,
        "SELECT COUNT(*) FROM maintenance_records WHERE status IN (" . metricSqlList(MAINTENANCE_OPEN_STATUSES) . ")");
}

/** SQL for (truck_id, next_due_date, label) rows of upcoming service. */
function dashboardMaintenanceDueSql(): string {
    return "
        SELECT mr.truck_id, mr.next_due_date, mr.maintenance_type AS label
        FROM maintenance_records mr
        WHERE mr.next_due_date IS NOT NULL AND mr.status <> 'Cancelled'
          AND mr.maintenance_id = (
              SELECT m2.maintenance_id FROM maintenance_records m2
              WHERE m2.truck_id = mr.truck_id AND m2.next_due_date IS NOT NULL AND m2.status <> 'Cancelled'
              ORDER BY " . maintenanceRecordDateSql('m2') . " DESC, m2.maintenance_id DESC
              LIMIT 1
          )
        UNION ALL
        SELECT pms.truck_id, pms.next_due_date, pms.service_name AS label
        FROM preventive_maintenance_schedules pms
        WHERE pms.status = 'Active'";
}

/** Service items due within $days days (overdue included), soonest first. */
function dashboardMaintenanceDue(PDO $pdo, int $days, int $limit = 50): array {
    $days = max(0, min(366, $days));
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare(
        "SELECT due.truck_id, tr.plate_number, tr.brand, tr.model, due.next_due_date, due.label
         FROM (" . dashboardMaintenanceDueSql() . ") due
         JOIN trucks tr ON tr.truck_id = due.truck_id
         WHERE due.next_due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
         ORDER BY due.next_due_date ASC
         LIMIT $limit"
    );
    $stmt->execute([$days]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dashboardOpenIncidents(PDO $pdo, int $limit = 5): array {
    $limit = max(1, min(500, $limit));
    return $pdo->query(
        "SELECT i.incident_id, i.incident_type, i.severity, i.incident_date, t.trip_number, tr.plate_number
         FROM trip_incidents i
         JOIN trips t ON t.trip_id = i.trip_id
         LEFT JOIN trucks tr ON tr.truck_id = COALESCE(i.truck_id, t.truck_id)
         WHERE i.resolved_at IS NULL
         ORDER BY FIELD(i.severity, 'Critical', 'High', 'Medium', 'Low'), i.incident_date DESC
         LIMIT $limit"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function dashboardOpenIncidentCount(PDO $pdo): int {
    return dashboardCount($pdo, 'SELECT COUNT(*) FROM trip_incidents WHERE resolved_at IS NULL');
}

function dashboardLowStockParts(PDO $pdo, int $limit = 5): array {
    $limit = max(1, min(500, $limit));
    return $pdo->query(
        "SELECT p.part_id, p.part_name, p.unit, p.reorder_level, COALESCE(i.quantity_on_hand, 0) AS quantity
         FROM parts p LEFT JOIN inventory i ON i.part_id = p.part_id
         WHERE p.is_active = 1 AND COALESCE(i.quantity_on_hand, 0) <= p.reorder_level
         ORDER BY COALESCE(i.quantity_on_hand, 0) ASC, p.part_name
         LIMIT $limit"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function dashboardLowStockCount(PDO $pdo): int {
    return dashboardCount($pdo,
        "SELECT COUNT(*) FROM parts p LEFT JOIN inventory i ON i.part_id = p.part_id
         WHERE p.is_active = 1 AND COALESCE(i.quantity_on_hand, 0) <= p.reorder_level");
}

/**
 * Receivables now: outstanding balance, overdue balance and count.
 */
function dashboardReceivables(PDO $pdo): array {
    $row = $pdo->query(
        "SELECT COALESCE(SUM(x.balance), 0) AS outstanding,
                COALESCE(SUM(CASE WHEN x.overdue THEN x.balance ELSE 0 END), 0) AS overdue_amount,
                COALESCE(SUM(x.overdue), 0) AS overdue_count
         FROM (
             SELECT b.total_amount - " . billingCollectedSql('b') . " AS balance,
                    (b.due_date IS NOT NULL AND b.due_date < CURDATE()) AS overdue
             FROM billings b
             WHERE b.status IN ('Issued', 'Partially Paid', 'Overdue')
         ) x
         WHERE x.balance > 0"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'outstanding'    => (float)($row['outstanding'] ?? 0),
        'overdue_amount' => (float)($row['overdue_amount'] ?? 0),
        'overdue_count'  => (int)($row['overdue_count'] ?? 0),
    ];
}

/** Revenue billed, collections received and collection rate for [from, to). */
function dashboardBilledCollected(PDO $pdo, string $from, string $to): array {
    $stmt = $pdo->prepare(
        "SELECT
           (SELECT COALESCE(SUM(b.total_amount), 0) FROM billings b
             WHERE " . billingRevenueSql('b') . " AND b.billing_date >= ? AND b.billing_date < ?) AS billed,
           (SELECT COALESCE(SUM(c.amount), 0) FROM collections c
             WHERE c.payment_date >= ? AND c.payment_date < ?) AS collected"
    );
    $stmt->execute([$from, $to, $from, $to]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $billed = (float)($row['billed'] ?? 0);
    $collected = (float)($row['collected'] ?? 0);
    return ['billed' => $billed, 'collected' => $collected, 'rate' => metricRate($collected, $billed)];
}

/** Maintenance cost (not Cancelled) dated in [from, to). */
function dashboardMaintenanceCost(PDO $pdo, string $from, string $to): float {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(mr.total_cost), 0) FROM maintenance_records mr
         WHERE mr.status <> 'Cancelled'
           AND " . maintenanceRecordDateSql('mr') . " >= ? AND " . maintenanceRecordDateSql('mr') . " < ?"
    );
    $stmt->execute([$from, $to]);
    return (float)$stmt->fetchColumn();
}

/** Monthly series [labels, keys] for the last $months months incl. this one. */
function dashboardMonthKeys(int $months): array {
    $months = max(1, min(36, $months));
    $keys = [];
    $labels = [];
    $cursor = new DateTime('first day of this month');
    $cursor->modify('-' . ($months - 1) . ' months');
    for ($i = 0; $i < $months; $i++) {
        $keys[] = $cursor->format('Y-m');
        $labels[] = $cursor->format('M Y');
        $cursor->modify('+1 month');
    }
    return ['keys' => $keys, 'labels' => $labels];
}

/**
 * Monthly totals keyed 'Y-m' for a fixed metric. $metric is one of the
 * keys below (never user input).
 */
function dashboardMonthlySeries(PDO $pdo, string $metric, int $months): array {
    $sql = [
        'billed'      => "SELECT DATE_FORMAT(b.billing_date, '%Y-%m') AS k, SUM(b.total_amount) FROM billings b
                          WHERE " . billingRevenueSql('b') . " AND b.billing_date >= ? GROUP BY k",
        'collected'   => "SELECT DATE_FORMAT(c.payment_date, '%Y-%m') AS k, SUM(c.amount) FROM collections c
                          WHERE c.payment_date >= ? GROUP BY k",
        'maintenance' => "SELECT DATE_FORMAT(" . maintenanceRecordDateSql('mr') . ", '%Y-%m') AS k, SUM(mr.total_cost)
                          FROM maintenance_records mr
                          WHERE mr.status <> 'Cancelled' AND " . maintenanceRecordDateSql('mr') . " >= ? GROUP BY k",
        'expenses'    => "SELECT DATE_FORMAT(er.expense_date, '%Y-%m') AS k, SUM(er.amount) FROM expense_records er
                          WHERE " . operatingExpenseSql('er') . " AND er.expense_date >= ? GROUP BY k",
    ][$metric] ?? null;
    if ($sql === null) {
        throw new InvalidArgumentException('Unknown dashboard series.');
    }
    $keys = dashboardMonthKeys($months)['keys'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$keys[0] . '-01']);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_map(static fn(string $k): float => round((float)($rows[$k] ?? 0), 2), $keys);
}
