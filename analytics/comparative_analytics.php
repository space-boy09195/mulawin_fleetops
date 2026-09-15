<?php
// analytics/comparative_analytics.php
//
// COMPARATIVE layer — second step in the Descriptive -> Comparative ->
// Diagnostic -> Prescriptive progression. Answers "how does this period
// compare with the previous one?" Every check in this file needs
// $metrics['hasComparison'] to be true — periods like "All Time" have no
// natural prior window, so this entire file is a no-op (returns an empty
// array immediately) when that flag is false.
//
// This file only reports GOOD news — rising revenue, improving on-time
// rate, and so on. Bad-news comparisons (revenue falling, maintenance
// cost rising) go one step further than a plain before/after comparison
// because they also try to explain WHY, which is what makes them
// diagnostic instead — see diagnostic_analytics.php.
//
// No DB access needed here at all: this is pure math on the $metrics
// array that pages/analytics.php already built from the DB earlier in
// the request, which is why the function signature doesn't take $pdo.
//
// Called by includes/alerts.php, which merges this file's output with
// descriptive_analytics.php and diagnostic_analytics.php before sorting
// and returning the combined alert list.

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