<?php
// analytics/descriptive_analytics.php
//
// DESCRIPTIVE LAYER — Step 1 of the analytics system.
//
// WHAT THIS DOES:
// - Answers one simple question: "What is the number right now?"
// - Works like a basic snapshot: it checks current numbers against a set limit.
//   (Example: "If usage is below 60%, raise a red flag" — no matter if usage is getting better or worse).
// - Does NOT compare past data and does NOT try to explain why something happened.
//
// WHY IT NEEDS THE DATABASE ($pdo):
// - Out of the 3 detection files (Descriptive, Comparative, Diagnostic), this is the ONLY ONE
//   that talks directly to the database ($pdo).
// - Reason: It needs to run a live count of "open incidents" straight from the `incidents` table,
//   instead of relying on the pre-calculated numbers from `pages/analytics.php`.
//
// HOW IT FITS INTO THE APP:
// - Called by `includes/alerts.php`.
// - The system combines the results from this file with `comparative_analytics.php`
//   and `diagnostic_analytics.php`, sorts them into one list, and shows the final alerts.
//

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