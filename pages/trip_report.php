<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/crew_trip_access.php';
require_once __DIR__ . '/../includes/trip_queries.php';
require_once __DIR__ . '/../includes/trip_workflow.php';

// trips.view or billing.view, or a Helper limited to trips assigned to them.
$helperEmployeeId = tripPageAccess(['trips.view', 'billing.view']);

$tripId = filter_input(INPUT_GET, 'trip_id', FILTER_VALIDATE_INT);
if (!$tripId) {
    http_response_code(400);
    exit('Invalid trip.');
}
if ($helperEmployeeId !== null) {
    requireHelperAssignedToTrip((int)$tripId, $helperEmployeeId);
}

$pdo = getDBConnection();
$tripStmt = $pdo->prepare(tripSelectSql() . ' WHERE t.trip_id = ?');
$tripStmt->execute([$tripId]);
$trip = $tripStmt->fetch(PDO::FETCH_ASSOC);
if (!$trip) {
    http_response_code(404);
    exit('Trip not found.');
}
requireTripInDispatcherScope($trip['shift'], $trip['truck_type']);

// Trip documents through document_links (target).
$docsStmt = $pdo->prepare("
    SELECT d.document_id, d.document_category AS doc_type, f.original_name AS file_name,
           d.description, f.uploaded_at
    FROM document_links dl
    JOIN documents d ON d.document_id = dl.document_id
    JOIN files f ON f.file_id = d.file_id
    WHERE dl.entity_type = 'trip' AND dl.entity_id = ?
      AND COALESCE(d.visibility_scope, 'all') IN ('all', 'operations')
    ORDER BY f.uploaded_at
");
$docsStmt->execute([$tripId]);
$documents = $docsStmt->fetchAll(PDO::FETCH_ASSOC);
$deliveryStmt = $pdo->prepare('SELECT * FROM trip_deliveries WHERE trip_id = ?');
$deliveryStmt->execute([$tripId]);
$returnStmt = $pdo->prepare('SELECT * FROM trip_returns WHERE trip_id = ?');
$returnStmt->execute([$tripId]);
$deliveryReturn = tripDeliveryFormValues(
    $deliveryStmt->fetch(PDO::FETCH_ASSOC) ?: null,
    $returnStmt->fetch(PDO::FETCH_ASSOC) ?: null
);

layoutHead('Trip Report', APP_BASE . '/assets/css/trip_monitor.css');
?>
<div class="card p-4">
  <div class="d-flex justify-content-between align-items-start">
    <div>
      <h1 class="h3 mb-1">Closed Trip Report</h1>
      <p class="text-muted mb-0"><?= htmlspecialchars($trip['trip_number']) ?></p>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <span class="badge bg-success"><?= htmlspecialchars($trip['status']) ?></span>
      <a class="btn btn-sm btn-outline-primary" href="<?= APP_BASE ?>/pages/trip_workflow.php?trip_id=<?= (int)$trip['trip_id'] ?>">Workflow timeline</a>
    </div>
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
  <h2 class="h5">Delivery and return details</h2>
  <?php if (!$deliveryReturn): ?>
    <p class="text-muted">No delivery/return detail record is available for this trip.</p>
  <?php else: ?>
    <dl class="row">
      <dt class="col-sm-3">Delivered units</dt>
      <dd class="col-sm-9"><?= $deliveryReturn['delivered_unit_count'] === null ? '—' : htmlspecialchars((string)$deliveryReturn['delivered_unit_count']) ?></dd>
      <dt class="col-sm-3">Delivery receipt</dt>
      <dd class="col-sm-9"><?= htmlspecialchars($deliveryReturn['delivery_receipt_number'] ?? '—') ?></dd>
      <dt class="col-sm-3">Shared waybill</dt>
      <dd class="col-sm-9"><?= htmlspecialchars($deliveryReturn['shared_waybill_reference'] ?? '—') ?></dd>
      <dt class="col-sm-3">Co-load reference</dt>
      <dd class="col-sm-9"><?= htmlspecialchars($deliveryReturn['co_load_reference'] ?? '—') ?></dd>
      <dt class="col-sm-3">Delivery details</dt>
      <dd class="col-sm-9"><?= nl2br(htmlspecialchars($deliveryReturn['delivery_notes'])) ?></dd>
      <dt class="col-sm-3">Return location</dt>
      <dd class="col-sm-9"><?= htmlspecialchars($deliveryReturn['return_location'] ?? '—') ?></dd>
      <dt class="col-sm-3">Return details</dt>
      <dd class="col-sm-9"><?= nl2br(htmlspecialchars($deliveryReturn['return_notes'])) ?></dd>
    </dl>
  <?php endif; ?>
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
