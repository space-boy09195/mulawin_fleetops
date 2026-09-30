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

requireAnyPermission(['payroll.view', 'payroll.manage']);
requirePostMethod();
enforceCsrf();

$pdo    = getDBConnection();
$action = $_POST['action'] ?? '';
if ($action === 'list') {
    requirePermission('payroll.view');
} else {
    requirePermission('payroll.manage');
}

// ── List recent payroll records ───────────────────────────────────────────────
if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT
            pr.payroll_id, pr.employee_id, pr.pay_period_start, pr.pay_period_end,
            pr.base_amount, pr.allowance_amount, pr.deduction_amount,
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
    $baseAmount = ($_POST['base_amount'] ?? '') !== ''
        ? (float)$_POST['base_amount']
        : requiredPositiveFloat('amount_paid', 'Amount paid');
    $allowanceAmount = ($_POST['allowance_amount'] ?? '') !== '' ? (float)$_POST['allowance_amount'] : 0.0;
    $deductionAmount = ($_POST['deduction_amount'] ?? '') !== '' ? (float)$_POST['deduction_amount'] : 0.0;
    $deductions = json_decode((string)($_POST['deductions'] ?? ''), true);
    if ($deductions === null && ($_POST['deductions'] ?? '') !== '') {
        jsonFail('Deduction breakdown is invalid.');
    }
    if (!is_array($deductions)) {
        $deductions = [];
    }
    if (count($deductions) > 30) {
        jsonFail('A payroll record cannot contain more than 30 deduction items.');
    }
    $validatedDeductions = [];
    $itemizedDeductionTotal = 0.0;
    foreach ($deductions as $index => $deduction) {
        if (!is_array($deduction)) {
            jsonFail('Deduction item ' . ((int)$index + 1) . ' is invalid.');
        }
        $name = trim((string)($deduction['name'] ?? ''));
        $rawAmount = trim((string)($deduction['amount'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100
            || !preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $rawAmount)
            || (float)$rawAmount <= 0) {
            jsonFail('Each deduction needs a name and a positive amount with at most two decimal places.');
        }
        $itemAmount = round((float)$rawAmount, 2);
        $itemizedDeductionTotal += $itemAmount;
        $validatedDeductions[] = ['name' => $name, 'amount' => $itemAmount];
    }
    if ($validatedDeductions) {
        if ($deductionAmount > 0 && abs(round($deductionAmount - $itemizedDeductionTotal, 2)) > 0.009) {
            jsonFail('The deduction total must match the sum of its breakdown items.');
        }
        $deductionAmount = round($itemizedDeductionTotal, 2);
    }
    $amount = round($baseAmount + $allowanceAmount - $deductionAmount, 2);
    $paidDate    = requiredString('paid_date', 'Paid date');
    $notes       = optionalString('notes');

    if ($baseAmount < 0 || $allowanceAmount < 0 || $deductionAmount < 0 || $amount <= 0) {
        jsonFail('Base pay and allowances cannot be negative; deductions must leave positive net pay.');
    }
    foreach ([$baseAmount, $allowanceAmount, $deductionAmount] as $component) {
        if (round($component, 2) !== $component) {
            jsonFail('Payroll amounts may have at most two decimal places.');
        }
    }
    $periodStartDate = DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
    $periodEndDate = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);
    $paidDateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $paidDate);
    if (!$periodStartDate || $periodStartDate->format('Y-m-d') !== $periodStart
        || !$periodEndDate || $periodEndDate->format('Y-m-d') !== $periodEnd) {
        jsonFail('Enter valid pay period dates.');
    }
    if (strtotime($periodEnd) < strtotime($periodStart)) {
        jsonFail('Enter a valid pay period.');
    }
    if (!$paidDateValue || $paidDateValue->format('Y-m-d') !== $paidDate) {
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
            (employee_id, pay_period_start, pay_period_end, base_amount, allowance_amount,
             deduction_amount, amount_paid, paid_date, notes, recorded_by)
        VALUES (:employee_id, :period_start, :period_end, :base_amount, :allowance_amount,
                :deduction_amount, :amount, :paid_date, :notes, :recorded_by)
    ");
    try {
        $pdo->beginTransaction();
        $stmt->execute([
            ':employee_id'  => $employeeId,
            ':period_start' => $periodStart,
            ':period_end'   => $periodEnd,
            ':base_amount' => $baseAmount,
            ':allowance_amount' => $allowanceAmount,
            ':deduction_amount' => $deductionAmount,
            ':amount'       => $amount,
            ':paid_date'    => $paidDate,
            ':notes'        => $notes,
            ':recorded_by'  => currentUserId(),
        ]);
        $newId = (int)$pdo->lastInsertId();
        if ($validatedDeductions) {
            $insertDeduction = $pdo->prepare(
                'INSERT INTO payroll_deductions (payroll_id, deduction_name, amount) VALUES (?, ?, ?)'
            );
            foreach ($validatedDeductions as $deduction) {
                $insertDeduction->execute([$newId, $deduction['name'], $deduction['amount']]);
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('payroll_handler/create: ' . $e->getMessage());
        jsonFail('Could not record payroll payment.', 500);
    }

    auditLog('CREATE', 'payroll_records', $newId, null, [
        'employee' => $emp['full_name'],
        'base_amount' => $baseAmount,
        'allowance_amount' => $allowanceAmount,
        'deduction_amount' => $deductionAmount,
        'net_amount' => $amount,
    ]);

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
