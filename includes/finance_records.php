<?php
// ============================================================
// includes/finance_records.php
// Shared finance definitions on the target schema, used by the billing,
// trip cost, Trip Pay, payroll, finance and analytics pages so the same
// figure is computed the same way everywhere.
//
//   Revenue (billed)   billings.total_amount, status not Draft/Cancelled,
//                      dated by billing_date
//   Collected          collections.amount, dated by payment_date
//   Outstanding        total_amount - collected, for non-Cancelled,
//                      non-Draft billings
//   Overdue            outstanding > 0 and due_date < today (computed on
//                      read; the stored status stays Issued / Partially Paid)
//   Trip expenses      expense_records with reference_type 'trip'
//                      (Fuel, Toll, Driver Allowance, Other - T-F1),
//                      dated by expense_date
//   Trip Pay (wages)   trip_pay_records not Cancelled, dated by the trip's
//                      completion (else scheduled departure) - kept separate
//                      from Driver Allowance
//   Payroll            payroll_records net_amount, dated by its
//                      disbursement (reference PR-REC-<id>)
//   Maintenance cost   maintenance_records.total_cost, not Cancelled,
//                      dated by completion / start / scheduled / created
// ============================================================

require_once __DIR__ . '/metrics.php';

const TRIP_PAY_TYPES = ['Driver' => 'Driver Trip Pay', 'Helper' => 'Helper Trip Pay'];
const BILLING_REVENUE_STATUSES = ['Issued', 'Partially Paid', 'Paid', 'Overdue'];
const PAYROLL_DISBURSEMENT_PREFIX = 'PR-REC-';
// Expense types counted as operating expenses in cost totals. Maintenance,
// Parts and Payroll expense rows are left out because those costs are
// counted from maintenance_records and payroll_records (no double counting).
const OPERATING_EXPENSE_TYPES = ['Fuel', 'Toll', 'Driver Allowance', 'Operating', 'Other'];

function operatingExpenseSql(string $alias = 'er'): string {
    $a = metricAlias($alias);
    return "{$a}.expense_type IN (" . metricSqlList(OPERATING_EXPENSE_TYPES) . ')';
}

function billingCollectedSql(string $alias = 'b'): string {
    $a = metricAlias($alias);
    return "(SELECT COALESCE(SUM(cpay.amount), 0) FROM collections cpay WHERE cpay.billing_id = {$a}.billing_id)";
}

function billingRevenueSql(string $alias = 'b'): string {
    $a = metricAlias($alias);
    return "{$a}.status IN (" . metricSqlList(BILLING_REVENUE_STATUSES) . ')';
}

/**
 * Display status: 'Overdue' when an unpaid issued billing is past due.
 */
function billingDisplayStatusSql(string $alias = 'b'): string {
    $a = metricAlias($alias);
    return "CASE WHEN {$a}.status IN ('Issued', 'Partially Paid', 'Overdue')
                  AND {$a}.due_date IS NOT NULL AND {$a}.due_date < CURDATE()
             THEN 'Overdue' ELSE {$a}.status END";
}

function maintenanceRecordDateSql(string $alias = 'mr'): string {
    $a = metricAlias($alias);
    return "COALESCE({$a}.actual_completion_date, {$a}.start_date, {$a}.scheduled_date, DATE({$a}.created_at))";
}

function tripPayDateSql(string $tripAlias = 't'): string {
    $a = metricAlias($tripAlias);
    return "DATE(COALESCE({$a}.completion_time, {$a}.scheduled_departure))";
}

function payrollPaidDateSql(string $alias = 'pr'): string {
    $a = metricAlias($alias);
    return "(SELECT MAX(pd.payment_date) FROM payroll_disbursements pd
              WHERE pd.payroll_period_id = {$a}.payroll_period_id
                AND (pd.reference_number = CONCAT('" . PAYROLL_DISBURSEMENT_PREFIX . "', {$a}.payroll_record_id)
                     OR pd.reference_number LIKE CONCAT('" . PAYROLL_DISBURSEMENT_PREFIX . "', {$a}.payroll_record_id, ' %')))";
}

/**
 * Notes stored after the reference on a payroll disbursement, or null.
 */
function payrollNotesSql(string $alias = 'pr'): string {
    $a = metricAlias($alias);
    return "(SELECT NULLIF(TRIM(SUBSTRING(MAX(pd.reference_number), CHAR_LENGTH(CONCAT('" . PAYROLL_DISBURSEMENT_PREFIX . "', {$a}.payroll_record_id)) + 1)), '')
              FROM payroll_disbursements pd
              WHERE pd.payroll_period_id = {$a}.payroll_period_id
                AND pd.reference_number LIKE CONCAT('" . PAYROLL_DISBURSEMENT_PREFIX . "', {$a}.payroll_record_id, ' %'))";
}

/**
 * Notes stored on a Trip Pay row: the entered paid date first (the target
 * row has no paid-date column), then the user's notes; max 255 characters.
 */
function tripPayComposeNotes(string $paidDate, ?string $notes): string {
    $text = 'Paid ' . $paidDate . ($notes !== null && $notes !== '' ? '. ' . $notes : '');
    return mb_substr($text, 0, 255, 'UTF-8');
}

/**
 * ['paid_date' => ?string, 'notes' => ?string] from stored Trip Pay notes.
 */
function tripPaySplitNotes(?string $stored): array {
    if ($stored !== null && preg_match('/^Paid (\d{4}-\d{2}-\d{2})(?:\. (.*))?$/s', $stored, $m)) {
        return ['paid_date' => $m[1], 'notes' => ($m[2] ?? '') !== '' ? $m[2] : null];
    }
    return ['paid_date' => null, 'notes' => $stored];
}

/**
 * Description stored on a trip expense_records row (NOT NULL): the "other"
 * description and notes joined, else the expense type.
 */
function tripExpenseDescription(string $type, ?string $otherDescription, ?string $notes): string {
    $parts = array_values(array_filter([$otherDescription, $notes], static fn($v) => $v !== null && $v !== ''));
    return $parts ? implode(' — ', $parts) : $type;
}
