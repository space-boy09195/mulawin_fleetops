<?php
// Trip Pay entry for Payroll: per-trip Driver/Helper wages only. Shows just
// the trip and its assigned crew, never trip expenses, revenue or profit
// (those stay on the Finance-only Trip Costs page). Saving goes through
// ajax/trip_pay_handler.php, which enforces payroll.manage and the trip's
// actual driver/helper assignment on the server.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/finance_records.php';

requirePermission('payroll.manage');

$pdo = getDBConnection();
$search = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($search) > 30) {
    $search = mb_substr($search, 0, 30);
}

// Target: crew on trips (driver, second driver (T-T1), helper); wages in
// trip_pay_records (T-P1). Trips awaiting approval are not payable.
$tripSql = "
    SELECT t.trip_id, t.trip_number, t.status, t.created_at,
           t.driver_id, drv.full_name AS driver_name,
           t.second_driver_id, sdrv.full_name AS second_driver_name,
           t.helper_id, hlp.full_name AS helper_name
    FROM trips t
    LEFT JOIN employees drv  ON drv.employee_id  = t.driver_id
    LEFT JOIN employees sdrv ON sdrv.employee_id = t.second_driver_id
    LEFT JOIN employees hlp  ON hlp.employee_id  = t.helper_id
    WHERE t.status NOT IN ('Cancelled', 'Pending Approval')";
$params = [];
if ($search !== '') {
    $tripSql .= ' AND t.trip_number LIKE ?';
    $params[] = '%' . addcslashes($search, '%_\\') . '%';
}
$tripSql .= ' ORDER BY t.created_at DESC LIMIT 200';
$tripStmt = $pdo->prepare($tripSql);
$tripStmt->execute($params);
$trips = $tripStmt->fetchAll(PDO::FETCH_ASSOC);

$tripPayTableMissing = false;
$tripPayByTrip = [];
$tripIds = array_map(fn($trip) => (int)$trip['trip_id'], $trips);
if ($tripIds) {
    $placeholders = implode(',', array_fill(0, count($tripIds), '?'));
    $payStmt = $pdo->prepare("
        SELECT tp.trip_id, tp.employee_id,
               IF(tp.pay_type = 'Helper Trip Pay', 'Helper', 'Driver') AS crew_role,
               tp.amount, tp.notes AS stored_notes, e.full_name AS employee_name
        FROM trip_pay_records tp
        JOIN employees e ON e.employee_id = tp.employee_id
        WHERE tp.trip_id IN ($placeholders) AND tp.status <> 'Cancelled'
        ORDER BY tp.trip_pay_id DESC
    ");
    $payStmt->execute($tripIds);
    foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $pay) {
        $pay['paid_date'] = tripPaySplitNotes($pay['stored_notes'])['paid_date'] ?? '';
        unset($pay['stored_notes']);
        $tripPayByTrip[(int)$pay['trip_id']][] = $pay;
    }
}

layoutHead('Trip Pay', APP_BASE . '/assets/css/billing.css');
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
      <h1 class="bil-title mb-0">Trip Pay</h1>
      <p class="bil-subtitle mb-0">Log the per-trip wage for each trip's assigned Driver and Helper. Trip Pay is not Driver Allowance: allowances are trip expenses recorded by Finance, and both may exist for the same trip and crew member.</p>
    </div>
  </div>

  <form method="get" class="d-flex gap-2 mb-3" role="search">
    <input type="search" name="q" class="form-control" style="max-width: 280px" maxlength="30"
           placeholder="Search trip number" value="<?= htmlspecialchars($search) ?>" aria-label="Search trip number">
    <button type="submit" class="btn btn-outline-secondary">Search</button>
    <?php if ($search !== ''): ?><a class="btn btn-link" href="<?= APP_BASE ?>/pages/trip_pay.php">Clear</a><?php endif; ?>
  </form>

  <div class="bil-table-wrap">
    <table class="table bil-table">
      <thead><tr><th>Trip</th><th>Trip Date</th><th>Status</th><th>Assigned Crew</th><th>Trip Pay Logged</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($trips as $trip):
        $entries = $tripPayByTrip[(int)$trip['trip_id']] ?? [];
      ?>
      <tr>
        <td><?= htmlspecialchars($trip['trip_number']) ?></td>
        <td><?= htmlspecialchars(date('M d, Y', strtotime($trip['created_at']))) ?></td>
        <td><?= htmlspecialchars($trip['status']) ?></td>
        <td>
          <div><?= htmlspecialchars($trip['driver_name'] ?? '—') ?> <span class="text-muted">(Driver)</span></div>
          <?php if (!empty($trip['second_driver_name'])): ?>
          <div><?= htmlspecialchars($trip['second_driver_name']) ?> <span class="text-muted">(Second driver)</span></div>
          <?php endif; ?>
          <?php if (!empty($trip['helper_name'])): ?>
          <div><?= htmlspecialchars($trip['helper_name']) ?> <span class="text-muted">(Helper)</span></div>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($tripPayTableMissing): ?>
            <span class="text-muted">—</span>
          <?php elseif (!$entries): ?>
            <span class="text-muted">Not logged</span>
          <?php else: foreach ($entries as $entry): ?>
            <div class="small"><?= htmlspecialchars($entry['crew_role']) ?>: ₱<?= number_format((float)$entry['amount'], 2) ?>
              <span class="text-muted">on <?= htmlspecialchars($entry['paid_date']) ?></span></div>
          <?php endforeach; endif; ?>
        </td>
        <td class="text-end">
          <?php if (!$tripPayTableMissing): ?>
          <button type="button" class="btn btn-sm btn-outline-secondary crew-pay-btn"
                  data-trip-id="<?= (int)$trip['trip_id'] ?>"
                  data-trip="<?= htmlspecialchars($trip['trip_number']) ?>"
                  data-driver-id="<?= (int)$trip['driver_id'] ?>"
                  data-driver-name="<?= htmlspecialchars($trip['driver_name'] ?? '') ?>"
                  data-second-driver-id="<?= $trip['second_driver_id'] !== null ? (int)$trip['second_driver_id'] : '' ?>"
                  data-second-driver-name="<?= htmlspecialchars($trip['second_driver_name'] ?? '') ?>"
                  data-helper-id="<?= $trip['helper_id'] !== null ? (int)$trip['helper_id'] : '' ?>"
                  data-helper-name="<?= htmlspecialchars($trip['helper_name'] ?? '') ?>"
                  data-existing="<?= htmlspecialchars(json_encode($entries), ENT_QUOTES, 'UTF-8') ?>">
            <i class="bi bi-pencil me-1"></i>Manage Trip Pay
          </button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$trips): ?><div class="bil-empty"><p><?= $search !== '' ? 'No trips match that trip number.' : 'No trips yet.' ?></p></div><?php endif; ?>
    <?php if (count($trips) === 200): ?><p class="text-muted small mt-2">Showing the 200 most recent trips. Search by trip number to find an older trip.</p><?php endif; ?>
  </div>

  <div class="modal fade" id="crewPayModal" tabindex="-1" aria-labelledby="crewPayTitle">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="crewPayTitle">Log Trip Pay</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="crewPayMessage"></div>
        <p class="text-muted small">Trip Pay is per-trip Driver/Helper compensation, not Driver Allowance. A separate Driver Allowance expense may also be recorded for this trip and crew member.</p>
        <input type="hidden" id="crewPayTripId">
        <div class="mb-3">
          <label class="form-label">Paid Date</label>
          <input type="date" id="crewPayDate" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div id="crewPayRows" class="d-grid gap-2 mb-3"></div>
        <div class="mb-3">
          <label class="form-label">Notes</label>
          <input type="text" id="crewPayNotes" class="form-control" maxlength="200">
        </div>
        <div id="crewPayExisting"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-bil-primary" id="crewPaySubmit">Save Trip Pay</button>
      </div>
    </div></div>
  </div>
</div>
<script>
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char]));
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
      <input type="number" min="0.01" step="0.01" class="form-control crew-pay-amount" placeholder="₱0.00" value="${escapeHtml(existingAmount ?? '')}">
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
    document.getElementById('crewPayTitle').textContent = `Log Trip Pay — ${button.dataset.trip}`;

    if (button.dataset.driverId) {
      const existingPay = existingByEmployee[button.dataset.driverId];
      crewPayRows.appendChild(buildCrewPayRow(button.dataset.driverId, button.dataset.driverName, 'Driver', existingPay?.amount));
    }
    if (button.dataset.secondDriverId) {
      const existingPay = existingByEmployee[button.dataset.secondDriverId];
      crewPayRows.appendChild(buildCrewPayRow(button.dataset.secondDriverId, button.dataset.secondDriverName, 'Driver', existingPay?.amount));
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

  try {
    const response = await fetch('<?= APP_BASE ?>/ajax/trip_pay_handler.php', { method: 'POST', body });
    const result = await response.json();
    crewPayMessage.innerHTML = `<div class="alert alert-${result.success ? 'success' : 'danger'}">${escapeHtml(result.message)}</div>`;
    if (result.success) setTimeout(() => window.softReload(), 700);
  } catch (error) {
    crewPayMessage.innerHTML = '<div class="alert alert-danger">Network error. Please try again.</div>';
  }
});
</script>
<?php layoutFoot(); ?>
