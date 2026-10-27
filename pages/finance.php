<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../app/modules/finance/finance_records.php';

requireAnyPermission(['finance.funds.view', 'finance.disbursements.view', 'finance.ap.view']);
$pdo = getDBConnection();
$canManageFunds = currentUserHasAnyPermission(['finance.funds.manage']);
$canManageDisbursements = currentUserHasAnyPermission(['finance.disbursements.manage']);
$canManagePayables = currentUserHasAnyPermission(['finance.ap.manage']);
// Target tables (see ajax/finance_handler.php). Figures follow
// includes/finance_records.php.
$funds = $pdo->query(
    "SELECT fr.fund_request_id, fr.request_number, fr.request_type, fr.purpose, fr.amount, fr.status,
            COALESCE(fr.submitted_at, fr.created_at) AS requested_at,
            COALESCE(ue.full_name, u.username) AS requester
     FROM fund_requests fr
     JOIN users u ON u.user_id = fr.requested_by
     LEFT JOIN employees ue ON ue.employee_id = u.employee_id
     ORDER BY fr.created_at DESC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);
$disbursements = $pdo->query(
    "SELECT CONCAT('DISB-', LPAD(disbursement_id, 6, '0')) AS disbursement_number,
            transaction_reference AS payee_name, amount, payment_method AS payment_mode,
            payment_date AS disbursed_at
     FROM disbursements ORDER BY payment_date DESC, disbursement_id DESC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);
$payables = $pdo->query(
    "SELECT ap.ap_id AS payable_id, CONCAT('AP-', LPAD(ap.ap_id, 6, '0')) AS payable_number,
            COALESCE(s.supplier_name, '—') AS supplier_name, ap.reference_number AS invoice_number,
            ap.description, ap.amount, ap.due_date, ap.status
     FROM accounts_payable ap LEFT JOIN suppliers s ON s.supplier_id = ap.supplier_id
     ORDER BY ap.due_date IS NULL, ap.due_date ASC, ap.ap_id DESC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);
$vouchers = $pdo->query(
    "SELECT pv.voucher_id, pv.voucher_number, CONCAT('AP-', LPAD(pv.reference_id, 6, '0')) AS payable_number,
            pv.amount, pv.status
     FROM payment_vouchers pv
     WHERE pv.reference_type = 'accounts_payable'
     ORDER BY pv.voucher_id DESC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);
// Dropdown choices for the forms below. Each mirrors the eligibility check
// in ajax/finance_handler.php, which still validates the submitted ID.
$fundTripOptions = !$canManageFunds ? [] : $pdo->query(
    // create_fund_request: any trip that is not Cancelled.
    "SELECT t.trip_id, t.trip_number, t.status, COALESCE(c.client_name, '—') AS client_name
     FROM trips t LEFT JOIN clients c ON c.client_id = t.client_id
     WHERE t.status <> 'Cancelled'
     ORDER BY t.scheduled_departure DESC, t.trip_id DESC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);
$voucherPayableOptions = !$canManagePayables ? [] : $pdo->query(
    // create_voucher: an Open, Partially Paid or Overdue payable with an
    // amount not yet reserved by a voucher that is not Rejected/Cancelled.
    "SELECT * FROM (
       SELECT ap.ap_id AS payable_id, CONCAT('AP-', LPAD(ap.ap_id, 6, '0')) AS payable_number,
              COALESCE(s.supplier_name, '—') AS supplier_name, ap.status, ap.due_date,
              ap.amount - COALESCE((SELECT SUM(pv.amount) FROM payment_vouchers pv
                                    WHERE pv.reference_type = 'accounts_payable' AND pv.reference_id = ap.ap_id
                                      AND pv.status NOT IN ('Rejected', 'Cancelled')), 0) AS remaining
       FROM accounts_payable ap LEFT JOIN suppliers s ON s.supplier_id = ap.supplier_id
       WHERE ap.status IN ('Open', 'Partially Paid', 'Overdue')
     ) eligible
     WHERE remaining > 0
     ORDER BY due_date IS NULL, due_date ASC, payable_id DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$disbursableFundOptions = !$canManageDisbursements ? [] : $pdo->query(
    // create_disbursement: an Approved or Processing fund request with an
    // amount not yet disbursed.
    "SELECT * FROM (
       SELECT fr.fund_request_id, fr.request_number, fr.request_type, fr.status,
              fr.amount - COALESCE((SELECT SUM(d.amount) FROM disbursements d
                                    WHERE d.reference_type = 'fund_request' AND d.reference_id = fr.fund_request_id), 0) AS remaining
       FROM fund_requests fr
       WHERE fr.status IN ('Approved', 'Processing')
     ) eligible
     WHERE remaining > 0
     ORDER BY fund_request_id"
)->fetchAll(PDO::FETCH_ASSOC);
$disbursableVoucherOptions = !$canManageDisbursements ? [] : $pdo->query(
    // create_disbursement: an Approved payable voucher with an amount not
    // yet disbursed.
    "SELECT * FROM (
       SELECT pv.voucher_id, pv.voucher_number, pv.payee,
              pv.amount - COALESCE((SELECT SUM(d.amount) FROM disbursements d
                                    WHERE d.reference_type = 'payment_voucher' AND d.reference_id = pv.voucher_id), 0) AS remaining
       FROM payment_vouchers pv
       WHERE pv.status = 'Approved' AND pv.reference_type = 'accounts_payable'
     ) eligible
     WHERE remaining > 0
     ORDER BY voucher_id"
)->fetchAll(PDO::FETCH_ASSOC);
$summary = $pdo->query(
    "SELECT
       (SELECT COALESCE(SUM(b.total_amount - " . billingCollectedSql('b') . "), 0)
          FROM billings b WHERE b.status IN ('Issued', 'Partially Paid', 'Overdue')) AS outstanding_billings,
       (SELECT COALESCE(SUM(amount), 0) FROM expense_records WHERE reference_type = 'trip') AS recorded_expenses,
       (SELECT COALESCE(SUM(amount), 0) FROM disbursements) AS disbursed_total,
       (SELECT COALESCE(SUM(amount), 0) FROM accounts_payable WHERE status IN ('Open','Partially Paid','Overdue')) AS open_payables"
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
<div class="col-md-3"><label class="form-label" for="fundTrip">Trip (required for Trip Allowance)</label><select id="fundTrip" class="form-select" data-refresh-options>
<option value=""><?= $fundTripOptions ? 'No trip' : 'No trips available' ?></option>
<?php foreach ($fundTripOptions as $tripOption): ?><option value="<?= (int)$tripOption['trip_id'] ?>"><?= htmlspecialchars($tripOption['trip_number'] . ' · ' . $tripOption['client_name'] . ' · ' . $tripOption['status']) ?></option><?php endforeach; ?>
</select><div class="form-text">The 200 most recent trips that are not cancelled.</div></div>
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
<div class="col-md-4"><label class="form-label" for="voucherPayable">Accounts payable</label><select id="voucherPayable" class="form-select" data-refresh-options required<?= $voucherPayableOptions ? '' : ' disabled' ?>>
<option value=""><?= $voucherPayableOptions ? 'Select an open payable' : 'No open payables with an unvouchered balance' ?></option>
<?php foreach ($voucherPayableOptions as $payableOption): ?><option value="<?= (int)$payableOption['payable_id'] ?>"><?= htmlspecialchars($payableOption['payable_number'] . ' · ' . $payableOption['supplier_name'] . ' · ₱' . number_format((float)$payableOption['remaining'], 2) . ' unvouchered · ' . $payableOption['status']) ?></option><?php endforeach; ?>
</select></div>
<div class="col-md-4"><label class="form-label">Voucher amount</label><input id="voucherAmount" class="form-control" type="number" min="0.01" step="0.01" required></div>
<div class="col-md-4"><label class="form-label">Notes</label><input id="voucherNotes" class="form-control" maxlength="1000"></div>
<div class="col-12"><button class="btn btn-outline-primary"<?= $voucherPayableOptions ? '' : ' disabled' ?>>Submit voucher for approval</button></div>
</form></div></div>
<?php endif; ?>
<?php if ($canManageDisbursements): ?>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Record disbursement</h2></div><div class="card-body-custom">
<form id="disbursementForm" class="row g-3">
<div class="col-md-6"><label class="form-label" for="disbRequest">Approved fund request</label><select id="disbRequest" class="form-select" data-refresh-options<?= $disbursableFundOptions ? '' : ' disabled' ?>>
<option value=""><?= $disbursableFundOptions ? 'None' : 'No approved fund requests awaiting disbursement' ?></option>
<?php foreach ($disbursableFundOptions as $fundOption): ?><option value="<?= (int)$fundOption['fund_request_id'] ?>"><?= htmlspecialchars($fundOption['request_number'] . ' · ' . $fundOption['request_type'] . ' · ₱' . number_format((float)$fundOption['remaining'], 2) . ' remaining · ' . $fundOption['status']) ?></option><?php endforeach; ?>
</select></div>
<div class="col-md-6"><label class="form-label" for="disbVoucher">Approved payment voucher</label><select id="disbVoucher" class="form-select" data-refresh-options<?= $disbursableVoucherOptions ? '' : ' disabled' ?>>
<option value=""><?= $disbursableVoucherOptions ? 'None' : 'No approved payment vouchers awaiting disbursement' ?></option>
<?php foreach ($disbursableVoucherOptions as $voucherOption): ?><option value="<?= (int)$voucherOption['voucher_id'] ?>"><?= htmlspecialchars($voucherOption['voucher_number'] . ' · ' . $voucherOption['payee'] . ' · ₱' . number_format((float)$voucherOption['remaining'], 2) . ' remaining') ?></option><?php endforeach; ?>
</select></div>
<div class="col-12 form-text mt-1">Choose either one fund request or one payment voucher, not both.</div>
<div class="col-md-3"><label class="form-label">Payee</label><input id="disbPayee" class="form-control" maxlength="150" required></div>
<div class="col-md-2"><label class="form-label">Amount</label><input id="disbAmount" class="form-control" type="number" min="0.01" step="0.01" required></div>
<div class="col-md-2"><label class="form-label">Payment mode</label><select id="disbMode" class="form-select" required><?php foreach (PAYMENT_MODES as $mode): ?><option><?= htmlspecialchars($mode) ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label">Date</label><input id="disbDate" class="form-control" type="date" value="<?= date('Y-m-d') ?>" required></div>
<div class="col-12"><button class="btn btn-outline-primary"<?= ($disbursableFundOptions || $disbursableVoucherOptions) ? '' : ' disabled' ?>>Record disbursement</button></div>
</form></div></div>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Accounts payable</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Payable</th><th>Supplier</th><th>Invoice</th><th>Description</th><th>Amount</th><th>Due</th><th>Status</th></tr></thead><tbody>
<?php foreach ($payables as $payable): ?><tr><td><?= htmlspecialchars($payable['payable_number']) ?><br><span class="text-muted small">ID <?= (int)$payable['payable_id'] ?></span></td><td><?= htmlspecialchars($payable['supplier_name']) ?></td><td><?= htmlspecialchars($payable['invoice_number'] ?? '—') ?></td><td><?= htmlspecialchars($payable['description']) ?></td><td>₱<?= number_format((float)$payable['amount'], 2) ?></td><td><?= htmlspecialchars($payable['due_date'] ?? '—') ?></td><td><?= htmlspecialchars($payable['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$payables): ?><tr><td colspan="7" class="text-center text-muted py-4">No accounts payable records found.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Payment vouchers</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Voucher</th><th>Payable</th><th>Amount</th><th>Status</th></tr></thead><tbody>
<?php foreach ($vouchers as $voucher): ?><tr><td><?= htmlspecialchars($voucher['voucher_number']) ?><br><span class="text-muted small">ID <?= (int)$voucher['voucher_id'] ?></span></td><td><?= htmlspecialchars($voucher['payable_number']) ?></td><td>₱<?= number_format((float)$voucher['amount'], 2) ?></td><td><?= htmlspecialchars($voucher['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$vouchers): ?><tr><td colspan="4" class="text-center text-muted py-4">No payment vouchers found.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Fund requests</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Request</th><th>Type</th><th>Purpose</th><th>Amount</th><th>Status</th><th>Requester</th></tr></thead><tbody>
<?php foreach ($funds as $fund): ?><tr><td><?= htmlspecialchars($fund['request_number']) ?><br><span class="text-muted small">ID <?= (int)$fund['fund_request_id'] ?></span></td><td><?= htmlspecialchars($fund['request_type']) ?></td><td><?= htmlspecialchars($fund['purpose']) ?></td><td>₱<?= number_format((float)$fund['amount'], 2) ?></td><td><?= htmlspecialchars($fund['status']) ?></td><td><?= htmlspecialchars($fund['requester']) ?></td></tr><?php endforeach; ?>
<?php if (!$funds): ?><tr><td colspan="6" class="text-center text-muted py-4">No fund requests found.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><div class="card-header-custom"><h2 class="card-title-custom">Disbursements</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Number</th><th>Payee</th><th>Amount</th><th>Mode</th><th>Date</th></tr></thead><tbody>
<?php foreach ($disbursements as $item): ?><tr><td><?= htmlspecialchars($item['disbursement_number']) ?></td><td><?= htmlspecialchars($item['payee_name']) ?></td><td>₱<?= number_format((float)$item['amount'], 2) ?></td><td><?= htmlspecialchars($item['payment_mode']) ?></td><td><?= htmlspecialchars($item['disbursed_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$disbursements): ?><tr><td colspan="5" class="text-center text-muted py-4">No disbursements found.</td></tr><?php endif; ?></tbody></table></div></div>
<script>
(() => {
  const feedback = document.getElementById('financeFeedback');
  // window.sendAction (assets/js/layout.js): busy button, server-confirmed
  // success, readable errors, entries kept on failure, form cleared on success.
  const send = (event, label, data) => {
    event.preventDefault();
    window.sendAction('<?= APP_BASE ?>/ajax/finance_handler.php', data, {
      feedback, label, form: event.target, button: event.target.querySelector('button')
    });
  };
  document.getElementById('fundRequestForm')?.addEventListener('submit', event => send(event, 'submit the fund request', new URLSearchParams({action:'create_fund_request', request_type:fundType.value, trip_id:fundTrip.value, amount:fundAmount.value, purpose:fundPurpose.value, notes:fundNotes.value, [window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN})));
  document.getElementById('disbursementForm')?.addEventListener('submit', event => send(event, 'record the disbursement', new URLSearchParams({action:'create_disbursement', fund_request_id:disbRequest.value, payment_voucher_id:disbVoucher.value, payee_name:disbPayee.value, amount:disbAmount.value, payment_mode:disbMode.value, disbursed_at:disbDate.value, [window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN})));
  document.getElementById('payableForm')?.addEventListener('submit', event => send(event, 'save the payable', new URLSearchParams({action:'create_payable', supplier_name:apSupplier.value, invoice_number:apInvoice.value, description:apDescription.value, amount:apAmount.value, due_date:apDueDate.value, [window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN})));
  document.getElementById('voucherForm')?.addEventListener('submit', event => send(event, 'submit the payment voucher', new URLSearchParams({action:'create_voucher', payable_id:voucherPayable.value, amount:voucherAmount.value, notes:voucherNotes.value, [window.CSRF_TOKEN_NAME]:window.CSRF_TOKEN})));
})();
</script>
<?php layoutFoot(); ?>
