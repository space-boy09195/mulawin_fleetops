<?php
// includes/alerts.php
// Simple rule-based alerts generator for Analytics (deterministic rules).

function getAnalyticsAlerts(PDO $pdo, array $metrics, string $periodLabel): array {
    // metrics expected keys: utilizationRate, collectionRate, onTimeRate, maintCost,
    // revTrend (array), costTrend (array)
    $alerts = [];

    $util = isset($metrics['utilizationRate']) ? (float)$metrics['utilizationRate'] : null;
    if ($util !== null && $util < 60) {
        $alerts[] = [
            'alert' => 'Low fleet utilization',
            'severity' => 'Warning',
            'source' => 'Operations',
            'detail' => "Fleet utilization is {$util}% for {$periodLabel} — consider reassigning idle trucks or promoting load consolidation.",
            'action_url' => '/pages/fleet_status.php',
            'type' => 'utilization',
                'prescription' => [
                    'Review idle truck list and assign to pending dispatches.',
                    'Check recent trip cancellations and contact affected clients.',
                    'Consider promotions or consolidated loads to increase utilization.'
                ],
            ];
    }

    $coll = isset($metrics['collectionRate']) ? (float)$metrics['collectionRate'] : null;
    if ($coll !== null && $coll < 80) {
        $alerts[] = [
            'alert' => 'Low collection rate',
            'severity' => 'Warning',
            'source' => 'Accounting',
            'detail' => "Collection rate is {$coll}% for {$periodLabel} — follow up on overdue invoices.",
            'action_url' => '/pages/billing.php',
            'type' => 'collections',
                'prescription' => [
                    'Open the overdue invoices report and prioritize top balances.',
                    'Call or email clients with invoices older than 30 days.',
                    'Offer partial-payment plans where appropriate and record promises to pay.'
                ],
            ];
    }

    $onTime = isset($metrics['onTimeRate']) ? (float)$metrics['onTimeRate'] : null;
    if ($onTime !== null && $onTime < 85) {
        $alerts[] = [
            'alert' => 'Low on-time delivery rate',
            'severity' => 'Warning',
            'source' => 'Dispatch',
            'detail' => "On-time delivery is {$onTime}% for {$periodLabel} — investigate delays or scheduling issues.",
            'action_url' => '/pages/trip_monitor.php',
            'type' => 'ontime',
                'prescription' => [
                    'Review delayed trips and identify common bottlenecks (loading, traffic, driver availability).',
                    'Contact drivers with repeated late trips to coach or reschedule.',
                    'Adjust routing or dispatch windows to improve punctuality.'
                ],
            ];
    }

    // Trend-based alerts: detect sharp increase in maintenance cost vs previous bucket
    if (!empty($metrics['costTrend']) && is_array($metrics['costTrend'])) {
        $ct = array_values($metrics['costTrend']);
        $n = count($ct);
        if ($n >= 2) {
            $last = (float)$ct[$n - 1];
            $prev = (float)$ct[$n - 2];
            // Avoid division by zero — if prev is tiny, use absolute delta threshold
            if ($prev > 0) {
                $pct = ($last - $prev) / $prev * 100.0;
                if ($pct >= 30) {
                    $alerts[] = [
                        'alert' => 'Maintenance cost spike',
                        'severity' => 'Critical',
                        'source' => 'Maintenance',
                        'detail' => "Maintenance cost increased by " . round($pct,1) . "% vs previous period. Review recent repairs and parts usage.",
                        'action_url' => '/pages/maintenance.php',
                        'type' => 'maint_spike',
                        'prescription' => [
                            'Open recent maintenance records and inspect parts replaced and truck IDs.',
                            'Identify recurring failures and consider preventive replacement of affected parts.',
                            'Schedule a maintenance review meeting to check root causes.'
                        ],
                    ];
                } elseif ($pct >= 15) {
                    $alerts[] = [
                        'alert' => 'Rising maintenance costs',
                        'severity' => 'Warning',
                        'source' => 'Maintenance',
                        'detail' => "Maintenance cost rose by " . round($pct,1) . "% vs previous period.",
                        'action_url' => '/pages/maintenance.php',
                        'type' => 'maint_rise',
                        'prescription' => [
                            'Inspect last month`s maintenance log for high-cost repairs.',
                            'Check parts inventory consumption to detect abnormal usage.',
                            'Plan preventive maintenance for vehicles with repeated repairs.'
                        ],
                    ];
                }
            } else {
                // prev is zero — if last is meaningfully large, flag
                if ($last >= 5000) {
                    $alerts[] = [
                        'alert' => 'New maintenance spending',
                        'severity' => 'Warning',
                        'source' => 'Maintenance',
                        'detail' => "Maintenance cost for the latest bucket is ₱" . number_format($last,2) . ", while previous bucket had no spending.",
                        'action_url' => '/pages/maintenance.php',
                        'type' => 'maint_new',
                        'prescription' => [
                            'Review the new maintenance entries to confirm scope and cost.',
                            'Check whether multiple trucks show the same fault — may indicate supplier or part batch issues.',
                            'If expensive, obtain a second estimate or review warranty/coverage.'
                        ],
                    ];
                }
            }
        }
    }

    // Add open incidents/high severity incidents check (existing DB query)
    $openIncidents = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE resolved_at IS NULL")->fetchColumn();
    if ($openIncidents > 0) {
        $alerts[] = [
            'alert' => 'Open incidents',
            'severity' => $openIncidents > 5 ? 'Critical' : 'Warning',
            'source' => 'Safety',
            'detail' => "{$openIncidents} unresolved incident" . ($openIncidents !== 1 ? 's' : '') . ". Investigate and resolve.",
            'action_url' => '/pages/incidents.php',
            'type' => 'incidents',
                'prescription' => [
                    'Open each unresolved incident and assign an owner for remediation.',
                    'If safety-related, halt affected vehicle until inspected.',
                    'Log follow-up actions and expected resolution dates.'
                ],
            ];
    }

    // Sort alerts by severity and return
    usort($alerts, function($a, $b) {
        $prio = ['Critical' => 0, 'Warning' => 1, 'Info' => 2];
        return ($prio[$a['severity']] ?? 3) <=> ($prio[$b['severity']] ?? 3);
    });

    return $alerts;
}
