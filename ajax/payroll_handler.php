<?php
// ============================================================
// ajax/payroll_handler.php
// action=list   — recent payroll records (for the Payroll tab table)
// action=create — log a new payroll disbursement
// action=delete — soft-delete a payroll record (recoverable via Recycle Bin)
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/soft_delete.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db_helpers.php';

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]);
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';

// ── List recent payroll records ───────────────────────────────────────────────
if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT
            pr.payroll_id, pr.employee_id, pr.pay_period_start, pr.pay_period_end,
            pr.amount_paid, pr.paid_date, pr.notes,
            e.full_name AS employee_name, e.position,
            u.full_name AS recorded_by_name
        FROM payroll_records pr
        JOIN employees e ON pr.employee_id = e.employee_id
        JOIN users u     ON pr.recorded_by = u.user_id
        ORDER BY pr.paid_date DESC, pr.created_at DESC
        LIMIT 200
    ");
    jsonOk(['records' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── Log a new payroll disbursement ────────────────────────────────────────────
if ($action === 'create') {
    $employeeId  = requiredInt('employee_id', 'Employee', 1);
    $periodStart = requiredString('pay_period_start', 'Pay period start');
    $periodEnd   = requiredString('pay_period_end', 'Pay period end');
    $amount      = requiredPositiveFloat('amount_paid', 'Amount paid');
    $paidDate    = requiredString('paid_date', 'Paid date');
    $notes       = optionalString('notes');

    if (strtotime($periodEnd) < strtotime($periodStart)) {
        jsonFail('Enter a valid pay period.');
    }
    if (strtotime($paidDate) === false) {
        jsonFail('Enter a valid paid date.');
    }

    $emp = findOrFail($pdo, 'employees', 'employee_id', $employeeId, 'Employee not found.');

    // Drivers and Helpers are on-call and already compensated per trip via
    // Driver Allowance in trip_expenses — a payroll entry on top of that
    // would double-pay them. Blocked here too, not just hidden in the UI.
    if (in_array(strtolower(trim($emp['position'])), ['driver', 'helper'], true)) {
        jsonFail('Drivers and Helpers are paid per trip via Driver Allowance, not through payroll. Log their pay on the Trip Costs page instead.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO payroll_records
            (employee_id, pay_period_start, pay_period_end, amount_paid, paid_date, notes, recorded_by)
        VALUES (:employee_id, :period_start, :period_end, :amount, :paid_date, :notes, :recorded_by)
    ");
    $stmt->execute([
        ':employee_id'  => $employeeId,
        ':period_start' => $periodStart,
        ':period_end'   => $periodEnd,
        ':amount'       => $amount,
        ':paid_date'    => $paidDate,
        ':notes'        => $notes,
        ':recorded_by'  => currentUserId(),
    ]);
    $newId = (int)$pdo->lastInsertId();

    auditLog('CREATE', 'payroll_records', $newId, null, "Paid ₱" . number_format($amount, 2) . " to {$emp['full_name']}");

    jsonOk(['payroll_id' => $newId]);
}

// ── Delete (soft) a payroll record — Head Management only ────────────────────
if ($action === 'delete') {
    if (currentRoleId() !== ROLE_HEAD_MANAGEMENT) {
        jsonFail('Only Head Management can delete payroll records.', 403);
    }

    $payrollId = requiredInt('payroll_id', 'Payroll record', 1);

    $deletedByName = $_SESSION['full_name'] ?? 'Unknown user';
    $ok = archiveAndDelete($pdo, 'payroll_records', 'payroll_id', $payrollId, currentUserId(), $deletedByName);
    if ($ok) {
        auditLog('DELETE', 'payroll_records', $payrollId, null, 'Payroll record archived');
        jsonOk([]);
    }

    jsonFail('Record not found or could not be deleted.');
}

jsonFail('Unknown action.');
