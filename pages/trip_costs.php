<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]);
layoutHead('Trip Costs & Fuel Analysis', APP_BASE . '/assets/css/billing.css');
$pdo = getDBConnection();

$trips = $pdo->query("
    SELECT t.trip_id, t.trip_number, r.distance_km, t.cargo_weight_tons,
           tr.plate_number, tr.fuel_efficiency_km_per_liter, tr.capacity_tons
    FROM trips t
    JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
    JOIN routes r ON r.route_id = dr.route_id
    JOIN trucks tr ON tr.truck_id = dr.truck_id
    WHERE t.status <> 'Cancelled'
    ORDER BY t.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$rows = $pdo->query("
    SELECT t.trip_number, t.trip_id, tr.plate_number, r.distance_km,
           tr.fuel_efficiency_km_per_liter, tr.capacity_tons, t.cargo_weight_tons,
           COALESCE(SUM(e.amount), 0) AS total_expense,
           COALESCE(SUM(CASE WHEN e.expense_type = 'Fuel' THEN e.quantity ELSE 0 END), 0) AS fuel_liters,
           COALESCE((SELECT SUM(b.amount) FROM billings b WHERE b.trip_id = t.trip_id), 0) AS revenue
    FROM trips t
    JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
    JOIN routes r ON r.route_id = dr.route_id
    JOIN trucks tr ON tr.truck_id = dr.truck_id
    LEFT JOIN trip_expenses e ON e.trip_id = t.trip_id
    WHERE t.status <> 'Cancelled'
    GROUP BY t.trip_id
    ORDER BY t.created_at DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

$expenseStmt = $pdo->query("
    SELECT e.expense_id, e.trip_id, e.expense_type, e.amount, e.quantity, e.other_description, e.expense_date,
           e.notes, u.full_name AS recorded_by
    FROM trip_expenses e
    JOIN users u ON u.user_id = e.recorded_by
    JOIN trips t ON t.trip_id = e.trip_id
    WHERE t.status <> 'Cancelled'
    ORDER BY e.expense_date DESC, e.created_at DESC
");
$expensesByTrip = [];
foreach ($expenseStmt->fetchAll(PDO::FETCH_ASSOC) as $expense) {
    $expensesByTrip[(int)$expense['trip_id']][] = $expense;
}
$isHead = currentRoleId() === ROLE_HEAD_MANAGEMENT;

function expectedFuel(array $row): float {
    $distance = (float)($row['distance_km'] ?? 0);
    $efficiency = (float)($row['fuel_efficiency_km_per_liter'] ?? 0);
    if ($distance <= 0 || $efficiency <= 0) return 0;
    $loadFactor = 0;
    if ((float)$row['capacity_tons'] > 0) {
        $loadFactor = min(1, max(0, (float)$row['cargo_weight_tons'] / (float)$row['capacity_tons']));
    }
    return $distance / $efficiency * (1 + ($loadFactor * 0.10));
}
?>
<div class="bil-page">
  <div class="bil-header d-flex align-items-center justify-content-between mb-4">
    <div>
      <h1 class="bil-title mb-0">Trip Costs &amp; Fuel Analysis</h1>
      <p class="bil-subtitle mb-0">Record operating expenses and identify fuel usage above the 15% variance threshold.</p>
    </div>
    <button class="btn btn-bil-primary" data-bs-toggle="modal" data-bs-target="#expenseModal">
      <i class="bi bi-plus-lg me-1"></i> Record Expense
    </button>
  </div>
  <div class="bil-table-wrap">
    <table class="table bil-table">
      <thead><tr><th>Trip</th><th>Truck</th><th>Revenue</th><th>Expenses</th><th>Net Profit</th><th>CPK</th><th>Fuel Analysis</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row):
        $expected = expectedFuel($row);
        $actual = (float)$row['fuel_liters'];
        $variance = $expected > 0 ? (($actual - $expected) / $expected) * 100 : null;
        $profit = (float)$row['revenue'] - (float)$row['total_expense'];
        $cpk = (float)$row['distance_km'] > 0 ? (float)$row['total_expense'] / (float)$row['distance_km'] : 0;
      ?>
      <tr>
        <td><?= htmlspecialchars($row['trip_number']) ?></td>
        <td><?= htmlspecialchars($row['plate_number']) ?></td>
        <td>₱<?= number_format((float)$row['revenue'], 2) ?></td>
        <td>
          <button type="button" class="btn btn-sm btn-outline-secondary expense-breakdown-btn"
                  data-trip="<?= htmlspecialchars($row['trip_number']) ?>"
                  data-expenses="<?= htmlspecialchars(json_encode($expensesByTrip[(int)$row['trip_id']] ?? []), ENT_QUOTES, 'UTF-8') ?>">
            ₱<?= number_format((float)$row['total_expense'], 2) ?> <span aria-hidden="true">...</span>
          </button>
        </td>
        <td class="<?= $profit >= 0 ? 'text-success' : 'text-danger' ?>">₱<?= number_format($profit, 2) ?></td>
        <td>₱<?= number_format($cpk, 2) ?>/km</td>
        <td>
          <?php if ($variance === null): ?>
            <span class="text-muted">No fuel baseline</span>
          <?php elseif ($variance > 15): ?>
            <span class="badge bg-danger">Anomaly +<?= number_format($variance, 1) ?>%</span>
            <small class="d-block text-muted"><?= number_format($actual, 1) ?>L actual / <?= number_format($expected, 1) ?>L expected</small>
          <?php else: ?>
            <span class="badge bg-success">Normal</span>
            <small class="d-block text-muted"><?= number_format($variance, 1) ?>% variance</small>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$rows): ?><div class="bil-empty"><p>No trip cost records yet.</p></div><?php endif; ?>
  </div>

  <div class="modal fade" id="expenseBreakdownModal" tabindex="-1" aria-labelledby="expenseBreakdownTitle">
    <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="expenseBreakdownTitle">Expense Breakdown</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="table-responsive"><table class="table table-sm" id="expenseBreakdownTable">
          <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Quantity</th><th>Notes</th><th>Recorded By</th><?php if ($isHead): ?><th></th><?php endif; ?></tr></thead>
          <tbody></tbody><tfoot><tr><th colspan="2">Total</th><th id="expenseBreakdownTotal"></th><th colspan="<?= $isHead ? 4 : 3 ?>"></th></tr></tfoot>
        </table></div>
        <div id="expenseBreakdownEmpty" class="text-muted d-none">No manually recorded expenses for this trip.</div>
      </div>
    </div></div>
  </div>
</div>

<div class="modal fade" id="expenseModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
    <form method="post" action="<?= APP_BASE ?>/ajax/trip_costs_handler.php" id="expenseForm">
      <div class="modal-header"><h5 class="modal-title" id="expenseModalTitle">Record Trip Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body expense-receipt-layout">
        <div class="expense-entry-panel">
        <input type="hidden" name="action" value="create_expense">
        <input type="hidden" name="expense_id" value="">
        <div class="mb-3"><label class="form-label">Trip</label><select name="trip_id" class="form-select" required><option value="">Select trip</option><?php foreach ($trips as $trip): ?><option value="<?= $trip['trip_id'] ?>"><?= htmlspecialchars($trip['trip_number'] . ' — ' . $trip['plate_number']) ?></option><?php endforeach; ?></select></div>
        <div class="row g-2"><div class="col-md-6"><label class="form-label">Type</label><select name="expense_type" id="expenseType" class="form-select" required><option>Fuel</option><option>Toll</option><option>Driver Allowance</option><option>Other</option></select></div><div class="col-md-6"><label class="form-label">Amount (₱)</label><input name="amount" id="expenseAmount" type="number" min="0.01" step="0.01" class="form-control" required></div></div>
        <div id="otherDescriptionWrap" class="mt-2 d-none"><label class="form-label">What is it?</label><input name="other_description" id="otherDescription" class="form-control" maxlength="255" placeholder="Describe this expense"></div>
        <div class="row g-2 mt-1"><div class="col-md-6"><label class="form-label">Fuel quantity (L)</label><input name="quantity" id="expenseQuantity" type="number" min="0.01" step="0.01" class="form-control"></div><div class="col-md-6"><label class="form-label">Date</label><input name="expense_date" id="expenseDate" type="date" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" class="form-control" required></div></div>
        <div class="mt-2"><label class="form-label">Notes</label><input name="notes" class="form-control" maxlength="255"></div>
        <div id="expenseMessage" class="mt-3"></div>
        </div>
        <aside class="expense-receipt" aria-label="Expense receipt preview">
          <div class="receipt-heading"><span>EXPENSE RECEIPT</span><small id="receiptDate"><?= date('Y-m-d') ?></small></div>
          <div id="receiptItems" class="receipt-items"><div class="text-muted">Enter an amount to preview it here.</div></div>
          <div class="receipt-total"><span>TOTAL</span><strong id="receiptTotal">₱0.00</strong></div>
        </aside>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-bil-primary"><span id="expenseSubmitText">Save Expense</span></button></div>
    </form>
  </div></div>
</div>
<script>
const expenseForm = document.getElementById('expenseForm');
const expenseType = document.getElementById('expenseType');
const expenseAmount = document.getElementById('expenseAmount');
const expenseDate = document.getElementById('expenseDate');
const otherWrap = document.getElementById('otherDescriptionWrap');
const receiptItems = document.getElementById('receiptItems');
const receiptTotal = document.getElementById('receiptTotal');
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char]));
function updateReceipt() {
  const type = expenseType.value === 'Other' && document.getElementById('otherDescription').value.trim()
    ? document.getElementById('otherDescription').value.trim() : expenseType.value;
  const amount = Number(expenseAmount.value || 0);
  document.getElementById('receiptDate').textContent = expenseDate.value || '—';
  receiptItems.innerHTML = amount > 0 ? `<div class="receipt-line"><span>${escapeHtml(type || 'Expense')}</span><strong>₱${amount.toLocaleString(undefined, {minimumFractionDigits: 2})}</strong></div>` : '<div class="text-muted">Enter an amount to preview it here.</div>';
  receiptTotal.textContent = `₱${amount.toLocaleString(undefined, {minimumFractionDigits: 2})}`;
}
expenseType.addEventListener('change', () => { otherWrap.classList.toggle('d-none', expenseType.value !== 'Other'); updateReceipt(); });
[expenseAmount, expenseDate, document.getElementById('otherDescription')].forEach((el) => el.addEventListener('input', updateReceipt));
expenseForm.addEventListener('submit', async function (event) {
  event.preventDefault();
  const response = await fetch(this.action, { method: 'POST', body: new URLSearchParams(new FormData(this)) });
   const result = await response.json();
  document.getElementById('expenseMessage').innerHTML = '<div class="alert alert-' + (result.success ? 'success' : 'danger') + '">' + result.message + '</div>';
  if (result.success) setTimeout(() => window.location.reload(), 700);
});

document.querySelectorAll('.expense-breakdown-btn').forEach((button) => {
  button.addEventListener('click', () => {
    const expenses = JSON.parse(button.dataset.expenses || '[]');
    const tbody = document.querySelector('#expenseBreakdownTable tbody');
    const empty = document.getElementById('expenseBreakdownEmpty');
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
    let total = 0;
    tbody.innerHTML = expenses.map((expense) => {
      total += Number(expense.amount);
      const label = expense.expense_type === 'Other' && expense.other_description ? `${expense.expense_type}: ${expense.other_description}` : expense.expense_type;
      const edit = <?= $isHead ? 'true' : 'false' ?> ? `<td><button type="button" class="btn btn-sm btn-outline-primary edit-expense-btn" data-expense='${escapeHtml(JSON.stringify(expense))}'>Edit</button></td>` : '';
      return `<tr><td>${escapeHtml(expense.expense_date)}</td><td>${escapeHtml(label)}</td>` +
        `<td>₱${Number(expense.amount).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>` +
        `<td>${expense.quantity ? `${escapeHtml(expense.quantity)} L` : '—'}</td>` +
        `<td>${escapeHtml(expense.notes || '—')}</td><td>${escapeHtml(expense.recorded_by)}</td>${edit}</tr>`;
    }).join('');
    document.getElementById('expenseBreakdownTitle').textContent = `Expense Breakdown — ${button.dataset.trip}`;
    document.getElementById('expenseBreakdownTotal').textContent = `₱${total.toLocaleString(undefined, {minimumFractionDigits: 2})}`;
    empty.classList.toggle('d-none', expenses.length > 0);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('expenseBreakdownModal')).show();
  });
});
document.addEventListener('click', (event) => {
  const button = event.target.closest('.edit-expense-btn');
  if (!button) return;
  const expense = JSON.parse(button.dataset.expense);
  Object.entries({expense_id: expense.expense_id, trip_id: expense.trip_id, expense_type: expense.expense_type, amount: expense.amount, quantity: expense.quantity || '', other_description: expense.other_description || '', expense_date: expense.expense_date, notes: expense.notes || ''}).forEach(([name, value]) => expenseForm.elements[name].value = value);
  expenseForm.elements.action.value = 'update_expense';
  expenseDate.removeAttribute('min');
  document.getElementById('expenseModalTitle').textContent = 'Edit Trip Expense';
  document.getElementById('expenseSubmitText').textContent = 'Update Expense';
  otherWrap.classList.toggle('d-none', expense.expense_type !== 'Other');
  updateReceipt();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('expenseModal')).show();
});
document.getElementById('expenseModal').addEventListener('hidden.bs.modal', () => {
  expenseForm.reset();
  expenseForm.elements.action.value = 'create_expense';
  expenseForm.elements.expense_id.value = '';
  expenseDate.min = '<?= date('Y-m-d') ?>';
  expenseDate.value = '<?= date('Y-m-d') ?>';
  document.getElementById('expenseModalTitle').textContent = 'Record Trip Expense';
  document.getElementById('expenseSubmitText').textContent = 'Save Expense';
  otherWrap.classList.add('d-none');
  updateReceipt();
});
</script>
<?php layoutFoot(); ?>
