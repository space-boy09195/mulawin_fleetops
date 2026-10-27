<?php
// ============================================================
// ajax/payroll_handler.php
// action=list   — recent payroll records (for the Payroll tab table)
// action=create — log a payroll payment already made
// action=delete — soft-delete a payroll record (recoverable via Recycle Bin)
//
// Target schema: payroll_periods (unique start/end) -> payroll_records (one
// per employee per period; base / allowance / deduction / net amounts) ->
// payroll_deductions (itemised). The legacy screen logs payments that were
// already made, so the record is stored as 'Disbursed' and the paid date
// lives on a payroll_disbursements row whose reference_number is
// PR-REC-<payroll_record_id> (the target has no paid-date column on the
// record). Trip Pay (drivers / helpers) stays in trip_pay_records.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/core/audit.php';
require_once __DIR__ . '/../app/core/soft_delete.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/shared/validate.php';
require_once __DIR__ . '/../app/shared/db_helpers.php';
require_once __DIR__ . '/../app/modules/finance/finance_records.php';

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
            pr.payroll_record_id AS payroll_id, pr.employee_id,
            pp.period_start AS pay_period_start, pp.period_end AS pay_period_end,
            pr.base_amount, pr.allowance_amount, pr.deduction_amount,
            pr.net_amount AS amount_paid, " . payrollPaidDateSql('pr') . " AS paid_date,
            pr.status,
            e.full_name AS employee_name, e.position
        FROM payroll_records pr
        JOIN payroll_periods pp ON pp.payroll_period_id = pr.payroll_period_id
        JOIN employees e ON pr.employee_id = e.employee_id
        ORDER BY pp.period_end DESC, pr.created_at DESC
        LIMIT 200
    ");
    jsonOk(['records' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── Log a payroll payment ─────────────────────────────────────────────────────
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
    // Net pay rule (unchanged): base + allowance - deductions.
    $amount = round($baseAmount + $allowanceAmount - $deductionAmount, 2);
    $paidDate    = requiredString('paid_date', 'Paid date');
    $notes       = optionalString('notes', null, 120);

    if ($baseAmount < 0 || $allowanceAmount < 0 || $deductionAmount < 0 || $amount <= 0) {
        jsonFail('Base pay and allowances cannot be negative; deductions must leave positive net pay.');
    }
    foreach ([$baseAmount, $allowanceAmount, $deductionAmount] as $component) {
        if (round($component, 2) !== $component) {
            jsonFail('Payroll amounts may have at most two decimal places.');
        }
    }
    if (!isValidDate($periodStart) || !isValidDate($periodEnd)) {
        jsonFail('Enter valid pay period dates.');
    }
    if ($periodEnd < $periodStart) {
        jsonFail('Enter a valid pay period.');
    }
    if (!isValidDate($paidDate)) {
        jsonFail('Enter a valid paid date.');
    }

    $emp = findOrFail($pdo, 'employees', 'employee_id', $employeeId, 'Employee not found.');

    // Drivers and Helpers are paid per trip through Trip Pay, not this
    // periodic payroll flow. Driver Allowance remains a separate expense.
    if (in_array(strtolower(trim((string)$emp['position'])), ['driver', 'helper'], true)) {
        jsonFail('Drivers and Helpers are paid per trip through Trip Pay, not through periodic payroll. Log their wages on the Trip Pay page instead.');
    }

    try {
        $pdo->beginTransaction();
        // One payroll period per date range (uq_payroll_period).
        $pdo->prepare(
            "INSERT INTO payroll_periods (period_start, period_end, status, prepared_by)
             VALUES (?, ?, 'Disbursed', ?)
             ON DUPLICATE KEY UPDATE payroll_period_id = LAST_INSERT_ID(payroll_period_id)"
        )->execute([$periodStart, $periodEnd, currentUserId()]);
        $periodId = (int)$pdo->lastInsertId();

        try {
            $pdo->prepare(
                "INSERT INTO payroll_records
                    (payroll_period_id, employee_id, base_amount, trip_pay, allowance_amount,
                     deduction_amount, net_amount, status)
                 VALUES (?, ?, ?, 0, ?, ?, ?, 'Disbursed')"
            )->execute([$periodId, $employeeId, $baseAmount, $allowanceAmount, $deductionAmount, $amount]);
        } catch (PDOException $e) {
            // uq_payroll_employee_period: one record per employee per period.
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                $pdo->rollBack();
                jsonFail('A payroll record already exists for this employee and pay period.', 409);
            }
            throw $e;
        }
        $newId = (int)$pdo->lastInsertId();
        if ($validatedDeductions) {
            $insertDeduction = $pdo->prepare(
                'INSERT INTO payroll_deductions (payroll_record_id, deduction_type, amount) VALUES (?, ?, ?)'
            );
            foreach ($validatedDeductions as $deduction) {
                $insertDeduction->execute([$newId, $deduction['name'], $deduction['amount']]);
            }
        }
        // The payment itself: date, amount, reference to the record. The
        // legacy form has no payment method, so it is recorded as 'Other'.
        $reference = PAYROLL_DISBURSEMENT_PREFIX . $newId;
        $pdo->prepare(
            "INSERT INTO payroll_disbursements
                (payroll_period_id, amount, payment_date, payment_method, reference_number, processed_by)
             VALUES (?, ?, ?, 'Other', ?, ?)"
        )->execute([
            $periodId, $amount, $paidDate,
            mb_substr($reference . ($notes !== null ? ' ' . $notes : ''), 0, 150, 'UTF-8'),
            currentUserId(),
        ]);
        auditLog('CREATE', 'payroll_records', $newId, null, [
            'employee' => $emp['full_name'],
            'payroll_period_id' => $periodId,
            'base_amount' => $baseAmount,
            'allowance_amount' => $allowanceAmount,
            'deduction_amount' => $deductionAmount,
            'net_amount' => $amount,
            'paid_date' => $paidDate,
            'notes' => $notes,
        ]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('payroll_handler/create: ' . $e->getMessage());
        jsonFail('Could not record payroll payment.', 500);
    }

    jsonOk(['payroll_id' => $newId], 'Payroll payment recorded.');
}

// ── Delete (soft) a payroll record — Admin only ──────────────────────────────
if ($action === 'delete') {
    if (!isAdmin()) {
        jsonFail('Only an Admin can delete payroll records.', 403);
    }

    $payrollId = requiredInt('payroll_id', 'Payroll record', 1);

    $deletedByName = $_SESSION['full_name'] ?? 'Unknown user';
    $ok = archiveAndDelete($pdo, 'payroll_records', 'payroll_record_id', $payrollId, currentUserId(), $deletedByName, 'Payroll record #' . $payrollId);
    if ($ok) {
        auditLog('DELETE', 'payroll_records', $payrollId, null, 'Payroll record archived');
        jsonOk([]);
    }

    jsonFail('Record not found or could not be deleted.');
}

jsonFail('Unknown action.');
