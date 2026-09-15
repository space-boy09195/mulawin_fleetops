<?php
// includes/alerts.php
//
// ALERTS ORCHESTRATOR — The Boss/Traffic Controller for the Analytics Page.
//
// WHAT THIS DOES:
// - Manages and gathers all alerts in one place.
// - Acts as a middleman (orchestrator): it doesn't do any calculations itself, 
//   it just calls the 4 main analytics layer files and merges their results together:
//     1. descriptive_analytics.php  -> What is the number right now?
//     2. comparative_analytics.php  -> How does it compare to last period?
//     3. diagnostic_analytics.php   -> What/Who caused the change?
//     4. prescriptive_analytics.php -> What actions should we take?
//
// WHY IT IS EASY TO USE:
// - `getAnalyticsAlerts()` works exactly the same as it did before the code split. 
// - Other files (like `pages/analytics.php`) call it the same way without needing any updates.
//
// WHAT EACH ALERT CONTAINS (Output Structure):
// - 'alert'        => Short summary headline
// - 'severity'     => 'Critical', 'Warning', or 'Info'
// - 'source'       => Department responsible
// - 'detail'       => One-line breakdown with the actual numbers
// - 'action_url'   => Link/page to go fix the issue
// - 'type'         => Internal code label
// - 'priority'     => Visual styling tier ('high', 'medium', or 'good')
// - 'prescription' => List of recommended action bullet points
//
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