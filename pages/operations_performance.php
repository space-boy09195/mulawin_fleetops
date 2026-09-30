<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('operations.reports.view');
$today = date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? $today;
if (!isValidDate($from) || !isValidDate($to) || $from > $to) {
    http_response_code(400);
    exit('Choose a valid date range.');
}
$pdo = getDBConnection();
$params = [':from' => $from . ' 00:00:00', ':to' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
$where = 't.created_at >= :from AND t.created_at < :to';
$summaryStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total_trips,
            SUM(t.status = 'Completed') AS completed,
            SUM(t.status = 'Cancelled') AS cancelled,
            SUM(t.is_late = 1) AS late_trips,
            AVG(CASE WHEN t.status = 'Completed' AND t.actual_departure_at IS NOT NULL AND t.actual_arrival IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, t.actual_departure_at, t.actual_arrival) END) AS avg_trip_minutes
     FROM trips t WHERE $where"
);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
$dispatcherStmt = $pdo->prepare(
    "SELECT u.full_name AS dispatcher, COUNT(t.trip_id) AS trips,
            SUM(t.status = 'Completed') AS completed,
            SUM(t.status = 'Cancelled') AS cancelled,
            SUM(t.is_late = 1) AS late_trips
     FROM trips t
     JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
     JOIN users u ON u.user_id = dr.requested_by
     WHERE $where
     GROUP BY u.user_id, u.full_name ORDER BY trips DESC, dispatcher"
);
$dispatcherStmt->execute($params);
$dispatchers = $dispatcherStmt->fetchAll(PDO::FETCH_ASSOC);
$truckStmt = $pdo->prepare(
    "SELECT tr.plate_number, tr.truck_type, COUNT(t.trip_id) AS trips,
            SUM(t.status = 'Completed') AS completed,
            SUM(t.is_late = 1) AS late_trips
     FROM trips t
     JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
     JOIN trucks tr ON tr.truck_id = dr.truck_id
     WHERE $where
     GROUP BY tr.truck_id, tr.plate_number, tr.truck_type
     ORDER BY trips DESC, tr.plate_number"
);
$truckStmt->execute($params);
$trucks = $truckStmt->fetchAll(PDO::FETCH_ASSOC);
$completedStmt = $pdo->prepare(
    "SELECT t.trip_number, t.status, t.is_late, t.actual_departure_at, t.actual_arrival,
            dr.client_name, dr.waybill_reference, tr.plate_number, u.full_name AS dispatcher
     FROM trips t
     JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
     JOIN trucks tr ON tr.truck_id = dr.truck_id
     JOIN users u ON u.user_id = dr.requested_by
     WHERE $where AND t.status = 'Completed'
     ORDER BY t.actual_arrival DESC LIMIT 200"
);
$completedStmt->execute($params);
$completedTrips = $completedStmt->fetchAll(PDO::FETCH_ASSOC);
layoutHead('Operations Performance');
?>
<div class="page-header"><h1 class="page-title">Operations Performance</h1><p class="page-subtitle">Review trip completion, dispatcher workloads, truck activity, and completed trip details.</p></div>
<form method="get" class="card mb-4"><div class="card-body-custom row g-3 align-items-end">
<div class="col-sm-4"><label class="form-label" for="from">From</label><input class="form-control" id="from" name="from" type="date" value="<?= htmlspecialchars($from) ?>" required></div>
<div class="col-sm-4"><label class="form-label" for="to">To</label><input class="form-control" id="to" name="to" type="date" value="<?= htmlspecialchars($to) ?>" required></div>
<div class="col-sm-4"><button class="btn btn-primary">Apply date range</button></div></div></form>
<div class="row g-3 mb-4">
<?php foreach ([
    'Trips' => (int)$summary['total_trips'],
    'Completed' => (int)$summary['completed'],
    'Cancelled' => (int)$summary['cancelled'],
    'Late trips' => (int)$summary['late_trips'],
    'Avg. completed duration' => $summary['avg_trip_minutes'] === null ? '—' : round((float)$summary['avg_trip_minutes'] / 60, 1) . ' hrs',
] as $label => $value): ?>
<div class="col-6 col-xl"><div class="stat-card"><div class="stat-info"><div class="stat-value"><?= htmlspecialchars((string)$value) ?></div><div class="stat-label"><?= htmlspecialchars($label) ?></div></div></div></div>
<?php endforeach; ?>
</div>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Dispatcher performance</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Dispatcher</th><th>Trips</th><th>Completed</th><th>Cancelled</th><th>Late</th></tr></thead><tbody>
<?php foreach ($dispatchers as $row): ?><tr><td><?= htmlspecialchars($row['dispatcher']) ?></td><td><?= (int)$row['trips'] ?></td><td><?= (int)$row['completed'] ?></td><td><?= (int)$row['cancelled'] ?></td><td><?= (int)$row['late_trips'] ?></td></tr><?php endforeach; ?>
<?php if (!$dispatchers): ?><tr><td colspan="5" class="text-center text-muted py-4">No trips in this date range.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="card mb-4"><div class="card-header-custom"><h2 class="card-title-custom">Truck utilization by trip count</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Truck</th><th>Type</th><th>Trips</th><th>Completed</th><th>Late</th></tr></thead><tbody>
<?php foreach ($trucks as $row): ?><tr><td><?= htmlspecialchars($row['plate_number']) ?></td><td><?= htmlspecialchars($row['truck_type']) ?></td><td><?= (int)$row['trips'] ?></td><td><?= (int)$row['completed'] ?></td><td><?= (int)$row['late_trips'] ?></td></tr><?php endforeach; ?>
<?php if (!$trucks): ?><tr><td colspan="5" class="text-center text-muted py-4">No truck activity in this date range.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="card"><div class="card-header-custom"><h2 class="card-title-custom">Completed trips</h2><span class="text-muted small">Up to 200 records</span></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Trip</th><th>Client</th><th>Truck</th><th>Dispatcher</th><th>Waybill</th><th>Departed</th><th>Arrived</th><th>Late</th></tr></thead><tbody>
<?php foreach ($completedTrips as $trip): ?><tr><td><?= htmlspecialchars($trip['trip_number']) ?></td><td><?= htmlspecialchars($trip['client_name'] ?? '—') ?></td><td><?= htmlspecialchars($trip['plate_number']) ?></td><td><?= htmlspecialchars($trip['dispatcher']) ?></td><td><?= htmlspecialchars($trip['waybill_reference'] ?? '—') ?></td><td><?= htmlspecialchars($trip['actual_departure_at'] ?? '—') ?></td><td><?= htmlspecialchars($trip['actual_arrival'] ?? '—') ?></td><td><?= (int)$trip['is_late'] ? 'Yes' : 'No' ?></td></tr><?php endforeach; ?>
<?php if (!$completedTrips): ?><tr><td colspan="8" class="text-center text-muted py-4">No completed trips in this date range.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php layoutFoot(); ?>
