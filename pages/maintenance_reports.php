<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('maintenance.reports.view');
$today = date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? $today;
if (!isValidDate($from) || !isValidDate($to) || $from > $to) {
    http_response_code(400);
    exit('Choose a valid date range.');
}
$pdo = getDBConnection();
$params = [':from' => $from . ' 00:00:00', ':to' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
$orderStmt = $pdo->prepare(
    "SELECT wo.work_order_id, wo.title, wo.priority, wo.status, wo.opened_at, wo.closed_at,
            TIMESTAMPDIFF(MINUTE, wo.opened_at, COALESCE(wo.closed_at, NOW())) AS downtime_minutes,
            tr.plate_number, u.full_name AS opened_by
     FROM repair_work_orders wo
     JOIN trucks tr ON tr.truck_id = wo.truck_id
     JOIN users u ON u.user_id = wo.created_by
     WHERE wo.opened_at >= :from AND wo.opened_at < :to
     ORDER BY wo.opened_at DESC LIMIT 500"
);
$orderStmt->execute($params);
$orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
$totalsStmt = $pdo->prepare(
    "SELECT COUNT(*) AS order_count,
            SUM(status IN ('Open','In Progress')) AS open_orders,
            SUM(status = 'Completed') AS completed_orders,
            SUM(CASE WHEN status <> 'Cancelled'
                THEN TIMESTAMPDIFF(MINUTE, opened_at, COALESCE(closed_at, NOW())) ELSE 0 END) AS downtime_minutes
     FROM repair_work_orders WHERE opened_at >= :from AND opened_at < :to"
);
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);
$maintenanceStmt = $pdo->prepare(
    "SELECT tr.plate_number, COUNT(*) AS services, COALESCE(SUM(mr.cost), 0) AS total_cost
     FROM maintenance_records mr JOIN trucks tr ON tr.truck_id = mr.truck_id
     WHERE mr.date_performed >= :from_date AND mr.date_performed <= :to_date
     GROUP BY tr.truck_id, tr.plate_number ORDER BY total_cost DESC, services DESC"
);
$maintenanceStmt->execute([':from_date' => $from, ':to_date' => $to]);
$serviceCosts = $maintenanceStmt->fetchAll(PDO::FETCH_ASSOC);
layoutHead('Maintenance Reports');
$downtimeHours = round((int)$totals['downtime_minutes'] / 60, 1);
?>
<div class="page-header"><h1 class="page-title">Maintenance Reports</h1><p class="page-subtitle">Review repair-order downtime and recorded maintenance costs by truck.</p></div>
<form method="get" class="card mb-4"><div class="card-body-custom row g-3 align-items-end">
<div class="col-sm-4"><label class="form-label" for="from">From</label><input class="form-control" id="from" name="from" type="date" value="<?= htmlspecialchars($from) ?>" required></div>
<div class="col-sm-4"><label class="form-label" for="to">To</label><input class="form-control" id="to" name="to" type="date" value="<?= htmlspecialchars($to) ?>" required></div>
<div class="col-sm-4"><button class="btn btn-primary">Apply date range</button></div></div></form>
<div class="row g-3 mb-4">
<?php foreach (['Repair orders' => (int)$totals['order_count'], 'Open orders' => (int)$totals['open_orders'], 'Completed orders' => (int)$totals['completed_orders'], 'Recorded downtime' => $downtimeHours . ' hrs'] as $label => $value): ?>
<div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-info"><div class="stat-value"><?= htmlspecialchars((string)$value) ?></div><div class="stat-label"><?= htmlspecialchars($label) ?></div></div></div></div>
<?php endforeach; ?>
</div>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Repair orders and downtime</h2><span class="text-muted small">Up to 500 records; cancelled orders excluded from downtime totals</span></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Truck</th><th>Work order</th><th>Priority</th><th>Status</th><th>Opened</th><th>Closed</th><th>Elapsed downtime</th><th>Opened by</th></tr></thead><tbody>
<?php foreach ($orders as $order): $minutes = max(0, (int)$order['downtime_minutes']); ?><tr><td><?= htmlspecialchars($order['plate_number']) ?></td><td><?= htmlspecialchars($order['title']) ?></td><td><?= htmlspecialchars($order['priority']) ?></td><td><?= htmlspecialchars($order['status']) ?></td><td><?= htmlspecialchars($order['opened_at']) ?></td><td><?= htmlspecialchars($order['closed_at'] ?? 'Ongoing') ?></td><td><?= intdiv($minutes, 60) ?>h <?= $minutes % 60 ?>m</td><td><?= htmlspecialchars($order['opened_by']) ?></td></tr><?php endforeach; ?>
<?php if (!$orders): ?><tr><td colspan="8" class="text-center text-muted py-4">No repair orders in this date range.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="card"><div class="card-header-custom"><h2 class="card-title-custom">Maintenance cost by truck</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Truck</th><th>Service records</th><th>Recorded cost</th></tr></thead><tbody>
<?php foreach ($serviceCosts as $row): ?><tr><td><?= htmlspecialchars($row['plate_number']) ?></td><td><?= (int)$row['services'] ?></td><td>₱<?= number_format((float)$row['total_cost'], 2) ?></td></tr><?php endforeach; ?>
<?php if (!$serviceCosts): ?><tr><td colspan="3" class="text-center text-muted py-4">No maintenance records in this date range.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php layoutFoot(); ?>
