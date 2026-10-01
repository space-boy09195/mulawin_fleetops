<?php
// Printable/exportable pre-advice notice for an approved trip — a summary of
// the trip's route, client, truck, and crew assignment that dispatch/crew can
// print and carry before departure. Read-only; does not change trip state.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';

requirePermission('trips.view');

$tripId = filter_var($_GET['trip_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($tripId === false) {
    http_response_code(400);
    exit('Invalid trip.');
}

$pdo = getDBConnection();
$stmt = $pdo->prepare(
    'SELECT t.trip_id, t.trip_number, t.status, t.cargo_description, t.cargo_weight_tons,
            dr.dispatch_id, dr.client_name, dr.booking_reference, dr.waybill_reference,
            dr.unit_count, dr.scheduled_at, dr.expected_arrival, dr.remarks,
            tr.plate_number, tr.brand, tr.model, tr.truck_type,
            driver.full_name AS driver_name, driver.license_number AS driver_license,
            second.full_name AS second_driver_name,
            helper.full_name AS helper_name,
            route.route_name, route.origin, route.destination,
            approver.full_name AS approved_by_name
     FROM trips t
     JOIN dispatch_requests dr ON dr.dispatch_id = t.dispatch_id
     JOIN trucks tr ON tr.truck_id = dr.truck_id
     JOIN employees driver ON driver.employee_id = dr.driver_id
     LEFT JOIN employees second ON second.employee_id = dr.second_driver_id
     LEFT JOIN employees helper ON helper.employee_id = dr.helper_id
     JOIN routes route ON route.route_id = dr.route_id
     LEFT JOIN users approver ON approver.user_id = dr.approved_by
     WHERE t.trip_id = ?'
);
$stmt->execute([$tripId]);
$trip = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$trip) {
    http_response_code(404);
    exit('Trip not found.');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pre-Advice — <?= htmlspecialchars($trip['trip_number']) ?></title>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <style>
    body { max-width: 760px; margin: 2rem auto; padding: 0 1rem; color: #17212b; }
    @media print { .no-print { display: none !important; } body { margin: 0; } }
  </style>
</head>
<body>
  <div class="d-flex justify-content-between align-items-start">
    <div><h1>Trip Pre-Advice</h1><p class="text-muted">Mulawin FleetOps</p></div>
    <button class="btn btn-primary no-print" type="button" onclick="window.print()">Print / Save PDF</button>
  </div>
  <hr>
  <dl class="row">
    <dt class="col-sm-4">Trip number</dt><dd class="col-sm-8"><?= htmlspecialchars($trip['trip_number']) ?></dd>
    <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= htmlspecialchars($trip['status']) ?></dd>
    <dt class="col-sm-4">Client</dt><dd class="col-sm-8"><?= htmlspecialchars($trip['client_name']) ?></dd>
    <dt class="col-sm-4">Route</dt><dd class="col-sm-8"><?= htmlspecialchars(($trip['route_name'] ?: '') . ' (' . $trip['origin'] . ' → ' . $trip['destination'] . ')') ?></dd>
    <dt class="col-sm-4">Scheduled departure</dt><dd class="col-sm-8"><?= $trip['scheduled_at'] ? date('M j, Y g:i A', strtotime($trip['scheduled_at'])) : '—' ?></dd>
    <dt class="col-sm-4">Expected arrival</dt><dd class="col-sm-8"><?= $trip['expected_arrival'] ? date('M j, Y g:i A', strtotime($trip['expected_arrival'])) : '—' ?></dd>
  </dl>
  <hr>
  <h2 class="h5">Truck and crew</h2>
  <dl class="row">
    <dt class="col-sm-4">Truck</dt><dd class="col-sm-8"><?= htmlspecialchars($trip['plate_number'] . ' — ' . $trip['brand'] . ' ' . $trip['model'] . ' (' . $trip['truck_type'] . ')') ?></dd>
    <dt class="col-sm-4">Driver</dt><dd class="col-sm-8"><?= htmlspecialchars($trip['driver_name']) ?><?= $trip['driver_license'] ? ' — License ' . htmlspecialchars($trip['driver_license']) : '' ?></dd>
    <?php if ($trip['second_driver_name']): ?>
    <dt class="col-sm-4">Second driver</dt><dd class="col-sm-8"><?= htmlspecialchars($trip['second_driver_name']) ?></dd>
    <?php endif; ?>
    <?php if ($trip['helper_name']): ?>
    <dt class="col-sm-4">Helper</dt><dd class="col-sm-8"><?= htmlspecialchars($trip['helper_name']) ?></dd>
    <?php endif; ?>
  </dl>
  <hr>
  <h2 class="h5">Cargo and references</h2>
  <dl class="row">
    <dt class="col-sm-4">Cargo</dt><dd class="col-sm-8"><?= $trip['cargo_description'] ? nl2br(htmlspecialchars($trip['cargo_description'])) : '<span class="text-muted">—</span>' ?></dd>
    <dt class="col-sm-4">Cargo weight</dt><dd class="col-sm-8"><?= $trip['cargo_weight_tons'] !== null ? number_format((float)$trip['cargo_weight_tons'], 2) . ' tons' : '<span class="text-muted">—</span>' ?></dd>
    <dt class="col-sm-4">Unit count</dt><dd class="col-sm-8"><?= $trip['unit_count'] !== null ? number_format((float)$trip['unit_count'], 2) : '<span class="text-muted">—</span>' ?></dd>
    <dt class="col-sm-4">Booking reference</dt><dd class="col-sm-8"><?= $trip['booking_reference'] ? htmlspecialchars($trip['booking_reference']) : '<span class="text-muted">—</span>' ?></dd>
    <dt class="col-sm-4">Waybill reference</dt><dd class="col-sm-8"><?= $trip['waybill_reference'] ? htmlspecialchars($trip['waybill_reference']) : '<span class="text-muted">—</span>' ?></dd>
  </dl>
  <?php if ($trip['remarks']): ?><p><strong>Remarks:</strong> <?= nl2br(htmlspecialchars($trip['remarks'])) ?></p><?php endif; ?>
  <p class="text-muted small mt-5">
    Approved by <?= htmlspecialchars($trip['approved_by_name'] ?? 'pending approval') ?>.
    Dispatch #<?= (int)$trip['dispatch_id'] ?>, Trip #<?= (int)$trip['trip_id'] ?>.
    This pre-advice is informational and not a live tracking or GPS document.
  </p>
</body>
</html>
