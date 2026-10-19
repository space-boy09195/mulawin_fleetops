<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('maintenance.reports.operations.view');
if (!isAdmin() && currentRoleKey() !== 'operations_head') {
    http_response_code(403);
    include __DIR__ . '/403.php';
    exit;
}

$today = new DateTimeImmutable('today');
$fromRaw = $_GET['from'] ?? null;
$toRaw = $_GET['to'] ?? null;

$parseDate = static function ($value, string $label): DateTimeImmutable {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        http_response_code(400);
        exit('Invalid ' . $label . ' date.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value) {
        http_response_code(400);
        exit('Invalid ' . $label . ' date.');
    }
    return $date;
};

$fromDate = $fromRaw === null ? $today->modify('-29 days') : $parseDate($fromRaw, 'start');
$toDate = $toRaw === null ? $today : $parseDate($toRaw, 'end');
if ($fromDate > $toDate) {
    http_response_code(400);
    exit('The start date must not be after the end date.');
}
$fromSql = $fromDate->format('Y-m-d 00:00:00');
$toSql = $toDate->modify('+1 day')->format('Y-m-d 00:00:00');

$pdo = getDBConnection();
$truckSummary = $pdo->prepare(
    "SELECT tr.truck_id, tr.plate_number, tr.brand, tr.model,
            COALESCE(costs.record_count, 0) AS maintenance_record_count,
            COALESCE(costs.recorded_cost, 0) AS recorded_cost,
            COALESCE(orders.total_count, 0) AS work_order_count,
            COALESCE(orders.open_count, 0) AS open_work_order_count,
            COALESCE(orders.completed_count, 0) AS completed_work_order_count,
            COALESCE(orders.cancelled_count, 0) AS cancelled_work_order_count,
            COALESCE(orders.downtime_minutes, 0) AS downtime_minutes
     FROM trucks tr
     LEFT JOIN (
         SELECT mr.truck_id, COUNT(*) AS record_count, SUM(mr.total_cost) AS recorded_cost
         FROM maintenance_records mr
         WHERE mr.status <> 'Cancelled'
           AND COALESCE(mr.actual_completion_date, mr.start_date, mr.scheduled_date, DATE(mr.created_at)) >= :costFrom
           AND COALESCE(mr.actual_completion_date, mr.start_date, mr.scheduled_date, DATE(mr.created_at)) < :costTo
         GROUP BY mr.truck_id
     ) costs ON costs.truck_id = tr.truck_id
     LEFT JOIN (
         SELECT mr.truck_id, COUNT(*) AS total_count,
                SUM(mr.status NOT IN ('Completed', 'Cancelled')) AS open_count,
                SUM(mr.status = 'Completed') AS completed_count,
                SUM(mr.status = 'Cancelled') AS cancelled_count,
                SUM(CASE WHEN mr.status <> 'Cancelled'
                    THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, mr.created_at, COALESCE(mr.closed_at, NOW())))
                    ELSE 0 END) AS downtime_minutes
         FROM maintenance_records mr
         WHERE mr.record_source = 'work_order'
           AND mr.created_at >= :orderFrom AND mr.created_at < :orderTo
         GROUP BY mr.truck_id
     ) orders ON orders.truck_id = tr.truck_id
     WHERE costs.truck_id IS NOT NULL OR orders.truck_id IS NOT NULL
     ORDER BY COALESCE(costs.recorded_cost, 0) DESC, tr.plate_number"
);
$truckSummary->execute([
    ':costFrom' => $fromSql,
    ':costTo' => $toSql,
    ':orderFrom' => $fromSql,
    ':orderTo' => $toSql,
]);
$truckRows = $truckSummary->fetchAll(PDO::FETCH_ASSOC);

$orderStmt = $pdo->prepare(
    "SELECT mr.maintenance_id, tr.plate_number, tr.brand, tr.model,
            mr.reported_issue AS title, mr.work_order_priority AS priority, mr.status,
            mr.created_at AS opened_at, mr.expected_completion_date, mr.closed_at,
            CASE WHEN mr.status = 'Cancelled' THEN 0
                 ELSE GREATEST(0, TIMESTAMPDIFF(MINUTE, mr.created_at, COALESCE(mr.closed_at, NOW())))
            END AS downtime_minutes
     FROM maintenance_records mr
     JOIN trucks tr ON tr.truck_id = mr.truck_id
     WHERE mr.record_source = 'work_order'
       AND mr.created_at >= :orderFrom AND mr.created_at < :orderTo
     ORDER BY mr.created_at DESC, mr.maintenance_id DESC
     LIMIT 500"
);
$orderStmt->execute([':orderFrom' => $fromSql, ':orderTo' => $toSql]);
$workOrderRows = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

$totalRecordedCost = array_sum(array_map(static fn(array $row): float => (float)$row['recorded_cost'], $truckRows));
$totalDowntimeMinutes = array_sum(array_map(static fn(array $row): int => (int)$row['downtime_minutes'], $truckRows));
layoutHead('Operations Maintenance Report');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
  <div>
    <h1 class="page-title">Operations Maintenance Report</h1>
    <p class="page-subtitle">Limited per-truck view of recorded maintenance costs, work-order counts, downtime, and closure times.</p>
  </div>
  <form method="get" class="d-flex flex-wrap gap-2 align-items-end">
    <div><label class="form-label" for="maintenanceFrom">From</label><input class="form-control" id="maintenanceFrom" name="from" type="date" value="<?= htmlspecialchars($fromDate->format('Y-m-d')) ?>" required></div>
    <div><label class="form-label" for="maintenanceTo">To</label><input class="form-control" id="maintenanceTo" name="to" type="date" value="<?= htmlspecialchars($toDate->format('Y-m-d')) ?>" required></div>
    <button class="btn btn-primary" type="submit">Apply</button>
  </form>
</div>

<div class="alert alert-info">
  Recorded cost uses <code>maintenance_records.total_cost</code> for non-cancelled records dated by completion, then start, scheduled, or created date. Work-order counts use opened date. Downtime covers each matching non-cancelled order from opening to its recorded close time, or through now while still open. Parts stock-in purchases and separate labor figures are not included.
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6"><div class="card h-100"><div class="card-body-custom"><div class="text-muted small">Recorded maintenance cost</div><div class="fs-3 fw-semibold">₱<?= number_format($totalRecordedCost, 2) ?></div></div></div></div>
  <div class="col-md-6"><div class="card h-100"><div class="card-body-custom"><div class="text-muted small">Total work-order downtime</div><div class="fs-3 fw-semibold"><?= number_format($totalDowntimeMinutes / 60, 1) ?> hours</div><div class="small text-muted">Based on work orders opened in the selected period.</div></div></div></div>
</div>

<div class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Per-truck summary</h2></div>
  <div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th>Truck</th><th>Maintenance records</th><th>Recorded cost</th><th>Work orders</th><th>Open</th><th>Completed</th><th>Cancelled</th><th>Downtime</th></tr></thead>
    <tbody>
    <?php if (!$truckRows): ?><tr><td colspan="8" class="text-muted p-3">No maintenance records or work orders in this date range.</td></tr><?php endif; ?>
    <?php foreach ($truckRows as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['plate_number'] . ' — ' . $row['brand'] . ' ' . $row['model']) ?></td>
      <td><?= (int)$row['maintenance_record_count'] ?></td>
      <td>₱<?= number_format((float)$row['recorded_cost'], 2) ?></td>
      <td><?= (int)$row['work_order_count'] ?></td>
      <td><?= (int)$row['open_work_order_count'] ?></td>
      <td><?= (int)$row['completed_work_order_count'] ?></td>
      <td><?= (int)$row['cancelled_work_order_count'] ?></td>
      <td><?= number_format((int)$row['downtime_minutes'] / 60, 1) ?> hrs</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Work-order closure times</h2><span class="text-muted small">Latest 500 orders opened in the selected period; opener names are excluded.</span></div>
  <div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th>Truck</th><th>Work order</th><th>Status</th><th>Opened</th><th>Expected completion</th><th>Closed</th><th>Elapsed downtime</th></tr></thead>
    <tbody>
    <?php if (!$workOrderRows): ?><tr><td colspan="7" class="text-muted p-3">No work orders opened in this date range.</td></tr><?php endif; ?>
    <?php foreach ($workOrderRows as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['plate_number'] . ' — ' . $row['brand'] . ' ' . $row['model']) ?></td>
      <td><?= htmlspecialchars($row['title'] ?? '—') ?><div class="small text-muted"><?= htmlspecialchars((string)$row['priority']) ?></div></td>
      <td><?= htmlspecialchars($row['status']) ?></td>
      <td><?= date('M d, Y H:i', strtotime($row['opened_at'])) ?></td>
      <td><?= $row['expected_completion_date'] ? date('M d, Y', strtotime($row['expected_completion_date'])) : '—' ?></td>
      <td><?= $row['closed_at'] ? date('M d, Y H:i', strtotime($row['closed_at'])) : 'Not closed' ?></td>
      <td><?= $row['status'] === 'Cancelled' ? 'Not counted' : number_format((int)$row['downtime_minutes'] / 60, 1) . ' hrs' . ($row['closed_at'] ? '' : ' (ongoing)') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php layoutFoot(); ?>
