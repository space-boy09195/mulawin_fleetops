<?php
// analytics/comparative_analytics.php
//
// COMPARATIVE LAYER — Step 2 of the analytics system.
//
// WHAT THIS DOES:
// - Answers one question: "Is this period better than the previous one?"
// - Compares current numbers against past numbers (e.g., this month vs. last month).
// - REQUIRES PRIOR DATA: If there is no previous period to compare against (like selecting
//   "All Time"), it immediately stops and returns nothing.
//
// KEY RULE (GOOD NEWS ONLY):
// - This file ONLY flags GOOD news (e.g., revenue went up, on-time rate improved).
// - Bad news (e.g., revenue dropped) is handled by `diagnostic_analytics.php` instead,
//   because bad news needs to explain WHY things got worse, not just that they did.
//
// NO DATABASE ACCESS NEEDED:
// - Does NOT talk to the database ($pdo). 
// - It just does quick math using the pre-calculated numbers (`$metrics` array) 
//   already created by `pages/analytics.php`.
//
// HOW IT FITS INTO THE APP:
// - Called by `includes/alerts.php`.
// - Its results are combined with `descriptive_analytics.php` and `diagnostic_analytics.php`,
//   sorted into one single list, and returned as alerts.
//

require_once __DIR__ . '/prescriptive_analytics.php';

function getComparativeAlerts(array $metrics, string $periodLabel): array {
    $alerts = [];
    $hasComparison = $metrics['hasComparison'] ?? false;

    // No prior period to compare against — nothing for this file to do.
    if (!$hasComparison) {
        return $alerts;
    }

    $util       = isset($metrics['utilizationRate'])     ? (float)$metrics['utilizationRate']     : null;
    $utilPrev   = isset($metrics['utilizationRatePrev']) && $metrics['utilizationRatePrev'] !== null ? (float)$metrics['utilizationRatePrev'] : null;
    $coll       = isset($metrics['collectionRate'])      ? (float)$metrics['collectionRate']      : null;
    $collPrev   = isset($metrics['collectionRatePrev'])  && $metrics['collectionRatePrev'] !== null  ? (float)$metrics['collectionRatePrev']  : null;
    $onTime     = isset($metrics['onTimeRate'])          ? (float)$metrics['onTimeRate']          : null;
    $onTimePrev = isset($metrics['onTimeRatePrev'])      && $metrics['onTimeRatePrev'] !== null      ? (float)$metrics['onTimeRatePrev']      : null;
    $revenue     = isset($metrics['revenue'])     ? (float)$metrics['revenue']     : null;
    $revenuePrev = isset($metrics['revenuePrev']) && $metrics['revenuePrev'] !== null ? (float)$metrics['revenuePrev'] : null;

    // ══════════════════════════════════════════════════════════════════════
    // POSITIVE TRENDS (Info severity) — the engine should surface good
    // news too, not just problems. Each check here is a simple "current
    // vs previous" comparison with no drill-down into causes.
    // ══════════════════════════════════════════════════════════════════════
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
                'prescription' => getPrescription('revenue_growth'),
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
                'prescription' => getPrescription('ontime_improve'),
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
                'prescription' => getPrescription('utilization_improve'),
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
                'prescription' => getPrescription('collection_improve'),
            ];
        }
    }

    return $alerts;
}