<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireAnyPermission(['finance.funds.view', 'finance.disbursements.view']);
$pdo = getDBConnection();
$canManageFunds = currentUserHasAnyPermission(['finance.funds.manage']);
$canManageDisbursements = currentUserHasAnyPermission(['finance.disbursements.manage']);
$canManagePayables = currentUserHasAnyPermission(['finance.ap.manage']);
$funds = $pdo->query(
    "SELECT fr.request_number, fr.request_type, fr.purpose, fr.amount, fr.status,
            fr.requested_at, u.full_name AS requester
     FROM fund_requests fr JOIN users u ON u.user_id = fr.requested_by
     ORDER BY fr.requested_at DESC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);
$disbursements = $pdo->query(
    "SELECT disbursement_number, payee_name, amount, payment_mode, disbursed_at
     FROM finance_disbursements ORDER BY disbursed_at DESC, disbursement_id DESC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);
$payables = $pdo->query(
    "SELECT payable_id, payable_number, supplier_name, invoice_number, description, amount, due_date, status
     FROM accounts_payable ORDER BY due_date IS NULL, due_date ASC, payable_id DESC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);
$vouchers = $pdo->query(
    "SELECT pv.voucher_number, ap.payable_number, pv.amount, pv.status
     FROM payment_vouchers pv JOIN accounts_payable ap ON ap.payable_id = pv.payable_id
     ORDER BY pv.voucher_id DESC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);
$summary = $pdo->query(
    "SELECT
       (SELECT COALESCE(SUM(amount), 0) FROM billings WHERE status <> 'Paid') AS outstanding_billings,
       (SELECT COALESCE(SUM(amount), 0) FROM trip_expenses) AS recorded_expenses,
       (SELECT COALESCE(SUM(amount), 0) FROM finance_disbursements) AS disbursed_total,
       (SELECT COALESCE(SUM(amount), 0) FROM accounts_payable WHERE status IN ('Open','Partially Paid')) AS open_payables"
)->fetch(PDO::FETCH_ASSOC);
layoutHead('Finance');
?>
<div class="page-header"><h1 class="page-title">Finance</h1><p class="page-subtitle">Fund requests, cash advances, and disbursement records.</p></div>
<div id="financeFeedback" class="alert d-none" role="alert"></div>
<div class="row g-3 mb-4">
<?php foreach ([
  ['Outstanding billings', $summary['outstanding_billings']],
  ['Recorded expenses', $summary['recorded_expenses']],
  ['Disbursed total', $summary['disbursed_total']],
  ['Open payables', $summary['open_payables']]
] as [$label, $amount]): ?>
<div class="col-md-3"><div class="card h-100"><div class="card-body-custom"><div class="text-muted small"><?= htmlspecialchars($label) ?></div><div class="fs-4 fw-semibold">₱<?= number_format((float)$amount, 2) ?></div></div></div></div>
<?php endforeach; ?>
</div>
<?php if ($canManageFunds): ?>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Request funds</h2></div><div class="card-body-custom">
<form id="fundRequestForm" class="row g-3">
<div class="col-md-3"><label class="form-label">Request type</label><select id="fundType" class="form-select" required><option>Trip Allowance</option><option>Cash Advance</option><option>Operating Fund</option><option>Other</option></select></div>
<div class="col-md-3"><label class="form-label">Trip ID (optional)</label><input id="fundTrip" class="form-control" type="number" min="1"></div>
<div class="col-md-3"><label class="form-label">Amount</label><input id="fundAmount" class="form-control" type="number" min="0.01" step="0.01" required></div>
<div class="col-md-3"><label class="form-label">Purpose</label><input id="fundPurpose" class="form-control" maxlength="255" required></div>
<div class="col-12"><label class="form-label">Notes</label><input id="fundNotes" class="form-control" maxlength="1000"></div>
<div class="col-12"><button class="btn btn-primary">Submit for approval</button></div>
</form></div></div>
<?php endif; ?>
<?php if ($canManagePayables): ?>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Record accounts payable</h2></div><div class="card-body-custom">
<form id="payableForm" class="row g-3">
<div class="col-md-3"><label class="form-label">Supplier</label><input id="apSupplier" class="form-control" maxlength="150" required></div>
<div class="col-md-2"><label class="form-label">Invoice number</label><input id="apInvoice" class="form-control" maxlength="100"></div>
<div class="col-md-3"><label class="form-label">Description</label><input id="apDescription" class="form-control" maxlength="255" required></div>
<div class="col-md-2"><label class="form-label">Amount</label><input id="apAmount" class="form-control" type="number" min="0.01" step="0.01" required></div>
<div class="col-md-2"><label class="form-label">Due date</label><input id="apDueDate" class="form-control" type="date"></div>
<div class="col-12"><button class="btn btn-outline-primary">Save payable</button></div>
</form></div></div>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Prepare payment voucher</h2></div><div class="card-body-custom">
<form id="voucherForm" class="row g-3">
<div class="col-md-4"><label class="form-label">Accounts payable ID</label><input id="voucherPayable" class="form-control" type="number" min="1" required></div>
<div class="col-md-4"><label class="form-label">Voucher amount</label><input id="voucherAmount" class="form-control" type="number" min="0.01" step="0.01" required></div>
<div class="col-md-4"><label class="form-label">Notes</label><input id="voucherNotes" class="form-control" maxlength="1000"></div>
<div class="col-12"><button class="btn btn-outline-primary">Submit voucher for approval</button></div>
</form></div></div>
<?php endif; ?>
<?php if ($canManageDisbursements): ?>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Record disbursement</h2></div><div class="card-body-custom">
<form id="disbursementForm" class="row g-3">
<div class="col-md-2"><label class="form-label">Fund request ID</label><input id="disbRequest" class="form-control" type="number" min="1"></div>
<div class="col-md-2"><label class="form-label">Payment voucher ID</label><input id="disbVoucher" class="form-control" type="number" min="1"></div>
<div class="col-md-3"><label class="form-label">Payee</label><input id="disbPayee" class="form-control" maxlength="150" required></div>
<div class="col-md-2"><label class="form-label">Amount</label><input id="disbAmount" class="form-control" type="number" min="0.01" step="0.01" required></div>
<div class="col-md-2"><label class="form-label">Payment mode</label><input id="disbMode" class="form-control" maxlength="50" placeholder="Cash / Bank" required></div>
<div class="col-md-2"><label class="form-label">Date</label><input id="disbDate" class="form-control" type="date" value="<?= date('Y-m-d') ?>" required></div>
<div class="col-12"><button class="btn btn-outline-primary">Record disbursement</button></div>
</form></div></div>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Accounts payable</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Payable</th><th>Supplier</th><th>Invoice</th><th>Description</th><th>Amount</th><th>Due</th><th>Status</th></tr></thead><tbody>
<?php foreach ($payables as $payable): ?><tr><td><?= htmlspecialchars($payable['payable_number']) ?><br><span class="text-muted small">ID <?= (int)$payable['payable_id'] ?></span></td><td><?= htmlspecialchars($payable['supplier_name']) ?></td><td><?= htmlspecialchars($payable['invoice_number'] ?? '—') ?></td><td><?= htmlspecialchars($payable['description']) ?></td><td>₱<?= number_format((float)$payable['amount'], 2) ?></td><td><?= htmlspecialchars($payable['due_date'] ?? '—') ?></td><td><?= htmlspecialchars($payable['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$payables): ?><tr><td colspan="7" class="text-center text-muted py-4">No accounts payable records found.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Payment vouchers</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Voucher</th><th>Payable</th><th>Amount</th><th>Status</th></tr></thead><tbody>
<?php foreach ($vouchers as $voucher): ?><tr><td><?= htmlspecialchars($voucher['voucher_number']) ?></td><td><?= htmlspecialchars($voucher['payable_number']) ?></td><td>₱<?= number_format((float)$voucher['amount'], 2) ?></td><td><?= htmlspecialchars($voucher['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$vouchers): ?><tr><td colspan="4" class="text-center text-muted py-4">No payment vouchers found.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Fund requests</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Request</th><th>Type</th><th>Purpose</th><th>Amount</th><th>Status</th><th>Requester</th></tr></thead><tbody>
<?php foreach ($funds as $fund): ?><tr><td><?= htmlspecialchars($fund['request_number']) ?></td><td><?= htmlspecialchars($fund['request_type']) ?></td><td><?= htmlspecialchars($fund['purpose']) ?></td><td>₱<?= number_format((float)$fund['amount'], 2) ?></td><td><?= htmlspecialchars($fund['status']) ?></td><td><?= htmlspecialchars($fund['requester']) ?></td></tr><?php endforeach; ?>
<?php if (!$funds): ?><tr><td colspan="6" class="text-center text-muted py-4">No fund requests found.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><div class="card-header-custom"><h2 class="card-title-custom">Disbursements</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Number</th><th>Payee</th><th>Amount</th><th>Mode</th><th>Date</th></tr></thead><tbody>
<?php foreach ($disbursements as $item): ?><tr><td><?= htmlspecialchars($item['disbursement_number']) ?></td><td><?= htmlspecialchars($item['payee_name']) ?></td><td>₱<?= number_format((float)$item['amount'], 2) ?></td><td><?= htmlspecialchars($item['payment_mode']) ?></td><td><?= htmlspecialchars($item['disbursed_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$disbursements): ?><tr><td colspan="5" class="text-center text-muted py-4">No disbursements found.</td></tr><?php endif; ?></tbody></table></div></div>
<script>
(() => {
  const feedback = document.getElementById('financeFeedback');
  const send = async (data) => { const response = await fetch('<?= APP_BASE ?>/ajax/finance_handler.php', {method: 'POST', body: data}); const result = await response.json(); feedback.textContent = result.message || 'Request completed.'; feedback.className = `alert alert-${result.success ? 'success' : 'danger'}`; if (result.success) setTimeout(() => window.location.reload(), 700); };
  document.getElementById('fundRequestForm')?.addEventListener('submit', event => { event.preventDefault(); send(new URLSearchParams({action:'create_fund_request', request_type:fundType.value, trip_id:fundTrip.value, amount:fundAmount.value, purpose:fundPurpose.value, notes:fundNotes.value, [window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN})); });
  document.getElementById('disbursementForm')?.addEventListener('submit', event => { event.preventDefault(); send(new URLSearchParams({action:'create_disbursement', fund_request_id:disbRequest.value, payment_voucher_id:disbVoucher.value, payee_name:disbPayee.value, amount:disbAmount.value, payment_mode:disbMode.value, disbursed_at:disbDate.value, [window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN})); });
  document.getElementById('payableForm')?.addEventListener('submit', event => { event.preventDefault(); send(new URLSearchParams({action:'create_payable', supplier_name:apSupplier.value, invoice_number:apInvoice.value, description:apDescription.value, amount:apAmount.value, due_date:apDueDate.value, [window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN})); });
  document.getElementById('voucherForm')?.addEventListener('submit', event => { event.preventDefault(); send(new URLSearchParams({action:'create_voucher', payable_id:voucherPayable.value, amount:voucherAmount.value, notes:voucherNotes.value, [window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN})); });
})();
</script>
<?php layoutFoot(); ?>
