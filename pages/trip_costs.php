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
           COALESCE((SELECT SUM(b.amount) FROM billings b WHERE b.trip_id = t.trip_id), 0) AS revenue,
           dr.driver_id, drv.full_name AS driver_name,
           dr.helper_id, hlp.full_name AS helper_name
    FROM trips t
    JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
    JOIN routes r ON r.route_id = dr.route_id
    JOIN trucks tr ON tr.truck_id = dr.truck_id
    LEFT JOIN trip_expenses e ON e.trip_id = t.trip_id
    LEFT JOIN employees drv ON drv.employee_id = dr.driver_id
    LEFT JOIN employees hlp ON hlp.employee_id = dr.helper_id
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

// ── Crew pay: per-trip Driver/Helper wages ────────────────────────────────────
// Wrapped defensively: if trip_pay hasn't been created yet (migration not
// run), degrade to "no crew pay data" instead of a fatal crash.
$tripPayTableMissing = false;
$tripPayByTrip = [];
try {
    $tripPayStmt = $pdo->query("
        SELECT tp.trip_pay_id, tp.trip_id, tp.employee_id, tp.crew_role, tp.amount, tp.paid_date,
               tp.notes, e.full_name AS employee_name, u.full_name AS recorded_by
        FROM trip_pay tp
        JOIN employees e ON e.employee_id = tp.employee_id
        JOIN users u     ON u.user_id     = tp.recorded_by
        JOIN trips t     ON t.trip_id     = tp.trip_id
        WHERE t.status <> 'Cancelled'
        ORDER BY tp.paid_date DESC, tp.created_at DESC
    ");
    foreach ($tripPayStmt->fetchAll(PDO::FETCH_ASSOC) as $pay) {
        $tripPayByTrip[(int)$pay['trip_id']][] = $pay;
    }
} catch (PDOException $e) {
    if ($e->getCode() === '42S02' || str_contains($e->getMessage(), "doesn't exist")) {
        $tripPayTableMissing = true;
    } else {
        throw $e;
    }
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

  <?php if ($tripPayTableMissing): ?>
  <div class="alert alert-warning d-flex align-items-start gap-2 mb-4" role="alert">
    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
    <div>
      <strong>Crew pay data is not available.</strong> The <code>trip_pay</code> table hasn't been created yet.
      Run <code>db/trip_pay_migration.sql</code> against the database to enable Driver/Helper pay tracking.
    </div>
  </div>
  <?php endif; ?>

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
      <thead><tr><th>Trip</th><th>Truck</th><th>Crew</th><th>Revenue</th><th>Expenses</th><th>Crew Pay</th><th>Net Profit</th><th>CPK</th><th>Fuel Analysis</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row):
        $expected = expectedFuel($row);
        $actual = (float)$row['fuel_liters'];
        $variance = $expected > 0 ? (($actual - $expected) / $expected) * 100 : null;
        $tripPayEntries = $tripPayByTrip[(int)$row['trip_id']] ?? [];
        $tripPayTotal   = array_sum(array_column($tripPayEntries, 'amount'));
        $profit = (float)$row['revenue'] - (float)$row['total_expense'] - $tripPayTotal;
        $cpk = (float)$row['distance_km'] > 0 ? (float)$row['total_expense'] / (float)$row['distance_km'] : 0;
      ?>
      <tr>
        <td><?= htmlspecialchars($row['trip_number']) ?></td>
        <td><?= htmlspecialchars($row['plate_number']) ?></td>
        <td>
          <div><?= htmlspecialchars($row['driver_name'] ?? '—') ?> <span class="text-muted">(Driver)</span></div>
          <?php if (!empty($row['helper_name'])): ?>
          <div><?= htmlspecialchars($row['helper_name']) ?> <span class="text-muted">(Helper)</span></div>
          <?php endif; ?>
        </td>
        <td>₱<?= number_format((float)$row['revenue'], 2) ?></td>
        <td>
          <button type="button" class="btn btn-sm btn-outline-secondary expense-breakdown-btn"
                  data-trip="<?= htmlspecialchars($row['trip_number']) ?>"
                  data-expenses="<?= htmlspecialchars(json_encode($expensesByTrip[(int)$row['trip_id']] ?? []), ENT_QUOTES, 'UTF-8') ?>">
            ₱<?= number_format((float)$row['total_expense'], 2) ?> <span aria-hidden="true">...</span>
          </button>
        </td>
        <td>
          <?php if ($tripPayTableMissing): ?>
            <span class="text-muted">—</span>
          <?php else: ?>
          <button type="button" class="btn btn-sm btn-outline-secondary crew-pay-btn"
                  data-trip-id="<?= $row['trip_id'] ?>"
                  data-trip="<?= htmlspecialchars($row['trip_number']) ?>"
                  data-driver-id="<?= (int)$row['driver_id'] ?>"
                  data-driver-name="<?= htmlspecialchars($row['driver_name'] ?? '') ?>"
                  data-helper-id="<?= $row['helper_id'] !== null ? (int)$row['helper_id'] : '' ?>"
                  data-helper-name="<?= htmlspecialchars($row['helper_name'] ?? '') ?>"
                  data-existing="<?= htmlspecialchars(json_encode($tripPayEntries), ENT_QUOTES, 'UTF-8') ?>">
            <?= $tripPayTotal > 0 ? '₱' . number_format($tripPayTotal, 2) : 'Log pay' ?> <span aria-hidden="true">...</span>
          </button>
          <?php endif; ?>
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

  <div class="modal fade" id="crewPayModal" tabindex="-1" aria-labelledby="crewPayTitle">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="crewPayTitle">Log Crew Pay</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="crewPayMessage"></div>
        <input type="hidden" id="crewPayTripId">
        <div class="mb-3">
          <label class="form-label">Paid Date</label>
          <input type="date" id="crewPayDate" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div id="crewPayRows" class="d-grid gap-2 mb-3"></div>
        <div class="mb-3">
          <label class="form-label">Notes</label>
          <input type="text" id="crewPayNotes" class="form-control" maxlength="255">
        </div>
        <div id="crewPayExisting"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-bil-primary" id="crewPaySubmit">Save Crew Pay</button>
      </div>
    </div></div>
  </div>
</div>

<div class="modal fade" id="expenseModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
    <form method="post" action="<?= APP_BASE ?>/ajax/trip_costs_handler.php" id="expenseForm">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
      <div class="modal-header"><h5 class="modal-title" id="expenseModalTitle">Record Trip Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body expense-receipt-layout">
        <div class="expense-entry-panel">
          <input type="hidden" name="action" value="create_expense">
          <input type="hidden" name="expense_id" value="">
          <div class="mb-3"><label class="form-label">Trip</label><select name="trip_id" class="form-select" required><option value="">Select trip</option><?php foreach ($trips as $trip): ?><option value="<?= $trip['trip_id'] ?>"><?= htmlspecialchars($trip['trip_number'] . ' — ' . $trip['plate_number']) ?></option><?php endforeach; ?></select></div>
          <div class="row g-2 mb-3"><div class="col-md-12"><label class="form-label">Date</label><input name="expense_date" id="expenseDate" type="date" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" class="form-control" required></div></div>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label mb-0">Expense entries</label>
            <button type="button" id="addExpenseRow" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>Add item</button>
          </div>
          <div id="expenseRows" class="d-grid gap-3"></div>
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
const expenseRows = document.getElementById('expenseRows');
const expenseDate = document.getElementById('expenseDate');
const receiptItems = document.getElementById('receiptItems');
const receiptTotal = document.getElementById('receiptTotal');
const addExpenseRowButton = document.getElementById('addExpenseRow');
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char]));

function buildExpenseRow(index) {
  const row = document.createElement('div');
  row.className = 'expense-item border rounded p-3';
  row.dataset.index = String(index);
  row.innerHTML = `
    <div class="d-flex justify-content-between align-items-center mb-2">
      <span class="fw-semibold">Expense <span class="expense-row-number">${index + 1}</span></span>
      <button type="button" class="btn btn-sm btn-outline-danger remove-expense-row">Remove</button>
    </div>
    <div class="row g-2">
      <div class="col-md-4"><label class="form-label">Type</label><select name="expenses[${index}][expense_type]" class="form-select expense-item-type" required><option>Fuel</option><option>Toll</option><option>Driver Allowance</option><option>Other</option></select></div>
      <div class="col-md-4"><label class="form-label">Amount (₱)</label><input name="expenses[${index}][amount]" type="number" min="0.01" step="0.01" class="form-control expense-item-amount" required></div>
      <div class="col-md-4 expense-item-quantity-wrap"><label class="form-label">Fuel quantity (L)</label><input name="expenses[${index}][quantity]" type="number" min="0.01" step="0.01" class="form-control expense-item-quantity"></div>
    </div>
    <div class="expense-item-other-wrap mt-2 d-none"><label class="form-label">What is it?</label><input name="expenses[${index}][other_description]" class="form-control expense-item-other-description" maxlength="255" placeholder="Describe this expense"></div>
    <div class="mt-2"><label class="form-label">Notes</label><input name="expenses[${index}][notes]" class="form-control expense-item-notes" maxlength="255"></div>
  `;

  const typeSelect = row.querySelector('.expense-item-type');
  const quantityWrap = row.querySelector('.expense-item-quantity-wrap');
  const otherWrap = row.querySelector('.expense-item-other-wrap');
  const otherDescription = row.querySelector('.expense-item-other-description');
  const amountInput = row.querySelector('.expense-item-amount');
  const removeButton = row.querySelector('.remove-expense-row');

  const syncTypeFields = () => {
    const isOther = typeSelect.value === 'Other';
    const isFuel = typeSelect.value === 'Fuel';
    otherWrap.classList.toggle('d-none', !isOther);
    quantityWrap.classList.toggle('d-none', !isFuel);
    if (!isFuel) row.querySelector('.expense-item-quantity').value = '';
    if (!isOther) otherDescription.value = '';
  };

  typeSelect.addEventListener('change', syncTypeFields);
  amountInput.addEventListener('input', updateReceipt);
  [otherDescription, expenseDate].forEach((el) => el && el.addEventListener('input', updateReceipt));
  removeButton.addEventListener('click', () => {
    if (document.querySelectorAll('.expense-item').length > 1) {
      row.remove();
      updateExpenseRowNumbers();
      updateReceipt();
    }
  });

  syncTypeFields();
  return row;
}

function updateExpenseRowNumbers() {
  document.querySelectorAll('.expense-item').forEach((row, index) => {
    row.querySelector('.expense-row-number').textContent = index + 1;
    const typeSelect = row.querySelector('.expense-item-type');
    const amountInput = row.querySelector('.expense-item-amount');
    const quantityInput = row.querySelector('.expense-item-quantity');
    const otherInput = row.querySelector('.expense-item-other-description');
    const notesInput = row.querySelector('.expense-item-notes');
    if (typeSelect) typeSelect.name = `expenses[${index}][expense_type]`;
    if (amountInput) amountInput.name = `expenses[${index}][amount]`;
    if (quantityInput) quantityInput.name = `expenses[${index}][quantity]`;
    if (otherInput) otherInput.name = `expenses[${index}][other_description]`;
    if (notesInput) notesInput.name = `expenses[${index}][notes]`;
  });
}

function addExpenseRow() {
  const index = document.querySelectorAll('.expense-item').length;
  expenseRows.appendChild(buildExpenseRow(index));
  updateExpenseRowNumbers();
  updateReceipt();
}

function updateReceipt() {
  const entries = [...document.querySelectorAll('.expense-item')].map((row) => {
    const type = row.querySelector('.expense-item-type').value;
    const amount = Number(row.querySelector('.expense-item-amount').value || 0);
    const customLabel = type === 'Other' ? (row.querySelector('.expense-item-other-description').value.trim() || 'Other') : type;
    return { type: customLabel, amount };
  }).filter((entry) => entry.amount > 0);

  document.getElementById('receiptDate').textContent = expenseDate.value || '—';
  if (!entries.length) {
    receiptItems.innerHTML = '<div class="text-muted">Enter an amount to preview it here.</div>';
    receiptTotal.textContent = '₱0.00';
    return;
  }

  const total = entries.reduce((sum, entry) => sum + entry.amount, 0);
  receiptItems.innerHTML = entries.map((entry) => `
    <div class="receipt-line"><span>${escapeHtml(entry.type || 'Expense')}</span><strong>₱${entry.amount.toLocaleString(undefined, {minimumFractionDigits: 2})}</strong></div>
  `).join('');
  receiptTotal.textContent = `₱${total.toLocaleString(undefined, {minimumFractionDigits: 2})}`;
}

addExpenseRowButton.addEventListener('click', addExpenseRow);
expenseDate.addEventListener('input', updateReceipt);
expenseForm.addEventListener('submit', async function (event) {
  event.preventDefault();
  const rows = [...document.querySelectorAll('.expense-item')];
  if (!rows.length) {
    document.getElementById('expenseMessage').innerHTML = '<div class="alert alert-danger">Add at least one expense entry.</div>';
    return;
  }
  const validEntries = rows.filter((row) => {
    const type = row.querySelector('.expense-item-type').value;
    const amount = Number(row.querySelector('.expense-item-amount').value || 0);
    const quantity = row.querySelector('.expense-item-quantity');
    const other = row.querySelector('.expense-item-other-description');
    return type && amount > 0 && (!quantity || Number(quantity.value || 0) > 0 || type !== 'Fuel') && (type !== 'Other' || other.value.trim());
  });

  if (validEntries.length !== rows.length) {
    document.getElementById('expenseMessage').innerHTML = '<div class="alert alert-danger">Every expense needs a valid type and amount, and fuel entries require liters.</div>';
    return;
  }

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
  expenseRows.innerHTML = '';
  const row = buildExpenseRow(0);
  row.querySelector('.expense-item-type').value = expense.expense_type;
  row.querySelector('.expense-item-amount').value = expense.amount;
  row.querySelector('.expense-item-quantity').value = expense.quantity || '';
  row.querySelector('.expense-item-other-description').value = expense.other_description || '';
  row.querySelector('.expense-item-notes').value = expense.notes || '';
  row.querySelector('.remove-expense-row').style.display = 'none';
  row.querySelector('.expense-item-type').dispatchEvent(new Event('change'));
  expenseRows.appendChild(row);
  expenseForm.elements.trip_id.value = expense.trip_id;
  expenseForm.elements.expense_date.value = expense.expense_date;
  expenseForm.elements.expense_id.value = expense.expense_id;
  expenseForm.elements.action.value = 'update_expense';
  expenseDate.removeAttribute('min');
  document.getElementById('expenseModalTitle').textContent = 'Edit Trip Expense';
  document.getElementById('expenseSubmitText').textContent = 'Update Expense';
  updateReceipt();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('expenseModal')).show();
});
document.getElementById('expenseModal').addEventListener('hidden.bs.modal', () => {
  expenseRows.innerHTML = '';
  addExpenseRow();
  expenseForm.reset();
  expenseForm.elements.action.value = 'create_expense';
  expenseForm.elements.expense_id.value = '';
  expenseDate.min = '<?= date('Y-m-d') ?>';
  expenseDate.value = '<?= date('Y-m-d') ?>';
  document.getElementById('expenseModalTitle').textContent = 'Record Trip Expense';
  document.getElementById('expenseSubmitText').textContent = 'Save Expense';
  document.getElementById('expenseMessage').innerHTML = '';
  updateReceipt();
});

expenseRows.innerHTML = '';
addExpenseRow();
updateReceipt();

// ── Log Crew Pay ─────────────────────────────────────────────────────────────
const crewPayModalEl = document.getElementById('crewPayModal');
const crewPayRows     = document.getElementById('crewPayRows');
const crewPayMessage  = document.getElementById('crewPayMessage');
const crewPayExisting = document.getElementById('crewPayExisting');
const crewPaySubmit   = document.getElementById('crewPaySubmit');

function buildCrewPayRow(employeeId, name, role, existingAmount) {
  const row = document.createElement('div');
  row.className = 'row g-2 align-items-end crew-pay-row';
  row.dataset.employeeId = employeeId;
  row.dataset.role = role;
  row.innerHTML = `
    <div class="col-7"><label class="form-label mb-0">${escapeHtml(name)} <span class="text-muted">(${role})</span></label></div>
    <div class="col-5">
      <input type="number" min="0.01" step="0.01" class="form-control crew-pay-amount" placeholder="₱0.00" value="${existingAmount ?? ''}">
    </div>
  `;
  return row;
}

document.querySelectorAll('.crew-pay-btn').forEach((button) => {
  button.addEventListener('click', () => {
    const existing = JSON.parse(button.dataset.existing || '[]');
    const existingByEmployee = {};
    existing.forEach((e) => { existingByEmployee[e.employee_id] = e; });

    crewPayRows.innerHTML = '';
    crewPayMessage.innerHTML = '';
    document.getElementById('crewPayTripId').value = button.dataset.tripId;
    document.getElementById('crewPayTitle').textContent = `Log Crew Pay — ${button.dataset.trip}`;

    if (button.dataset.driverId) {
      const existingPay = existingByEmployee[button.dataset.driverId];
      crewPayRows.appendChild(buildCrewPayRow(button.dataset.driverId, button.dataset.driverName, 'Driver', existingPay?.amount));
    }
    if (button.dataset.helperId) {
      const existingPay = existingByEmployee[button.dataset.helperId];
      crewPayRows.appendChild(buildCrewPayRow(button.dataset.helperId, button.dataset.helperName, 'Helper', existingPay?.amount));
    }

    if (existing.length) {
      crewPayExisting.innerHTML = '<hr><div class="text-muted small mb-1">Already logged:</div>' +
        existing.map((e) => `<div class="small">${escapeHtml(e.employee_name)} (${escapeHtml(e.crew_role)}) — ₱${Number(e.amount).toLocaleString(undefined, {minimumFractionDigits: 2})} on ${escapeHtml(e.paid_date)}</div>`).join('');
    } else {
      crewPayExisting.innerHTML = '';
    }

    bootstrap.Modal.getOrCreateInstance(crewPayModalEl).show();
  });
});

crewPaySubmit.addEventListener('click', async () => {
  const tripId = document.getElementById('crewPayTripId').value;
  const paidDate = document.getElementById('crewPayDate').value;
  const notes = document.getElementById('crewPayNotes').value.trim();

  const entries = [...crewPayRows.querySelectorAll('.crew-pay-row')].map((row) => ({
    employee_id: row.dataset.employeeId,
    crew_role: row.dataset.role,
    amount: Number(row.querySelector('.crew-pay-amount').value || 0),
  })).filter((e) => e.amount > 0);

  if (!paidDate || entries.length === 0) {
    crewPayMessage.innerHTML = '<div class="alert alert-danger">Enter a paid date and at least one amount.</div>';
    return;
  }

  const body = new URLSearchParams();
  body.append('action', 'log');
  body.append('trip_id', tripId);
  body.append('paid_date', paidDate);
  body.append('notes', notes);
  entries.forEach((e, i) => {
    body.append(`entries[${i}][employee_id]`, e.employee_id);
    body.append(`entries[${i}][crew_role]`, e.crew_role);
    body.append(`entries[${i}][amount]`, e.amount);
  });
  body.append(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);

  const response = await fetch('<?= APP_BASE ?>/ajax/trip_pay_handler.php', { method: 'POST', body });
  const result = await response.json();
  crewPayMessage.innerHTML = `<div class="alert alert-${result.success ? 'success' : 'danger'}">${escapeHtml(result.message)}</div>`;
  if (result.success) setTimeout(() => window.location.reload(), 700);
});
</script>
<?php layoutFoot(); ?>