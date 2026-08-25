<?php
// includes/alerts.php
// Rule-based Analytical Insights engine for the Analytics page.
//
// Deterministic business rules only — no ML. Follows a
// Descriptive -> Comparative -> Diagnostic -> Prescriptive progression:
//   - Descriptive:  what is the number right now
//   - Comparative:  how does it compare with the previous period
//   - Diagnostic:   what's the likely driver (e.g. which truck/client)
//   - Prescriptive: concrete next actions (the existing 'prescription' list)
//
// Each insight is an associative array:
//   [
//     'alert'        => short headline,
//     'severity'     => 'Critical' | 'Warning' | 'Info',
//     'source'       => department label,
//     'detail'       => one-line explanation with the numbers,
//     'action_url'   => where to go to act on it,
//     'type'         => machine-readable tag,
//     'priority'     => optional explicit CSS-tier override ('high'|'medium'|'good'),
//     'prescription' => array of concrete next-step bullets,
//   ]

function getAnalyticsAlerts(PDO $pdo, array $metrics, string $periodLabel): array {
    $alerts = [];
    $hasComparison = $metrics['hasComparison'] ?? false;

    $util         = isset($metrics['utilizationRate'])     ? (float)$metrics['utilizationRate']     : null;
    $utilPrev     = isset($metrics['utilizationRatePrev']) && $metrics['utilizationRatePrev'] !== null ? (float)$metrics['utilizationRatePrev'] : null;
    $coll         = isset($metrics['collectionRate'])      ? (float)$metrics['collectionRate']      : null;
    $collPrev     = isset($metrics['collectionRatePrev'])  && $metrics['collectionRatePrev'] !== null  ? (float)$metrics['collectionRatePrev']  : null;
    $onTime       = isset($metrics['onTimeRate'])          ? (float)$metrics['onTimeRate']          : null;
    $onTimePrev   = isset($metrics['onTimeRatePrev'])      && $metrics['onTimeRatePrev'] !== null      ? (float)$metrics['onTimeRatePrev']      : null;
    $maintCost    = isset($metrics['maintCost'])           ? (float)$metrics['maintCost']           : null;
    $maintCostPrev = isset($metrics['maintCostPrev'])      && $metrics['maintCostPrev'] !== null      ? (float)$metrics['maintCostPrev']       : null;
    $revenue      = isset($metrics['revenue'])              ? (float)$metrics['revenue']              : null;
    $revenuePrev  = isset($metrics['revenuePrev'])          && $metrics['revenuePrev'] !== null        ? (float)$metrics['revenuePrev']          : null;
    $rangeStartSql = $metrics['rangeStartSql'] ?? null;

    // ══════════════════════════════════════════════════════════════════════
    // ABSOLUTE-THRESHOLD CHECKS — still valid regardless of trend direction.
    // ══════════════════════════════════════════════════════════════════════
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
                'Consider promotions or consolidated loads to increase utilization.',
            ],
        ];
    }

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
                'Offer partial-payment plans where appropriate and record promises to pay.',
            ],
        ];
    }

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
                'Adjust routing or dispatch windows to improve punctuality.',
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // DIAGNOSTIC: maintenance cost trend, using the REAL previous-period
    // total (not a single bucket-to-bucket delta, which is noisy and
    // depends on whatever chart granularity happens to be selected).
    // When cost has risen meaningfully, also names the single truck
    // contributing the most to this period's spend — the "why", not just
    // the "what".
    // ══════════════════════════════════════════════════════════════════════
    if ($hasComparison && $maintCost !== null && $maintCostPrev !== null) {
        if ($maintCostPrev > 0) {
            $pct = (($maintCost - $maintCostPrev) / $maintCostPrev) * 100;
            if ($pct >= 15) {
                $contributorNote = '';
                if ($rangeStartSql) {
                    $stmt = $pdo->prepare("
                        SELECT tr.plate_number, tr.brand, tr.model, SUM(mr.cost) AS total_cost
                        FROM maintenance_records mr
                        JOIN trucks tr ON tr.truck_id = mr.truck_id
                        WHERE mr.date_performed >= :rangeStart
                        GROUP BY tr.truck_id
                        ORDER BY total_cost DESC
                        LIMIT 1
                    ");
                    $stmt->execute([':rangeStart' => $rangeStartSql]);
                    $topTruck = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($topTruck) {
                        $label = trim($topTruck['brand'] . ' ' . $topTruck['model']) . ' (' . $topTruck['plate_number'] . ')';
                        $share = $maintCost > 0 ? round(((float)$topTruck['total_cost'] / $maintCost) * 100) : 0;
                        $contributorNote = " Largest single contributor: {$label} at ₱" . number_format((float)$topTruck['total_cost'], 2) . " ({$share}% of this period's total).";
                    }
                }
                $alerts[] = [
                    'alert' => $pct >= 30 ? 'Maintenance cost spike' : 'Rising maintenance costs',
                    'severity' => $pct >= 30 ? 'Critical' : 'Warning',
                    'source' => 'Maintenance',
                    'detail' => 'Maintenance expenses increased ' . round($pct, 1) . '% compared with the previous period (₱'
                                . number_format($maintCostPrev, 2) . ' → ₱' . number_format($maintCost, 2) . ').' . $contributorNote,
                    'action_url' => '/pages/maintenance.php',
                    'type' => $pct >= 30 ? 'maint_spike' : 'maint_rise',
                    'prescription' => [
                        'Review this period\'s maintenance records for high-cost repairs.',
                        'Check parts inventory consumption for abnormal usage.',
                        'If one truck dominates the spend, inspect it for a recurring fault rather than one-off wear.',
                    ],
                ];
            }
        } elseif ($maintCost >= 5000) {
            $alerts[] = [
                'alert' => 'New maintenance spending',
                'severity' => 'Warning',
                'source' => 'Maintenance',
                'detail' => "Maintenance cost for {$periodLabel} is ₱" . number_format($maintCost, 2) . ', while the previous period had none.',
                'action_url' => '/pages/maintenance.php',
                'type' => 'maint_new',
                'prescription' => [
                    'Review the new maintenance entries to confirm scope and cost.',
                    'Check whether multiple trucks show the same fault — may indicate a supplier or parts-batch issue.',
                    'If expensive, obtain a second estimate or review warranty coverage.',
                ],
            ];
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // DIAGNOSTIC: revenue decline vs the previous period.
    // ══════════════════════════════════════════════════════════════════════
    if ($hasComparison && $revenue !== null && $revenuePrev !== null && $revenuePrev > 0) {
        $pct = (($revenue - $revenuePrev) / $revenuePrev) * 100;
        if ($pct <= -15) {
            $alerts[] = [
                'alert' => 'Revenue decline',
                'severity' => $pct <= -30 ? 'Critical' : 'Warning',
                'source' => 'Accounting',
                'detail' => 'Revenue billed fell ' . round(abs($pct), 1) . '% compared with the previous period (₱'
                            . number_format($revenuePrev, 2) . ' → ₱' . number_format($revenue, 2) . ').',
                'action_url' => '/pages/billing.php',
                'type' => 'revenue_decline',
                'prescription' => [
                    'Compare completed-trip volume this period against the previous one.',
                    'Check for completed trips that haven\'t been billed yet.',
                    'Review pricing on recent contracts against prior rates.',
                ],
            ];
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // POSITIVE TRENDS (Info severity) — the engine should surface good news
    // too, not just problems. Same comparative logic, opposite direction.
    // ══════════════════════════════════════════════════════════════════════
    if ($hasComparison) {
        if ($revenue !== null && $revenuePrev !== null && $revenuePrev > 0) {
            $pct = (($revenue - $revenuePrev) / $revenuePrev) * 100;
            if ($pct >= 15) {
                $alerts[] = [
                    'alert' => 'Revenue growth',
                    'severity' => 'Info',
                    'priority' => 'good',
                    'source' => 'Accounting',
                    'detail' => 'Revenue billed increased ' . round($pct, 1) . "% compared with the previous period ({$periodLabel}).",
                    'action_url' => '/pages/analytics.php',
                    'type' => 'revenue_growth',
                    'prescription' => ['This is a positive trend — no action needed. Keep monitoring next period to confirm it holds.'],
                ];
            }
        }

        if ($onTime !== null && $onTimePrev !== null) {
            $diff = round($onTime - $onTimePrev, 1);
            if ($diff >= 5) {
                $alerts[] = [
                    'alert' => 'On-time delivery improving',
                    'severity' => 'Info',
                    'priority' => 'good',
                    'source' => 'Dispatch',
                    'detail' => "On-time delivery rose {$diff} points compared with the previous period.",
                    'action_url' => '/pages/trip_monitor.php',
                    'type' => 'ontime_improve',
                    'prescription' => ['This is a positive trend — no action needed. Keep monitoring next period to confirm it holds.'],
                ];
            }
        }

        if ($util !== null && $utilPrev !== null) {
            $diff = round($util - $utilPrev, 1);
            if ($diff >= 5) {
                $alerts[] = [
                    'alert' => 'Fleet utilization improving',
                    'severity' => 'Info',
                    'priority' => 'good',
                    'source' => 'Operations',
                    'detail' => "Fleet utilization rose {$diff} points compared with the previous period.",
                    'action_url' => '/pages/fleet_status.php',
                    'type' => 'utilization_improve',
                    'prescription' => ['This is a positive trend — no action needed. Keep monitoring next period to confirm it holds.'],
                ];
            }
        }

        if ($coll !== null && $collPrev !== null) {
            $diff = round($coll - $collPrev, 1);
            if ($diff >= 5) {
                $alerts[] = [
                    'alert' => 'Collection rate improving',
                    'severity' => 'Info',
                    'priority' => 'good',
                    'source' => 'Accounting',
                    'detail' => "Collection rate rose {$diff} points compared with the previous period.",
                    'action_url' => '/pages/billing.php',
                    'type' => 'collection_improve',
                    'prescription' => ['This is a positive trend — no action needed. Keep monitoring next period to confirm it holds.'],
                ];
            }
        }
    }

    // ── Open incidents — a live snapshot, not tied to any period ──────────────
    $openIncidents = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE resolved_at IS NULL")->fetchColumn();
    if ($openIncidents > 0) {
        $alerts[] = [
            'alert' => 'Open incidents',
            'severity' => $openIncidents > 5 ? 'Critical' : 'Warning',
            'source' => 'Safety',
            'detail' => "{$openIncidents} unresolved incident" . ($openIncidents !== 1 ? 's' : '') . '. Investigate and resolve.',
            'action_url' => '/pages/incidents.php',
            'type' => 'incidents',
            'prescription' => [
                'Open each unresolved incident and assign an owner for remediation.',
                'If safety-related, halt the affected vehicle until inspected.',
                'Log follow-up actions and expected resolution dates.',
            ],
        ];
    }

    // Sort: Critical first, then Warning, then Info (positive news last).
    usort($alerts, function ($a, $b) {
        $prio = ['Critical' => 0, 'Warning' => 1, 'Info' => 2];
        return ($prio[$a['severity']] ?? 3) <=> ($prio[$b['severity']] ?? 3);
    });

    return $alerts;
}