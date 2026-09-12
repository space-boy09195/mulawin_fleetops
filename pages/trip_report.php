<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';

requireRole([ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_ACCOUNTING]);

$tripId = filter_input(INPUT_GET, 'trip_id', FILTER_VALIDATE_INT);
if (!$tripId) {
    http_response_code(400);
    exit('Invalid trip.');
}

$pdo = getDBConnection();
$tripStmt = $pdo->prepare("
    SELECT t.trip_number, t.status, t.actual_arrival, tr.plate_number,
           e.full_name AS driver_name, r.origin, r.destination
    FROM trips t
    JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
    JOIN trucks tr ON tr.truck_id = dr.truck_id
    JOIN employees e ON e.employee_id = dr.driver_id
    JOIN routes r ON r.route_id = dr.route_id
    WHERE t.trip_id = ?
");
$tripStmt->execute([$tripId]);
$trip = $tripStmt->fetch(PDO::FETCH_ASSOC);
if (!$trip) {
    http_response_code(404);
    exit('Trip not found.');
}

$docsStmt = $pdo->prepare("
    SELECT document_id, doc_type, file_name, description, uploaded_at
    FROM documents
    WHERE trip_id = ? AND visibility_scope IN ('all', 'operations')
    ORDER BY uploaded_at
");
$docsStmt->execute([$tripId]);
$documents = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

layoutHead('Trip Report', APP_BASE . '/assets/css/trip_monitor.css');
?>
<div class="card p-4">
  <div class="d-flex justify-content-between align-items-start">
    <div>
      <h1 class="h3 mb-1">Closed Trip Report</h1>
      <p class="text-muted mb-0"><?= htmlspecialchars($trip['trip_number']) ?></p>
    </div>
    <span class="badge bg-success"><?= htmlspecialchars($trip['status']) ?></span>
  </div>
  <hr>
  <dl class="row mb-4">
    <dt class="col-sm-3">Route</dt>
    <dd class="col-sm-9"><?= htmlspecialchars($trip['origin'] . ' → ' . $trip['destination']) ?></dd>
    <dt class="col-sm-3">Truck</dt>
    <dd class="col-sm-9"><?= htmlspecialchars($trip['plate_number']) ?></dd>
    <dt class="col-sm-3">Driver</dt>
    <dd class="col-sm-9"><?= htmlspecialchars($trip['driver_name']) ?></dd>
    <dt class="col-sm-3">Completed</dt>
    <dd class="col-sm-9"><?= $trip['actual_arrival'] ? date('M j, Y g:i A', strtotime($trip['actual_arrival'])) : '—' ?></dd>
  </dl>
  <h2 class="h5">Delivery documents</h2>
  <?php if (!$documents): ?>
    <p class="text-muted">No delivery receipt or waybill was attached.</p>
  <?php else: ?>
    <div class="list-group">
      <?php foreach ($documents as $document): ?>
      <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
         href="<?= APP_BASE ?>/ajax/document_download.php?id=<?= (int)$document['document_id'] ?>" target="_blank" rel="noopener">
        <span><strong><?= htmlspecialchars($document['doc_type']) ?></strong> — <?= htmlspecialchars($document['file_name']) ?></span>
        <i class="bi bi-box-arrow-up-right"></i>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php layoutFoot(); ?>
