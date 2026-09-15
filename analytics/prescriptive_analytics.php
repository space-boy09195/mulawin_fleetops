<?php
// analytics/prescriptive_analytics.php
//
// PRESCRIPTIVE LAYER — Final step of the analytics system.
//
// WHAT THIS DOES:
// - Answers one simple question: "Now that we have an alert, what should we DO about it?"
// - Does NOT check thresholds, compare past data, or run database queries.
// - Acts as a cheat sheet / lookup table that pairs an alert type to an actionable fix or next step.
//
// HOW OTHER FILES USE IT:
// - The other detection files (Descriptive, Comparative, Diagnostic) call `getPrescription()` 
//   to attach action steps to an alert.
// - Example: `'prescription' => getPrescription('utilization')`
//
// WHY IT IS SPLIT INTO ITS OWN FILE:
// - Keeps action steps in one place. Anyone can edit or reword recommendations 
//   without touching code logic or risking breaking threshold checks.
//
// SAFE FALLBACK:
// - If an alert type isn't listed in the lookup table, it simply returns an empty list 
//   instead of crashing or throwing an error.
//
function getPrescription(string $type): array {
    static $prescriptions = [

        // ── Descriptive-alert prescriptions ──────────────────────────────
        'utilization' => [
            'Review idle truck list and assign to pending dispatches.',
            'Check recent trip cancellations and contact affected clients.',
            'Consider promotions or consolidated loads to increase utilization.',
        ],
        'collections' => [
            'Open the overdue invoices report and prioritize top balances.',
            'Call or email clients with invoices older than 30 days.',
            'Offer partial-payment plans where appropriate and record promises to pay.',
        ],
        'ontime' => [
            'Review delayed trips and identify common bottlenecks (loading, traffic, driver availability).',
            'Contact drivers with repeated late trips to coach or reschedule.',
            'Adjust routing or dispatch windows to improve punctuality.',
        ],
        'incidents' => [
            'Open each unresolved incident and assign an owner for remediation.',
            'If safety-related, halt the affected vehicle until inspected.',
            'Log follow-up actions and expected resolution dates.',
        ],

        // ── Diagnostic-alert prescriptions ────────────────────────────────
        // 'maint_spike' and 'maint_rise' are two severities of the same
        // underlying situation (>=30% vs >=15% cost increase), so they
        // share identical next steps — kept as two keys anyway so each
        // alert's 'type' tag maps directly to a table entry with no
        // extra branching needed at the call site.
        'maint_spike' => [
            'Review this period\'s maintenance records for high-cost repairs.',
            'Check parts inventory consumption for abnormal usage.',
            'If one truck dominates the spend, inspect it for a recurring fault rather than one-off wear.',
        ],
        'maint_rise' => [
            'Review this period\'s maintenance records for high-cost repairs.',
            'Check parts inventory consumption for abnormal usage.',
            'If one truck dominates the spend, inspect it for a recurring fault rather than one-off wear.',
        ],
        'maint_new' => [
            'Review the new maintenance entries to confirm scope and cost.',
            'Check whether multiple trucks show the same fault — may indicate a supplier or parts-batch issue.',
            'If expensive, obtain a second estimate or review warranty coverage.',
        ],
        'revenue_decline' => [
            'Compare completed-trip volume this period against the previous one.',
            'Check for completed trips that haven\'t been billed yet.',
            'Review pricing on recent contracts against prior rates.',
        ],

        // ── Comparative-alert (positive trend) prescriptions ──────────────
        // All four "improving" types get the same no-action-needed note —
        // kept as separate keys so the mapping stays explicit and each one
        // can be customized later without touching the others.
        'revenue_growth' => [
            'This is a positive trend — no action needed. Keep monitoring next period to confirm it holds.',
        ],
        'ontime_improve' => [
            'This is a positive trend — no action needed. Keep monitoring next period to confirm it holds.',
        ],
        'utilization_improve' => [
            'This is a positive trend — no action needed. Keep monitoring next period to confirm it holds.',
        ],
        'collection_improve' => [
            'This is a positive trend — no action needed. Keep monitoring next period to confirm it holds.',
        ],
    ];

    return $prescriptions[$type] ?? [];
}