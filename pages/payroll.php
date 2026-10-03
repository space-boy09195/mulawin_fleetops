<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('payroll.view');

$pdo = getDBConnection();
$records = $pdo->query(
    "SELECT pr.payroll_id, pr.pay_period_start, pr.pay_period_end, pr.base_amount,
            pr.allowance_amount, pr.deduction_amount, pr.amount_paid,
            pr.paid_date, pr.notes, e.full_name AS employee_name, e.position,
            u.full_name AS recorded_by_name
     FROM payroll_records pr
     JOIN employees e ON e.employee_id = pr.employee_id
     JOIN users u ON u.user_id = pr.recorded_by
     ORDER BY pr.paid_date DESC, pr.created_at DESC
     LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);
$employees = [];
if (currentUserHasAnyPermission(['payroll.manage'])) {
    $employees = $pdo->query(
        "SELECT employee_id, full_name, position
         FROM employees
         WHERE is_active = 1 AND LOWER(position) NOT IN ('driver', 'helper')
         ORDER BY full_name"
    )->fetchAll(PDO::FETCH_ASSOC);
}
layoutHead('Payroll');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
  <div>
    <h1 class="page-title">Payroll</h1>
    <p class="page-subtitle">Record periodic salary/payroll separately from per-trip Driver/Helper wages (Trip Pay) and trip-related Driver Allowance expenses.</p>
  </div>
</div>

<div id="payrollFeedback" class="alert d-none" role="alert"></div>

<?php if ($employees): ?>
<div class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Record payroll payment</h2></div>
  <div class="card-body-custom">
    <form id="payrollForm" class="row g-3">
      <div class="col-md-4">
        <label class="form-label" for="payrollEmployee">Employee</label>
        <select class="form-select" id="payrollEmployee" required>
          <option value="">Select employee</option>
          <?php foreach ($employees as $employee): ?>
          <option value="<?= (int)$employee['employee_id'] ?>">
            <?= htmlspecialchars($employee['full_name'] . ' — ' . $employee['position']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="payrollStart">Pay period start</label>
        <input class="form-control" type="date" id="payrollStart" required>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="payrollEnd">Pay period end</label>
        <input class="form-control" type="date" id="payrollEnd" required>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="payrollBase">Base pay</label>
        <input class="form-control" type="number" id="payrollBase" min="0" step="0.01" value="0.00" required>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="payrollAllowance">Allowances</label>
        <input class="form-control" type="number" id="payrollAllowance" min="0" step="0.01" value="0.00" required>
      </div>
      <div class="col-12">
        <label class="form-label">Deduction breakdown</label>
        <datalist id="payrollDeductionNames">
          <option value="SSS"><option value="PhilHealth"><option value="Pag-IBIG">
          <option value="Withholding Tax"><option value="Cash Advance">
          <option value="Loan Repayment"><option value="Other">
        </datalist>
        <div id="payrollDeductions" class="vstack gap-2"></div>
        <button class="btn btn-sm btn-outline-secondary mt-2" type="button" id="addPayrollDeduction">Add deduction</button>
        <input type="hidden" id="payrollDeduction" value="0.00">
        <div class="form-text">Payroll allowances are periodic payroll components, not Driver Allowance trip expenses. Enter each deduction and amount manually; this system does not calculate statutory contribution rates.</div>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="payrollDeductionTotal">Total deductions</label>
        <input class="form-control" type="number" id="payrollDeductionTotal" value="0.00" readonly>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="payrollNet">Net pay</label>
        <input class="form-control" type="number" id="payrollNet" readonly>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="payrollPaidDate">Paid date</label>
        <input class="form-control" type="date" id="payrollPaidDate" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="payrollNotes">Notes</label>
        <input class="form-control" type="text" id="payrollNotes" maxlength="1000">
      </div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Record payment</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header-custom"><h2 class="card-title-custom">Payroll records</h2></div>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Employee</th><th>Position</th><th>Period</th><th>Base</th><th>Allowances</th><th>Deductions</th><th>Net pay</th><th>Paid date</th><th>Recorded by</th><th>Payslip</th></tr></thead>
      <tbody>
      <?php foreach ($records as $record): ?>
        <tr>
          <td><?= htmlspecialchars($record['employee_name']) ?></td>
          <td><?= htmlspecialchars($record['position']) ?></td>
          <td><?= htmlspecialchars($record['pay_period_start'] . ' to ' . $record['pay_period_end']) ?></td>
          <td>₱<?= number_format((float)$record['base_amount'], 2) ?></td>
          <td>₱<?= number_format((float)$record['allowance_amount'], 2) ?></td>
          <td>₱<?= number_format((float)$record['deduction_amount'], 2) ?></td>
          <td>₱<?= number_format((float)$record['amount_paid'], 2) ?></td>
          <td><?= htmlspecialchars($record['paid_date']) ?></td>
          <td><?= htmlspecialchars($record['recorded_by_name']) ?></td>
          <td><a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= APP_BASE ?>/pages/payroll_slip.php?id=<?= (int)$record['payroll_id'] ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$records): ?><tr><td colspan="10" class="text-center text-muted py-4">No payroll records found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($employees): ?>
<script>
const updateNetPay = () => {
  const base = Number(document.getElementById('payrollBase').value) || 0;
  const allowances = Number(document.getElementById('payrollAllowance').value) || 0;
  const deductions = Array.from(document.querySelectorAll('.payroll-deduction-amount'))
    .reduce((total, input) => total + (Number(input.value) || 0), 0);
  document.getElementById('payrollDeduction').value = deductions.toFixed(2);
  document.getElementById('payrollDeductionTotal').value = deductions.toFixed(2);
  document.getElementById('payrollNet').value = Math.max(0, base + allowances - deductions).toFixed(2);
};
['payrollBase', 'payrollAllowance', 'payrollDeduction'].forEach(id => document.getElementById(id)?.addEventListener('input', updateNetPay));
const deductionContainer = document.getElementById('payrollDeductions');
const addPayrollDeduction = (name = '', amount = '') => {
  const row = document.createElement('div');
  row.className = 'row g-2 payroll-deduction-row';
  row.innerHTML = '<div class="col-md-7"><input class="form-control payroll-deduction-name" list="payrollDeductionNames" maxlength="100" placeholder="Deduction name" aria-label="Deduction name"></div><div class="col-md-4"><input class="form-control payroll-deduction-amount" type="number" min="0.01" step="0.01" placeholder="Amount" aria-label="Deduction amount"></div><div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100 remove-payroll-deduction" aria-label="Remove deduction">&times;</button></div>';
  row.querySelector('.payroll-deduction-name').value = name;
  row.querySelector('.payroll-deduction-amount').value = amount;
  row.querySelector('.payroll-deduction-amount').addEventListener('input', updateNetPay);
  row.querySelector('.remove-payroll-deduction').addEventListener('click', () => { row.remove(); updateNetPay(); });
  deductionContainer.appendChild(row);
};
document.getElementById('addPayrollDeduction').addEventListener('click', () => addPayrollDeduction());
addPayrollDeduction();
updateNetPay();
document.getElementById('payrollForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const feedback = document.getElementById('payrollFeedback');
  const data = new URLSearchParams({
    action: 'create',
    employee_id: document.getElementById('payrollEmployee').value,
    pay_period_start: document.getElementById('payrollStart').value,
    pay_period_end: document.getElementById('payrollEnd').value,
    base_amount: document.getElementById('payrollBase').value,
    allowance_amount: document.getElementById('payrollAllowance').value,
    deduction_amount: document.getElementById('payrollDeduction').value,
    deductions: JSON.stringify(Array.from(document.querySelectorAll('.payroll-deduction-row')).map(row => ({
      name: row.querySelector('.payroll-deduction-name').value.trim(),
      amount: row.querySelector('.payroll-deduction-amount').value
    })).filter(item => item.name !== '' || item.amount !== '')),
    paid_date: document.getElementById('payrollPaidDate').value,
    notes: document.getElementById('payrollNotes').value,
    [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN
  });
  const response = await fetch('<?= APP_BASE ?>/ajax/payroll_handler.php', {method: 'POST', body: data});
  const result = await response.json();
  feedback.textContent = result.message || (result.success ? 'Payroll recorded.' : 'Could not record payroll.');
  feedback.className = `alert alert-${result.success ? 'success' : 'danger'}`;
  if (result.success) setTimeout(() => window.location.reload(), 700);
});
</script>
<?php endif; ?>

<?php layoutFoot(); ?>
