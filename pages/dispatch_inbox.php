<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dispatcher_scope.php';

requirePermission('dispatch.instructions.encode');

$pdo = getDBConnection();
$scope = dispatcherScope();
$instructionWhere = "i.status = 'Open'";
$instructionParams = [];
if ($scope) {
    $instructionWhere .= ' AND i.shift = ?';
    $instructionParams[] = $scope['shift'];
}
$instructionQuery = $pdo->prepare(
    "SELECT i.instruction_id, i.batch_id, i.shift_date, i.shift, i.scheduled_at, i.unit_count,
            i.instruction_notes, c.client_name, r.route_name, r.origin, r.destination,
            u.full_name AS operations_head
     FROM dispatch_instructions i
     JOIN clients c ON c.client_id = i.client_id
     JOIN routes r ON r.route_id = i.route_id
     JOIN users u ON u.user_id = i.created_by
     WHERE " . $instructionWhere . "
     ORDER BY CASE WHEN i.batch_id IS NULL THEN 1 ELSE 0 END,
              i.batch_id DESC, i.shift_date, FIELD(i.shift, 'Day', 'Night'),
              i.scheduled_at, i.instruction_id"
);
$instructionQuery->execute($instructionParams);
$instructions = $instructionQuery->fetchAll(PDO::FETCH_ASSOC);
$recentlyEncoded = $pdo->query(
    "SELECT i.instruction_id, i.batch_id, i.shift_date, i.shift, i.scheduled_at, i.status,
            c.client_name, r.route_name, i.dispatch_id
     FROM dispatch_instructions i
     JOIN clients c ON c.client_id = i.client_id
     JOIN routes r ON r.route_id = i.route_id
     WHERE i.status = 'Encoded'
     ORDER BY i.encoded_at DESC
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
      <thead><tr><th>Batch</th><th>Ref</th><th>Shift</th><th>Client</th><th>Route</th><th>Scheduled</th><th>Units</th><th>From Operations Head</th><th>Instructions</th><th>Action</th></tr></thead>
      <tbody>
      <?php if (!$instructions): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">No dispatch instructions are waiting to be encoded.</td></tr>
      <?php else: foreach ($instructions as $instruction): ?>
        <tr>
          <td><?= $instruction['batch_id'] !== null ? 'Batch #' . (int)$instruction['batch_id'] : 'Single' ?></td>
          <td>#<?= (int)$instruction['instruction_id'] ?></td>
          <td><span class="badge text-bg-<?= $instruction['shift'] === 'Day' ? 'info' : 'dark' ?>"><?= htmlspecialchars($instruction['shift']) ?></span></td>
          <td><?= htmlspecialchars($instruction['client_name']) ?></td>
          <td><?= htmlspecialchars($instruction['route_name']) ?><br><small class="text-muted"><?= htmlspecialchars($instruction['origin'] . ' to ' . $instruction['destination']) ?></small></td>
          <td><?= date('M j, Y g:i A', strtotime($instruction['scheduled_at'])) ?></td>
          <td><?= $instruction['unit_count'] !== null ? number_format((float)$instruction['unit_count'], 2) : '—' ?></td>
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
