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
        <td>₱<?= number_format((float)$row['total_expense'], 2) ?></td>
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
</div>

<div class="modal fade" id="expenseModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="<?= APP_BASE ?>/ajax/trip_costs_handler.php" id="expenseForm">
      <div class="modal-header"><h5 class="modal-title">Record Trip Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="action" value="create_expense">
        <div class="mb-3"><label class="form-label">Trip</label><select name="trip_id" class="form-select" required><option value="">Select trip</option><?php foreach ($trips as $trip): ?><option value="<?= $trip['trip_id'] ?>"><?= htmlspecialchars($trip['trip_number'] . ' — ' . $trip['plate_number']) ?></option><?php endforeach; ?></select></div>
        <div class="row g-2"><div class="col-md-6"><label class="form-label">Type</label><select name="expense_type" class="form-select" required><option>Fuel</option><option>Toll</option><option>Driver Allowance</option><option>Other</option></select></div><div class="col-md-6"><label class="form-label">Amount (₱)</label><input name="amount" type="number" min="0.01" step="0.01" class="form-control" required></div></div>
        <div class="row g-2 mt-1"><div class="col-md-6"><label class="form-label">Fuel quantity (L)</label><input name="quantity" type="number" min="0.01" step="0.01" class="form-control"></div><div class="col-md-6"><label class="form-label">Date</label><input name="expense_date" type="date" value="<?= date('Y-m-d') ?>" class="form-control" required></div></div>
        <div class="mt-2"><label class="form-label">Notes</label><input name="notes" class="form-control" maxlength="255"></div>
        <div id="expenseMessage" class="mt-3"></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-bil-primary">Save Expense</button></div>
    </form>
  </div></div>
</div>
<script>
document.getElementById('expenseForm').addEventListener('submit', async function (event) {
  event.preventDefault();
  const response = await fetch(this.action, { method: 'POST', body: new URLSearchParams(new FormData(this)) });
  const result = await response.json();
  document.getElementById('expenseMessage').innerHTML = '<div class="alert alert-' + (result.success ? 'success' : 'danger') + '">' + result.message + '</div>';
  if (result.success) setTimeout(() => window.location.reload(), 700);
});
</script>
