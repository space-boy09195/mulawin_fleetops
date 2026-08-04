<?php
// ============================================================
// includes/recommendations.php
// Rule-based "prescriptive" recommendations engine.
//
// This is deterministic business-rule scoring — NOT machine learning.
// Each function looks at current data and returns a ranked list of
// concrete, actionable suggestions rather than just raw numbers.
//
// Each recommendation is an associative array:
//   [
//     'priority'   => 'high' | 'medium' | 'low',
//     'title'      => short headline,
//     'detail'     => one-line explanation with the "why",
//     'action_url' => where to go to act on it,
//     'action_label' => button text,
//   ]
// ============================================================

// ── Maintenance: which trucks need attention, and how urgently ───────────────
function getMaintenanceRecommendations(PDO $pdo, int $limit = 5): array {
    $rows = $pdo->query("
        SELECT tr.truck_id, tr.plate_number, tr.brand, tr.model,
               mr.next_due_date, mr.maintenance_type
        FROM trucks tr
        JOIN (
            SELECT truck_id, next_due_date, maintenance_type,
                   ROW_NUMBER() OVER (PARTITION BY truck_id ORDER BY date_performed DESC, created_at DESC) AS rn
            FROM maintenance_records
            WHERE next_due_date IS NOT NULL
        ) mr ON tr.truck_id = mr.truck_id AND mr.rn = 1
        WHERE mr.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
        ORDER BY mr.next_due_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $daysUntil = (int)floor((strtotime($r['next_due_date']) - strtotime('today')) / 86400);
        $truckLabel = trim($r['brand'] . ' ' . $r['model']) . ' (' . $r['plate_number'] . ')';

        if ($daysUntil < 0) {
            $out[] = [
                'priority'     => 'high',
                'title'        => "{$truckLabel} — maintenance overdue",
                'detail'       => 'Overdue by ' . abs($daysUntil) . ' day' . (abs($daysUntil) !== 1 ? 's' : '') . " for {$r['maintenance_type']}. Schedule immediately to avoid breakdown risk.",
                'action_url'   => '/pages/maintenance.php',
                'action_label' => 'Log Record',
                'sort_key'     => $daysUntil, // more negative = more urgent
            ];
        } else {
            $out[] = [
                'priority'     => $daysUntil <= 3 ? 'high' : 'medium',
                'title'        => "{$truckLabel} — {$r['maintenance_type']} due soon",
                'detail'       => 'Due in ' . $daysUntil . ' day' . ($daysUntil !== 1 ? 's' : '') . '. Schedule now to keep it preventive rather than reactive.',
                'action_url'   => '/pages/maintenance.php',
                'action_label' => 'Schedule',
                'sort_key'     => $daysUntil,
            ];
        }
    }

    usort($out, fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);
    return array_slice($out, 0, $limit);
}

// ── Parts: which parts to reorder, based on actual consumption rate ─────────
// (not just the static reorder_level threshold — projects days-until-stockout
// from real Stock Out movement history over the last 30 days)
function getPartsReorderRecommendations(PDO $pdo, int $limit = 5): array {
    $rows = $pdo->query("
        SELECT p.part_id, p.part_name, p.quantity, p.reorder_level, p.unit,
               COALESCE(u.used_30d, 0) AS used_30d
        FROM parts_inventory p
        LEFT JOIN (
            SELECT part_id, SUM(ABS(quantity)) AS used_30d
            FROM parts_movements
            WHERE movement_type = 'Stock Out'
              AND moved_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY part_id
        ) u ON u.part_id = p.part_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $qty        = (int)$r['quantity'];
        $reorderLvl = (int)$r['reorder_level'];
        $avgDaily   = (float)$r['used_30d'] / 30;
        $daysLeft   = $avgDaily > 0 ? $qty / $avgDaily : null; // null = no recent usage data

        $alreadyLow = $qty <= $reorderLvl;
        $runningOutSoon = $daysLeft !== null && $daysLeft <= 14;

        if (!$alreadyLow && !$runningOutSoon) continue; // nothing to recommend

        if ($alreadyLow) {
            $priority = $qty === 0 ? 'high' : 'medium';
            $detail   = "Currently {$qty} {$r['unit']} — at or below the reorder level ({$reorderLvl}).";
            $sortKey  = $qty; // 0 stock sorts first
        } else {
            $daysRounded = max(0, (int)round($daysLeft));
            $priority = $daysRounded <= 5 ? 'high' : 'medium';
            $detail   = "At current usage (~" . round($avgDaily, 1) . " {$r['unit']}/day), this will run out in ~{$daysRounded} day" . ($daysRounded !== 1 ? 's' : '') . '.';
            $sortKey  = 1000 + $daysRounded; // stockouts-already-low always outrank projected ones
        }

        $out[] = [
            'priority'     => $priority,
            'title'        => "{$r['part_name']} — reorder recommended",
            'detail'       => $detail,
            'action_url'   => "/pages/parts.php?quick_movement={$r['part_id']}&type=Stock+In",
            'action_label' => 'Reorder',
            'sort_key'     => $sortKey,
        ];
    }

    usort($out, fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);
    return array_slice($out, 0, $limit);
}

// ── Collections: which overdue invoices to chase first ────────────────────────
// Priority score = balance × days overdue, so a large old balance outranks a
// small one that's only slightly overdue.
function getCollectionsRecommendations(PDO $pdo, int $limit = 5): array {
    $rows = $pdo->query("
        SELECT b.billing_id, b.billing_number, b.client_name, b.due_date,
               vs.balance, t.trip_number
        FROM billings b
        JOIN v_billing_summary vs ON b.billing_id = vs.billing_id
        JOIN trips t              ON b.trip_id    = t.trip_id
        WHERE b.status != 'Paid' AND b.due_date < CURDATE()
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $daysOverdue = (int)floor((strtotime('today') - strtotime($r['due_date'])) / 86400);
        $balance     = (float)$r['balance'];
        $score       = $balance * $daysOverdue;
        $clientLabel = $r['client_name'] ?: $r['trip_number'];

        $out[] = [
            'priority'     => $daysOverdue > 30 || $balance >= 20000 ? 'high' : 'medium',
            'title'        => "{$r['billing_number']} — {$clientLabel}",
            'detail'       => '₱' . number_format($balance, 2) . " overdue by {$daysOverdue} day" . ($daysOverdue !== 1 ? 's' : '') . '.',
            'action_url'   => "/pages/billing.php?quick_payment={$r['billing_id']}",
            'action_label' => 'Record Payment',
            'sort_key'     => -$score, // most valuable-and-overdue first
        ];
    }

    usort($out, fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);
    return array_slice($out, 0, $limit);
}

// ── Dispatch: drivers whose recent late-trip rate suggests a closer look ─────
function getDispatchRecommendations(PDO $pdo, int $limit = 5): array {
    $rows = $pdo->query("
        SELECT e.employee_id, e.full_name,
               COUNT(*) AS trip_count,
               SUM(CASE WHEN t.is_late = 1 THEN 1 ELSE 0 END) AS late_count
        FROM dispatch_requests dr
        JOIN trips t     ON t.dispatch_id = dr.dispatch_id AND t.status = 'Completed'
        JOIN employees e ON e.employee_id = dr.driver_id
        WHERE t.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY e.employee_id
        HAVING trip_count >= 3
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $tripCount = (int)$r['trip_count'];
        $lateCount = (int)$r['late_count'];
        $lateRate  = $tripCount > 0 ? ($lateCount / $tripCount) * 100 : 0;

        if ($lateRate < 30) continue; // only flag meaningfully high late rates

        $out[] = [
            'priority'     => $lateRate >= 50 ? 'high' : 'medium',
            'title'        => "{$r['full_name']} — high late-trip rate",
            'detail'       => round($lateRate) . "% of the last {$tripCount} trips were late ({$lateCount} of {$tripCount}). Worth a check-in.",
            'action_url'   => '/pages/trip_monitor.php',
            'action_label' => 'Review Trips',
            'sort_key'     => -$lateRate,
        ];
    }

    usort($out, fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);
    return array_slice($out, 0, $limit);
}