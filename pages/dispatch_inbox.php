<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';

requirePermission('dispatch.instructions.encode');

$pdo = getDBConnection();
$scopeResult = dispatcherScopeResult();
// Instructions are open client_bookings rows (T-C1: route, scheduled
// departure, shift). The legacy batch grouping has no target table, so
// batch_id is always NULL and items are listed by date and shift.
$instructionWhere = "i.status = 'Pending' AND i.scheduled_departure IS NOT NULL";
$instructionParams = [];
if ($scopeResult['status'] === 'denied') {
    // A dispatcher without one valid assignment sees no instructions.
    $instructionWhere .= ' AND 1 = 0';
} elseif ($scopeResult['status'] === 'scoped') {
    $instructionWhere .= ' AND i.shift = ?';
    $instructionParams[] = $scopeResult['shift'];
    // Only the truck types this dispatcher may encode.
    $instructionWhere .= ' AND i.truck_type IN (' . implode(',', array_fill(0, count($scopeResult['truck_types']), '?')) . ')';
    array_push($instructionParams, ...$scopeResult['truck_types']);
}
$instructionQuery = $pdo->prepare(
    "SELECT i.booking_id AS instruction_id, NULL AS batch_id, i.booking_reference,
            DATE(i.scheduled_departure) AS shift_date, i.shift, i.truck_type,
            i.scheduled_departure AS scheduled_at, i.quantity AS unit_count,
            i.notes AS instruction_notes, c.client_name, r.route_name, r.origin, r.destination,
            COALESCE(e.full_name, u.username) AS operations_head
     FROM client_bookings i
     JOIN clients c ON c.client_id = i.client_id
     JOIN routes r ON r.route_id = i.route_id
     LEFT JOIN users u ON u.user_id = i.created_by
     LEFT JOIN employees e ON e.employee_id = u.employee_id
     WHERE " . $instructionWhere . "
     ORDER BY DATE(i.scheduled_departure), FIELD(i.shift, 'Day', 'Night'),
              i.scheduled_departure, i.booking_id
     LIMIT 300"
);
$instructionQuery->execute($instructionParams);
$instructions = $instructionQuery->fetchAll(PDO::FETCH_ASSOC);
$recentlyEncoded = $pdo->query(
    "SELECT i.booking_id AS instruction_id, NULL AS batch_id, DATE(i.scheduled_departure) AS shift_date,
            i.shift, i.scheduled_departure AS scheduled_at, 'Encoded' AS status,
            c.client_name, r.route_name, dr.dispatch_request_id AS dispatch_id
     FROM client_bookings i
     JOIN clients c ON c.client_id = i.client_id
     JOIN routes r ON r.route_id = i.route_id
     JOIN trips t ON t.booking_id = i.booking_id
     LEFT JOIN dispatch_requests dr ON dr.trip_id = t.trip_id
     WHERE i.status IN ('Confirmed', 'Completed') AND i.scheduled_departure IS NOT NULL
     ORDER BY t.created_at DESC
     LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);

layoutHead('Dispatcher Queue');
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Dispatcher Queue</h1>
    <p class="page-subtitle">Encode the dispatch instructions sent by the Operations Head.</p>
  </div>
  <a class="btn btn-outline-primary" href="<?= APP_BASE ?>/pages/dispatch.php">Dispatch Workspace</a>
</div>

<div class="card mb-4">
  <div class="card-header-custom">
    <h2 class="card-title-custom mb-0">Instructions to Encode</h2>
    <span class="badge text-bg-warning"><?= count($instructions) ?> open</span>
  </div>
  <div class="table-responsive">
    <table class="table-custom">
      <thead><tr><th>Truck Type</th><th>Ref</th><th>Shift</th><th>Client</th><th>Route</th><th>Scheduled</th><th>Units</th><th>From Operations Head</th><th>Instructions</th><th>Action</th></tr></thead>
      <tbody>
      <?php if (!$instructions): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">No dispatch instructions are waiting to be encoded.</td></tr>
      <?php else: foreach ($instructions as $instruction): ?>
        <tr>
          <td><?= htmlspecialchars((string)$instruction['truck_type']) ?></td>
          <td><?= htmlspecialchars((string)$instruction['booking_reference']) ?></td>
          <td><span class="badge text-bg-<?= $instruction['shift'] === 'Day' ? 'info' : 'dark' ?>"><?= htmlspecialchars($instruction['shift']) ?></span></td>
          <td><?= htmlspecialchars($instruction['client_name']) ?></td>
          <td><?= htmlspecialchars($instruction['route_name']) ?><br><small class="text-muted"><?= htmlspecialchars($instruction['origin'] . ' to ' . $instruction['destination']) ?></small></td>
          <td><?= date('M j, Y g:i A', strtotime($instruction['scheduled_at'])) ?></td>
          <td><?= $instruction['unit_count'] !== null ? number_format((int)$instruction['unit_count']) : '—' ?></td>
          <td><?= htmlspecialchars($instruction['operations_head']) ?></td>
          <td><?= $instruction['instruction_notes'] ? htmlspecialchars($instruction['instruction_notes']) : '—' ?></td>
          <td><a class="btn btn-sm btn-primary" href="<?= APP_BASE ?>/pages/dispatch.php?instruction_id=<?= (int)$instruction['instruction_id'] ?>">Encode Dispatch</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header-custom"><h2 class="card-title-custom mb-0">Recently Encoded</h2></div>
  <div class="table-responsive">
    <table class="table-custom">
      <thead><tr><th>Batch</th><th>Ref</th><th>Shift</th><th>Client</th><th>Route</th><th>Scheduled</th><th>Dispatch</th></tr></thead>
      <tbody>
      <?php if (!$recentlyEncoded): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No encoded instructions yet.</td></tr>
      <?php else: foreach ($recentlyEncoded as $instruction): ?>
        <tr>
          <td><?= $instruction['batch_id'] !== null ? 'Batch #' . (int)$instruction['batch_id'] : 'Single' ?></td>
          <td>#<?= (int)$instruction['instruction_id'] ?></td>
          <td><?= htmlspecialchars($instruction['shift']) ?></td>
          <td><?= htmlspecialchars($instruction['client_name']) ?></td>
          <td><?= htmlspecialchars($instruction['route_name']) ?></td>
          <td><?= date('M j, Y g:i A', strtotime($instruction['scheduled_at'])) ?></td>
          <td>Dispatch #<?= (int)$instruction['dispatch_id'] ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layoutFoot(); ?>
