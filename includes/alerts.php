<?php
// includes/alerts.php
// Rule-based Analytical Insights engine for the Analytics page.
//
// This file is now just an ORCHESTRATOR. All the actual detection logic
// lives in /analytics/, split by stage of the same progression this file
// used to document in one big comment:
//   - analytics/descriptive_analytics.php   -> what is the number right now
//   - analytics/comparative_analytics.php   -> how does it compare with the previous period
//   - analytics/diagnostic_analytics.php    -> what's the likely driver (e.g. which truck/client)
//   - analytics/prescriptive_analytics.php  -> concrete next actions (the 'prescription' list)
//
// getAnalyticsAlerts() below has the SAME name, SAME parameters, and SAME
// return shape as before the split — pages/analytics.php calls it exactly
// as it did previously, so nothing outside this file needs to change.
//
// Each alert is still an associative array:
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

require_once __DIR__ . '/../analytics/descriptive_analytics.php';
require_once __DIR__ . '/../analytics/comparative_analytics.php';
require_once __DIR__ . '/../analytics/diagnostic_analytics.php';
require_once __DIR__ . '/../analytics/prescriptive_analytics.php';

function getAnalyticsAlerts(PDO $pdo, array $metrics, string $periodLabel): array {
    // Each layer only needs the pieces of $metrics relevant to it — see
    // the comments inside each file for exactly what it reads. Passing
    // the whole array through to all three keeps this orchestrator simple
    // and means adding a new metric later doesn't require touching this
    // function's signature.
    $alerts = array_merge(
        getDescriptiveAlerts($pdo, $metrics, $periodLabel),
        getComparativeAlerts($metrics, $periodLabel),
        getDiagnosticAlerts($pdo, $metrics, $periodLabel)
    );

    // Sort: Critical first, then Warning, then Info (positive news last).
    // Unchanged from the original — sorting stays here, once, so ordering
    // is consistent across all three sources rather than each file trying
    // to sort its own slice.
    usort($alerts, function ($a, $b) {
        $prio = ['Critical' => 0, 'Warning' => 1, 'Info' => 2];
        return ($prio[$a['severity']] ?? 3) <=> ($prio[$b['severity']] ?? 3);
    });

    return $alerts;
}