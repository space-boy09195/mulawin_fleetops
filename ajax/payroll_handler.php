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

header('Content-Type: application/json');

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

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
    echo json_encode(['success' => true, 'records' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── Log a new payroll disbursement ────────────────────────────────────────────
if ($action === 'create') {
    $employeeId  = (int)($_POST['employee_id'] ?? 0);
    $periodStart = trim($_POST['pay_period_start'] ?? '');
    $periodEnd   = trim($_POST['pay_period_end'] ?? '');
    $amount      = (float)($_POST['amount_paid'] ?? 0);
    $paidDate    = trim($_POST['paid_date'] ?? '');
    $notes       = trim($_POST['notes'] ?? '') ?: null;

    if ($employeeId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Select an employee.']);
        exit;
    }
    if (!$periodStart || !$periodEnd || strtotime($periodEnd) < strtotime($periodStart)) {
        echo json_encode(['success' => false, 'message' => 'Enter a valid pay period.']);
        exit;
    }
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Amount paid must be greater than zero.']);
        exit;
    }
    if (!$paidDate || strtotime($paidDate) === false) {
        echo json_encode(['success' => false, 'message' => 'Enter a valid paid date.']);
        exit;
    }

    $empCheck = $pdo->prepare("SELECT full_name, position FROM employees WHERE employee_id = ?");
    $empCheck->execute([$employeeId]);
    $emp = $empCheck->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        echo json_encode(['success' => false, 'message' => 'Employee not found.']);
        exit;
    }
    // Drivers and Helpers are on-call and already compensated per trip via
    // Driver Allowance in trip_expenses — a payroll entry on top of that
    // would double-pay them. Blocked here too, not just hidden in the UI.
    if (in_array(strtolower(trim($emp['position'])), ['driver', 'helper'], true)) {
        echo json_encode(['success' => false, 'message' => 'Drivers and Helpers are paid per trip via Driver Allowance, not through payroll. Log their pay on the Trip Costs page instead.']);
        exit;
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

    echo json_encode(['success' => true, 'payroll_id' => $newId]);
    exit;
}

// ── Delete (soft) a payroll record — Head Management only ────────────────────
if ($action === 'delete') {
    if (currentRoleId() !== ROLE_HEAD_MANAGEMENT) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only Head Management can delete payroll records.']);
        exit;
    }

    $payrollId = (int)($_POST['payroll_id'] ?? 0);
    if ($payrollId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid record.']);
        exit;
    }

    $deletedByName = $_SESSION['full_name'] ?? 'Unknown user';
    $ok = archiveAndDelete($pdo, 'payroll_records', 'payroll_id', $payrollId, currentUserId(), $deletedByName);
    if ($ok) {
        auditLog('DELETE', 'payroll_records', $payrollId, null, 'Payroll record archived');
    }

    echo json_encode(['success' => $ok, 'message' => $ok ? null : 'Record not found or could not be deleted.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);