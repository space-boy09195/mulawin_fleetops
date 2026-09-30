<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('payroll.view');
$payrollId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($payrollId === false) {
    http_response_code(400);
    exit('Invalid payroll record.');
}
$stmt = getDBConnection()->prepare(
    'SELECT pr.payroll_id, pr.pay_period_start, pr.pay_period_end, pr.base_amount,
            pr.allowance_amount, pr.deduction_amount, pr.amount_paid, pr.paid_date,
            pr.notes, e.full_name, e.position, e.employee_code, u.full_name AS recorded_by
     FROM payroll_records pr
     JOIN employees e ON e.employee_id = pr.employee_id
     JOIN users u ON u.user_id = pr.recorded_by
     WHERE pr.payroll_id = ?'
);
$stmt->execute([$payrollId]);
$slip = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$slip) {
    http_response_code(404);
    exit('Payslip not found.');
}
$deductionStmt = getDBConnection()->prepare(
    'SELECT deduction_name, amount FROM payroll_deductions WHERE payroll_id = ? ORDER BY deduction_id'
);
$deductionStmt->execute([$payrollId]);
$deductions = $deductionStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Payslip <?= (int)$slip['payroll_id'] ?></title>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <style>
    body { max-width: 760px; margin: 2rem auto; padding: 0 1rem; color: #17212b; }
    .amount { font-variant-numeric: tabular-nums; }
    @media print { .no-print { display: none !important; } body { margin: 0; } }
  </style>
</head>
<body>
  <div class="d-flex justify-content-between align-items-start">
    <div><h1>Payroll Payslip</h1><p class="text-muted">Mulawin FleetOps</p></div>
    <button class="btn btn-primary no-print" type="button" onclick="window.print()">Print / Save PDF</button>
  </div>
  <hr>
  <dl class="row">
    <dt class="col-sm-4">Employee</dt><dd class="col-sm-8"><?= htmlspecialchars($slip['full_name']) ?></dd>
    <dt class="col-sm-4">Employee code</dt><dd class="col-sm-8"><?= htmlspecialchars($slip['employee_code']) ?></dd>
    <dt class="col-sm-4">Position</dt><dd class="col-sm-8"><?= htmlspecialchars($slip['position']) ?></dd>
    <dt class="col-sm-4">Pay period</dt><dd class="col-sm-8"><?= htmlspecialchars($slip['pay_period_start'] . ' to ' . $slip['pay_period_end']) ?></dd>
    <dt class="col-sm-4">Payment date</dt><dd class="col-sm-8"><?= htmlspecialchars($slip['paid_date']) ?></dd>
  </dl>
  <table class="table">
    <tbody>
      <tr><th>Base pay</th><td class="text-end amount">₱<?= number_format((float)$slip['base_amount'], 2) ?></td></tr>
      <tr><th>Allowances</th><td class="text-end amount">₱<?= number_format((float)$slip['allowance_amount'], 2) ?></td></tr>
      <?php if ($deductions): foreach ($deductions as $deduction): ?>
      <tr><th>Deduction: <?= htmlspecialchars($deduction['deduction_name']) ?></th><td class="text-end amount">− ₱<?= number_format((float)$deduction['amount'], 2) ?></td></tr>
      <?php endforeach; else: ?>
      <tr><th>Deductions (legacy total; no itemized breakdown)</th><td class="text-end amount">− ₱<?= number_format((float)$slip['deduction_amount'], 2) ?></td></tr>
      <?php endif; ?>
      <tr><th>Total deductions</th><td class="text-end amount">− ₱<?= number_format((float)$slip['deduction_amount'], 2) ?></td></tr>
      <tr class="table-light"><th>Net pay</th><th class="text-end amount">₱<?= number_format((float)$slip['amount_paid'], 2) ?></th></tr>
    </tbody>
  </table>
  <?php if ($slip['notes']): ?><p><strong>Notes:</strong> <?= nl2br(htmlspecialchars($slip['notes'])) ?></p><?php endif; ?>
  <p class="text-muted small mt-5">Recorded by <?= htmlspecialchars($slip['recorded_by']) ?>. Payroll record #<?= (int)$slip['payroll_id'] ?>.</p>
</body>
</html>
