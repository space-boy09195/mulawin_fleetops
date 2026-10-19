<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/enums.php';
require_once __DIR__ . '/../includes/client_rates.php';

requireAnyPermission(['clients.manage']);

$clientId = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);
if (!$clientId || $clientId < 1) {
    http_response_code(400);
    exit('Invalid client.');
}

$pdo = getDBConnection();
$clientQuery = $pdo->prepare('SELECT client_id, client_name, status FROM clients WHERE client_id = ?');
$clientQuery->execute([$clientId]);
$client = $clientQuery->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    http_response_code(404);
    exit('Client not found.');
}

// Target client_locations: no pickup/delivery type and no notes.
$locationQuery = $pdo->prepare(
    'SELECT location_id, location_name, address, contact_person, contact_number, is_active
     FROM client_locations
     WHERE client_id = ?
     ORDER BY is_active DESC, location_name'
);
$locationQuery->execute([$clientId]);
$locations = $locationQuery->fetchAll(PDO::FETCH_ASSOC);

// Target client_rates: per route (or any route) and truck type.
$rateQuery = $pdo->prepare(
    'SELECT cr.rate_id, cr.route_id, cr.truck_type, cr.rate_type, cr.rate_amount,
            cr.effective_date, cr.end_date, cr.is_active,
            r.route_name, r.origin, r.destination, r.distance
     FROM client_rates cr
     LEFT JOIN routes r ON r.route_id = cr.route_id
     WHERE cr.client_id = ?
     ORDER BY cr.is_active DESC, cr.effective_date DESC, cr.rate_id DESC'
);
$rateQuery->execute([$clientId]);
$rates = $rateQuery->fetchAll(PDO::FETCH_ASSOC);
$routes = $pdo->query(
    'SELECT route_id, route_name, origin, destination, distance FROM routes WHERE is_active = 1 ORDER BY route_name'
)->fetchAll(PDO::FETCH_ASSOC);
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$rateTypeLabels = [
    'Route'    => 'Per trip on the route',
    'Distance' => 'Per kilometre of the route',
    'Fixed'    => 'Fixed per trip',
];
$GLOBALS['page_js'] = APP_BASE . '/assets/js/client_master_data.js';

layoutHead('Client Locations & Rates', APP_BASE . '/assets/css/billing.css');
?>
<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-3">
  <div>
    <h1 class="page-title">Locations &amp; Rates</h1>
    <p class="page-subtitle"><?= $escape($client['client_name']) ?> · <?= $escape($client['status']) ?></p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= APP_BASE ?>/pages/clients.php">Back to Clients</a>
</div>

<div id="masterDataAlert" class="alert d-none" role="alert"></div>

<section class="card mb-4">
  <div class="card-header-custom"><h2 class="card-title-custom">Client locations</h2></div>
  <div class="card-body">
    <form id="locationForm" class="mb-4">
      <input type="hidden" name="action" value="save_location">
      <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
      <input type="hidden" name="location_id" id="locationId">
      <div class="row g-3 align-items-end">
        <div class="col-md-3"><label class="form-label" for="locationName">Location name</label><input class="form-control" name="location_name" id="locationName" maxlength="150" required></div>
        <div class="col-md-5"><label class="form-label" for="locationAddress">Address</label><input class="form-control" name="address" id="locationAddress" maxlength="1000" required></div>
        <div class="col-md-2"><label class="form-label" for="locationContact">Contact person</label><input class="form-control" name="contact_person" id="locationContact" maxlength="150"></div>
        <div class="col-md-2"><label class="form-label" for="locationPhone">Contact number</label><input class="form-control" name="contact_number" id="locationPhone" maxlength="50"></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary" type="submit" id="saveLocationButton">Add location</button><button class="btn btn-outline-secondary d-none" type="button" id="cancelLocationEdit">Cancel edit</button></div>
      </div>
    </form>
    <div class="table-responsive">
      <table class="table-custom">
        <thead><tr><th>Location</th><th>Address</th><th>Contact</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$locations): ?><tr><td colspan="5" class="text-center text-muted py-4">No client locations yet.</td></tr>
        <?php else: foreach ($locations as $location): ?>
          <tr>
            <td><strong><?= $escape($location['location_name']) ?></strong></td>
            <td><?= $escape($location['address']) ?></td>
            <td><?= $escape($location['contact_person'] ?? '—') ?><br><?= $escape($location['contact_number'] ?? '') ?></td>
            <td><?= $location['is_active'] ? 'Active' : 'Inactive' ?></td>
            <td class="text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-primary edit-location"
                data-id="<?= (int)$location['location_id'] ?>" data-name="<?= $escape($location['location_name']) ?>"
                data-address="<?= $escape($location['address']) ?>"
                data-contact="<?= $escape($location['contact_person'] ?? '') ?>" data-phone="<?= $escape($location['contact_number'] ?? '') ?>">Edit</button>
              <button type="button" class="btn btn-sm btn-outline-secondary toggle-location"
                data-id="<?= (int)$location['location_id'] ?>" data-active="<?= (int)$location['is_active'] ?>">
                <?= $location['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="card">
  <div class="card-header-custom"><h2 class="card-title-custom">Effective-dated rate cards</h2></div>
  <div class="card-body">
    <p class="small text-muted">A rate applies to one truck type, on one route or on any route (fixed rates only). Billing suggests the charge from the rate in force on the trip date; a route rate wins over an any-route rate.</p>
    <form id="rateForm" class="mb-4">
      <input type="hidden" name="action" value="save_rate">
      <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
      <input type="hidden" name="rate_id" id="rateId">
      <div class="row g-3 align-items-end">
        <div class="col-md-4"><label class="form-label" for="rateRoute">Route</label><select class="form-select" name="route_id" id="rateRoute"><option value="">Any route (fixed rates only)</option><?php foreach ($routes as $route): ?><option value="<?= (int)$route['route_id'] ?>"><?= $escape($route['route_name'] . ' — ' . $route['origin'] . ' to ' . $route['destination'] . ($route['distance'] !== null ? ' (' . $route['distance'] . ' km)' : '')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label" for="rateTruckType">Truck type</label><select class="form-select" name="truck_type" id="rateTruckType" required><?php foreach (TRUCK_CATEGORIES as $truckType): ?><option><?= $escape($truckType) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label" for="rateType">Rate type</label><select class="form-select" name="rate_type" id="rateType"><?php foreach ($rateTypeLabels as $value => $label): ?><option value="<?= $escape($value) ?>"><?= $escape($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label" for="rateAmount">Amount (PHP)</label><input class="form-control" type="number" name="rate_amount" id="rateAmount" min="0.01" step="0.01" required></div>
        <div class="col-md-3"><label class="form-label" for="effectiveFrom">Effective from</label><input class="form-control" type="date" name="effective_from" id="effectiveFrom" value="<?= date('Y-m-d') ?>" required></div>
        <div class="col-md-3"><label class="form-label" for="effectiveTo">Effective to</label><input class="form-control" type="date" name="effective_to" id="effectiveTo"></div>
        <div class="col-md-6 d-flex gap-2"><button class="btn btn-primary" type="submit" id="saveRateButton">Add rate</button><button class="btn btn-outline-secondary d-none" type="button" id="cancelRateEdit">Cancel edit</button></div>
      </div>
    </form>
    <div class="table-responsive">
      <table class="table-custom">
        <thead><tr><th>Route</th><th>Truck type</th><th>Rate</th><th>Effective</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$rates): ?><tr><td colspan="6" class="text-center text-muted py-4">No client rates yet.</td></tr>
        <?php else: foreach ($rates as $rate): ?>
          <tr>
            <td><?= $rate['route_id'] ? $escape($rate['route_name'] . ' — ' . $rate['origin'] . ' to ' . $rate['destination']) : '<span class="text-muted">Any route</span>' ?></td>
            <td><?= $escape($rate['truck_type']) ?></td>
            <td>PHP <?= number_format((float)$rate['rate_amount'], 2) ?><br><span class="small text-muted"><?= $escape($rateTypeLabels[$rate['rate_type']] ?? $rate['rate_type']) ?></span></td>
            <td><?= $escape($rate['effective_date']) ?> → <?= $escape($rate['end_date'] ?? 'No end date') ?></td>
            <td><?= $rate['is_active'] ? 'Active' : 'Inactive' ?></td>
            <td class="text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-primary edit-rate"
                data-id="<?= (int)$rate['rate_id'] ?>" data-route="<?= (int)($rate['route_id'] ?? 0) ?: '' ?>"
                data-truck-type="<?= $escape($rate['truck_type']) ?>" data-rate-type="<?= $escape($rate['rate_type']) ?>"
                data-amount="<?= $escape($rate['rate_amount']) ?>" data-from="<?= $escape($rate['effective_date']) ?>"
                data-to="<?= $escape($rate['end_date'] ?? '') ?>">Edit</button>
              <button type="button" class="btn btn-sm btn-outline-secondary toggle-rate"
                data-id="<?= (int)$rate['rate_id'] ?>" data-active="<?= (int)$rate['is_active'] ?>">
                <?= $rate['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php layoutFoot(); ?>
