<?php
// ============================================================
// includes/recommendations.php
// Rule-based recommended actions on the target schema.
//
// Deterministic business rules over current records: descriptive and
// diagnostic only. Nothing is forecast or predicted (the former parts
// "days until stock-out" projection was removed).
//
// Each recommendation is an associative array:
//   [
//     'priority'     => 'high' | 'medium',
//     'title'        => short headline,
//     'detail'       => one-line explanation with the "why",
//     'action_url'   => where to go to act on it,
//     'action_label' => button text,
//   ]
// ============================================================

require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/finance_records.php';

// ── Maintenance: trucks whose next service date is due or within 14 days ─────
// Sources: the latest next_due_date per truck on maintenance_records (T-M1)
// and active preventive_maintenance_schedules (T-M2).
function getMaintenanceRecommendations(PDO $pdo, int $limit = 5): array {
    $rows = $pdo->query("
        SELECT tr.plate_number, tr.brand, tr.model, due.next_due_date, due.label
        FROM (
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
            WHERE pms.status = 'Active'
        ) due
        JOIN trucks tr ON tr.truck_id = due.truck_id
        WHERE due.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
        ORDER BY due.next_due_date ASC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $daysUntil = (int)floor((strtotime($r['next_due_date']) - strtotime('today')) / 86400);
        $truckLabel = trim(($r['brand'] ?? '') . ' ' . ($r['model'] ?? ''));
        $truckLabel = ($truckLabel !== '' ? $truckLabel . ' ' : '') . '(' . $r['plate_number'] . ')';
        if ($daysUntil < 0) {
            $out[] = [
                'priority'     => 'high',
                'title'        => "{$truckLabel} — maintenance overdue",
                'detail'       => 'Overdue by ' . abs($daysUntil) . ' day' . (abs($daysUntil) !== 1 ? 's' : '') . " for {$r['label']}.",
                'action_url'   => '/pages/maintenance.php',
                'action_label' => 'Log Record',
                'sort_key'     => $daysUntil,
            ];
        } else {
            $out[] = [
                'priority'     => $daysUntil <= 3 ? 'high' : 'medium',
                'title'        => "{$truckLabel} — {$r['label']} due soon",
                'detail'       => 'Due in ' . $daysUntil . ' day' . ($daysUntil !== 1 ? 's' : '') . ' (' . $r['next_due_date'] . ').',
                'action_url'   => '/pages/maintenance.php',
                'action_label' => 'Schedule',
                'sort_key'     => $daysUntil,
            ];
        }
    }
    usort($out, fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);
    return array_slice($out, 0, $limit);
}

// ── Parts: active parts at or below their reorder level ─────────────────────
// Stock-out quantity over the last 30 days is shown as context only.
function getPartsReorderRecommendations(PDO $pdo, int $limit = 5): array {
    $rows = $pdo->query("
        SELECT p.part_id, p.part_name, p.unit, p.reorder_level,
               COALESCE(i.quantity_on_hand, 0) AS quantity,
               COALESCE(u.used_30d, 0) AS used_30d
        FROM parts p
        LEFT JOIN inventory i ON i.part_id = p.part_id
        LEFT JOIN (
            SELECT part_id, SUM(quantity) AS used_30d
            FROM inventory_transactions
            WHERE transaction_type = 'Stock Out'
              AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY part_id
        ) u ON u.part_id = p.part_id
        WHERE p.is_active = 1 AND COALESCE(i.quantity_on_hand, 0) <= p.reorder_level
        ORDER BY COALESCE(i.quantity_on_hand, 0) ASC, p.part_name
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $qty = (float)$r['quantity'];
        $fmt = static fn(float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        $detail = 'Currently ' . $fmt($qty) . " {$r['unit']} — at or below the reorder level (" . $fmt((float)$r['reorder_level']) . ').';
        if ((float)$r['used_30d'] > 0) {
            $detail .= ' Issued in the last 30 days: ' . $fmt((float)$r['used_30d']) . " {$r['unit']}.";
        }
        $out[] = [
            'priority'     => $qty <= 0 ? 'high' : 'medium',
            'title'        => "{$r['part_name']} — reorder recommended",
            'detail'       => $detail,
            'action_url'   => "/pages/parts.php?quick_movement={$r['part_id']}&type=Stock+In",
            'action_label' => 'Reorder',
            'sort_key'     => $qty,
        ];
    }
    return array_slice($out, 0, $limit);
}

// ── Collections: overdue billings, largest balance x days overdue first ─────
function getCollectionsRecommendations(PDO $pdo, int $limit = 5): array {
    $rows = $pdo->query("
        SELECT b.billing_id, b.invoice_number, cl.client_name, b.due_date, t.trip_number,
               b.total_amount - " . billingCollectedSql('b') . " AS balance
        FROM billings b
        JOIN clients cl ON cl.client_id = b.client_id
        JOIN trips t   ON t.trip_id   = b.trip_id
        WHERE b.status IN ('Issued', 'Partially Paid', 'Overdue')
          AND b.due_date IS NOT NULL AND b.due_date < CURDATE()
          AND b.total_amount > " . billingCollectedSql('b') . "
        LIMIT 500
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $daysOverdue = (int)floor((strtotime('today') - strtotime($r['due_date'])) / 86400);
        $balance     = (float)$r['balance'];
        $clientLabel = $r['client_name'] ?: $r['trip_number'];
        $out[] = [
            'priority'     => $daysOverdue > 30 || $balance >= 20000 ? 'high' : 'medium',
            'title'        => "{$r['invoice_number']} — {$clientLabel}",
            'detail'       => '₱' . number_format($balance, 2) . " overdue by {$daysOverdue} day" . ($daysOverdue !== 1 ? 's' : '') . '.',
            'action_url'   => "/pages/billing.php?quick_payment={$r['billing_id']}",
            'action_label' => 'Record Payment',
            'sort_key'     => -($balance * $daysOverdue),
        ];
    }
    usort($out, fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);
    return array_slice($out, 0, $limit);
}

// ── Dispatch: drivers with a high late-delivery rate in the last 30 days ────
// Only measurable deliveries count (scheduled arrival and arrival recorded;
// see includes/metrics.php). At least 3 measurable trips are required.
function getDispatchRecommendations(PDO $pdo, int $limit = 5): array {
    $rows = $pdo->query("
        SELECT e.employee_id, e.full_name,
               SUM(" . tripMeasurableSql('t') . ") AS trip_count,
               SUM(" . tripLateSql('t') . ") AS late_count
        FROM trips t
        JOIN employees e ON e.employee_id = t.driver_id
        WHERE t.scheduled_departure >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY e.employee_id, e.full_name
        HAVING trip_count >= 3
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $tripCount = (int)$r['trip_count'];
        $lateCount = (int)$r['late_count'];
        $lateRate  = metricRate($lateCount, $tripCount, 0) ?? 0.0;
        if ($lateRate < 30) continue;
        $out[] = [
            'priority'     => $lateRate >= 50 ? 'high' : 'medium',
            'title'        => "{$r['full_name']} — high late-delivery rate",
            'detail'       => (int)$lateRate . "% of {$tripCount} measurable deliveries in the last 30 days arrived late ({$lateCount} of {$tripCount}).",
            'action_url'   => '/pages/trip_monitor.php',
            'action_label' => 'Review Trips',
            'sort_key'     => -$lateRate,
        ];
    }
    usort($out, fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);
    return array_slice($out, 0, $limit);
}
