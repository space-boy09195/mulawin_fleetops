<?php
// analytics/descriptive_analytics.php
//
// DESCRIPTIVE layer — first step in the Descriptive -> Comparative ->
// Diagnostic -> Prescriptive progression. Answers only "what is the
// number right now?" There is no comparison to a previous period here
// and no investigation into causes — just a snapshot check against a
// fixed, hardcoded threshold (e.g. "utilization under 60% is worth
// flagging, regardless of whether it's trending up or down").
//
// This is the only one of the three DETECTION files (descriptive,
// comparative, diagnostic) that needs $pdo directly on its own — not for
// any of the period-based metrics, but because "open incidents" is a
// live COUNT(*) against the incidents table rather than something
// derived from the $metrics array pages/analytics.php builds.
//
// Called by includes/alerts.php, which merges this file's output with
// comparative_analytics.php and diagnostic_analytics.php before sorting
// and returning the combined alert list.

require_once __DIR__ . '/prescriptive_analytics.php';

function getDescriptiveAlerts(PDO $pdo, array $metrics, string $periodLabel): array {
    $alerts = [];

    $util   = isset($metrics['utilizationRate']) ? (float)$metrics['utilizationRate'] : null;
    $coll   = isset($metrics['collectionRate'])  ? (float)$metrics['collectionRate']  : null;
    $onTime = isset($metrics['onTimeRate'])      ? (float)$metrics['onTimeRate']      : null;

    // ══════════════════════════════════════════════════════════════════════
    // FIXED-THRESHOLD CHECKS — still valid regardless of trend direction.
    // A truck fleet running at 55% utilization is a problem whether it was
    // 40% or 70% last period; that's what makes this "descriptive" rather
    // than "comparative" — no previous-period value is read or needed.
    // ══════════════════════════════════════════════════════════════════════
    if ($util !== null && $util < 60) {
        $alerts[] = [
            'alert' => 'Low fleet utilization',
            'severity' => 'Warning',
            'source' => 'Operations',
            'detail' => "Fleet utilization is {$util}% for {$periodLabel} — consider reassigning idle trucks or promoting load consolidation.",
            'action_url' => '/pages/fleet_status.php',
            'type' => 'utilization',
            'prescription' => getPrescription('utilization'),
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
            'prescription' => getPrescription('collections'),
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
            'prescription' => getPrescription('ontime'),
        ];
    }

    // ── Open incidents — a live snapshot, not tied to any period at all.
    //    This is the most "purely descriptive" check in the whole engine:
    //    it's just a raw count, full stop. ──
    $openIncidents = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE resolved_at IS NULL")->fetchColumn();
    if ($openIncidents > 0) {
        $alerts[] = [
            'alert' => 'Open incidents',
            'severity' => $openIncidents > 5 ? 'Critical' : 'Warning',
            'source' => 'Safety',
            'detail' => "{$openIncidents} unresolved incident" . ($openIncidents !== 1 ? 's' : '') . '. Investigate and resolve.',
            'action_url' => '/pages/incidents.php',
            'type' => 'incidents',
            'prescription' => getPrescription('incidents'),
        ];
    }

    return $alerts;
}