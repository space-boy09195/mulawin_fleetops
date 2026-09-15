<?php
// analytics/diagnostic_analytics.php
//
// DIAGNOSTIC layer — third step in the Descriptive -> Comparative ->
// Diagnostic -> Prescriptive progression. Answers "what's the likely
// DRIVER behind this number?" not just "it changed." Both checks below
// compare against the previous period the same way comparative_analytics
// .php does, but go one step further by trying to explain WHY — that
// extra step is what makes them diagnostic instead of comparative.
//
// This is the only detection file that needs $pdo directly for its own
// query: the maintenance-cost check drills into maintenance_records to
// name the single truck responsible for the largest share of this
// period's spend, rather than just reporting the total went up.
//
// Called by includes/alerts.php, which merges this file's output with
// descriptive_analytics.php and comparative_analytics.php before sorting
// and returning the combined alert list.

require_once __DIR__ . '/prescriptive_analytics.php';

function getDiagnosticAlerts(PDO $pdo, array $metrics, string $periodLabel): array {
    $alerts = [];
    $hasComparison = $metrics['hasComparison'] ?? false;

    $maintCost     = isset($metrics['maintCost'])     ? (float)$metrics['maintCost']     : null;
    $maintCostPrev = isset($metrics['maintCostPrev']) && $metrics['maintCostPrev'] !== null ? (float)$metrics['maintCostPrev'] : null;
    $revenue       = isset($metrics['revenue'])       ? (float)$metrics['revenue']       : null;
    $revenuePrev   = isset($metrics['revenuePrev'])   && $metrics['revenuePrev'] !== null ? (float)$metrics['revenuePrev']   : null;
    $rangeStartSql = $metrics['rangeStartSql'] ?? null;

    // ══════════════════════════════════════════════════════════════════════
    // Maintenance cost trend, using the REAL previous-period total (not a
    // single bucket-to-bucket delta, which is noisy and depends on
    // whatever chart granularity happens to be selected). When cost has
    // risen meaningfully, this also names the single truck contributing
    // the most to this period's spend — the "why", not just the "what".
    // That drill-down query is the whole reason this check lives here
    // instead of in comparative_analytics.php.
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
                $type = $pct >= 30 ? 'maint_spike' : 'maint_rise';
                $alerts[] = [
                    'alert' => $pct >= 30 ? 'Maintenance cost spike' : 'Rising maintenance costs',
                    'severity' => $pct >= 30 ? 'Critical' : 'Warning',
                    'source' => 'Maintenance',
                    'detail' => 'Maintenance expenses increased ' . round($pct, 1) . '% compared with the previous period (₱'
                                . number_format($maintCostPrev, 2) . ' → ₱' . number_format($maintCost, 2) . ').' . $contributorNote,
                    'action_url' => '/pages/maintenance.php',
                    'type' => $type,
                    'prescription' => getPrescription($type),
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
                'prescription' => getPrescription('maint_new'),
            ];
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Revenue decline vs the previous period. Kept here (rather than in
    // comparative_analytics.php) to match the original code's own
    // "DIAGNOSTIC: revenue decline" labeling — the intent is that a
    // revenue drop is the kind of number that prompts someone to go
    // looking for a cause, even though this particular check doesn't
    // itself drill into which client/trip caused it.
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
                'prescription' => getPrescription('revenue_decline'),
            ];
        }
    }

    return $alerts;
}